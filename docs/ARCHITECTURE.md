# Architecture

How BanzaiPlay is put together, and the decisions behind it that are not obvious from the code — several of which depart from the original spec in [CLAUDE.md](../CLAUDE.md) on purpose. BanzaiPlay was built from BanzaiEmbed's foundations (records, uploader, admin screens, path relocation); where the reasoning is the same, BanzaiEmbed's [ARCHITECTURE.md](../../Banzai-Embed/docs/ARCHITECTURE.md) has the longer version.

## Layout

```
banzaiplay.php                  bootstrap: constants, autoloader, plugins_loaded, bzpl_uninstall()
                                (registered as the uninstall hook on activation), deactivation
includes/
  class-plugin.php              wires everything; fires bzpl/init
  class-game-manager.php        the banzaiplay_games option, where each game's files live, status, dimensions
  class-uploader.php            zip → staging → game directory; the security boundary
  class-engine-detector.php     which engine made a build, and what Frame needs to start it
  class-path-rewriter.php       points a root-based build's references at its new location (from BanzaiEmbed)
  class-asset-redirect.php      redirects would-be 404s naming a file in a game's build to it (from BanzaiEmbed)
  class-frame.php               serves the document inside each game's iframe, and compressed Unity files
  class-embed.php               the player markup; shared by shortcode and block
  class-shortcode.php           [banzai-play] → Embed::render()
  class-block.php               banzaiplay/game → Embed::render(); editor data
  class-admin.php               menu, list/edit screens, admin-post handlers, notices
  class-filesystem.php          the only code that touches the disk (WP_Filesystem_Direct, plus streaming)
  class-settings.php, …         feature modules (see Feature modules)
blocks/game/block.json          block metadata (editor script registered by handle — no build step)
templates/                      admin screens and cards, and the gallery
assets/js/frontend.js           the player on the page: overlay, loading screen, frame, focus, fullscreen
assets/js/frame.js              the bridge inside every game's frame
assets/js/engines/              unity.js, godot.js start those engines; phaser.js, playcanvas.js improve progress
assets/js/admin.js, block.js    admin screens (with upload progress) and the block editor
assets/js/player-extras.js      the player's extras on the page: data, events, results, statistics, several games
assets/js/gallery.js            the gallery's lightbox; admin-extras.js: media and colour fields, Data Bridge rows
tests/                          fixtures, upload script, browser tests
tools/build.ps1                 allowlist packager for the GitHub release zip
```

Naming follows the sibling plugins' real convention (not the spec's `banzaiplay_` everywhere): namespace `BanzaiPlay\`, global functions `bzpl_`, constants `BZPL_`, hooks `bzpl/…`, admin handles and CSS classes `bzpl-`. The slug, text domain, option (`banzaiplay_games`), uploads folder and the front-end CSS classes (`banzaiplay-container` and friends, from the spec — site owners style these) are `banzaiplay`.

## The core decision: every game plays in a same-origin iframe

The spec describes Unity booted inline on the WordPress page (a `<canvas>` in the container, `createUnityInstance()` called by the page) and only generic HTML5 games in an iframe. BanzaiPlay puts every game in an iframe, because the frame solves most of the spec's "core problems" by itself:

- **Keyboard capture (#2).** Key events only go to the focused document. While the frame has focus the game gets every key; when the visitor clicks outside, the page gets them. Unity's `captureAllKeyboardInput` only ever affects its own window, so it doesn't need overriding — the inline alternative (stopping key events on the page's window while the game isn't focused) would also stop the page's own key handlers. Escape is caught by the bridge's capture-phase listener on the frame's window, registered before any game script, so the game never sees it.
- **Isolation.** The game's CSS, globals and engine runtime can't touch the theme, and vice versa. Two engines on one page can't collide.
- **Unloading.** Removing the frame frees the game completely, WebGL context and memory included — the foundation for the "one game at a time" setting.
- **Sizing (#3).** The game sees a window exactly the player's size; engines that fill their window (Unity, Godot, Phaser's FIT/RESIZE) just work.

The cost: the canvas is not in the page, so the spec's container markup has an empty `.banzaiplay-stage` where the frame goes instead of `<canvas class="banzaiplay-canvas">`.

### The frame document (`Frame`)

Served at `/?banzaiplay_frame={slug}&v={build}.{plugin version}` on `do_parse_request` (priority 5), before WordPress resolves anything. Headers: `X-Frame-Options: SAMEORIGIN` and `frame-ancestors 'self'` (only this site may frame it), `X-Robots-Tag: noindex`, `Cache-Control: public, max-age=300` (short, so switching a game off reaches cached copies soon; the version in the URL handles new builds). An inactive game is served only to users who can manage games, for the edit screen's preview.

What's in it depends on the engine's **mode** (`engine_data.mode`):

- **`unity`** — a document BanzaiPlay writes: a full-window canvas, the bridge, and `engines/unity.js`, which loads the build's `.loader.js` and calls `createUnityInstance(canvas, config, onProgress)` (or `UnityLoader.instantiate()` for 2019 and earlier). The config is assembled from the build's files and the names in its `index.html` (`companyName`, `productName`, `productVersion`). Unity's `showBanner` is replaced so errors land on the player's error screen instead of an `alert()`.
- **`godot`** — a document BanzaiPlay writes, with `<base href>` at the export folder and `engines/godot.js`, which loads the engine script and starts it the way the export's page would, from the export's `GODOT_CONFIG` (parsed at upload with a brace-matcher, since it contains nested objects). It calls `init()` + `preloadFile()` + `start()` rather than `startGame()` so the pack can be fetched as `index.pck?v={build}` but stored in the engine's filesystem as `index.pck`: Godot names the pack the same in every export.
- **`html`** — every other engine boots from its own page, so that page is served with `<base href>` pointing at its folder in the build (relative URLs resolve there: scripts, fetches, workers, dynamic imports) and the bridge injected first in `<head>`. A page's own `<base>` is kept, made absolute. The page's charset is sent in the header, because the injected tags push its `<meta charset>` past the 1024 bytes browsers look in. Godot 3.2 and earlier (no config object) play this way too.

Engine hooks run in `<head>` after the bridge for `html` games: `phaser.js` traps the `window.Phaser` assignment and wraps `LoaderPlugin.prototype.start` so the first scene's preload reports real progress and marks the game ready; `playcanvas.js` waits for `pc.app` and listens for `preload:progress` and `start`. Without a hook, `html` games get an estimated progress bar (resources loaded, via `PerformanceObserver`) and are ready at the page's `load`.

Only the script for the game's engine is ever loaded, and only inside its frame. The page loads `frontend.js` and `frontend.css`, nothing else.

**No `sandbox` attribute** although the spec mentions a sandboxed iframe: the frame must keep `allow-same-origin` and `allow-scripts` (without the first, localStorage and IndexedDB throw — where games keep their saves — and module scripts and fetches of the game's own files become cross-origin and fail), and with both a sandbox is no boundary at all: the game could remove it. Firefox also warns about exactly that combination in the console of every page. The real boundary is who may upload (`unfiltered_html`). The frame does get `allow="autoplay; fullscreen; gamepad; accelerometer; gyroscope; clipboard-write; screen-wake-lock"`.

### The bridge (`assets/js/frame.js`)

Messages to the parent only, at the frame's own origin (which is the page's — SAMEORIGIN): `hello`, `progress {value}` (0–1, never backwards; negative = unknown), `ready`, `error {message}`, `exit`, `focus`, `blur`, `release`. The parent sends `focus`. `frontend.js` matches messages to players by `event.source` — the frame's WindowProxy, which stays the same object across the frame's navigation — and checks the origin.

It also smooths over:

- **Audio (#5).** Chrome lets a same-origin (or `allow="autoplay"`) frame play audio once the top page has had a user gesture — the Play click. Firefox checks the top-level document too. Safari wants a gesture in the frame itself. So the bridge wraps `AudioContext`/`webkitAudioContext` (with `Reflect.construct` and `new.target`, so subclasses still work) to track every context, resumes suspended ones as soon as it can and again on the first pointer or key event in the frame, and retries media elements whose `play()` was refused.
- **WebAssembly MIME types.** `instantiateStreaming()`/`compileStreaming()` fail outright when `.wasm` isn't served as `application/wasm` (Godot doesn't fall back). The bridge falls back to compiling the downloaded bytes.

### The player on the page (`assets/js/frontend.js`)

Click to Play → the frame is created and focused, so the keyboard goes straight to the game. Autoplay creates it without focusing — a page must never lose focus on load. Its `src` is set **before** it is inserted: a frame inserted without one loads `about:blank` first, and that `load` once counted as the game's, revealing heavy builds (Unity at 90%) before they were ready. Its window — the same WindowProxy before and after it navigates — is registered once inserted, before any message can arrive. A frame that never says `hello` (the bridge didn't run) is revealed 500 ms after its real `load`.

- **Focus.** `focus`/`blur` from the bridge toggle `.is-focused` (the glow) and the hint under the game ("Press Esc to give the keyboard back to the page"). `release` focuses the container (`tabindex="-1"`), which takes focus out of the frame; the next Tab goes to the fullscreen button. The release key is per game: Esc, Shift+Esc (for games that use Esc), or none (click outside only). Tab is never taken from the game — Esc is the documented way out, which is what WCAG's "no keyboard trap" asks for.
- **Fullscreen (#4).** `requestFullscreen()` (or `webkitRequestFullscreen()`) on the container, so the controls bar comes along; CSS letterboxes the viewport with `width: min(100vw, (100vh − controls) × ratio)`. On touch devices it tries `screen.orientation.lock()` to the game's orientation. The button is hidden where the API isn't available (iPhone Safari). The game is re-focused after entering.
- **Sizing.** The container is `width: 100%` up to `max-width: {native width}px`; the viewport has `aspect-ratio: var(--banzaiplay-ar)`. Fit mode `scale` (for fixed-size canvases) gives the frame the native size as its `width`/`height` attributes and scales it with a transform from a `ResizeObserver` — pointer coordinates map through transforms, so the game sees its own unscaled space.
- **Mobile (#6).** `desktop_only` shows a note on touch-only devices (`(hover: none) and (pointer: coarse)`) and relabels the button "Play anyway".

## Data

One autoloaded option, `banzaiplay_games`, keyed by slug. The record shape is `Game_Manager::defaults()`; missing fields are filled from it, so adding a field needs no migration. `active` (whether the owner wants it shown) is separate from `status()` (`ready`, `needs-entry`, `no-build` — whether it can be played). The slug cannot change after creation: shortcodes, blocks and the uploads path use it.

`start` is when the player creates the game's frame: `click` (on Play; the default) or `load` (with the page). `Embed::render()` takes `autoplay` from the shortcode or block when they give one (`autoplay="true"`/`"false"`; the block's `start` of `click`/`load`) and from the game otherwise, except for the edit screen's preview, which always waits — opening a game's settings shouldn't download it. In `frontend.js`, a `desktopOnly` game on a touch-only device never starts by itself, and an autoplayed game is never focused: a page must not lose the keyboard on load, and the bridge's audio resume waits for the visitor's first click in the game.

`engine` is what the game is played as: `engine_override` if set, else `detected_engine`. `entry` is what boots it (a page, a Unity loader or legacy build JSON, or a Godot engine script), chosen from `entries` by `entry_override` or by default. `engine_data` holds everything Frame needs; its `mode` picks the document. Width and height are the admin's (both or neither), else the build's (`detected_width/height`: a Unity template's canvas, a page's first `<canvas width height>`, PlayCanvas's `config.json`), else 960 × 540.

## Builds: one stable folder per game

The spec's layout, `uploads/banzaiplay/{slug}/`, deliberately — **not** BanzaiEmbed's new folder per upload. Engines key saved data to the URL the game is served from: Unity's `persistentDataPath` and PlayerPrefs hash the folder of the `.data` file. A new folder per upload would wipe every player's saves on every update.

So uploads are unpacked into `uploads/banzaiplay/_staging/{timestamp-random}/` (slugs can't contain `_`, so it can't collide with a game), analysed and relocated there, and only then swapped in: the old folder is moved aside, the new one moved into place (the old one is put back if that fails), the old one deleted. A failed upload leaves the live game untouched. Staging folders over a day old are swept on the next upload.

Cache-busting is the build ID (`build`, new per upload) in the query string of everything BanzaiPlay requests: the frame URL, Unity's loader/data/framework/code, Godot's engine script and pack. The page itself only references the frame URL, so a cached page picks up the new build as soon as its frame does. Not covered: files a game requests itself under unchanged names (an unhashed `game.js` loaded by the page, sprites). Browsers revalidate those heuristically; hashed file names (Vite, webpack) avoid it. Module scripts are never given a query — a chunk importing the entry without one would load a second instance of it.

## Upload security

As BanzaiEmbed (`Uploader`): `manage_options` **and** `unfiltered_html`; entries read one at a time and only allowlisted static types written (no extract-to-temp, so no PHP ever exists on disk); PHP-like extensions anywhere in a name refused; traversal, absolute paths, drive letters, NUL bytes and dotfiles refused; caps on file count (20 000) and size (1 GB), filterable; one wrapper folder unwrapped. No `.htaccess` is written.

Differences: the allowlist adds engine formats (`.data`, `.mem`, `.pck`, `.unityweb`, `.bundle`, `.br`, `.gz`, `.zip`, audio/3D/tooling formats, Defold's numbered archive parts). Entries are **streamed** to disk (`ZipArchive::getStream()` → `stream_copy_to_stream()`), never held whole in memory — a Unity `.data` file can be hundreds of MB — and a stream that delivers more than its entry declared is treated as a zip bomb. (Without ZipArchive, the PclZip fallback can only extract to strings; the upload card warns.)

## Compressed Unity builds

Unity's gzip and Brotli builds need `Content-Encoding` headers, which static hosting only sends with server configuration — the most common reason a Unity build fails on WordPress ("Unable to parse Build/x.framework.js.br"). A plugin can't write nginx rules, and an `.htaccess` with `Header` directives turns the directory into 500s on hosts whose `AllowOverride` doesn't allow them. So `Frame::serve_file()` sends the compressed files itself (`/?banzaiplay_file=slug&path=Build/x.data.gz&v=build`): only paths stored on the game, with the inner type (`application/wasm`, `application/javascript`), `Content-Encoding`, a year's `immutable` caching and output buffering/compression switched off, then `readfile()`. It answers 406 when the browser didn't offer the encoding — browsers only accept Brotli over HTTPS, so `unity.js` explains that up front on http://. The admin is told that Decompression Fallback (`.unityweb`, decompressed by the loader) is faster: it is served statically.

## Builds compiled for another path

`Path_Rewriter` and `Asset_Redirect` are BanzaiEmbed's, adapted: a page whose `<script src>`/`<link href>` names one of the build's files by a root-absolute path (`/assets/index.js` → base `/`) has every exact `{base}{file}` reference in its HTML, JS and CSS rewritten at upload to the game folder, and Vite's preload helper relocated so lazy chunks load. URLs assembled at runtime (`BASE_URL + 'x.png'`, `/draco/`) reach the server at the root and 404; `Asset_Redirect` 302s those to the file in an active `html`-mode game's folder, preferring the game named by the Referer — the frame document's `banzaiplay_frame` query, or a file in its folder. See BanzaiEmbed's architecture for the safety rules (would-be 404s only, GET/HEAD only, dotted last segment, site files like robots.txt never claimed).

## Admin

The edit screen has a **Preview** card that renders the real player (inactive games included, for managers), so a build can be tested before it is placed on a page. Uploads post to admin-post.php like every other form, but `admin.js` sends them with `XMLHttpRequest` to show upload progress, with `ajax=1`; `Admin::redirect()` then answers with JSON naming the page instead of redirecting, because an XHR following the redirect itself would render the page and use up the queued notices. The client checks the file against `wp_max_upload_size()` first. The upload card shows the current limits and how to raise them (php.ini / `.user.ini`, `.htaccess` for mod_php, nginx's `client_max_body_size`).

The spec's `wp_ajax_banzaiplay_upload_game`/`_delete_game` are admin-post actions instead (`banzaiplay_save_game`, `banzaiplay_delete_game`, `banzaiplay_toggle_game`), as in BanzaiEmbed: they work without JavaScript and give every error a page to land on.

## Feature modules

BanzaiPlay is distributed free on GitHub, with no paid tier and no licensing; it was built with a Freemius Pro tier, dropped when WordPress.org turned away BanzaiEmbed for being an embedding plugin. The modules that made up Pro are registered in `Plugin::run()` like everything else, and still attach through hooks the core fires rather than being named by it — `bzpl/player_config`, `bzpl/player_classes`, `bzpl/player_style`, `bzpl/player_logo`, `bzpl/frontend_script_deps`, `bzpl/enqueue_assets`, `bzpl/admin_menu` (with `Admin::add_screen()`/`render_screen()`), `bzpl/admin_enqueue`, `bzpl/edit_cards`, `bzpl/save_game`, `bzpl/header_actions`, `bzpl/game_deleted`. Keep new features to that shape. Each edit-screen card posts a hidden marker field (`bzpl_look`, `bzpl_gallery`, …) and its save handler only touches the record when the marker was posted, so the Add New form leaves those settings alone.

Uninstall cleanup (games, builds, settings, the plays table, the cron event) is `bzpl_uninstall()`, registered with `register_uninstall_hook()` on activation.

The page side is one script, `player-extras.js`, registered as a **dependency of `frontend.js`** so it runs first: a game that starts with the page starts while `frontend.js` runs, and its listeners must already be there. It listens on `document` for the player's DOM events (`banzaiplay:start` — cancelable —, `frame`, `ready`, `error`, `exit`, `unload`), which are free and public, and uses the Player's `start()`, `stop()`, `unload()`.

| Module | What it does |
|---|---|
| `Settings` | BanzaiPlay → Settings, option `banzaiplay_settings`: default logo and colour; play one game at a time; close games scrolled out of view; record plays, and for how long. |
| `Branding` | Spec #11. Per game (`look`: cover, backdrop, logo, colour), falling back to Settings: `has-backdrop`/`has-logo` classes, `--banzaiplay-accent`, readable `--banzaiplay-on-accent` (black or white, whichever contrasts more — the switch is at luminance ≈ 0.18) and `--banzaiplay-glow`, the logo above the title. Images are media-library attachments. |
| `Gallery` | Spec #12: `[banzai-play-gallery]` with `tags`, `games`, `engine`, `filter`, `open`, `columns`, `orderby`, `limit`. Per game `gallery`: tags, page URL; the picture is the cover. Each card's player is printed in a `<template>`; `gallery.js` clones it into the gallery's `<dialog>` and starts it on the click that opened it (so it has sound and the keyboard), and removes it on close. Esc first releases the keyboard (the frame has it), then closes. The card title is the one control per game; the picture repeats it for the mouse only. |
| `Plays` | Spec #13's storage and #15's server side. Table `{prefix}banzaiplay_plays` (dbDelta, `banzaiplay_db_version`), one row per play: random `sid`, game, post, UTC start, seconds, device class, completed, score, event count — no IP, user ID or cookie. Endpoint `admin-ajax.php?action=bzpl_play` (`op` = `start` / `ping` / `event`). Starts return a token (HMAC of sid and game), which later calls must send: another site can't post events in a logged-in visitor's name, but events are still the browser's claim — documented as such. Starts are rate-limited per visitor (120 / 10 min, by hashed address in a transient), events capped at 300 per play, time capped at what has passed since the start. Every event fires `bzpl/game_event` (and `bzpl/game_event/{name}`) with the logged-in user; `complete` and `score` update the row. Old rows are pruned daily on cron. Not a REST route, for the reasons in BanzaiEmbed's Data Bridge. |
| `Analytics` | BanzaiPlay → Analytics: plays, average time, completion rate (over games that ever sent `complete`), device split, a server-rendered SVG chart of plays per day (site time zone), and a table per game. |
| `Data_Bridge` | Spec #14, BanzaiEmbed's design: values (text, post, site) go in the player's config, cacheable; the visitor's fields (ID, name, email, roles, REST nonce) are fetched from `admin-ajax.php?action=bzpl_user`, never cached. The page hands them to the frame **on the iframe element** (`frame.banzaiPlay`) before setting its `src`, and the bridge reads `frameElement.banzaiPlay` synchronously, before the game's first script — so `BanzaiPlay.data` is there from the start. |
| `Game_Events` | Spec #15's per-game part: the results screen switch (`events.results`) and the card documenting `emit()`, the DOM event and the PHP hooks. |
| `Player_Extras` | Registers `player-extras.js`/`.css` (with their `window.banzaiPlayExtras` config) and `admin-extras.js` (media library, colour picker). |

**The game's API** is `window.BanzaiPlay` inside the frame, defined by the bridge itself so a game built for it never throws, even where nothing is configured: `game`, `data` (`{}` with no Data Bridge values), `user()` (`null` without user fields), `emit(name, data)` (also `emit(slug, name, data)`, the spec's form). Names are 1–64 of `A-Za-z0-9_.:-`; data is copied through JSON. `emit()` posts an `emit` message to the page, where `player-extras.js` fires `banzaiplay:event` on the player, reports it through the play's session, and — with the results screen on — shows score and time over the game for `complete`. A session's requests are **sent one after another**: a `score` just before `complete` once landed after it and overwrote the score. `score` events are coalesced to one every two seconds. `window.BanzaiPlay.games[slug]` and `window.BanzaiPlay.emit(slug, name, data)` on the page are the spec's parent-side forms.

**Time played** counts only while the game runs and the page is visible; it is reported every 30 s, when the tab is hidden (often the last chance on mobile), and on unload or `pagehide` by `sendBeacon`. The edit screen's preview is never counted (`track` is left out of its config).

**Several games on a page** (spec #16; nothing downloads before Play): games that start with the page load one after another in page order — `banzaiplay:start` is cancelled for an autoplay while another game is loading, the queued player shows "Waiting for the other game to load…", and the next starts when one becomes ready, fails or goes. With *one at a time*, starting a game `stop()`s the others, and only the first autoplay game starts. With *close out of view*, an `IntersectionObserver` stops a game ten seconds after it leaves the viewport (never in fullscreen).
## Testing

`tests/make-fixtures.php` (in the wp-env CLI container) builds synthetic fixtures — a plain HTML5 game, a root-based Vite-style build, Unity plain and gzip, Godot 4.3, global-script Phaser, a hostile zip — with the real file layouts and loader APIs, each recording what it saw in `window.fixture`. `tests/e2e.sh` uploads zips through the real form. `tests/browser.mjs` plays every game on a page in headless Chrome/Edge via the DevTools protocol (real clicks and key presses) and checks the player's state, that keys reach the game and not the page, and that Esc gives them back. The fakes test BanzaiPlay's side, not Unity or Godot, so `tests/fetch-real.sh` downloads real third-party exports into `tests/real/` (gitignored — several have no licence; never commit or ship them):

| Build | What it covers | Result (2026-10-04, headless Edge, software WebGL) |
|---|---|---|
| `unitydemo` — [dylanebert/UnityDemo](https://huggingface.co/spaces/dylanebert/UnityDemo) | Unity 2020+, uncompressed, 55 MB | Runs in ~8 s; focus, Esc release |
| `unity-gzip-rogueci` — [yanniboi/game-ci-test](https://github.com/yanniboi/game-ci-test) `gh-pages` | Unity, gzip without Decompression Fallback → `serve_file()` | Runs in ~7 s |
| `godot4-warlocks` — [tsvetowntopalov/warlocks](https://github.com/tsvetowntopalov/warlocks) | Godot 4.x, `GODOT_THREADS_ENABLED = false`, 62 MB | Runs (~70 s on software rendering, mostly shader compilation) |
| `godot3-dodge` — [godot-demo/godot-2d](https://huggingface.co/spaces/godot-demo/godot-2d) | Godot 3.x config API (`gdnativeLibs`) | Runs in ~9 s; keys reach the game |
| `godot3-dodge-threads` — [godot-demo/godot-2d-threads](https://huggingface.co/spaces/godot-demo/godot-2d-threads) | Threaded export (`index.worker.js`) | Fails cleanly with the threads message |

The feature modules are covered by `tests/features.mjs`, which runs `tests/features-setup.php` itself — it adds two images, a must-use plugin that logs `bzpl/game_event`, and the test pages. It covers the Settings and edit-screen cards saved through their forms, the branded player, the Data Bridge from inside the frame, events reaching the page, the results screen, the PHP hook and the plays table, Play again, one at a time, load one at a time (event order), the gallery's filters and lightbox, and Analytics. (Last run 2026-10-04, as `pro.mjs`, before licensing was removed.)

Not covered by a real build yet: Unity Brotli (only the HTTPS error path is testable on the http:// test site), Unity 2019 `UnityLoader.js`, Construct 3, PlayCanvas, a global-script Phaser game, and real mobile devices.

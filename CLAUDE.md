# BanzaiPlay — Game Embed for WordPress

## Overview

A WordPress plugin that lets developers embed HTML5/WebGL games from any engine into WordPress pages and posts. Upload your game build, get a shortcode or Gutenberg block, and the plugin handles loading screens, input management, responsive canvas sizing, fullscreen, audio initialization, and all the engine-specific quirks that make raw iframe embedding unreliable.

Supports Unity WebGL, Phaser, Godot HTML5, Construct 3, PlayCanvas, PixiJS, and any other engine that exports to HTML5/WebGL. The plugin detects the engine from the build files and applies the right configuration automatically.

## Distribution

Free and open source, released on GitHub (https://github.com/JoshRobs/BanzaiPlay) as a zip attached to each release. WordPress.org rejected BanzaiEmbed because it doesn't accept embedding plugins, and BanzaiPlay is the same kind, so the planned Freemius Pro tier was dropped: every feature ships to everyone, with no licensing, no upsells, no "Powered by" branding and no Freemius SDK. Don't reintroduce feature gating or "Pro" labels.

## Why This Exists

There is no maintained, polished WordPress plugin for embedding games. The existing options are:

- **Unity for WP** — GitHub repo only, not on wordpress.org, Unity-only
- **Bean Unity3D WebGL** — planned for 2019, never shipped
- **WordPress WebGL Plugin** — from 2016, abandoned
- **Manual iframe embedding** — the actual advice given in Unity forums: "upload via FTP and use an iframe"

Every game developer with a WordPress portfolio or studio site hits this problem. The current answer is "figure it out yourself." BanzaiPlay is the clean, maintained answer.

## Target User

- Indie game devs showcasing portfolio pieces on their WordPress site
- Game studios with WordPress marketing/press sites
- Educational institutions embedding gamified learning content
- Marketing agencies adding interactive game-like experiences to client sites
- The itch.io crowd who also maintain WordPress sites for their studio or personal brand
- Web game developers (Phaser, PixiJS) who publish directly on WordPress

## Architecture

- **Type:** WordPress plugin (standard WP plugin structure)
- **Admin interface:** Game management page for uploading, configuring, and managing game builds
- **Frontend output:** Shortcode `[banzai-play]` and Gutenberg block that render the game container with all necessary loading/input/sizing infrastructure
- **Storage:** Game builds stored in `wp-content/uploads/banzaiplay/{game-slug}/`
- **No external dependencies:** No Node.js, no build tools on the server

## Core Problems This Plugin Solves

These are the game-specific challenges that a general embed plugin or raw iframe won't handle properly:

### 1. Loading Experience
Unity WebGL builds are often 20-50MB+. Without a proper loading bar, the user stares at a blank rectangle for 30 seconds. The plugin provides a styled, branded loading screen with a real progress bar that reads the engine's loading events.

### 2. Keyboard Input Capture
Unity WebGL captures ALL keyboard input on the page by default — Tab, arrow keys, spacebar, everything. This breaks WordPress navigation, comment forms, and accessibility. The plugin manages focus: the game only captures input when the canvas is clicked/focused, and releases it when the user clicks outside or presses Escape.

### 3. Canvas Sizing
Game canvases need specific aspect ratios. A 16:9 game crammed into a 4:3 container looks terrible. The plugin maintains the game's native aspect ratio within the available space, handles responsive resizing, and avoids the page-wide horizontal scroll that a fixed-width canvas causes on mobile.

### 4. Fullscreen
Browsers require fullscreen requests to originate from a user gesture. The plugin provides a fullscreen button that handles this correctly across browsers, including the exit-fullscreen flow and canvas resize on fullscreen toggle.

### 5. Audio Initialization
Modern browsers block autoplay audio. A game that starts with sound will produce silence or errors. The plugin shows a "Click to Play" overlay that ensures user interaction has occurred before the game initializes audio, or handles the audio context resume on first interaction.

### 6. Mobile Considerations
Touch input, virtual viewport issues, orientation lock preferences, and performance warnings for games that are too heavy for mobile. The plugin can optionally show a "Best experienced on desktop" message for resource-intensive games.

## MVP Scope — Core

### 1. Game Management Admin Page

Admin page under top-level "BanzaiPlay" menu.

**Game list view:**
- Table: Game Name, Engine (auto-detected), Shortcode, Date Uploaded, Build Size, Status
- Actions per row: Edit, Delete, Copy Shortcode

**Add new game flow:**
- **Game Name** (required) — display name, generates the slug
- **Game Slug** (auto-generated, editable) — used in shortcode: `[banzai-play game="my-game"]`
- **Upload zone** — accepts a zip file containing the game build
- On upload: extract to `wp-content/uploads/banzaiplay/{game-slug}/`
- **Auto-detect engine** after extraction (see Engine Detection below)
- Show detected engine and entry point, let user confirm or override
- **Canvas Width / Height** (optional) — native resolution of the game. Defaults to 960x540. Used to calculate aspect ratio for responsive sizing
- **Description** (optional) — shown on the loading screen or in the Gutenberg block placeholder

### 2. Engine Detection

After zip extraction, the plugin scans the build files to identify the engine:

**Unity WebGL:**
- Look for `Build/` directory containing `.framework.js`, `.data`, `.wasm` files
- Or `UnityLoader.js` (older Unity versions)
- Or `Build/*.loader.js` pattern (Unity 2020+)

**Phaser:**
- Look for `phaser.min.js` or `phaser.js` in the build
- Or references to `Phaser.Game` in the JS files

**Godot HTML5:**
- Look for `.pck` file (Godot pack file)
- Or `godot.js` / `godot.tools.js`
- Or files matching `*.wasm` alongside a `.pck`

**Construct 3:**
- Look for `c3runtime.js` or `data.json` with Construct-specific structure
- Or `sw.js` (Construct's service worker)

**PlayCanvas:**
- Look for `__loading__.js` or `__start__.js`
- Or `config.json` with PlayCanvas-specific structure

**Generic HTML5 / Other:**
- Look for `index.html` as the entry point
- Treat as a generic HTML5 game loaded via iframe-like embedding

**Detection result:**
- Store the detected engine type and entry point file path
- Display to user with option to override if detection is wrong
- Engine type determines which loading/initialization strategy the frontend uses

### 3. Frontend Game Container

When the shortcode or block renders, it outputs a game container with:

**Container structure:**
```html
<div class="banzaiplay-container" data-game="{slug}" data-engine="{engine}" style="aspect-ratio: {width}/{height}; max-width: {width}px;">
  <div class="banzaiplay-overlay banzaiplay-click-to-play">
    <div class="banzaiplay-game-title">{Game Name}</div>
    <button class="banzaiplay-play-btn">Play</button>
  </div>
  <div class="banzaiplay-loading" style="display:none;">
    <div class="banzaiplay-progress-bar">
      <div class="banzaiplay-progress-fill"></div>
    </div>
    <div class="banzaiplay-progress-text">Loading...</div>
  </div>
  <canvas class="banzaiplay-canvas" style="display:none;"></canvas>
  <div class="banzaiplay-controls">
    <button class="banzaiplay-fullscreen-btn" title="Fullscreen">Fullscreen</button>
  </div>
</div>
```

**Initialization flow:**
1. Page loads — game container shows "Click to Play" overlay (ensures user gesture for audio)
2. User clicks Play — overlay hides, loading screen appears
3. Plugin loads the game using engine-specific initialization:
   - **Unity:** Create Unity instance via `createUnityInstance()` with progress callback driving the loading bar
   - **Phaser / PixiJS / Generic JS:** Load the game's entry script, which mounts to the canvas or container
   - **Godot:** Load the Godot engine and `.pck` file with progress tracking
   - **Construct 3:** Load via its own runtime initialization
   - **Generic HTML5:** Load `index.html` in a sandboxed iframe within the container
4. Loading bar fills based on engine progress events
5. Game starts — loading screen hides, canvas shows, controls appear

### 4. Input Management

**Focus handling:**
- Game canvas only captures keyboard input when focused (clicked)
- Visual focus indicator (subtle border glow) so user knows the game is active
- Clicking outside the canvas releases focus back to the page
- Escape key releases focus (configurable per game in case the game uses Escape)

**Unity-specific:**
- Override Unity's default `WebGLInput.captureAllKeyboardInput` to `false`
- Set it to `true` only when the canvas has focus
- Handle Tab key specifically — Unity captures it for UI navigation, but the page needs it for accessibility

**Touch input:**
- For mobile visitors, touch events pass through to the canvas normally
- No additional touch handling needed for V1 — the engine handles it

### 5. Responsive Canvas

- Container maintains the game's aspect ratio using CSS `aspect-ratio`
- `max-width` set to the game's native width so it doesn't scale up past native resolution (pixel games look terrible upscaled)
- Container is `width: 100%` up to `max-width`, so it scales down on smaller screens
- On window resize, the canvas dimensions update to match the container

### 6. Fullscreen

- Fullscreen button in the controls bar
- Uses the Fullscreen API (`requestFullscreen()`) on the game container
- On entering fullscreen: canvas resizes to fill the screen while maintaining aspect ratio (letterboxed if needed)
- On exiting fullscreen: canvas returns to its inline size
- Hide the fullscreen button if the Fullscreen API isn't available (some mobile browsers)

### 7. Loading Screen

- Styled loading screen with progress bar
- Game title displayed during loading
- Progress bar driven by engine-specific progress events:
  - Unity: `createUnityInstance` progress callback (0-1)
  - Godot: engine loading progress events
  - Phaser/Generic: approximate based on script load events
- ~~BanzaiPlay branding subtly shown (small "Powered by BanzaiPlay" text, removable in pro)~~ — dropped with the paid tier; no attribution is shown

### 8. Shortcode

`[banzai-play game="my-game"]`

**Optional attributes:**
- `width` / `height` — override the stored dimensions for this instance
- `class` — additional CSS classes on the container
- `autoplay` — skip the "Click to Play" overlay (default: false; not recommended due to audio issues but some users want it)
- `fullscreen` — show/hide the fullscreen button (default: true)

### 9. Gutenberg Block

"BanzaiPlay" block with:
- Dropdown to select from uploaded games
- Placeholder preview showing game name, engine badge, and dimensions
- Settings sidebar for the same options as shortcode attributes
- Not a live preview — shows a styled placeholder card

### 10. Game Updates

- "Replace Build" upload on the game edit page
- Uploads new zip, replaces existing files
- Re-runs engine detection
- Cache-busting version parameter on all enqueued assets

## MVP Scope — Advanced Features

Originally planned as a paid Pro tier behind Freemius. All of these are now free — see **Distribution** above.

### 11. Custom Loading Screens

- Upload a custom background image for the loading screen
- Custom loading bar color
- Custom logo/branding to replace the default
- Remove "Powered by BanzaiPlay" text

### 12. Game Portfolio Shortcode

`[banzai-play-gallery]`

Renders a responsive grid/gallery of all uploaded games with:
- Thumbnail for each game (auto-generated from a screenshot upload, or a placeholder)
- Game name and description
- Click to open the game in a lightbox or navigate to its page
- Filterable by engine type or custom tags

This is the feature that portfolio sites need — a single shortcode that shows all their games in a polished grid.

### 13. Analytics Dashboard

Track per-game:
- Total plays (Click to Play events)
- Average session duration
- Completion rate (if the game sends a completion event via the BanzaiPlay JS API)
- Device breakdown (desktop vs mobile)

Lightweight — stores data in a custom WordPress table, no external analytics service needed.

### 14. Data Bridge — WordPress to Game

Pass WordPress data into the game:
- Current user info (ID, name, role)
- Custom data key-value pairs set in the admin
- WordPress REST API base URL and nonce for authenticated requests
- Exposed as a global JS object: `window.BanzaiPlay.games['my-game'] = { userId: 1, ... }`

Enables: leaderboards that save to WordPress, personalized game experiences, authenticated game saves, LMS integration where game completion triggers WordPress actions.

### 15. Game-to-WordPress Communication

A lightweight JS API the game can call to communicate back:
```javascript
// From inside the game's code:
window.parent.BanzaiPlay.emit('my-game', 'score', { points: 1500 });
window.parent.BanzaiPlay.emit('my-game', 'complete', { time: 120 });
```

The plugin listens for these events and can:
- Update the analytics dashboard
- Trigger WordPress hooks (so other plugins can react to game events)
- Display a score/completion overlay after the game ends

### 16. Multiple Instances and Lazy Loading

- Support multiple games on one page with lazy loading — game builds don't download until "Click to Play" is pressed
- Priority loading — if multiple games are on one page, only load one at a time to avoid memory issues
- Unload/dispose a game when the user scrolls away or clicks to play a different one

## Out of Scope for V1

- Server-side game compilation or building
- Multiplayer server infrastructure
- Game development tools or an editor
- Mobile app wrapping (Cordova/Capacitor)
- DRM or game file protection (builds are inherently accessible in the browser)
- A/B testing different game builds
- Integration with itch.io API or Steam
- VR/XR game embedding (WebXR is too niche for V1)
- Elementor-specific widget (shortcode + Gutenberg block covers all page builders)

## Technical Notes

### Plugin Structure

```
banzaiplay/
├── banzaiplay.php                         # Main plugin file
├── readme.txt                              # WordPress.org readme
├── assets/
│   ├── js/
│   │   ├── admin.js                       # Admin page (upload, game management)
│   │   ├── block.js                       # Gutenberg block
│   │   ├── frontend.js                    # Game container, loading, input, fullscreen
│   │   └── engines/
│   │       ├── unity.js                   # Unity-specific initialization and progress
│   │       ├── godot.js                   # Godot-specific initialization
│   │       ├── phaser.js                  # Phaser detection and initialization
│   │       ├── construct.js               # Construct 3 initialization
│   │       └── generic.js                 # Fallback iframe embedding
│   └── css/
│       ├── admin.css
│       ├── frontend.css                   # Game container, loading screen, controls
│       └── block-editor.css
├── includes/
│   ├── class-banzaiplay-plugin.php
│   ├── class-banzaiplay-game-manager.php  # CRUD for games
│   ├── class-banzaiplay-engine-detector.php # Engine auto-detection logic
│   ├── class-banzaiplay-shortcode.php
│   ├── class-banzaiplay-block.php
│   └── class-banzaiplay-uploader.php      # Zip upload, extraction, file management
└── templates/
    └── admin-page.php
```

### Prefix

All functions, classes, constants, options, and hooks use the `banzaiplay_` prefix. Consistent with BanzaiStyle (`banzaistyle_`) and BanzaiEmbed (`banzaiembed_`).

### File Storage

Games stored at: `wp-content/uploads/banzaiplay/{game-slug}/`

Game metadata stored as WordPress option: `banzaiplay_games` (serialized array of game configs including name, slug, engine, entry files, dimensions, upload date).

### File Size Considerations

Unity WebGL builds can be 50MB+. WordPress's default upload limit is often 2MB. The plugin should:
- Check and display the current upload limit on the admin page
- Provide instructions for increasing it (php.ini `upload_max_filesize` and `post_max_size`)
- Consider chunked upload for large files in a future version
- For V1, clearly document the upload limit situation and how to increase it

### Engine-Specific Frontend Scripts

Only load the engine-specific JS file for the engine that game uses. Don't load unity.js for a Phaser game. Detect from the stored game config and enqueue only what's needed.

### Security

- Validate zip contents — reject uploads containing PHP files, .htaccess, or other server-side scripts
- Serve game files with appropriate MIME types
- Game files should not be executable server-side — only static HTML/JS/CSS/WASM/data
- The sandboxed iframe approach for generic HTML5 games provides natural isolation

### WordPress Hooks

- `admin_menu` — register admin page
- `admin_enqueue_scripts` — admin JS/CSS
- `wp_enqueue_scripts` — conditional frontend JS/CSS only on pages with the shortcode
- `init` — register shortcode and Gutenberg block
- `wp_ajax_banzaiplay_upload_game` — handle zip upload
- `wp_ajax_banzaiplay_delete_game` — handle deletion

### Compatibility

- **WordPress:** 6.0+
- **PHP:** 7.4+
- **Browsers:** Chrome, Firefox, Edge, Safari (WebGL 2.0 support required)
- **Engines tested against:** Unity 2020+, Unity 6+, Phaser 3, Godot 4.x HTML5 export, Construct 3, PlayCanvas, PixiJS, and any HTML5 game with an index.html entry point

## Development Priorities

1. Admin page with game list and add-new form
2. Zip upload, extraction, and file storage
3. Engine detection (start with Unity + Generic HTML5, add others incrementally)
4. Frontend container with Click to Play overlay
5. Unity WebGL initialization with loading progress bar
6. Input focus management (critical — test thoroughly)
7. Responsive canvas sizing with aspect ratio preservation
8. Fullscreen toggle
9. Generic HTML5/iframe fallback for non-Unity games
10. Phaser engine support
11. Godot engine support
12. Shortcode with all attributes
13. Gutenberg block
14. Game update/replace flow
15. Construct 3 and PlayCanvas support
16. ~~Pro feature gating (Freemius, same as BanzaiStyle)~~ — removed; everything is free
17. Custom loading screens
18. Game portfolio gallery
19. Analytics
20. Data bridge and game-to-WP communication

## Implementation Decisions (read before changing code)

All the priorities are built: the core (1–15) and the advanced features (17–20, spec sections 11–16); 16, licensing, was removed. Where the code departs from the spec above, it does so deliberately — [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) has the reasoning. In short:

- **Naming** follows the sibling plugins' real convention, not `banzaiplay_` everywhere: namespace `BanzaiPlay\`, functions `bzpl_`, constants `BZPL_`, hooks `bzpl/…`, admin classes `bzpl-`. Class files are `includes/class-{name}.php` via the autoloader. The option (`banzaiplay_games`), uploads folder, text domain and the front-end CSS classes (`banzaiplay-container` etc., which site owners style) keep `banzaiplay`.
- **Every game plays in a same-origin iframe**, Unity included — not inline on the page. The frame does keyboard isolation by itself: keys only reach the focused document, so Unity's `captureAllKeyboardInput` needs no overriding. It also isolates CSS and globals and frees everything on unload. The container's `<canvas>` from the spec is an empty `.banzaiplay-stage` that receives the frame on Play. Nothing downloads before Play.
- **`Frame` serves the frame document** at `/?banzaiplay_frame=slug&v=build.version` on `do_parse_request`. Mode `unity`/`godot`: a document BanzaiPlay writes, with `engines/unity.js` (createUnityInstance / legacy UnityLoader) or `engines/godot.js` (init + preloadFile + start, so the pack is cache-busted) providing real progress. Mode `html` (every other engine): the build's own page with `<base href>` and the bridge (`assets/js/frame.js`) injected first in `<head>`, plus `engines/phaser.js` / `playcanvas.js` hooks for real progress. Godot ≤ 3.2 plays as `html`.
- **The bridge** posts `hello/progress/ready/error/exit/focus/blur/release` to the parent (same origin; `frontend.js` matches by `event.source`). It catches the release key (Esc, Shift+Esc or none, per game) in the capture phase before any game script, resumes suspended AudioContexts and media, and falls back when `.wasm` has the wrong MIME type. Tab is never taken from the game: Esc is the documented way out.
- **No `sandbox` attribute**: games need `allow-same-origin` + `allow-scripts` (storage, module scripts, fetches), and with both a sandbox is no boundary. The boundary is `manage_options` + `unfiltered_html`.
- **Stable folder per game** (`uploads/banzaiplay/{slug}/`, as specced — not BanzaiEmbed's folder per upload), because Unity/Godot saves are keyed to the URL. Uploads unpack into `_staging/` and are swapped in atomically. Cache-busting is `?v={build}` on everything BanzaiPlay requests; module scripts never get a query.
- **Compressed Unity builds** (.gz/.br) are served through `/?banzaiplay_file=…` with `Content-Encoding`, because a plugin can't safely configure the server. Brotli needs HTTPS; `.unityweb` (Decompression Fallback) is served statically.
- **Root-based builds** (Vite `base: '/'`) are relocated on upload by `Path_Rewriter` (HTML included), with runtime root paths caught by `Asset_Redirect` — both ported from BanzaiEmbed. The real Dropout build in `../dropoutBuild.zip` (Vite + Phaser, base `/`) plays with no failed request.
- **Forms post to admin-post.php**, not the spec's `wp_ajax_` actions. admin.js sends uploads by XHR for a progress bar with `ajax=1`, and `Admin::redirect()` answers that with JSON so queued notices survive. Uploads stream entries to disk (no whole-file memory).
- **Start is a per-game setting** (`start`: `click`, the default, or `load`), not only the spec's shortcode `autoplay`. The shortcode's `autoplay` now defaults to empty = "use the game's setting", and `true`/`false` override it on that page. The block's boolean `autoplay` became a `start` attribute (`""` = the game's setting, `click`, `load`) — changed before the first release, so no saved blocks used it. The edit screen's preview always waits for Play, and a game marked "best on desktop" never starts by itself on a touch device. Autoplay never moves focus into the game.
- **Width/height** are both-or-neither on the game; shortcode/block may give one and the other follows the ratio. Fit `scale` (fixed-size canvases) renders the frame at native size and transforms it.
- **Uninstall cleanup** is `bzpl_uninstall()`, registered with `register_uninstall_hook()` on activation.
- **Feature modules** (`Settings`, `Player_Extras`, `Branding`, `Gallery`, `Plays`, `Analytics`, `Data_Bridge`, `Game_Events`) are registered in `Plugin::run()` and attach through hooks the core fires (`bzpl/player_config`, `bzpl/edit_cards`, `bzpl/save_game`, `bzpl/admin_menu`… — ARCHITECTURE.md → Feature modules lists them). Deliberate choices beyond the spec: a site-wide **Settings** screen (default logo/colour, the several-games behaviour, statistics on/off and retention); the loading screen's **cover image** doubles as the gallery picture; the **game API** (`window.BanzaiPlay` with `data`, `user()`, `emit()`) lives *inside the frame* and is always defined by the bridge, so games never throw — the spec's `window.parent.BanzaiPlay.emit(slug, …)` and `BanzaiPlay.games[slug]` also work; per-visitor data is fetched (`admin-ajax.php?action=bzpl_user`), never put in the cacheable page; play statistics are anonymous (no IP/user/cookie), sent to `admin-ajax.php?action=bzpl_play` with a per-play token, and every game event fires `bzpl/game_event` in PHP. Spec #16: lazy loading is built into the player; `player-extras.js` adds loading autoplay games one at a time (always), and optional "one game at a time" and "close games scrolled out of view".
- **Testing**: `npx @wordpress/env start` (port 8892) → `tests/make-fixtures.php` (in the cli container) → `bash tests/e2e.sh` → `node tests/browser.mjs` (plays every game on `/banzaiplay-tests/`), `tests/screens.mjs`, `tests/upload.mjs`. The Unity/Godot fixtures are stand-ins with the real APIs; `bash tests/fetch-real.sh` downloads real third-party exports (Unity plain + gzip, Godot 4, Godot 3, Godot 3 threaded) into gitignored `tests/real/` — never commit them. Upload with `bash tests/e2e.sh tests/real/*.zip`, put them on a page, and run `node tests/browser.mjs <page-url>`. Results are in ARCHITECTURE.md → Testing. Feature modules: `node tests/features.mjs` (it runs tests/features-setup.php through `docker exec`). Never `wp plugin delete` on a test site you want to keep — that runs uninstall, which deletes every game.

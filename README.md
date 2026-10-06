# BanzaiPlay — Game Embed for WordPress

A WordPress plugin for embedding HTML5 and WebGL games — Unity, Godot, Phaser, Construct 3, PlayCanvas, PixiJS or any HTML5 build. Upload the web export as a zip; embed it with `[banzai-play game="my-game"]` or the **BanzaiPlay Game** block. BanzaiPlay detects the engine and adds the Click to Play screen, a real loading bar, keyboard focus handling, responsive sizing and fullscreen. Nothing is compiled on the server.

BanzaiPlay is free and open source under the GPL. Every feature is included — there is no paid tier and no licence key.

## Requirements

- WordPress 6.0+
- PHP 7.4+ (with the Zip extension for large builds)

## Install

1. Download `banzaiplay-{version}.zip` from the [latest release](https://github.com/JoshRobs/BanzaiPlay/releases/latest).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip and activate it.
3. Go to **BanzaiPlay → Add New**, name your game and upload a zip of your web build.
4. Copy the shortcode into any page, or add the **BanzaiPlay Game** block.

BanzaiPlay is not listed on WordPress.org, so WordPress won't offer updates for it. To update, download the new release and upload it the same way; WordPress offers to replace the installed version, and your games are kept.

## Features

- **Engine detected for you.** Unity (2019 and later), Godot (3.3 and later, and 4.x), Construct 3, PlayCanvas, Phaser and PixiJS are recognised from their files; anything else with an `index.html` plays as an HTML5 game. You can override the detection.
- **A real loading bar.** Unity and Godot builds report their engine's own progress events, so a 50 MB build shows exactly how far along it is.
- **Click to Play, or start with the page.** By default nothing downloads until a visitor presses Play, and that click is what browsers need to allow sound.
- **A keyboard that belongs to the game only while you play.** Arrows, Space and even Tab go to the game while it has focus, and none of them scroll the page. Click outside or press Esc to give the keyboard back.
- **Sized right everywhere.** The player keeps the game's aspect ratio, shrinks to fit phones, and never stretches past native resolution. Fullscreen is one button.
- **Engine quirks handled.** Compressed Unity builds are served with the headers Unity needs, WebAssembly loads even with the wrong MIME type, and builds made for the site root are relocated on upload.
- **Isolated from your theme.** Every game plays in its own same-origin frame.
- **Your loading screen.** Cover art behind the Play and loading screens, your logo, and your colour on the Play button and progress bar, set for every game or per game.
- **A game portfolio.** `[banzai-play-gallery]` shows your games in a filterable grid and plays each one in a lightbox.
- **Play statistics.** Plays, average time played, completion rate and phone versus desktop, per game and per day, stored anonymously on your own site.
- **WordPress to game.** Hand your game values you set in the admin, the post it is on, and the logged-in player (name, roles and a REST API nonce).
- **Game to WordPress.** `BanzaiPlay.emit('complete', { score: 1500 })` shows a results screen, counts towards statistics, and fires a WordPress action other plugins can react to.
- **Pages full of games.** Games that start with the page load one after another; optionally only one plays at a time, or a game closes once scrolled out of view.

## Preparing a game

Export it for the web as you would for itch.io, and zip the folder:

| Engine | Export |
| --- | --- |
| Unity | WebGL build: `index.html`, `Build/`, `TemplateData/`. Compression Disabled, or gzip/Brotli (with Decompression Fallback for the fastest loading). Multithreading off. |
| Godot | The Web preset. With Godot 4.3+, Thread Support off. |
| Construct 3 | Web (HTML5) export. |
| PlayCanvas | The project zip from the Publish screen. |
| Phaser, PixiJS, others | The production build (`dist/` or `build/`). With Vite, `base: './'` — builds made for `/` are relocated on upload, but a relative base is the most robust. |

Every game plays in a same-origin iframe, so it needs no changes. A game can talk to WordPress through `window.BanzaiPlay` inside its frame — `BanzaiPlay.emit('complete', { score: 1500 })`, `BanzaiPlay.data`, `await BanzaiPlay.user()` — which is always defined, so calling it never throws.

## Development

The product spec is [CLAUDE.md](CLAUDE.md). How it is built, and where and why it departs from the spec, is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

The repository root is the plugin. With Docker running:

```bash
npx @wordpress/env start          # http://localhost:8892  (admin / password)
npx @wordpress/env stop
```

Port 8892 keeps it clear of BanzaiStyle's wp-env on 8888 and BanzaiEmbed's on 8890.

### Tests

End-to-end, against the wp-env site, through the real admin forms and a headless Chrome or Edge (Node 22+, driven over the DevTools protocol — nothing to install):

```bash
npx @wordpress/env run cli php wp-content/plugins/BanzaiPlay/tests/make-fixtures.php
bash tests/e2e.sh                 # uploads every fixture as a game
node tests/browser.mjs            # plays each one on /banzaiplay-tests/
node tests/start.mjs              # the per-game Start setting
node tests/screens.mjs            # admin screenshots (BLOCK_POST=<id> adds the block editor)
node tests/features.mjs           # loading screen, gallery, Data Bridge, events, statistics
```

The fixtures are stand-ins with the engines' real file layouts and loader APIs. `bash tests/fetch-real.sh` downloads real third-party Unity and Godot exports into `tests/real/` (gitignored — never commit them); upload them with `bash tests/e2e.sh tests/real/*.zip`.

Screenshots and scratch files go to `tests/output/` (gitignored).

### Releasing

```powershell
pwsh tools/build.ps1   # → dist/banzaiplay-{version}.zip
```

Bump `Version:` in [banzaiplay.php](banzaiplay.php) (and `BZPL_VERSION`, and `Stable tag` in [readme.txt](readme.txt)), build, then attach the zip to a GitHub release tagged `v{version}`. The zip has a single top-level `banzaiplay/` folder, which is what WordPress's plugin uploader expects.

## License

GPLv2 or later. See [license.txt](license.txt).

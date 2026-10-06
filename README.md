# BanzaiPlay — Game Embed for WordPress

A WordPress plugin for embedding HTML5 and WebGL games — Unity, Godot, Phaser, Construct 3, PlayCanvas, PixiJS or any HTML5 build. Upload the web export as a zip; embed it with `[banzai-play game="my-game"]` or the **BanzaiPlay Game** block. BanzaiPlay detects the engine and adds the Click to Play screen, a real loading bar, keyboard focus handling, responsive sizing and fullscreen. Nothing is compiled on the server.

The product spec is [CLAUDE.md](CLAUDE.md). How it is built, and where and why it departs from the spec, is in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md). The wp.org listing is [readme.txt](readme.txt).

## Requirements

- WordPress 6.0+
- PHP 7.4+ (with the Zip extension for large builds)

## Preparing a game

Export it for the web as you would for itch.io, and zip the folder:

| Engine | Export |
| --- | --- |
| Unity | WebGL build: `index.html`, `Build/`, `TemplateData/`. Compression Disabled, or gzip/Brotli (with Decompression Fallback for the fastest loading). Multithreading off. |
| Godot | The Web preset. With Godot 4.3+, Thread Support off. |
| Construct 3 | Web (HTML5) export. |
| PlayCanvas | The project zip from the Publish screen. |
| Phaser, PixiJS, others | The production build (`dist/` or `build/`). With Vite, `base: './'` — builds made for `/` are relocated on upload, but a relative base is the most robust. |

Every game plays in a same-origin iframe, so it needs no changes. A game can talk to WordPress through `window.BanzaiPlay` inside its frame — `BanzaiPlay.emit('complete', { score: 1500 })`, `BanzaiPlay.data`, `await BanzaiPlay.user()` — which exists in every version, so calling it never throws; the data and what happens to events come with Pro.

## Development

The repository root is the plugin. With Docker running:

```bash
npx @wordpress/env start          # http://localhost:8892  (admin / password)
npx @wordpress/env stop
```

Port 8892 keeps it clear of BanzaiStyle's wp-env on 8888 and BanzaiEmbed's on 8890.

### Freemius licensing

Freemius' opt-in screen replaces BanzaiPlay's admin screens until someone opts in or skips. On a test site, skip it:

```bash
npx @wordpress/env run cli wp eval 'banzaiplay_fs()->skip_connection( null, true );'
```

Two non-secret constants in [.wp-env.json](.wp-env.json) put the install into Freemius' developer mode: `WP_FS__DEV_MODE` and `WP_FS__SKIP_EMAIL_ACTIVATION`. The plugin's **secret key** must not be committed. It goes in `.wp-env.override.json`, which is gitignored and never packaged by `tools/build.ps1`:

```bash
cp .wp-env.override.json.example .wp-env.override.json
# paste the secret key from the Freemius dashboard, then
npx @wordpress/env start
```

The constant name embeds the slug, case-sensitively: `WP_FS__banzaiplay_SECRET_KEY`.

To try the licensed and unlicensed paths without a real licence, force the gate either way (wp-env drops it again on the next `start`):

```bash
npx @wordpress/env run cli wp config set BZPL_SIMULATE_PRO true --raw   # or false
npx @wordpress/env run cli wp config delete BZPL_SIMULATE_PRO
```

Pro code lives in `*__premium_only.*` files, loaded from one `is__premium_only()` block in `Plugin::run()`. Freemius strips both from the free build, so free code must never reference a Pro class — see [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md#pro).

### Tests

End-to-end, against the wp-env site, through the real admin forms and a headless Chrome or Edge (Node 22+, driven over the DevTools protocol — nothing to install):

```bash
npx @wordpress/env run cli php wp-content/plugins/BanzaiPlay/tests/make-fixtures.php
bash tests/e2e.sh                 # uploads every fixture as a game
node tests/browser.mjs            # plays each one on /banzaiplay-tests/
node tests/start.mjs              # the per-game Start setting
node tests/screens.mjs            # admin screenshots (BLOCK_POST=<id> adds the block editor)
node tests/pro.mjs                # every Pro feature; needs BZPL_SIMULATE_PRO true
```

The fixtures are stand-ins with the engines' real file layouts and loader APIs. `bash tests/fetch-real.sh` downloads real third-party Unity and Godot exports into `tests/real/` (gitignored — never commit them); upload them with `bash tests/e2e.sh tests/real/*.zip`.

To check the free build stands on its own, `pwsh tests/make-free.ps1` makes an approximation of it, and its header explains how to swap it in and run `tests/free.mjs`. Remove that copy by deleting its folder, never with `wp plugin delete`: deleting a plugin runs its uninstall, which would delete every game.

Screenshots and scratch files go to `tests/output/` (gitignored).

### Packaging

```powershell
pwsh tools/build.ps1   # → dist/banzaiplay-{version}.zip
```

This is the premium build. Upload it to Freemius, which generates the free build from it.

=== BanzaiPlay — Game Embed for WordPress ===
Contributors: joshuaroberts
Tags: game, unity, godot, phaser, html5
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Embed Unity, Godot, Phaser, Construct 3, PlayCanvas, PixiJS or any HTML5 game in a page or post — with a loading screen, keyboard focus handling, responsive sizing and fullscreen.

== Description ==

You exported your game for the web. Now it needs to live on your WordPress site — and "upload it over FTP and use an iframe" means a blank rectangle for thirty seconds, arrow keys that scroll the page instead of moving your character, a canvas that spills off the side of every phone, and a game that starts in silence.

BanzaiPlay takes the build you already have, as a zip, works out which engine made it, and gives you a shortcode and a block to put it anywhere.

BanzaiPlay is free and open source. Every feature below is included, with no licence key. It is distributed on GitHub: https://github.com/JoshRobs/BanzaiPlay

**What you get**

* **Upload a zip, get a shortcode.** `[banzai-play game="my-game"]` — or the BanzaiPlay Game block in the block editor.
* **Engine detected for you.** Unity (2019 and later), Godot (3.3 and later, and 4.x), Construct 3, PlayCanvas, Phaser and PixiJS are recognised from their files, and anything else with an `index.html` plays as an HTML5 game. Detection got it wrong? Pick the engine yourself.
* **A real loading bar.** Unity and Godot builds are started by BanzaiPlay with their engine's own progress events, so a 50 MB build shows exactly how far along it is. Other engines get a loading screen that follows the download.
* **Click to Play, or start with the page.** By default nothing downloads until a visitor presses Play, so a page can hold several games, and the click is what browsers need to allow the game's sound. Set a game to start as soon as the page loads instead — for a single showcase game, say — and change that per page with the shortcode or block.
* **A keyboard that belongs to the game only while you play.** Every key goes to the game while it has focus — arrows, Space, even Tab — and none of them scroll the page. Click outside the game, or press Esc, and the page has the keyboard back. A subtle glow shows when the game is listening.
* **Sized right everywhere.** The player keeps the game's aspect ratio, shrinks to fit phones without sideways scrolling, and never stretches past the game's native resolution. Games with a fixed-size canvas can be scaled to fit instead.
* **Fullscreen.** One button, letterboxed to the game's aspect ratio, and phones turn to the game's orientation where the browser allows. Hidden where the browser can't do fullscreen.
* **Mobile notice.** Mark a game as best on desktop, and touch-screen visitors are told so before they choose to play.
* **Engine quirks handled.** Compressed Unity builds are served with the headers Unity needs, with no server configuration. WebAssembly loads even when the server sends the wrong file type. Builds made for the site root (Vite's default) have their paths fixed on upload.
* **Painless updates.** Upload a new build and it replaces the old one in one step; browsers pick up the new files, and players keep their saved progress.
* **Isolated from your theme.** Every game plays in its own frame, so its styles and scripts can't touch your site, and your theme can't break the game.

**Make it yours, and connect it to WordPress**

* **Your loading screen.** Your game's cover art behind the Play and loading screens, your logo above its title, your colour on the Play button and progress bar — set once for every game, or per game.
* **A game portfolio.** `[banzai-play-gallery]` shows your games in a grid with their covers, descriptions and tags, filterable by tag or engine, and plays each one in a lightbox — or links to its page.
* **Play statistics.** Plays, average time played, completion rate and phone versus desktop, per game and per day, on your own site. Anonymous: no IP addresses, cookies or user IDs, and no outside service.
* **WordPress to game.** Hand your game values you set in the admin, the post it is on, and the logged-in player — name, roles, and a REST API nonce for saving progress or scores to WordPress.
* **Game to WordPress.** `BanzaiPlay.emit( 'complete', { score: 1500 } )` from your game shows a results screen, counts towards statistics, and fires a WordPress action other plugins can react to — mark a lesson done, award a badge.
* **Pages full of games.** Games that start with the page load one after another, and optionally only one plays at a time, or a game closes once it is scrolled out of view, freeing its memory.

== Installation ==

1. Download `banzaiplay-{version}.zip` from https://github.com/JoshRobs/BanzaiPlay/releases/latest
2. Go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, and activate BanzaiPlay.
3. Go to **BanzaiPlay → Add New**, name your game and upload a zip of your web build.
4. Copy the shortcode into any page, or add the **BanzaiPlay Game** block.

To update, download the new release and upload it the same way. WordPress offers to replace the installed version, and your games are kept.

== Frequently Asked Questions ==

= Is it really free? =

Yes. Every feature is included, with no licence key, no account and no limits. It's GPL software, published on GitHub.

= Will WordPress update it automatically? =

No. BanzaiPlay isn't listed on WordPress.org, so WordPress doesn't check it for updates. Watch the GitHub repository's releases, and upload a new zip when you want to update.

= My build is bigger than the upload limit. =

WordPress uses PHP's upload limit, which is often 2–64 MB. The upload box shows the current limit, and **Uploading large builds** under it explains how to raise it — `upload_max_filesize` and `post_max_size` in php.ini or `.user.ini`, and `client_max_body_size` on nginx.

= How do I export my game for BanzaiPlay? =

Export it for the web as you would for itch.io, and zip the folder:

* **Unity:** a WebGL build — the folder with `index.html`, `Build/` and `TemplateData/`. Compression can be Disabled, gzip or Brotli; for the fastest loading turn on Decompression Fallback when compressing. Multithreading must be off.
* **Godot:** the Web export preset. With Godot 4.3 or later, turn Thread Support off.
* **Construct 3:** export as Web (HTML5).
* **PlayCanvas:** download the project as a zip from the Publish screen.
* **Phaser, PixiJS and others:** your production build (`dist/` or `build/`). With Vite, set `base: './'`.

= Who can upload games? =

Administrators who also have the `unfiltered_html` capability. A game is JavaScript that runs for every visitor, so on multisite only network administrators can upload one.

= What file types can a build contain? =

Static game files only — HTML, JavaScript, CSS, JSON, images, fonts, audio, video, 3D models, WebAssembly, and engine formats such as Unity's `.data` and `.unityweb`, Godot's `.pck` and Defold's archives. Anything else, including any PHP file, is left out of the upload and listed so you can see what was skipped.

= My Godot 4 game says it needs cross-origin isolation. =

Godot 4.0 to 4.2, and 4.3+ with Thread Support on, need the page to be "cross-origin isolated", which a WordPress page can't be without breaking other embeds such as YouTube videos. Export with Godot 4.3 or later with Thread Support off.

= The game uses Esc. How do players get out? =

Set the game's **Giving the keyboard back to the page** option to Shift+Esc, or to clicking outside only.

= Can I put more than one game on a page? =

Yes. Each one waits for its own Play button — unless it is set to start with the page, in which case every such game downloads as the page loads, so keep that to one game per page.

= Can a game start without the Play button? =

Yes: set its **Start** to "As soon as the page loads". On one page only, use `[banzai-play game="my-game" autoplay="true"]`, or the block's Start setting; `autoplay="false"` makes a game that normally starts with the page wait for Play there. Browsers keep the sound off until the visitor clicks the game, and the game only takes the keyboard once clicked.

= Can my game talk to WordPress? =

Every game gets `window.BanzaiPlay` inside its frame — from a Unity .jslib, Godot's JavaScriptBridge or plain JavaScript:

* `BanzaiPlay.emit( 'score', { score: 1500 } )` and `BanzaiPlay.emit( 'complete', { score: 1500, time: 120 } )` — or any event name of your own.
* `BanzaiPlay.data` — values set on the game's Data Bridge.
* `await BanzaiPlay.user()` — the logged-in player's details, as enabled for the game.

The API is always defined, so a game that uses it never breaks. Events reach a results screen, your statistics, the page (`banzaiplay:event`) and PHP (`do_action( 'bzpl/game_event', $name, $data, $context )`). Events come from the visitor's browser, so anyone can send one: don't give anything of value away on an event alone.

= Does it show the game in the block editor? =

The editor shows a card with the game's name, engine and size, the shape of the player. The game runs on the published page — and in the preview on the game's settings screen.

== External services ==

BanzaiPlay loads nothing from other sites on your pages: your game's files are served from your own `wp-content/uploads` folder, and play statistics are stored in your own database. Any services your game calls are up to your game.

The plugin collects no usage data and does not contact any service of its own.

== Changelog ==

= 0.1.0 =
* In development.

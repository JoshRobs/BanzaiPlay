<?php
/**
 * Builds the upload fixtures in tests/output/.
 *
 * Runs inside the wp-env CLI container, which has ZipArchive and zlib:
 *
 *     npx @wordpress/env run cli php wp-content/plugins/BanzaiPlay/tests/make-fixtures.php
 *
 * Every fixture is synthetic. The Unity and Godot ones are stand-ins with the
 * real file layout and the real loader APIs (createUnityInstance, Engine with
 * init/preloadFile/start), so they exercise detection, the documents Frame
 * writes, the compressed-file endpoint and the engine scripts — not Unity or
 * Godot themselves. Test real exports before a release.
 *
 * Each fake game draws on its canvas and records what it saw in
 * window.fixture (keys pressed, whether its files arrived intact), which the
 * browser test reads.
 *
 * @package BanzaiPlay
 */

$root = dirname( __DIR__ );
$out  = $root . '/tests/output';

if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}

/**
 * Zip literal name => contents pairs.
 *
 * @param string $zip_path Destination.
 * @param array  $files    Entry name => contents.
 */
function bzpl_zip( $zip_path, array $files ) {
	$zip = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

	foreach ( $files as $name => $contents ) {
		$zip->addFromString( $name, $contents );
	}

	$zip->close();
}

// Shared by every fake: counts keys, paints, and exposes what it saw.
$game_js = <<<'JS'
window.fixture = window.fixture || { keys: [], ready: false, audio: null };
function fixtureStart(canvas, label) {
	var ctx = canvas.getContext('2d');
	var x = 40;
	function paint() {
		canvas.width = canvas.clientWidth || canvas.width;
		canvas.height = canvas.clientHeight || canvas.height;
		ctx.fillStyle = '#1d2a3a'; ctx.fillRect(0, 0, canvas.width, canvas.height);
		ctx.fillStyle = '#1af0a4'; ctx.fillRect(x, canvas.height / 2 - 20, 40, 40);
		ctx.fillStyle = '#fff'; ctx.font = '20px sans-serif';
		ctx.fillText(label + ' · keys: ' + window.fixture.keys.length + ' · audio: ' + (window.fixture.audio && window.fixture.audio.state), 16, 32);
	}
	window.addEventListener('keydown', function (e) {
		window.fixture.keys.push(e.key);
		if (e.key === 'ArrowRight') x += 10;
		if (e.key === 'ArrowLeft') x -= 10;
		// Like Unity: swallow everything, so the page would never see it.
		e.preventDefault();
		paint();
	});
	window.addEventListener('resize', paint);
	try { window.fixture.audio = new (window.AudioContext || window.webkitAudioContext)(); window.fixture.audio.onstatechange = paint; } catch (e) {}
	window.fixture.ready = true;
	paint();
}
JS;

// A plain HTML5 game, its canvas a fixed 800 × 450.
bzpl_zip(
	$out . '/generic-canvas.zip',
	array(
		'my-game/index.html' => '<!doctype html><html><head><meta charset="utf-8"><title>Generic</title><style>html,body{margin:0;height:100%;background:#000}canvas{width:100%;height:100%;display:block}</style></head><body><canvas id="c" width="800" height="450"></canvas><script src="js/game.js"></script><script>fixtureStart(document.getElementById("c"), "generic");</script></body></html>',
		'my-game/js/game.js' => $game_js,
		'my-game/img/sprite.png' => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ),
	)
);

// A build compiled for the site root (Vite's default base: '/'), with a
// module entry, a lazily imported chunk and runtime-built root paths.
bzpl_zip(
	$out . '/rooted.zip',
	array(
		'index.html'            => '<!doctype html><html><head><meta charset="utf-8"><link rel="icon" href="/favicon.svg"><script type="module" crossorigin src="/assets/index-abc123.js"></script><link rel="stylesheet" href="/assets/index-abc123.css"></head><body><canvas id="c" width="640" height="480"></canvas></body></html>',
		'favicon.svg'           => '<svg xmlns="http://www.w3.org/2000/svg"/>',
		'assets/index-abc123.js' => $game_js . "\nconst il=`modulepreload`,al=function(e){return`/`+e};\nimport(al('assets/chunk-def456.js')).then(m=>{window.fixture.chunk=m.value;});\nfetch('/' + 'sounds/beep.txt').then(r=>r.text()).then(t=>{window.fixture.runtime=t.trim();});\nfixtureStart(document.getElementById('c'), 'rooted');",
		'assets/chunk-def456.js' => 'export const value = "chunk loaded";',
		'assets/index-abc123.css' => 'body{margin:0;background:url(/assets/bg.png)}canvas{width:100%;height:100vh;display:block}',
		'assets/bg.png'          => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ),
		'sounds/beep.txt'        => 'runtime path redirected',
	)
);

// Unity 2020+: Build/{name}.loader.js beside .framework.js, .data and .wasm.
$unity_loader = <<<'JS'
window.createUnityInstance = function (canvas, config, onProgress) {
	function text(url) { return fetch(url).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status + ' for ' + url); return r.text(); }); }
	return new Promise(function (resolve, reject) {
		var s = document.createElement('script');
		s.src = config.frameworkUrl;
		s.onload = function () {
			Promise.all([text(config.dataUrl), text(config.codeUrl)]).then(function (parts) {
				if (parts[0].trim() !== 'unity data' || parts[1].trim() !== 'unity code') {
					config.showBanner('Files arrived garbled: ' + parts.join(' | ').slice(0, 80), 'error');
					return reject(new Error('garbled'));
				}
				var p = 0;
				var timer = setInterval(function () {
					p += 0.2; onProgress(Math.min(1, p));
					if (p >= 1) {
						clearInterval(timer);
						window.fixture = window.fixture || { keys: [] };
						window.fixture.config = config;
						fixtureStart(canvas, 'unity ' + config.productName);
						resolve({ SetFullscreen: function () {}, Quit: function () { return Promise.resolve(); } });
					}
				}, 60);
			}, reject);
		};
		s.onerror = function () { reject(new Error('framework failed')); };
		document.body.appendChild(s);
	});
};
JS;

$unity_html = '<!DOCTYPE html><html><head><title>Unity WebGL Player | Fixture</title></head><body><div id="unity-container"><canvas id="unity-canvas" width=960 height=600 tabindex="-1"></canvas></div><script>var buildUrl = "Build"; var loaderUrl = buildUrl + "/Fixture.loader.js"; var config = { dataUrl: buildUrl + "/Fixture.data", frameworkUrl: buildUrl + "/Fixture.framework.js", codeUrl: buildUrl + "/Fixture.wasm", streamingAssetsUrl: "StreamingAssets", companyName: "Banzai Studio", productName: "Fixture Quest", productVersion: "2.1" };</script></body></html>';

foreach ( array( 'plain' => '', 'gzip' => '.gz' ) as $variant => $ext ) {
	$pack = static function ( $contents ) use ( $ext ) {
		return '' === $ext ? $contents : gzencode( $contents );
	};

	bzpl_zip(
		$out . '/unity-' . $variant . '.zip',
		array(
			'index.html'                           => $unity_html,
			'Build/Fixture.loader.js'              => $unity_loader,
			'Build/Fixture.framework.js' . $ext    => $pack( $game_js . "\nwindow.fakeFramework = true;" ),
			'Build/Fixture.data' . $ext            => $pack( 'unity data' ),
			'Build/Fixture.wasm' . $ext            => $pack( 'unity code' ),
			'TemplateData/style.css'               => 'body{}',
			'StreamingAssets/level1.json'          => '{"level":1}',
		)
	);
}

// Godot 4.3: index.html with GODOT_CONFIG, index.js defining Engine, .wasm, .pck.
$godot_engine = <<<'JS'
var Engine = (function () {
	function Engine(config) { this.config = config || {}; }
	Engine.getMissingFeatures = function () { return []; };
	Engine.prototype.init = function (basePath) {
		var cfg = this.config;
		return fetch(basePath + '.wasm').then(function (r) {
			if (!r.ok) throw new Error('wasm HTTP ' + r.status);
			if (cfg.onProgress) cfg.onProgress(cfg.fileSizes[basePath + '.wasm'], cfg.fileSizes[basePath + '.wasm'] * 2);
			return r.text();
		});
	};
	Engine.prototype.preloadFile = function (url, path) {
		var cfg = this;
		return fetch(url).then(function (r) { if (!r.ok) throw new Error('pck HTTP ' + r.status); return r.text(); }).then(function (t) { cfg.pack = { url: url, path: path, text: t.trim() }; });
	};
	Engine.prototype.start = function (override) {
		window.fixture = window.fixture || { keys: [] };
		window.fixture.godot = { args: override.args, pack: this.pack, canvasResizePolicy: this.config.canvasResizePolicy, baseURI: document.baseURI };
		fixtureStart(this.config.canvas, 'godot');
		return Promise.resolve();
	};
	return Engine;
})();
JS;

bzpl_zip(
	$out . '/godot.zip',
	array(
		'web/index.html' => '<!DOCTYPE html><html><head><title>Godot</title></head><body><canvas id="canvas"></canvas><script src="index.js"></script><script>const GODOT_CONFIG = {"args":["--fixture"],"canvasResizePolicy":2,"ensureCrossOriginIsolationHeaders":true,"executable":"index","experimentalVK":false,"fileSizes":{"index.pck":11,"index.wasm":11},"focusCanvas":true,"gdextensionLibs":[]};' . "\n" . 'const GODOT_THREADS_ENABLED = false; const engine = new Engine(GODOT_CONFIG); engine.startGame({ "onProgress": function (current, total) {} });</script></body></html>',
		'web/index.js'   => $game_js . "\n" . $godot_engine,
		'web/index.wasm' => 'godot wasm',
		'web/index.pck'  => 'godot pack',
		'web/index.audio.worklet.js' => '',
	)
);

// Phaser loaded as a global script, preloading in a scene.
$fake_phaser = <<<'JS'
(function (root) {
	function Emitter() { this.handlers = {}; }
	Emitter.prototype.on = function (n, f) { (this.handlers[n] = this.handlers[n] || []).push({ f: f }); return this; };
	Emitter.prototype.once = function (n, f) { (this.handlers[n] = this.handlers[n] || []).push({ f: f, once: true }); return this; };
	Emitter.prototype.emit = function (n, v) { var list = this.handlers[n] || []; this.handlers[n] = list.filter(function (h) { return !h.once; }); list.forEach(function (h) { h.f(v); }); };
	function LoaderPlugin() { Emitter.call(this); }
	LoaderPlugin.prototype = Object.create(Emitter.prototype);
	LoaderPlugin.prototype.start = function () {
		var me = this, p = 0;
		var t = setInterval(function () { p += 0.25; me.emit('progress', Math.min(1, p)); if (p >= 1) { clearInterval(t); me.emit('complete'); } }, 80);
	};
	root.Phaser = { VERSION: '3.80.0-fixture', Loader: { LoaderPlugin: LoaderPlugin }, Game: function (cfg) { var l = new LoaderPlugin(); l.once('complete', function () { fixtureStart(document.getElementById('c'), 'phaser'); window.fixture.phaserLoaded = true; }); l.start(); } };
})(this);
JS;

bzpl_zip(
	$out . '/phaser-global.zip',
	array(
		'index.html'           => '<!doctype html><html><head><meta charset="utf-8"><script src="lib/phaser.min.js"></script><script src="game.js"></script><style>html,body{margin:0;height:100%}canvas{width:100%;height:100%;display:block}</style></head><body><canvas id="c"></canvas><script>window.addEventListener("load", function () { new Phaser.Game({}); });</script></body></html>',
		'lib/phaser.min.js'    => $fake_phaser,
		'game.js'              => $game_js,
	)
);

// Everything a hostile or careless zip might hold.
bzpl_zip(
	$out . '/hostile.zip',
	array(
		'index.html'           => '<!doctype html><html><head></head><body><canvas id="c"></canvas><script src="game.js"></script><script>fixtureStart(document.getElementById("c"), "hostile");</script></body></html>',
		'game.js'              => $game_js,
		'../escape.js'         => 'alert(1)',
		'sub/../../escape2.js' => 'alert(1)',
		'shell.php'            => '<?php echo "pwned";',
		'img.php.png'          => '<?php echo "pwned";',
		'Shell.PHTML'          => '<?php echo "pwned";',
		'.htaccess'            => 'AddHandler application/x-httpd-php .png',
		'sub/.user.ini'        => 'auto_prepend_file=img.php.png',
		'web.config'           => '<configuration/>',
		'C:/win.js'            => 'alert(1)',
		'notes.md'             => '# notes',
		'__MACOSX/._game.js'   => 'junk',
		'archive/game.arcd0'   => 'defold archive part',
	)
);

// Nothing usable.
bzpl_zip( $out . '/nothing.zip', array( 'README.md' => '# hi', 'run.sh' => 'echo' ) );

foreach ( glob( $out . '/*.zip' ) as $file ) {
	echo basename( $file ), ' ', filesize( $file ), "\n";
}

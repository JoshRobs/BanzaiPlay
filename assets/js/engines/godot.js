/**
 * Starts a Godot web export (3.3 and later, 4.x) inside its BanzaiPlay frame.
 *
 * Does what the export's own page does with engine.startGame(), in its parts,
 * so the pack can be fetched with a cache-busting version yet stored in the
 * engine's filesystem under its plain name: Godot names the pack the same in
 * every export, and a browser holding the old one would start the old game.
 *
 * The document's <base> is the export folder, so the engine finds its .wasm,
 * audio worklets and GDExtension libraries by their relative names.
 *
 * Window.BanzaiPlayFrame.godot comes from Frame::print_godot().
 */
(function () {
	"use strict";

	var cfg = window.BanzaiPlayFrame || {};
	var api = cfg.api;
	var godot = cfg.godot;

	if (!api || !godot) {
		return;
	}

	function message(error) {
		return error && error.message ? error.message : String(error || "");
	}

	// Checked before the engine script loads: a threaded build's script
	// throws on SharedArrayBuffer as it is evaluated (Godot 3), and its
	// getMissingFeatures() may not ask about threads at all.
	if (godot.threads && (typeof SharedArrayBuffer === "undefined" || !window.crossOriginIsolated)) {
		api.error(cfg.i18n.threads);
		return;
	}

	api.progress(0);

	api.loadScript(godot.script)
		.then(function () {
			var Engine = window.Engine;

			if (typeof Engine !== "function") {
				throw new Error(cfg.i18n.failed);
			}

			if (typeof Engine.getMissingFeatures === "function") {
				var missing = Engine.getMissingFeatures({ threads: godot.threads });

				if (missing.length) {
					var threads = missing.some(function (feature) {
						return /SharedArrayBuffer|Cross Origin Isolation/i.test(feature);
					});

					throw new Error(threads ? cfg.i18n.threads : cfg.i18n.unsupported.replace("%s", missing.join(", ")));
				}
			}

			var exe = godot.executable;
			var sizes = {};

			// The preloader looks sizes up by the exact strings it is given.
			sizes[exe + ".wasm"] = godot.sizes.wasm;
			sizes[godot.packUrl] = godot.sizes.pck;

			var engine = new Engine(
				Object.assign({}, godot.config, {
					canvas: document.getElementById("canvas"),
					executable: exe,
					mainPack: godot.pack,
					fileSizes: sizes,
					onProgress: function (current, total) {
						// Downloads are most of the wait; compiling is the rest.
						api.progress(total > 0 ? (current / total) * 0.95 : -1);
					},
					onExit: function () {
						api.exit();
					},
				})
			);

			cfg.engine = engine;

			return Promise.all([engine.init(exe), engine.preloadFile(godot.packUrl, godot.pack)]).then(function () {
				return engine.start({ args: ["--main-pack", godot.pack].concat(godot.config.args || []) });
			});
		})
		.then(function () {
			api.ready();
		})
		.catch(function (error) {
			api.error(message(error));
		});
})();

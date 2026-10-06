/**
 * Starts a Unity WebGL build inside its BanzaiPlay frame.
 *
 * Unity 2020 and later: the build's .loader.js defines createUnityInstance(),
 * whose progress callback drives the page's loading bar. Unity 2019 and
 * earlier: UnityLoader.js and UnityLoader.instantiate(). Either way Unity's
 * own banners and alert() boxes are replaced by the page's error screen.
 *
 * Window.BanzaiPlayFrame.unity comes from Frame::print_unity().
 */
(function () {
	"use strict";

	var cfg = window.BanzaiPlayFrame || {};
	var api = cfg.api;
	var unity = cfg.unity;

	if (!api || !unity) {
		return;
	}

	function message(error) {
		return error && error.message ? error.message : String(error || "");
	}

	if (unity.brotli && window.location.protocol !== "https:") {
		api.error(cfg.i18n.brotliHttps);
		return;
	}

	api.progress(0);

	api.loadScript(unity.loader)
		.then(function () {
			return unity.legacy ? startLegacy() : startModern();
		})
		.catch(function (error) {
			api.error(message(error));
		});

	function startModern() {
		if (typeof window.createUnityInstance !== "function") {
			throw new Error(cfg.i18n.failed);
		}

		var canvas = document.getElementById("banzaiplay-canvas");
		var config = Object.assign({}, unity.config, {
			// Unity's default shows a banner over the canvas, or alert()s.
			showBanner: function (text, type) {
				if (type === "error") {
					api.error(text);
				} else if (window.console) {
					console.warn("[Unity] " + text);
				}
			},
		});

		return window.createUnityInstance(canvas, config, function (value) {
			api.progress(value);
		}).then(function (instance) {
			// Where Unity templates leave it, for games' own JS plugins.
			window.unityInstance = instance;
			cfg.instance = instance;
			api.ready();
		});
	}

	function startLegacy() {
		var Loader = window.UnityLoader;

		if (!Loader || typeof Loader.instantiate !== "function") {
			throw new Error(cfg.i18n.failed);
		}

		if (Loader.Error) {
			Loader.Error.handler = function (error) {
				api.error(message(error));
			};
		}

		var instance = Loader.instantiate("banzaiplay-unity", unity.json, {
			onProgress: function (gameInstance, value) {
				api.progress(value);

				// Downloads are done; the runtime starts straight after.
				if (value >= 1) {
					setTimeout(api.ready, 250);
				}
			},
			// The stock check alert()s on mobile and asks to continue.
			compatibilityCheck: function (gameInstance, onsuccess) {
				onsuccess();
			},
		});

		window.gameInstance = instance;
		cfg.instance = instance;
	}
})();

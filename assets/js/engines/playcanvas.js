/**
 * PlayCanvas hook: real loading progress from the application's preloader.
 *
 * A PlayCanvas export creates its application in __start__.js, configures
 * it from config.json and then preloads assets, firing preload:progress and,
 * once the scene is running, start. This waits for the application to exist
 * and reports both.
 */
(function () {
	"use strict";

	var api = window.BanzaiPlayFrame && window.BanzaiPlayFrame.api;

	if (!api) {
		return;
	}

	var done = api.hold();
	var tries = 0;

	function application() {
		var pc = window.pc;

		if (!pc) {
			return null;
		}

		if (pc.app) {
			return pc.app;
		}

		return pc.Application && typeof pc.Application.getApplication === "function" ? pc.Application.getApplication() : null;
	}

	var timer = setInterval(function () {
		var app = application();

		// Twenty seconds without an application: not the export we expected.
		if (!app && ++tries < 400) {
			return;
		}

		clearInterval(timer);

		if (!app || typeof app.on !== "function") {
			done();
			return;
		}

		app.on("preload:progress", function (value) {
			api.progress(value);
		});
		app.once("start", done);
	}, 50);
})();

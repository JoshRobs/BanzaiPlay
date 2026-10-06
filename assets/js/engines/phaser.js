/**
 * Phaser hook: real loading progress for games that load Phaser as a global
 * script (phaser.min.js and friends).
 *
 * Runs before the game's scripts. When Phaser defines window.Phaser, the
 * loader every scene uses is wrapped so the first loading run reports its
 * progress to the page and marks the game ready when it completes — Phaser
 * games preload their assets in a scene, after the page has long loaded.
 *
 * A game that bundles Phaser (webpack, Vite) never defines the global; the
 * hold is then released when the page loads and the bridge's estimate stands.
 */
(function () {
	"use strict";

	var api = window.BanzaiPlayFrame && window.BanzaiPlayFrame.api;

	if (!api) {
		return;
	}

	var done = api.hold();
	var hooked = false;
	var value;

	function hook(Phaser) {
		var Loader = Phaser && Phaser.Loader && Phaser.Loader.LoaderPlugin;

		if (hooked || !Loader || typeof Loader.prototype.start !== "function") {
			return;
		}

		hooked = true;

		var start = Loader.prototype.start;

		Loader.prototype.start = function () {
			if (!api.isReady()) {
				this.on("progress", function (progress) {
					api.progress(progress);
				});
				this.once("complete", function () {
					done();
				});
			}

			return start.apply(this, arguments);
		};
	}

	if (window.Phaser) {
		hook(window.Phaser);
	} else {
		try {
			Object.defineProperty(window, "Phaser", {
				configurable: true,
				enumerable: true,
				get: function () {
					return value;
				},
				set: function (Phaser) {
					value = Phaser;
					hook(Phaser);
				},
			});
		} catch (e) {
			// Something else owns the name; fall back to the page's load.
		}
	}

	window.addEventListener("load", function () {
		if (!hooked) {
			done();
		}
	});
})();

/**
 * [banzai-play-gallery], on the page. Not in the free build.
 *
 * Filter buttons show the games with a tag (or engine). Opening a game puts
 * a copy of its player, from the card's <template>, into the gallery's
 * <dialog> and starts it on the same click, so it can play with sound and
 * has the keyboard; closing the dialog removes the player, and the game
 * with it. Esc first gives the keyboard back to the page, as on any player,
 * and then closes the lightbox.
 */
(function () {
	"use strict";

	var l10n = window.banzaiPlayGalleryL10n || {};

	function setup(gallery) {
		if (gallery.banzaiPlayGallery) {
			return;
		}

		gallery.banzaiPlayGallery = true;

		var dialog = gallery.querySelector(".banzaiplay-lightbox");
		var title = dialog.querySelector(".banzaiplay-lightbox-title");
		var body = dialog.querySelector(".banzaiplay-lightbox-body");
		var none = gallery.querySelector(".banzaiplay-gallery-none");
		var status = gallery.querySelector(".banzaiplay-gallery-status");
		var kind = gallery.getAttribute("data-filter-kind");
		var items = Array.prototype.slice.call(gallery.querySelectorAll(".banzaiplay-gallery-item"));
		var opener = null;

		// Filters.
		gallery.querySelectorAll(".banzaiplay-gallery-filter").forEach(function (button) {
			button.addEventListener("click", function () {
				var token = button.getAttribute("data-filter");
				var shown = 0;

				gallery.querySelectorAll(".banzaiplay-gallery-filter").forEach(function (other) {
					other.setAttribute("aria-pressed", other === button ? "true" : "false");
				});

				items.forEach(function (item) {
					var values = kind === "engine" ? [item.getAttribute("data-engine")] : (item.getAttribute("data-tags") || "").split(" ");
					var match = !token || values.indexOf(token) !== -1;

					item.hidden = !match;
					shown += match ? 1 : 0;
				});

				none.hidden = shown > 0;

				if (status) {
					status.textContent = shown === 1 ? l10n.one || "1 game" : (l10n.count || "%d games").replace("%d", String(shown));
				}
			});
		});

		// The lightbox.
		function open(item, trigger) {
			var template = item.querySelector("template.banzaiplay-gallery-player");

			if (!template || typeof dialog.showModal !== "function") {
				return;
			}

			opener = trigger;
			title.textContent = item.querySelector(".banzaiplay-gallery-name").textContent.trim();
			body.replaceChildren(template.content.cloneNode(true));
			dialog.showModal();

			if (window.BanzaiPlay && typeof window.BanzaiPlay.init === "function") {
				window.BanzaiPlay.init(body);
			}

			var container = body.querySelector(".banzaiplay-container");

			if (container && container.banzaiPlay) {
				container.banzaiPlay.start(true);
			}
		}

		gallery.addEventListener("click", function (event) {
			var trigger = event.target.closest("[data-banzaiplay-open]");

			if (trigger && gallery.contains(trigger)) {
				open(trigger.closest(".banzaiplay-gallery-item"), trigger);
			}
		});

		dialog.querySelector(".banzaiplay-lightbox-close").addEventListener("click", function () {
			dialog.close();
		});

		// A click on the backdrop (the dialog itself, outside its content) closes it.
		dialog.addEventListener("click", function (event) {
			if (event.target === dialog) {
				dialog.close();
			}
		});

		dialog.addEventListener("close", function () {
			var container = body.querySelector(".banzaiplay-container");

			if (container && container.banzaiPlay) {
				container.banzaiPlay.unload();
			}

			body.replaceChildren();

			// The picture is mouse-only; the title button is the keyboard's way back.
			if (opener) {
				var item = opener.closest(".banzaiplay-gallery-item");
				var target = item && item.querySelector(".banzaiplay-gallery-name [data-banzaiplay-open]");

				(target || opener).focus({ preventScroll: true });
				opener = null;
			}
		});
	}

	function init(root) {
		(root || document).querySelectorAll(".banzaiplay-gallery").forEach(setup);
	}

	window.BanzaiPlay = window.BanzaiPlay || {};
	window.BanzaiPlay.initGallery = init;

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", function () {
			init();
		});
	} else {
		init();
	}
})();

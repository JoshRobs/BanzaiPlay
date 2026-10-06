/**
 * BanzaiPlay extras, on the edit and Settings screens.
 *
 * Image pickers (the media library), colour pickers (WordPress's), Data
 * Bridge rows, and the confirmation before play statistics are deleted.
 * Every field also works as a plain input without this script.
 */
(function ($) {
	"use strict";

	var __ = window.wp && wp.i18n ? wp.i18n.__ : function (text) {
		return text;
	};

	/**
	 * An image field: Choose opens the media library, Remove clears it.
	 * See Branding::media_field().
	 */
	function initMedia() {
		document.querySelectorAll("[data-bzpl-media]").forEach(function (field) {
			var input = field.querySelector(".bzpl-media-id");
			var img = field.querySelector(".bzpl-media-preview img");
			var empty = field.querySelector(".bzpl-media-empty");
			var choose = field.querySelector(".bzpl-media-choose");
			var remove = field.querySelector(".bzpl-media-remove");
			var frame = null;

			function set(id, url) {
				input.value = id ? String(id) : "";
				img.src = url || "";
				img.hidden = !url;
				empty.hidden = !!url;
				remove.hidden = !id;
				choose.textContent = id ? __("Replace", "banzaiplay") : __("Choose image", "banzaiplay");
				field.classList.toggle("has-image", !!id);
			}

			choose.addEventListener("click", function () {
				if (!window.wp || !wp.media) {
					return;
				}

				if (!frame) {
					frame = wp.media({
						title: field.getAttribute("data-title") || __("Choose image", "banzaiplay"),
						library: { type: "image" },
						button: { text: __("Use this image", "banzaiplay") },
						multiple: false,
					});

					frame.on("select", function () {
						var attachment = frame.state().get("selection").first().toJSON();
						var sizes = attachment.sizes || {};
						var preview = (sizes.medium || sizes.full || attachment).url;

						set(attachment.id, preview);
					});
				}

				frame.open();
			});

			remove.addEventListener("click", function () {
				set(0, "");
				choose.focus();
			});
		});
	}

	/** WordPress's colour picker on each colour field, empty allowed. */
	function initColors() {
		if (!$ || !$.fn.wpColorPicker) {
			return;
		}

		$(".bzpl-color").each(function () {
			if (!this.disabled) {
				$(this).wpColorPicker();
			}
		});
	}

	/**
	 * Data Bridge rows: add (cloning the <template>), remove, and show the
	 * value field only for sources that take one.
	 */
	function initBridge() {
		// Row indexes only need to be unique within one submit.
		var next = Date.now();

		document.querySelectorAll(".bzpl-bridge").forEach(function (card) {
			card.addEventListener("change", function (event) {
				if (!event.target.matches(".bzpl-bridge-source")) {
					return;
				}

				var select = event.target;
				var option = select.options[select.selectedIndex];
				var input = select.closest("tr").querySelector(".bzpl-bridge-value");
				var placeholder = option && option.getAttribute("data-placeholder");

				input.hidden = !placeholder;

				if (placeholder) {
					input.placeholder = placeholder;
				}
			});

			card.addEventListener("click", function (event) {
				var add = event.target.closest(".bzpl-bridge-add");
				var remove = event.target.closest(".bzpl-bridge-remove");

				if (add) {
					var template = card.querySelector(".bzpl-bridge-template");
					var rows = card.querySelector(".bzpl-bridge-rows");

					rows.insertAdjacentHTML("beforeend", template.innerHTML.replace(/__i__/g, String(next++)));
					rows.lastElementChild.querySelector("input").focus();
				} else if (remove) {
					var row = remove.closest("tr");
					var body = row.parentNode;

					// Keep one row to type into; clearing it saves as "no rows".
					if (body.children.length > 1) {
						body.removeChild(row);
					} else {
						row.querySelectorAll("input").forEach(function (input) {
							input.value = "";
						});
					}
				}
			});
		});
	}

	function initPurge() {
		var form = document.querySelector(".bzpl-purge");

		if (form) {
			form.addEventListener("submit", function (event) {
				// eslint-disable-next-line no-alert
				if (!window.confirm(form.getAttribute("data-confirm"))) {
					event.preventDefault();
				}
			});
		}
	}

	document.addEventListener("DOMContentLoaded", function () {
		initMedia();
		initColors();
		initBridge();
		initPurge();
	});
})(window.jQuery);

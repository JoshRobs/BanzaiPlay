/**
 * BanzaiPlay admin screens: slug generation, copy buttons, delete
 * confirmation, the upload drop zone (with upload progress) and the
 * active/inactive switches.
 *
 * Every form works without this file; it only makes them nicer.
 */
(function () {
	"use strict";

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	/** Mirror Game_Manager::sanitize_slug() closely enough to preview it. */
	function slugify(text) {
		return text
			.toLowerCase()
			.normalize("NFD")
			.replace(/[̀-ͯ]/g, "")
			.replace(/[^a-z0-9]+/g, "-")
			.replace(/^-+|-+$/g, "")
			.slice(0, 60);
	}

	function initSlug() {
		var name = document.getElementById("bzpl-name");
		var slug = document.getElementById("bzpl-slug");
		var preview = document.getElementById("bzpl-slug-preview");

		if (!name || !slug) {
			return;
		}

		// Follow the name until the user types a slug of their own.
		var touched = slug.value !== "";

		function showPreview() {
			if (preview) {
				preview.textContent = '[banzai-play game="' + (slugify(slug.value) || "my-game") + '"]';
			}
		}

		slug.addEventListener("input", function () {
			touched = slug.value !== "";
			showPreview();
		});

		name.addEventListener("input", function () {
			if (!touched) {
				slug.value = slugify(name.value);
				showPreview();
			}
		});
	}

	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		// Plain-http admin: the async clipboard API is unavailable.
		var area = document.createElement("textarea");
		area.value = text;
		area.setAttribute("readonly", "");
		area.style.position = "fixed";
		area.style.opacity = "0";
		document.body.appendChild(area);
		area.select();

		try {
			document.execCommand("copy");
		} finally {
			document.body.removeChild(area);
		}

		return Promise.resolve();
	}

	function initCopy() {
		document.addEventListener("click", function (event) {
			var button = event.target.closest(".bzpl-copy");

			if (!button) {
				return;
			}

			copy(button.getAttribute("data-copy")).then(function () {
				// Icon buttons swap their icon for a tick; text buttons their text.
				var icon = button.querySelector(".dashicons");
				var label = icon ? button.getAttribute("aria-label") : button.textContent;
				var done = __("Copied!", "banzaiplay");

				if (icon) {
					icon.classList.replace("dashicons-admin-page", "dashicons-yes");
					button.classList.add("is-copied");
					button.setAttribute("aria-label", done);
				} else {
					button.textContent = done;
				}

				setTimeout(function () {
					if (icon) {
						icon.classList.replace("dashicons-yes", "dashicons-admin-page");
						button.classList.remove("is-copied");
						button.setAttribute("aria-label", label);
					} else {
						button.textContent = label;
					}
				}, 1500);
			});
		});
	}

	function initDelete() {
		document.addEventListener("click", function (event) {
			var link = event.target.closest(".bzpl-delete");

			if (!link) {
				return;
			}

			var message = sprintf(
				/* translators: %s: game name. */
				__('Delete "%s" and all of its files? Pages that embed it will show nothing.', "banzaiplay"),
				link.getAttribute("data-name")
			);

			if (!window.confirm(message)) {
				event.preventDefault();
			}
		});
	}

	function formatSize(bytes) {
		var units = ["B", "KB", "MB", "GB"];
		var i = 0;

		while (bytes >= 1024 && i < units.length - 1) {
			bytes /= 1024;
			i++;
		}

		return (i ? bytes.toFixed(1) : String(bytes)) + " " + units[i];
	}

	function initDropzone() {
		var zone = document.querySelector(".bzpl-dropzone");

		if (!zone) {
			return;
		}

		var input = zone.querySelector("input[type=file]");
		var label = zone.querySelector(".bzpl-dropzone-file");

		function show() {
			var file = input.files && input.files[0];
			label.textContent = file ? file.name + " · " + formatSize(file.size) : "";
			zone.classList.toggle("has-file", !!file);
		}

		input.addEventListener("change", show);

		["dragenter", "dragover"].forEach(function (type) {
			zone.addEventListener(type, function (event) {
				event.preventDefault();
				zone.classList.add("is-dragging");
			});
		});

		["dragleave", "drop"].forEach(function (type) {
			zone.addEventListener(type, function () {
				zone.classList.remove("is-dragging");
			});
		});

		zone.addEventListener("drop", function (event) {
			event.preventDefault();

			if (event.dataTransfer && event.dataTransfer.files.length) {
				input.files = event.dataTransfer.files;
				show();
			}
		});
	}

	/**
	 * Game builds are big, and a plain form post shows nothing while a 50 MB
	 * zip goes up. With a file chosen, send the same form with XMLHttpRequest
	 * to show progress; the handler answers `ajax=1` with JSON naming the
	 * page it would have redirected to, and the notices it queued show there.
	 */
	function initUpload() {
		var form = document.querySelector(".bzpl-form");
		var input = form && form.querySelector("#bzpl-build");

		if (!input || !window.FormData || !window.XMLHttpRequest) {
			return;
		}

		var zone = input.closest(".bzpl-dropzone");
		var bar = zone.querySelector(".bzpl-upload-progress");
		var fill = zone.querySelector(".bzpl-upload-progress-fill");
		var sub = zone.querySelector(".bzpl-dropzone-sub");
		var max = parseInt(zone.getAttribute("data-bzpl-max"), 10) || 0;
		var busy = false;

		function setBusy(on) {
			busy = on;
			zone.classList.toggle("is-uploading", on);
			bar.hidden = !on;
			form.querySelectorAll("[type=submit]").forEach(function (button) {
				button.disabled = on;
			});
		}

		form.addEventListener("submit", function (event) {
			var file = input.files && input.files[0];

			if (!file) {
				return;
			}

			event.preventDefault();

			if (busy) {
				return;
			}

			if (max && file.size > max) {
				window.alert(
					sprintf(
						/* translators: 1: size of the chosen zip, 2: largest upload the server accepts. */
						__('This zip is %1$s, but this server accepts uploads of up to %2$s. See "Uploading large builds" below the upload box.', "banzaiplay"),
						formatSize(file.size),
						formatSize(max)
					)
				);
				return;
			}

			var data = new FormData(form);
			var xhr = new XMLHttpRequest();

			data.append("ajax", "1");
			xhr.open("POST", form.getAttribute("action"));
			xhr.responseType = "json";

			xhr.upload.addEventListener("progress", function (e) {
				if (e.lengthComputable) {
					var percent = Math.round((e.loaded / e.total) * 100);

					fill.style.width = percent + "%";
					/* translators: %d: percentage uploaded. */
					sub.textContent = sprintf(__("Uploading… %d%%", "banzaiplay"), percent);
				}
			});

			xhr.upload.addEventListener("load", function () {
				fill.style.width = "100%";
				sub.textContent = __("Unpacking and checking the build…", "banzaiplay");
			});

			xhr.addEventListener("load", function () {
				var json = xhr.response;

				if (json && json.redirect) {
					window.location.href = json.redirect;
					return;
				}

				setBusy(false);

				if (xhr.status === 413) {
					window.alert(__("The web server refused the upload as too large, before WordPress saw it. On nginx, raise client_max_body_size; see \"Uploading large builds\".", "banzaiplay"));
				} else {
					window.alert(__("The upload failed. Reload the page and try again.", "banzaiplay"));
				}
			});

			xhr.addEventListener("error", function () {
				setBusy(false);
				window.alert(__("The upload was interrupted. Check your connection and try again.", "banzaiplay"));
			});

			setBusy(true);
			xhr.send(data);
		});
	}

	/**
	 * The list's on/off switches post the same form without leaving the
	 * page. The switch flips straight away and flips back if saving fails.
	 */
	function initToggles() {
		if (!window.fetch || !window.FormData) {
			return;
		}

		function setState(form, active) {
			var button = form.querySelector(".bzpl-switch");
			var row = form.closest("tr");

			button.setAttribute("aria-checked", active ? "true" : "false");
			button.setAttribute("title", active ? __("Deactivate", "banzaiplay") : __("Activate", "banzaiplay"));
			form.querySelector("input[name=active]").value = active ? "0" : "1";

			if (row) {
				row.classList.toggle("is-inactive", !active);
			}

			// Keep the Active / Inactive counts honest.
			[["active", active ? 1 : -1], ["inactive", active ? -1 : 1]].forEach(function (pair) {
				var count = document.querySelector('[data-bzpl-count="' + pair[0] + '"]');

				if (count) {
					count.textContent = String(Math.max(0, (parseInt(count.textContent.replace(/\D/g, ""), 10) || 0) + pair[1]));
				}
			});
		}

		document.addEventListener("submit", function (event) {
			var form = event.target.closest(".bzpl-toggle-form");

			if (!form) {
				return;
			}

			event.preventDefault();

			var button = form.querySelector(".bzpl-switch");

			if (button.getAttribute("aria-busy") === "true") {
				return;
			}

			var active = form.querySelector("input[name=active]").value === "1";
			var body = new FormData(form);

			body.append("ajax", "1");
			setState(form, active);
			button.setAttribute("aria-busy", "true");

			// getAttribute: the form's <input name="action"> shadows form.action.
			fetch(form.getAttribute("action"), { method: "POST", body: body, credentials: "same-origin" })
				.then(function (response) {
					return response.json().then(function (json) {
						if (!response.ok || !json.success) {
							// The handler's own explanation, when it sent one.
							throw { message: json && typeof json.data === "string" ? json.data : "" };
						}
					});
				})
				.catch(function (error) {
					// Anything else — a network error, an HTML error page — gets the generic message.
					var message = error instanceof Error ? "" : error.message;

					setState(form, !active);
					window.alert(message || __("The game could not be updated. Reload the page and try again.", "banzaiplay"));
				})
				.then(function () {
					button.removeAttribute("aria-busy");
				});
		});
	}

	document.addEventListener("DOMContentLoaded", function () {
		initSlug();
		initCopy();
		initDelete();
		initDropzone();
		initUpload();
		initToggles();
	});
})();

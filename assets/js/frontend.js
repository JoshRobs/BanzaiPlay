/**
 * BanzaiPlay player, on the page.
 *
 * Each .banzaiplay-container (printed by Embed::render) gets a Player. Until
 * Play is pressed it is only an overlay: nothing of the game downloads. Play
 * creates the game's iframe (served by Frame) in the stage and shows the
 * loading screen, which the bridge inside the frame (frame.js) drives with
 * messages:
 *
 *     hello · progress {value} · ready · error {message} · exit
 *     focus · blur · release
 *
 * Only messages from this page's origin, sent by a frame this script created,
 * are listened to.
 *
 * Keyboard focus needs no capturing here: key events go to whichever document
 * has focus, so while the frame has it the game gets every key, and the page
 * gets them back when the visitor clicks outside or presses the release key
 * (the bridge then asks for focus to move to the container).
 *
 * Each container fires DOM events, which bubble, for other scripts:
 *
 *     banzaiplay:start  {byUser}   about to start; cancelable — then it doesn't
 *     banzaiplay:frame  {frame}    the game's iframe exists; its src is not set yet
 *     banzaiplay:ready             the game is running
 *     banzaiplay:error  {message}  the game could not start
 *     banzaiplay:exit              the game quit
 *     banzaiplay:unload            the game's iframe was removed
 *
 * and its Player is el.banzaiPlay: start(byUser), stop(), unload().
 */
(function () {
	"use strict";

	var l10n = window.banzaiPlayL10n || {};
	var players = new Map();
	var origin = window.location.origin;

	var fullscreenEnabled = !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);

	function fullscreenElement() {
		return document.fullscreenElement || document.webkitFullscreenElement || null;
	}

	function text(key, fallback) {
		return typeof l10n[key] === "string" ? l10n[key] : fallback;
	}

	function isTouchOnly() {
		return !!(window.matchMedia && window.matchMedia("(hover: none) and (pointer: coarse)").matches);
	}

	class Player {
		constructor(el) {
			this.el = el;

			try {
				this.cfg = JSON.parse(el.getAttribute("data-banzaiplay") || "{}");
			} catch (e) {
				this.cfg = {};
			}

			this.native = Array.isArray(this.cfg.native) ? this.cfg.native : [960, 540];
			this.viewport = el.querySelector(".banzaiplay-viewport");
			this.stage = el.querySelector(".banzaiplay-stage");
			this.overlay = el.querySelector(".banzaiplay-click-to-play");
			this.playButton = el.querySelector(".banzaiplay-play-btn");
			this.playLabel = el.querySelector(".banzaiplay-play-label");
			this.loading = el.querySelector(".banzaiplay-loading");
			this.bar = el.querySelector(".banzaiplay-progress-bar");
			this.fill = el.querySelector(".banzaiplay-progress-fill");
			this.progressText = el.querySelector(".banzaiplay-progress-text");
			this.message = el.querySelector(".banzaiplay-message");
			this.messageText = el.querySelector(".banzaiplay-message-text");
			this.retryButton = el.querySelector(".banzaiplay-retry-btn");
			this.controls = el.querySelector(".banzaiplay-controls");
			this.hint = el.querySelector(".banzaiplay-hint");
			this.fullscreenButton = el.querySelector(".banzaiplay-fullscreen-btn");

			this.frame = null;
			this.state = "idle";
			this.hello = false;
			this.wantsFocus = false;
			this.focused = false;
			this.released = false;

			this.playButton.addEventListener("click", () => this.start(true));
			this.retryButton.addEventListener("click", () => this.start(true));

			if (this.fullscreenButton) {
				// Phones without the Fullscreen API (iPhone Safari) get no button.
				this.fullscreenButton.hidden = !fullscreenEnabled;
				this.fullscreenButton.addEventListener("click", () => this.toggleFullscreen());
			}

			// A game marked best on desktop never downloads on a phone by itself,
			// even when it starts with the page elsewhere.
			let autoplay = !!this.cfg.autoplay;

			if (this.cfg.desktopOnly && isTouchOnly()) {
				const note = el.querySelector(".banzaiplay-mobile-note");

				if (note) {
					note.hidden = false;
				}

				this.playLabel.textContent = text("playAnyway", "Play anyway");
				this.overlay.hidden = false;
				autoplay = false;
			}

			if (this.cfg.fit === "scale" && typeof ResizeObserver === "function") {
				new ResizeObserver(() => this.fit()).observe(this.viewport);
			}

			el.classList.add("is-idle");

			if (autoplay) {
				this.start(false);
			}
		}

		/** Fire banzaiplay:{type} on the container. Returns false if cancelled. */
		dispatch(type, detail, cancelable) {
			const event = new CustomEvent("banzaiplay:" + type, {
				bubbles: true,
				cancelable: !!cancelable,
				detail: Object.assign({ player: this, game: this.cfg.game || "" }, detail || {}),
			});

			return this.el.dispatchEvent(event);
		}

		/**
		 * Create the game's frame. A click on Play also moves the keyboard into
		 * the game; autoplay doesn't, so a page never loses focus on load.
		 *
		 * @return {boolean} Whether it started (a banzaiplay:start listener can say no).
		 */
		start(byUser) {
			if (!this.dispatch("start", { byUser: !!byUser }, true)) {
				return false;
			}

			this.unload();

			this.state = "loading";
			this.hello = false;
			this.wantsFocus = byUser;
			this.released = false;
			this.overlay.hidden = true;
			this.message.hidden = true;
			this.loading.hidden = false;
			this.controls.hidden = true;
			this.el.classList.remove("is-idle", "is-running", "is-failed");
			this.el.classList.add("is-loading");
			this.setProgress(0);

			const frame = document.createElement("iframe");

			frame.className = "banzaiplay-frame";
			frame.title = this.cfg.title || "";
			frame.setAttribute("allow", "autoplay; fullscreen; gamepad; accelerometer; gyroscope; clipboard-write; screen-wake-lock");
			frame.setAttribute("allowfullscreen", "");

			if (this.cfg.fit === "scale") {
				frame.width = String(this.native[0]);
				frame.height = String(this.native[1]);
				frame.classList.add("is-scaled");
			}

			frame.addEventListener("load", () => this.frameLoaded(frame));
			this.dispatch("frame", { frame: frame });

			// src before it goes in the page: a frame inserted without one loads
			// about:blank first, and that load would count as the game's. Its
			// window exists once inserted, and messages can't come any sooner.
			frame.src = this.cfg.src;
			this.stage.appendChild(frame);
			players.set(frame.contentWindow, this);
			this.frame = frame;
			this.fit();

			if (byUser) {
				frame.focus();
			}

			return true;
		}

		unload() {
			if (!this.frame) {
				return;
			}

			players.delete(this.frame.contentWindow);
			this.frame.remove();
			this.frame = null;
			this.setFocused(false);
			this.dispatch("unload");
		}

		/**
		 * Remove the game and show its Play screen again — without moving
		 * focus, since whatever stops it is not the visitor's next step.
		 */
		stop() {
			if (fullscreenElement() === this.el) {
				(document.exitFullscreen || document.webkitExitFullscreen).call(document);
			}

			this.unload();
			this.state = "idle";
			this.loading.hidden = true;
			this.message.hidden = true;
			this.controls.hidden = true;
			this.overlay.hidden = false;
			this.el.classList.remove("is-loading", "is-running", "is-failed");
			this.el.classList.add("is-idle");
		}

		receive(data) {
			switch (data.type) {
				case "hello":
					this.hello = true;
					break;
				case "progress":
					this.setProgress(data.value);
					break;
				case "ready":
					this.reveal();
					break;
				case "error":
					this.fail(data.message);
					break;
				case "exit":
					this.ended();
					break;
				case "focus":
					this.released = false;
					this.setFocused(true);
					break;
				case "blur":
					this.setFocused(false);
					break;
				case "release":
					this.release();
					break;
			}
		}

		/**
		 * A frame without the bridge — something stripped it, or the game
		 * navigated to a page of its own — never says it is ready. Show it.
		 */
		frameLoaded(frame) {
			try {
				if (frame.contentWindow.location.href === "about:blank") {
					return;
				}
			} catch (e) {
				// Another origin: not the blank document, so a real load.
			}

			setTimeout(() => {
				if (!this.hello && this.state === "loading") {
					this.reveal();
				}
			}, 500);
		}

		setProgress(value) {
			const known = typeof value === "number" && value >= 0;
			const ratio = known ? Math.min(1, value) : 0;
			const percent = Math.round(ratio * 100);

			this.bar.classList.toggle("is-indeterminate", !known);
			this.fill.style.transform = "scaleX(" + ratio + ")";
			this.bar.setAttribute("aria-valuenow", String(percent));

			if (!known) {
				this.progressText.textContent = text("loading", "Loading…");
			} else if (percent >= 100) {
				this.progressText.textContent = text("starting", "Starting…");
			} else {
				this.progressText.textContent = text("loadingPercent", "Loading… %d%%").replace("%d", String(percent)).replace("%%", "%");
			}
		}

		reveal() {
			if (this.state !== "loading") {
				return;
			}

			this.state = "running";
			this.loading.hidden = true;
			this.controls.hidden = false;
			this.el.classList.remove("is-loading");
			this.el.classList.add("is-running");
			this.updateHint();

			if (this.wantsFocus) {
				this.focusGame();
			}

			this.dispatch("ready");
		}

		fail(message) {
			if (this.state === "error") {
				return;
			}

			this.unload();
			this.state = "error";
			this.loading.hidden = true;
			this.controls.hidden = !fullscreenElement();
			this.messageText.textContent = message || text("failed", "The game could not be loaded.");
			this.message.hidden = false;
			this.el.classList.remove("is-loading", "is-running");
			this.el.classList.add("is-failed");
			this.dispatch("error", { message: this.messageText.textContent });
		}

		ended() {
			this.dispatch("exit");
			this.unload();
			this.state = "idle";
			this.loading.hidden = true;
			this.controls.hidden = true;
			this.playLabel.textContent = text("playAgain", "Play again");
			this.overlay.hidden = false;
			this.el.classList.remove("is-loading", "is-running");
			this.el.classList.add("is-idle");
			this.playButton.focus({ preventScroll: true });
		}

		focusGame() {
			if (!this.frame) {
				return;
			}

			this.frame.focus();

			try {
				this.frame.contentWindow.postMessage({ banzaiplay: 1, type: "focus" }, origin);
			} catch (e) {
				// Not loaded yet; the frame takes focus with it anyway.
			}
		}

		/** The game gave the keyboard back: focus the container, out of the frame. */
		release() {
			this.released = true;
			this.el.focus({ preventScroll: true });
			this.setFocused(false);

			// Long enough to read; it would be noise if it stayed.
			clearTimeout(this.releasedTimer);
			this.releasedTimer = setTimeout(() => {
				this.released = false;
				this.updateHint();
			}, 5000);
		}

		setFocused(focused) {
			this.focused = focused;
			this.el.classList.toggle("is-focused", focused);
			this.updateHint();
		}

		updateHint() {
			if (!this.hint) {
				return;
			}

			if (this.state !== "running") {
				this.hint.textContent = "";
			} else if (this.focused) {
				this.hint.textContent = text(this.cfg.release || "escape", "");
			} else if (this.released) {
				this.hint.textContent = text("released", "");
			} else {
				this.hint.textContent = "";
			}
		}

		toggleFullscreen() {
			if (fullscreenElement() === this.el) {
				(document.exitFullscreen || document.webkitExitFullscreen).call(document);
				return;
			}

			const request = this.el.requestFullscreen || this.el.webkitRequestFullscreen;

			if (!request) {
				return;
			}

			const result = request.call(this.el, { navigationUI: "hide" });

			if (result && typeof result.catch === "function") {
				result.catch(() => {});
			}
		}

		fullscreenChanged() {
			const on = fullscreenElement() === this.el;

			this.el.classList.toggle("is-fullscreen", on);

			if (this.fullscreenButton) {
				const label = on ? text("exitFullscreen", "Exit fullscreen") : text("fullscreen", "Fullscreen");

				this.fullscreenButton.setAttribute("aria-label", label);
				this.fullscreenButton.title = label;
				this.fullscreenButton.classList.toggle("is-active", on);
			}

			if (on) {
				// Phones turn to the game's orientation where the browser allows.
				if (isTouchOnly() && screen.orientation && typeof screen.orientation.lock === "function") {
					screen.orientation.lock(this.native[0] >= this.native[1] ? "landscape" : "portrait").catch(() => {});
				}

				if (this.state === "running") {
					this.focusGame();
				}
			} else if (screen.orientation && typeof screen.orientation.unlock === "function") {
				try {
					screen.orientation.unlock();
				} catch (e) {
					// Not locked.
				}
			}

			this.fit();
		}

		/**
		 * "Scale a fixed-size game": the frame keeps the game's native size and
		 * is scaled, centred, to fit the viewport.
		 */
		fit() {
			if (this.cfg.fit !== "scale" || !this.frame) {
				return;
			}

			const box = this.viewport.getBoundingClientRect();
			const scale = Math.min(box.width / this.native[0], box.height / this.native[1]) || 1;
			const x = (box.width - this.native[0] * scale) / 2;
			const y = (box.height - this.native[1] * scale) / 2;

			this.frame.style.transform = "translate(" + x + "px," + y + "px) scale(" + scale + ")";
		}
	}

	window.addEventListener("message", (event) => {
		const data = event.data;

		if (event.origin !== origin || !data || data.banzaiplay !== 1) {
			return;
		}

		const player = players.get(event.source);

		if (player) {
			player.receive(data);
		}
	});

	function fullscreenChanged() {
		document.querySelectorAll(".banzaiplay-container").forEach((el) => {
			if (el.banzaiPlay) {
				el.banzaiPlay.fullscreenChanged();
			}
		});
	}

	document.addEventListener("fullscreenchange", fullscreenChanged);
	document.addEventListener("webkitfullscreenchange", fullscreenChanged);

	/**
	 * Start every player inside root that hasn't been — for content added
	 * after the page loaded (an AJAX-loaded tab, say).
	 */
	function init(root) {
		(root || document).querySelectorAll(".banzaiplay-container").forEach((el) => {
			if (!el.banzaiPlay) {
				el.banzaiPlay = new Player(el);
			}
		});
	}

	window.BanzaiPlay = window.BanzaiPlay || {};
	window.BanzaiPlay.init = init;

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", () => init());
	} else {
		init();
	}
})();

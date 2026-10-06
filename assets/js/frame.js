/**
 * BanzaiPlay bridge — runs inside a game's frame, before any of the game's
 * own scripts.
 *
 * It tells the page around the frame (frontend.js) how loading is going and
 * when the game is ready, fails or quits, and when the frame gains or loses
 * the keyboard; it hands the keyboard back on the release key; and it smooths
 * over the browser quirks that stop games from starting:
 *
 * - Audio: every AudioContext the game creates is tracked and resumed as soon
 *   as the browser allows (the Play click on the page usually counts), and
 *   again on the first click or key press inside the game. Media elements
 *   whose play() was refused are retried at the same moment.
 * - WebAssembly: instantiateStreaming() fails outright when a server sends
 *   .wasm without the application/wasm type; it falls back to compiling the
 *   downloaded bytes instead.
 *
 * Engine scripts (engines/*.js) use window.BanzaiPlayFrame.api. Games played
 * from their own page get an estimated progress bar and are "ready" when the
 * page has loaded, unless an engine hook holds that back for a better signal.
 *
 * Messages go to the parent only, at this frame's own origin: the frame is
 * served with X-Frame-Options: SAMEORIGIN, so that is the page's origin too.
 */
(function () {
	"use strict";

	var cfg = (window.BanzaiPlayFrame = window.BanzaiPlayFrame || {});
	var i18n = cfg.i18n || {};
	var parentWindow = window.parent !== window ? window.parent : null;
	var origin = window.location.origin;

	var state = { ready: false, failed: false, progress: 0, holds: 0, loaded: false };

	function post(type, data) {
		if (!parentWindow) {
			return;
		}

		var message = { banzaiplay: 1, type: type };

		for (var key in data) {
			if (Object.prototype.hasOwnProperty.call(data, key)) {
				message[key] = data[key];
			}
		}

		try {
			parentWindow.postMessage(message, origin);
		} catch (e) {
			// The page has gone; nothing to tell.
		}
	}

	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			var script = document.createElement("script");

			script.src = src;
			script.onload = function () {
				resolve();
			};
			script.onerror = function () {
				reject(new Error((i18n.failed || "The game could not be loaded.") + " (" + src.split("?")[0] + ")"));
			};
			(document.head || document.documentElement).appendChild(script);
		});
	}

	var api = {
		/** Report progress, 0–1; a negative value means "unknown". Never goes backwards. */
		progress: function (value) {
			if (state.ready || state.failed) {
				return;
			}

			if (typeof value !== "number" || value < 0 || value !== value) {
				post("progress", { value: -1 });
				return;
			}

			value = Math.min(1, value);

			if (value <= state.progress) {
				return;
			}

			state.progress = value;
			post("progress", { value: value });
		},

		/** The game is running: the page hides its loading screen. */
		ready: function () {
			if (state.ready || state.failed) {
				return;
			}

			state.ready = true;
			post("ready");
			resumeAudio();
		},

		/** The game cannot start. */
		error: function (message) {
			if (state.failed) {
				return;
			}

			state.failed = true;
			post("error", { message: String(message || i18n.failed || "The game could not be loaded.") });
		},

		/** The game quit (Godot's get_tree().quit(), say). */
		exit: function () {
			post("exit");
		},

		/**
		 * Keep a game played from its own page from counting as ready when the
		 * page loads; call the returned function when it really is. If it never
		 * is, it becomes ready ten seconds after the page loaded anyway.
		 */
		hold: function () {
			var released = false;

			state.holds++;

			return function () {
				if (released) {
					return;
				}

				released = true;
				state.holds--;

				if (state.loaded && !state.holds) {
					api.ready();
				}
			};
		},

		isReady: function () {
			return state.ready;
		},

		loadScript: loadScript,
	};

	cfg.api = api;

	// ---------------------------------------------------------------------
	// Keyboard: report focus, and give the keyboard back on the release key.

	var release = cfg.release || "escape";
	var swallowKeyUp = false;

	function isReleaseKey(event) {
		if (release === "none" || (event.key !== "Escape" && event.key !== "Esc")) {
			return false;
		}

		return (release === "shift_escape") === event.shiftKey;
	}

	window.addEventListener(
		"keydown",
		function (event) {
			if (!isReleaseKey(event)) {
				return;
			}

			// Registered before any game script, on the window, in the capture
			// phase: no listener of the game's sees the key.
			event.preventDefault();
			event.stopImmediatePropagation();
			swallowKeyUp = true;

			if (document.activeElement && document.activeElement !== document.body && document.activeElement.blur) {
				document.activeElement.blur();
			}

			post("release");
		},
		true
	);

	window.addEventListener(
		"keyup",
		function (event) {
			if (swallowKeyUp && (event.key === "Escape" || event.key === "Esc")) {
				swallowKeyUp = false;
				event.preventDefault();
				event.stopImmediatePropagation();
			}
		},
		true
	);

	window.addEventListener("focus", function () {
		post("focus");
	});

	window.addEventListener("blur", function () {
		post("blur");
	});

	function focusGame() {
		var target = document.querySelector("canvas");

		window.focus();

		if (target && target.hasAttribute("tabindex")) {
			target.focus({ preventScroll: true });
		}

		reportFocus();
	}

	// The page focuses the frame before this document exists, so its window
	// never gets a focus event for that; say so when it already has focus.
	function reportFocus() {
		if (document.hasFocus()) {
			post("focus");
		}
	}

	window.addEventListener("message", function (event) {
		if (event.source !== parentWindow || event.origin !== origin || !event.data || event.data.banzaiplay !== 1) {
			return;
		}

		if (event.data.type === "focus") {
			focusGame();
		}
	});

	// ---------------------------------------------------------------------
	// Audio: resume what the autoplay policy suspended.

	var contexts = [];
	var refused = [];

	function resumeAudio() {
		contexts.forEach(function (context) {
			if (context.state === "suspended" && typeof context.resume === "function") {
				context.resume().catch(function () {});
			}
		});

		refused.splice(0).forEach(function (media) {
			if (media.paused && media.isConnected !== false) {
				nativePlay.call(media).catch(function () {});
			}
		});
	}

	function patchAudioContext(name) {
		var Native = window[name];

		if (typeof Native !== "function" || typeof Reflect === "undefined") {
			return;
		}

		var Patched = function () {
			// new.target keeps subclasses (class X extends AudioContext) working.
			var context = Reflect.construct(Native, arguments, new.target || Patched);

			contexts.push(context);

			if (context.state === "suspended") {
				setTimeout(resumeAudio, 0);
			}

			return context;
		};

		Patched.prototype = Native.prototype;
		Object.setPrototypeOf(Patched, Native);

		try {
			window[name] = Patched;
		} catch (e) {
			// Read-only in this browser; leave it.
		}
	}

	patchAudioContext("AudioContext");
	patchAudioContext("webkitAudioContext");

	var nativePlay = window.HTMLMediaElement && HTMLMediaElement.prototype.play;

	if (nativePlay) {
		HTMLMediaElement.prototype.play = function () {
			var media = this;
			var result = nativePlay.apply(media, arguments);

			if (result && typeof result.catch === "function") {
				result.catch(function (error) {
					if (error && error.name === "NotAllowedError" && refused.indexOf(media) === -1) {
						refused.push(media);
					}
				});
			}

			return result;
		};
	}

	["pointerdown", "mousedown", "touchend", "keydown"].forEach(function (type) {
		window.addEventListener(type, resumeAudio, true);
	});

	// ---------------------------------------------------------------------
	// WebAssembly served with the wrong MIME type.

	function patchStreaming(name, fallback) {
		var native = window.WebAssembly && WebAssembly[name];

		if (typeof native !== "function") {
			return;
		}

		WebAssembly[name] = function (source, imports) {
			return Promise.resolve(source).then(function (response) {
				var type = response && response.headers ? response.headers.get("Content-Type") || "" : "";

				if (/^application\/wasm\b/i.test(type)) {
					return native.call(WebAssembly, response, imports);
				}

				return response.arrayBuffer().then(function (bytes) {
					return fallback(bytes, imports);
				});
			});
		};
	}

	patchStreaming("instantiateStreaming", function (bytes, imports) {
		return WebAssembly.instantiate(bytes, imports);
	});
	patchStreaming("compileStreaming", function (bytes) {
		return WebAssembly.compile(bytes);
	});

	// ---------------------------------------------------------------------
	// The game's own API, window.BanzaiPlay:
	//
	//     BanzaiPlay.game                  this game's slug
	//     BanzaiPlay.data                  values set on the game's Data Bridge
	//     BanzaiPlay.user()                Promise of the visitor's details, or null
	//     BanzaiPlay.emit(name, data)      tell the page: "score", "complete"…
	//
	// It is always defined, so a game written for it never throws, even on a
	// page where nothing listens for its events. The page hands the data over on the iframe element itself (same origin), before the game's
	// first script, so it can be read synchronously.

	var host = {};

	try {
		host = (window.frameElement && window.frameElement.banzaiPlay) || {};
	} catch (e) {
		// Not framed by this site: nothing handed over.
	}

	// Plain copies, so nothing the game holds belongs to the page's window.
	function plain(value) {
		try {
			return value === undefined ? null : JSON.parse(JSON.stringify(value));
		} catch (e) {
			return null;
		}
	}

	var userRequest = null;
	var userFetched = 0;

	window.BanzaiPlay = {
		game: cfg.game || "",

		data: plain(host.data) || {},

		/**
		 * The visitor's details enabled on the game's Data Bridge — always
		 * fetched, never in the page, so a cached page can't hand one visitor's
		 * details to the next. Resolves to null when the game has none.
		 */
		user: function (fresh) {
			if (!host.userUrl) {
				return Promise.resolve(null);
			}

			if (!userRequest || fresh || Date.now() - userFetched > 36e5) {
				userFetched = Date.now();
				userRequest = fetch(host.userUrl, { credentials: "same-origin", cache: "no-store" }).then(function (response) {
					if (!response.ok) {
						throw new Error("BanzaiPlay: the user request failed (" + response.status + ")");
					}

					return response.json();
				});
				userRequest.catch(function () {
					userRequest = null;
				});
			}

			return userRequest;
		},

		/**
		 * Send an event to the page: emit("score", { score: 1500 }), or
		 * emit("complete", { score: 1500, time: 120 }). The slug-first form,
		 * emit("my-game", "score", {…}), works too.
		 */
		emit: function (name, data) {
			if (arguments.length > 2) {
				name = arguments[1];
				data = arguments[2];
			}

			if (typeof name !== "string" || !/^[A-Za-z0-9_.:-]{1,64}$/.test(name)) {
				throw new TypeError("BanzaiPlay.emit: the event name must be 1–64 letters, numbers or _ . : -");
			}

			post("emit", { name: name, data: plain(data) });
		},
	};

	// ---------------------------------------------------------------------
	// Games played from their own page: estimated progress, ready on load.

	function estimateProgress() {
		var resources = 0;

		function update() {
			// Approaches 90% as files arrive; the rest is the page finishing.
			api.progress(0.9 * (1 - Math.exp(-resources / 12)));
		}

		if (typeof PerformanceObserver === "function") {
			try {
				new PerformanceObserver(function (list) {
					resources += list.getEntries().length;
					update();
				}).observe({ type: "resource", buffered: true });
			} catch (e) {
				// Old browser: the timer below still moves the bar.
			}
		}

		var timer = setInterval(function () {
			if (state.ready || state.failed) {
				clearInterval(timer);
				return;
			}

			resources += 0.25;
			update();
		}, 250);

		document.addEventListener("DOMContentLoaded", function () {
			api.progress(Math.max(state.progress, 0.3));
		});
	}

	post("hello");
	reportFocus();

	if (cfg.fatal) {
		api.error(cfg.fatal);
		return;
	}

	if (cfg.mode === "html") {
		estimateProgress();

		window.addEventListener("load", function () {
			state.loaded = true;
			api.progress(0.95);

			if (!state.holds) {
				// Give the game's own load handlers a moment to set up.
				setTimeout(api.ready, 100);
			} else {
				setTimeout(api.ready, 10000);
			}
		});
	}
})();

/**
 * BanzaiPlay player extras, on the page.
 *
 * Runs before frontend.js (it is one of its dependencies), so everything
 * here listens on document for the players' events and is in place before
 * the first player starts:
 *
 * - Data Bridge: hands a game its values (cfg.bridge) on the iframe element,
 *   where the bridge in the frame reads them before the game's first script.
 *   The page has them too, at window.BanzaiPlay.games[slug].
 * - Game events: BanzaiPlay.emit() in a game arrives as an "emit" message.
 *   It is fired on the player as banzaiplay:event {name, data}, sent to the
 *   server (Plays: hooks and statistics), and "complete" can show a results
 *   screen. window.BanzaiPlay.emit(slug, name, data) on the page does the same.
 * - Statistics: a play starts when its frame does; time is counted only while
 *   the game runs and the page is in view, and reported every 30 seconds and
 *   when the game or page goes away.
 * - Several games on a page: games that start with the page load one after
 *   another; optionally only one plays at a time, and games scrolled out of
 *   view close.
 */
(function () {
	"use strict";

	var extras = window.banzaiPlayExtras || {};
	var i18n = extras.i18n || {};
	var origin = window.location.origin;
	var api = (window.BanzaiPlay = window.BanzaiPlay || {});
	var sessions = new WeakMap();
	var waiting = [];

	api.games = api.games || {};

	function text(key, fallback) {
		return typeof i18n[key] === "string" ? i18n[key] : fallback;
	}

	function players() {
		var list = [];

		document.querySelectorAll(".banzaiplay-container").forEach(function (el) {
			if (el.banzaiPlay) {
				list.push(el.banzaiPlay);
			}
		});

		return list;
	}

	function isBusy(player) {
		return player.state === "loading" || player.state === "running";
	}

	function plain(value) {
		try {
			return value === undefined ? null : JSON.parse(JSON.stringify(value));
		} catch (e) {
			return null;
		}
	}

	function device() {
		var touch = window.matchMedia && window.matchMedia("(hover: none) and (pointer: coarse)").matches;

		if (!touch) {
			return "desktop";
		}

		return Math.min(window.screen.width, window.screen.height) >= 600 ? "tablet" : "mobile";
	}

	// ---------------------------------------------------------------------
	// Data Bridge.

	function readConfig(el) {
		try {
			return JSON.parse(el.getAttribute("data-banzaiplay") || "{}");
		} catch (e) {
			return {};
		}
	}

	function publishData(root) {
		(root || document).querySelectorAll(".banzaiplay-container[data-banzaiplay]").forEach(function (el) {
			var cfg = readConfig(el);

			if (cfg.game && cfg.bridge && !(cfg.game in api.games)) {
				api.games[cfg.game] = plain(cfg.bridge.data) || {};
			}
		});
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", function () {
			publishData();
		});
	} else {
		publishData();
	}

	document.addEventListener("banzaiplay:frame", function (event) {
		var player = event.detail.player;
		var bridge = player.cfg.bridge;

		if (bridge && player.cfg.game) {
			api.games[player.cfg.game] = api.games[player.cfg.game] || plain(bridge.data) || {};
		}

		event.detail.frame.banzaiPlay = {
			data: bridge ? plain(bridge.data) || {} : {},
			userUrl: bridge && bridge.userUrl ? bridge.userUrl : "",
		};
	});

	// ---------------------------------------------------------------------
	// Talking to the server.

	function body(fields) {
		var params = new URLSearchParams();

		params.set("action", extras.action || "bzpl_play");

		Object.keys(fields).forEach(function (key) {
			if (fields[key] !== undefined && fields[key] !== null) {
				params.set(key, String(fields[key]));
			}
		});

		return params;
	}

	function send(fields) {
		return fetch(extras.ajax, {
			method: "POST",
			body: body(fields),
			credentials: "same-origin",
			keepalive: true,
		}).then(function (response) {
			return response.ok ? response.json() : Promise.reject(new Error("BanzaiPlay: " + response.status));
		});
	}

	// For the page going away: a beacon survives it, a fetch may not.
	function beacon(fields) {
		if (navigator.sendBeacon && navigator.sendBeacon(extras.ajax, body(fields))) {
			return;
		}

		send(fields).catch(function () {});
	}

	// ---------------------------------------------------------------------
	// A play.

	function Session(player) {
		this.player = player;
		this.game = player.cfg.game;
		this.sid = "";
		this.token = "";
		this.queue = [];
		this.played = 0;
		this.since = 0;
		this.score = null;
		this.closed = false;
		this.reported = -1;
		this.scoreTimer = 0;
		this.started = send({ op: "start", game: this.game, device: device(), post: player.cfg.post || 0 })
			.then((reply) => {
				this.sid = reply.sid;
				this.token = reply.token;
				this.queue.splice(0).forEach((fields) => this.post(fields));
			})
			.catch(function () {
				// Statistics are a bonus: the game plays on without them.
			});
	}

	Session.prototype.post = function (fields, final) {
		if (!this.sid) {
			this.queue.push(fields);
			return;
		}

		fields.sid = this.sid;
		fields.token = this.token;
		fields.game = this.game;

		if (final) {
			beacon(fields);
			return;
		}

		// One at a time, so the server sees events in the order the game sent
		// them — a "score" just before "complete" must not land after it.
		this.chain = (this.chain || Promise.resolve()).then(function () {
			return send(fields).catch(function () {});
		});
	};

	Session.prototype.seconds = function () {
		return Math.round((this.played + (this.since ? Date.now() - this.since : 0)) / 1000);
	};

	/** Count time: the game is running and the page can be seen. */
	Session.prototype.resume = function () {
		if (!this.closed && !this.since && this.player.state === "running" && document.visibilityState === "visible") {
			this.since = Date.now();
		}
	};

	Session.prototype.pause = function () {
		if (this.since) {
			this.played += Date.now() - this.since;
			this.since = 0;
		}
	};

	Session.prototype.ping = function (final) {
		var seconds = this.seconds();

		if (seconds === this.reported || (!this.sid && final)) {
			return;
		}

		this.reported = seconds;
		this.post({ op: "ping", seconds: seconds }, final);
	};

	Session.prototype.event = function (name, data) {
		if (this.closed) {
			return;
		}

		var score = scoreOf(name, data);

		if (score !== null) {
			this.score = score;
		}

		// A game may send its score every frame: send the latest, at most
		// every two seconds. Anything else goes straight away.
		if (name === "score") {
			this.pendingScore = data;

			if (!this.scoreTimer) {
				this.scoreTimer = setTimeout(() => this.flushScore(), 2000);
			}

			return;
		}

		this.flushScore();
		this.post({ op: "event", name: name, data: JSON.stringify(data) });
	};

	Session.prototype.flushScore = function (final) {
		clearTimeout(this.scoreTimer);
		this.scoreTimer = 0;

		if (this.pendingScore !== undefined) {
			this.post({ op: "event", name: "score", data: JSON.stringify(this.pendingScore) }, final);
			this.pendingScore = undefined;
		}
	};

	Session.prototype.close = function () {
		if (this.closed) {
			return;
		}

		this.pause();
		this.flushScore(true);
		this.ping(true);
		this.closed = true;
	};

	function scoreOf(name, data) {
		if (name !== "score" && name !== "complete") {
			return null;
		}

		if (data && typeof data === "object") {
			data = "score" in data ? data.score : data.points;
		}

		return typeof data === "number" && isFinite(data) ? data : null;
	}

	function track(player) {
		// Events reach the server's hooks even when statistics are off, so
		// a play is started either way; the server decides what it keeps.
		if (!extras.ajax || !player.cfg.track || !player.cfg.game) {
			return null;
		}

		var session = new Session(player);

		sessions.set(player, session);

		return session;
	}

	document.addEventListener("banzaiplay:frame", function (event) {
		var player = event.detail.player;
		var old = sessions.get(player);

		if (old) {
			old.close();
		}

		track(player);
	});

	document.addEventListener("banzaiplay:ready", function (event) {
		var session = sessions.get(event.detail.player);

		if (session) {
			session.resume();
		}
	});

	document.addEventListener("banzaiplay:unload", function (event) {
		var session = sessions.get(event.detail.player);

		hideResults(event.detail.player);

		if (session) {
			session.close();
			sessions.delete(event.detail.player);
		}
	});

	setInterval(function () {
		players().forEach(function (player) {
			var session = sessions.get(player);

			if (session && !session.closed) {
				session.ping(false);
			}
		});
	}, 30000);

	document.addEventListener("visibilitychange", function () {
		players().forEach(function (player) {
			var session = sessions.get(player);

			if (!session) {
				return;
			}

			if (document.visibilityState === "visible") {
				session.resume();
			} else {
				// Hidden may be the last we hear of this page (mobile browsers
				// rarely fire pagehide), so report now.
				session.pause();
				session.flushScore(true);
				session.ping(true);
			}
		});
	});

	window.addEventListener("pagehide", function () {
		players().forEach(function (player) {
			var session = sessions.get(player);

			if (session) {
				session.close();
			}
		});
	});

	// ---------------------------------------------------------------------
	// Events from games.

	function gameEvent(player, name, data) {
		if (typeof name !== "string" || !/^[A-Za-z0-9_.:-]{1,64}$/.test(name)) {
			return;
		}

		var session = sessions.get(player);

		player.dispatch("event", { name: name, data: data });

		if (session) {
			session.event(name, data);
		}

		if (name === "complete" && player.cfg.results) {
			showResults(player, data, session);
		}
	}

	window.addEventListener("message", function (event) {
		var data = event.data;

		if (event.origin !== origin || !data || data.banzaiplay !== 1 || data.type !== "emit") {
			return;
		}

		players().some(function (player) {
			if (player.frame && player.frame.contentWindow === event.source) {
				gameEvent(player, data.name, plain(data.data));
				return true;
			}

			return false;
		});
	});

	/**
	 * window.BanzaiPlay.emit(slug, name, data): for games that call
	 * window.parent.BanzaiPlay.emit(...). With the same game on the page more
	 * than once, it goes to the one with the keyboard, else the first running.
	 */
	api.emit = function (slug, name, data) {
		var running = players().filter(function (player) {
			return player.cfg.game === slug && isBusy(player);
		});
		var target = running.filter(function (player) {
			return player.focused;
		})[0] || running[0];

		if (target) {
			gameEvent(target, name, plain(data));
		}
	};

	// ---------------------------------------------------------------------
	// The results screen.

	function formatTime(seconds) {
		seconds = Math.max(0, Math.round(seconds));

		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		var pad = function (n) {
			return (n < 10 ? "0" : "") + n;
		};

		return h ? h + ":" + pad(m) + ":" + pad(s) : m + ":" + pad(s);
	}

	function hideResults(player) {
		if (player.results) {
			player.results.remove();
			player.results = null;
		}
	}

	function showResults(player, data, session) {
		hideResults(player);

		var score = scoreOf("complete", data);
		var time = data && typeof data === "object" && typeof data.time === "number" ? data.time : session ? session.seconds() : null;

		if (score === null && session) {
			score = session.score;
		}

		var box = document.createElement("div");
		var title = document.createElement("div");
		var stats = document.createElement("dl");
		var actions = document.createElement("div");
		var again = document.createElement("button");
		var close = document.createElement("button");

		box.className = "banzaiplay-results";
		box.setAttribute("role", "dialog");
		box.setAttribute("aria-label", text("complete", "Well played!"));
		title.className = "banzaiplay-results-title";
		title.textContent = text("complete", "Well played!");
		stats.className = "banzaiplay-results-stats";
		actions.className = "banzaiplay-results-actions";

		function stat(label, value) {
			var item = document.createElement("div");
			var dt = document.createElement("dt");
			var dd = document.createElement("dd");

			dt.textContent = label;
			dd.textContent = value;
			item.append(dt, dd);
			stats.appendChild(item);
		}

		if (score !== null) {
			stat(text("score", "Score"), score.toLocaleString());
		}

		if (typeof time === "number" && isFinite(time)) {
			stat(text("time", "Time"), formatTime(time));
		}

		again.type = "button";
		again.className = "banzaiplay-play-btn banzaiplay-results-again";
		again.textContent = text("playAgain", "Play again");
		again.addEventListener("click", function () {
			hideResults(player);
			player.start(true);
		});

		close.type = "button";
		close.className = "banzaiplay-results-close";
		close.textContent = text("close", "Keep playing");
		close.addEventListener("click", function () {
			hideResults(player);
			player.focusGame();
		});

		actions.append(again, close);
		box.append(title);

		if (stats.children.length) {
			box.append(stats);
		}

		box.append(actions);
		player.viewport.appendChild(box);
		player.results = box;

		// The keyboard comes out of the game, to the screen's buttons.
		player.el.focus({ preventScroll: true });
		again.focus({ preventScroll: true });
	}

	// ---------------------------------------------------------------------
	// Several games on a page.

	function showWaiting(player) {
		player.overlay.hidden = true;
		player.loading.hidden = false;
		player.progressText.textContent = text("waiting", "Waiting for the other game to load…");
		player.bar.classList.add("is-indeterminate");
		player.el.classList.add("is-waiting");
	}

	function startNext() {
		if (players().some(function (player) { return player.state === "loading"; })) {
			return;
		}

		var next = waiting.shift();

		if (next) {
			next.el.classList.remove("is-waiting");
			next.start(false);
		}
	}

	document.addEventListener("banzaiplay:start", function (event) {
		var player = event.detail.player;

		if (player.cfg.preview) {
			return;
		}

		var others = players().filter(function (other) {
			return other !== player && !other.cfg.preview;
		});

		if (extras.oneAtATime) {
			// Starting with the page while another game is already playing:
			// that one keeps playing, this one waits for its Play button.
			if (!event.detail.byUser && others.some(isBusy)) {
				event.preventDefault();
				player.overlay.hidden = false;
				player.loading.hidden = true;
				return;
			}

			others.forEach(function (other) {
				if (isBusy(other)) {
					other.stop();
				}
			});
			return;
		}

		// Starting with the page: one download at a time, in page order.
		if (!event.detail.byUser && others.some(function (other) { return other.state === "loading"; })) {
			event.preventDefault();

			if (waiting.indexOf(player) === -1) {
				waiting.push(player);
			}

			showWaiting(player);
			return;
		}

		// Pressed Play while waiting its turn: no longer waiting.
		waiting = waiting.filter(function (other) {
			return other !== player;
		});
		player.el.classList.remove("is-waiting");
	});

	["banzaiplay:ready", "banzaiplay:error", "banzaiplay:unload"].forEach(function (type) {
		document.addEventListener(type, function () {
			// After the player has finished changing state.
			setTimeout(startNext, 0);
		});
	});

	if (extras.unloadHidden && typeof IntersectionObserver === "function") {
		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				var player = entry.target.banzaiPlay;

				if (!player) {
					return;
				}

				clearTimeout(player.hiddenTimer);

				if (!entry.isIntersecting && isBusy(player)) {
					player.hiddenTimer = setTimeout(function () {
						var fullscreen = document.fullscreenElement || document.webkitFullscreenElement;

						if (isBusy(player) && fullscreen !== player.el) {
							player.stop();
						}
					}, 10000);
				}
			});
		});

		document.addEventListener("banzaiplay:frame", function (event) {
			observer.observe(event.detail.player.el);
		});

		document.addEventListener("banzaiplay:unload", function (event) {
			clearTimeout(event.detail.player.hiddenTimer);
			observer.unobserve(event.detail.player.el);
		});
	}
})();

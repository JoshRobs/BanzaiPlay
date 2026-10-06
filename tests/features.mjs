/**
 * The feature cards and screens, end to end, through the real screens and
 * pages: Settings, the Loading screen, Gallery, Data Bridge and Game events
 * cards on the edit screen, the branded loading screen, the Data Bridge,
 * game events (page event, results screen, PHP hook, statistics), play one
 * at a time, load one at a time, the gallery and its lightbox, and Analytics.
 *
 * Needs the fixtures uploaded (tests/e2e.sh):
 *
 *   node tests/features.mjs [base-url]
 *
 * It runs tests/features-setup.php itself (wp-cli, through docker exec on the
 * wp-env cli container).
 */
import { execFileSync } from "node:child_process";
import { launch, sleep } from "./cdp.mjs";

const base = process.argv[2] || "http://localhost:8892";
// wp-env names it wp-env-{folder}-{hash}-cli-1.
const cli = execFileSync("docker", ["ps", "--format", "{{.Names}}"], { encoding: "utf8" }).split(/\r?\n/).find((name) => /^wp-env-banzaiplay-.*-cli-1$/.test(name));
const wp = (...args) => execFileSync("docker", ["exec", cli, "wp", ...args], { encoding: "utf8" }).trim();
const php = (code) => wp("eval", code);

let failed = 0;

function check(label, actual, expected) {
	const ok = typeof expected === "function" ? expected(actual) : JSON.stringify(actual) === JSON.stringify(expected);
	failed += ok ? 0 : 1;
	console.log((ok ? "PASS " : "FAIL ") + label + (ok ? "" : ` — got ${JSON.stringify(actual)}${typeof expected === "function" ? "" : `, expected ${JSON.stringify(expected)}`}`));
}

const setup = JSON.parse(wp("eval-file", "wp-content/plugins/BanzaiPlay/tests/features-setup.php"));
wp("option", "delete", "bzpl_test_events");
php('global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}banzaiplay_plays");');

const plays = () => JSON.parse(php('global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT game, post_id, seconds, device, completed, score, events FROM {$wpdb->prefix}banzaiplay_plays ORDER BY id", ARRAY_A));'));

const b = await launch({ width: 1280, height: 1000 });
const problems = [];
const collect = () => problems.push(...b.problems().filter((p) => !p.includes("favicon")));

async function submit(selector) {
	await b.click(selector);
	await sleep(600);
	await b.waitFor("document.readyState === 'complete' && !!document.querySelector('.notice-success')", 20000);
}

async function settings(values) {
	await b.go(`${base}/wp-admin/admin.php?page=banzaiplay-settings`);
	await b.evaluate(`(() => {
		const v = ${JSON.stringify(values)};
		const form = document.querySelector('.bzpl-settings');
		for (const [name, value] of Object.entries(v)) {
			const input = form.querySelector('[name="' + name + '"]');
			if (input.type === 'checkbox') input.checked = !!value; else input.value = String(value);
		}
		return true;
	})()`);
	await submit(".bzpl-settings-submit button");
}

const playing = (id) => `document.querySelector('#${id}').classList.contains('is-running') && !!document.querySelector('#${id} iframe')`;

try {
	await b.login(base);

	// ---- Settings.
	await settings({ logo: setup.logo, color: "#ff6600", one_at_a_time: false, unload_hidden: false, record: true, keep_days: 365 });
	check("settings saved", await b.evaluate("[document.querySelector('[name=logo]').value, document.querySelector('[name=color]').value]"), [String(setup.logo), "#ff6600"]);

	// ---- The feature cards, through the edit form.
	await b.go(`${base}/wp-admin/admin.php?page=banzaiplay&action=edit&game=generic-canvas`);
	await b.evaluate(`(() => {
		const f = (n) => document.querySelector('[name="' + n + '"]');
		f('look_cover').value = '${setup.cover}';
		f('look_backdrop').checked = true;
		f('look_color').value = '';
		f('gallery_tags').value = 'Puzzle, Jam, puzzle';
		f('events_results').checked = true;
		const rows = document.querySelector('.bzpl-bridge-rows');
		rows.querySelector('input[name$="[key]"]').value = 'levelSet';
		rows.querySelector('input[name$="[value]"]').value = 'hard';
		document.querySelector('.bzpl-bridge-add').click();
		const added = rows.lastElementChild;
		added.querySelector('input[name$="[key]"]').value = 'postTitle';
		const source = added.querySelector('select');
		source.value = 'post.title';
		source.dispatchEvent(new Event('change', { bubbles: true }));
		for (const field of ['displayName', 'nonce']) document.querySelector('input[name="bridge_user[]"][value="' + field + '"]').checked = true;
		return true;
	})()`);
	await submit(".bzpl-publish button[type=submit]");
	check(
		"feature cards saved through the form",
		await b.evaluate(`(() => {
			const f = (n) => document.querySelector('[name="' + n + '"]');
			return [f('look_cover').value, f('gallery_tags').value, f('events_results').checked,
				[...document.querySelectorAll('.bzpl-bridge-rows tr')].map(r => r.querySelector('input').value).filter(Boolean),
				[...document.querySelectorAll('input[name="bridge_user[]"]:checked')].map(i => i.value)];
		})()`),
		[String(setup.cover), "Puzzle, Jam", true, ["levelSet", "postTitle"], ["displayName", "nonce"]]
	);

	await b.go(`${base}/wp-admin/admin.php?page=banzaiplay&action=edit&game=phaser-global`);
	await b.evaluate("document.querySelector('[name=gallery_tags]').value = 'Arcade'; true");
	await submit(".bzpl-publish button[type=submit]");
	collect();

	// ---- The branded player, and the Data Bridge on the page.
	await b.go(setup.pages.features);
	const look = await b.evaluate(`(() => {
		const [a, c] = document.querySelectorAll('.banzaiplay-container');
		return {
			backdrop: [a.classList.contains('has-backdrop'), c.classList.contains('has-backdrop')],
			logo: [!!a.querySelector('.banzaiplay-click-to-play .banzaiplay-logo'), !!c.querySelector('.banzaiplay-logo')],
			accent: getComputedStyle(a).getPropertyValue('--banzaiplay-accent').trim(),
			onAccent: getComputedStyle(a.querySelector('.banzaiplay-play-btn')).color,
			poweredBy: document.querySelectorAll('.banzaiplay-branding').length,
		};
	})()`);
	check("cover behind the first game only", look.backdrop, [true, false]);
	check("site logo on both", look.logo, [true, true]);
	check("site colour, readable text on it", [look.accent, look.onAccent], ["#ff6600", "rgb(17, 17, 17)"]);
	check("no Powered by BanzaiPlay", look.poweredBy, 0);
	check("page sees BanzaiPlay.games", await b.evaluate("JSON.stringify(window.BanzaiPlay.games['generic-canvas'])"), JSON.stringify({ levelSet: "hard", postTitle: "Feature Tests" }));

	// ---- Play, and the game's side of the bridge.
	await b.evaluate("window.__events = []; document.addEventListener('banzaiplay:event', (e) => window.__events.push([e.detail.game, e.detail.name, e.detail.data])); true");
	await b.click("#banzaiplay-generic-canvas .banzaiplay-play-btn");
	check("game runs", !!(await b.waitFor(playing("banzaiplay-generic-canvas"))), true);

	const frame = "document.querySelector('#banzaiplay-generic-canvas iframe').contentWindow";
	check("game reads BanzaiPlay.data", await b.evaluate(`JSON.stringify(${frame}.BanzaiPlay.data)`), JSON.stringify({ levelSet: "hard", postTitle: "Feature Tests" }));
	const user = await b.evaluate(`${frame}.BanzaiPlay.user().then(u => ({ loggedIn: u.loggedIn, displayName: u.displayName, nonce: typeof u.nonce, keys: Object.keys(u).sort() }))`);
	check("game fetches BanzaiPlay.user()", user, { loggedIn: true, displayName: "admin", nonce: "string", keys: ["displayName", "loggedIn", "nonce"] });

	// ---- Events.
	await sleep(2500); // some time played
	await b.evaluate(`${frame}.BanzaiPlay.emit('score', { score: 10 }); ${frame}.BanzaiPlay.emit('complete', { score: 1500, time: 120 }); ${frame}.BanzaiPlay.emit('generic-canvas', 'level', { n: 2 }); true`);
	await sleep(1500);
	check("events fired on the page", await b.evaluate("window.__events"), [["generic-canvas", "score", { score: 10 }], ["generic-canvas", "complete", { score: 1500, time: 120 }], ["generic-canvas", "level", { n: 2 }]]);
	check("results screen", await b.evaluate("(() => { const r = document.querySelector('#banzaiplay-generic-canvas .banzaiplay-results'); return r ? [...r.querySelectorAll('dd')].map(d => d.textContent) : null; })()"), ["1,500", "2:00"]);
	await b.evaluate("window.BanzaiPlay.emit('generic-canvas', 'from-page', { ok: 1 }); true");
	await sleep(800);
	check("window.BanzaiPlay.emit() on the page", await b.evaluate("window.__events.length === 4 && window.__events[3][1]"), "from-page");
	check("results screen has the keyboard", await b.evaluate("document.activeElement.classList.contains('banzaiplay-results-again')"), true);
	await b.screenshot("feature-results");

	const hooked = JSON.parse(wp("option", "get", "bzpl_test_events", "--format=json"));
	check("bzpl/game_event fired in PHP, as the logged-in user", hooked.map((e) => [e.name, e.game, e.user > 0]), [["score", "generic-canvas", true], ["complete", "generic-canvas", true], ["level", "generic-canvas", true], ["from-page", "generic-canvas", true]]);

	// Play again: a new play.
	await b.click("#banzaiplay-generic-canvas .banzaiplay-results-again");
	check("Play again restarts", !!(await b.waitFor(playing("banzaiplay-generic-canvas") + " && !document.querySelector('#banzaiplay-generic-canvas .banzaiplay-results')")), true);
	await sleep(1500);

	// Leaving the page reports the time played.
	await b.go(`${base}/wp-admin/`);
	await sleep(1500);
	const rows = plays();
	check("two plays recorded", rows.map((r) => [r.game, r.device, r.post_id > 0]), [["generic-canvas", "desktop", true], ["generic-canvas", "desktop", true]]);
	check("first play completed with its score and events", [rows[0].completed, Number(rows[0].score), rows[0].events], ["1", 1500, "4"]);
	check("time played recorded for both", rows.map((r) => Number(r.seconds) >= 1), [true, true]);
	collect();

	// ---- Play one game at a time.
	await settings({ one_at_a_time: true });
	await b.go(setup.pages.features);
	await b.click("#banzaiplay-generic-canvas .banzaiplay-play-btn");
	await b.waitFor(playing("banzaiplay-generic-canvas"));
	await b.click("#banzaiplay-phaser-global .banzaiplay-play-btn");
	await b.waitFor(playing("banzaiplay-phaser-global"));
	check(
		"starting one game closes the other",
		await b.evaluate("[!!document.querySelector('#banzaiplay-generic-canvas iframe'), !document.querySelector('#banzaiplay-generic-canvas .banzaiplay-click-to-play').hidden, !!document.querySelector('#banzaiplay-phaser-global iframe')]"),
		[false, true, true]
	);
	collect();

	// ---- Games that start with the page load one after another.
	await settings({ one_at_a_time: false });
	const { identifier } = await b.cdp("Page.addScriptToEvaluateOnNewDocument", {
		source: "window.__log = []; ['frame', 'ready'].forEach((t) => document.addEventListener('banzaiplay:' + t, (e) => window.__log.push(t + ':' + e.detail.game)));",
	});
	await b.go(setup.pages.queue);
	await b.waitFor("window.__log.filter(e => e.startsWith('ready:')).length === 3", 30000);
	check("load one at a time, in page order", await b.evaluate("window.__log"), ["frame:unity-plain", "ready:unity-plain", "frame:godot", "ready:godot", "frame:generic-canvas", "ready:generic-canvas"]);
	await b.cdp("Page.removeScriptToEvaluateOnNewDocument", { identifier });
	collect();

	// ---- The gallery.
	await b.go(setup.pages.gallery);
	const gallery = await b.evaluate(`(() => {
		const card = document.querySelector('.banzaiplay-gallery-item[data-game="generic-canvas"]');
		return {
			cards: document.querySelectorAll('.banzaiplay-gallery-item').length,
			filters: [...document.querySelectorAll('.banzaiplay-gallery-filter')].map(b => b.textContent.trim()),
			cover: !!card.querySelector('img.banzaiplay-gallery-image'),
			tags: [...card.querySelectorAll('.banzaiplay-gallery-tag')].map(t => t.textContent),
			frames: document.querySelectorAll('iframe').length,
		};
	})()`);
	check("gallery shows every active game", gallery.cards, (n) => n >= 10);
	check("filters from the tags", gallery.filters, ["All", "Arcade", "Jam", "Puzzle"]);
	check("cover and tags on the card", [gallery.cover, gallery.tags], [true, ["Puzzle", "Jam"]]);
	check("nothing loads before a game is opened", gallery.frames, 0);
	await b.screenshot("feature-gallery", true);

	await b.click('.banzaiplay-gallery-filter[data-filter="puzzle"]');
	check("filter by tag", await b.evaluate("[...document.querySelectorAll('.banzaiplay-gallery-item')].filter(i => !i.hidden).map(i => i.dataset.game)"), ["generic-canvas"]);
	check("filter announced", await b.evaluate("document.querySelector('.banzaiplay-gallery-status').textContent"), "1 game");
	await b.click('.banzaiplay-gallery-filter[data-filter=""]');

	await b.click('.banzaiplay-gallery-item[data-game="generic-canvas"] .banzaiplay-gallery-name button');
	check("lightbox opens and plays", !!(await b.waitFor("document.querySelector('.banzaiplay-lightbox').open && !!document.querySelector('.banzaiplay-lightbox .banzaiplay-container.is-running iframe')")), true);
	check("lightbox game has the keyboard", await b.evaluate("document.activeElement && document.activeElement.tagName"), "IFRAME");
	await b.screenshot("feature-lightbox");
	await b.click(".banzaiplay-lightbox-close");
	await sleep(300);
	check(
		"closing removes the game and returns focus",
		await b.evaluate("[document.querySelector('.banzaiplay-lightbox').open, document.querySelectorAll('iframe').length, document.activeElement.closest('.banzaiplay-gallery-item')?.dataset.game]"),
		[false, 0, "generic-canvas"]
	);
	collect();

	// ---- Analytics.
	await b.go(`${base}/wp-admin/admin.php?page=banzaiplay-analytics`);
	const stats = await b.evaluate(`(() => {
		const row = [...document.querySelectorAll('.bzpl-analytics-table tbody tr')].find(r => r.querySelector('.row-title').textContent === 'generic-canvas');
		return [...row.querySelectorAll('td.num')].map(td => td.textContent.trim());
	})()`);
	check("Analytics: plays, completion and best score", [Number(stats[0]) >= 4, stats[2].endsWith("%"), stats[3]], [true, true, "1,500"]);
	check("Analytics chart drawn", await b.evaluate("document.querySelectorAll('.bzpl-chart rect').length"), 30);
	await b.screenshot("feature-analytics", true);
	collect();
} finally {
	b.close();
}

check("no failed requests or errors", problems, []);
process.exit(failed ? 1 : 0);

/**
 * The per-game Start setting, end to end: saved through the edit screen,
 * followed by shortcodes and blocks that don't choose, overridden by those
 * that do, never applied to the edit screen's preview, and never taking the
 * keyboard from the page.
 *
 * Needs the start-tests page: its players are, in order,
 *   1 [banzai-play game="generic-canvas"]                  → game's setting
 *   2 [banzai-play game="generic-canvas" autoplay="false"] → waits
 *   3 [banzai-play game="unity-plain" autoplay="true"]     → starts
 *   4 [banzai-play game="unity-plain"]                     → game's setting (Play)
 *   5 block, game's setting                                → game's setting
 *   6 block, start "click"                                 → waits
 *   7 block, start "load"                                  → starts
 *
 *   node tests/start.mjs [base-url]
 */
import { launch, sleep } from "./cdp.mjs";

const base = process.argv[2] || "http://localhost:8892";
const b = await launch();
let failed = 0;

function check(label, actual, expected) {
	const ok = JSON.stringify(actual) === JSON.stringify(expected);
	failed += ok ? 0 : 1;
	console.log((ok ? "PASS " : "FAIL ") + label + (ok ? "" : ` — got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`));
}

async function setStart(slug, value) {
	await b.go(`${base}/wp-admin/admin.php?page=banzaiplay&action=edit&game=${slug}`);
	await b.click(`input[name=start][value=${value}]`);
	await b.click(".bzpl-publish button[type=submit]");
	await sleep(500);
	await b.waitFor("document.readyState === 'complete' && !!document.querySelector('.notice-success')", 20000);

	return b.evaluate(`document.querySelector('input[name=start]:checked').value`);
}

// Which players started without a click, a moment after the page loaded.
async function started() {
	await b.go(`${base}/start-tests/`);
	await sleep(2500);

	return b.evaluate("[...document.querySelectorAll('.banzaiplay-container')].map(el => !!el.querySelector('iframe'))");
}

await b.login(base);

check("generic-canvas saved as 'load'", await setStart("generic-canvas", "load"), "load");
check("players that started (generic-canvas: load)", await started(), [true, false, true, false, true, false, true]);
check("the page keeps the keyboard", await b.evaluate("document.activeElement === document.body"), true);
check("overlay hidden on a started player", await b.evaluate("document.querySelector('.banzaiplay-container .banzaiplay-click-to-play').hidden"), true);
check("list shows it", await (async () => { await b.go(`${base}/wp-admin/admin.php?page=banzaiplay`); return b.evaluate("document.body.textContent.includes('starts with the page')"); })(), true);

await b.go(`${base}/wp-admin/admin.php?page=banzaiplay&action=edit&game=generic-canvas`);
await sleep(2000);
check("preview waits for Play", await b.evaluate("!document.querySelector('.bzpl-preview-card iframe') && !document.querySelector('.bzpl-preview-card .banzaiplay-click-to-play').hidden"), true);

check("generic-canvas saved as 'click'", await setStart("generic-canvas", "click"), "click");
check("players that started (generic-canvas: click)", await started(), [false, false, true, false, false, false, true]);

const problems = b.problems().filter((p) => !p.includes("favicon"));
check("no failed requests or errors", problems, []);

b.close();
process.exit(failed ? 1 : 0);

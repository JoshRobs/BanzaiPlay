/**
 * The free build stands on its own: run against the copy tests/make-free.ps1
 * makes, activated in place of BanzaiPlay, with BZPL_SIMULATE_PRO false (see
 * make-free.ps1). Then run browser.mjs and start.mjs too.
 *
 * Checks that no Pro screen or card is left, that the edit screen offers Pro
 * instead, that a game plays with "Powered by BanzaiPlay", and that the game
 * API in the frame is there and harmless.
 *
 *   node tests/free.mjs [base-url]
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

await b.login(base);

await b.go(`${base}/wp-admin/admin.php?page=banzaiplay&action=edit&game=generic-canvas`);
check(
	"menu has no Pro screens",
	await b.evaluate("[...document.querySelectorAll('#toplevel_page_banzaiplay .wp-submenu a')].map(a => a.textContent.trim()).filter(t => /Analytics|Settings|Activate/.test(t))"),
	[]
);
check(
	"edit screen offers Pro instead of its cards",
	await b.evaluate("[...document.querySelectorAll('.bzpl-card-pro')].map(c => [c.classList.contains('bzpl-upsell'), !!c.querySelector('a.button[href*=pricing]')])"),
	[[true, true], [true, true]]
);
check("no Pro fields", await b.evaluate("document.querySelectorAll('[name=bzpl_look], [name=bzpl_bridge], [name=bzpl_gallery], [name=bzpl_events]').length"), 0);
check("Pro screens are gone", await (async () => {
	await b.go(`${base}/wp-admin/admin.php?page=banzaiplay-settings`);
	return b.evaluate("!document.querySelector('.bzpl-settings')");
})(), true);
b.problems(); // WordPress answers the missing screen with an error page.

await b.go(`${base}/pro-tests/`);
check("Powered by BanzaiPlay on every player", await b.evaluate("document.querySelectorAll('.banzaiplay-container').length === document.querySelectorAll('.banzaiplay-branding').length"), true);
check("no Pro look", await b.evaluate("document.querySelectorAll('.has-backdrop, .has-logo, .banzaiplay-logo').length"), 0);
check("no Pro script or config", await b.evaluate("[typeof window.banzaiPlayPro, !!document.querySelector('script[src*=premium_only]'), JSON.parse(document.querySelector('.banzaiplay-container').dataset.banzaiplay).bridge === undefined]"), ["undefined", false, true]);

await b.click("#banzaiplay-generic-canvas .banzaiplay-play-btn");
check("game runs", !!(await b.waitFor("document.querySelector('#banzaiplay-generic-canvas').classList.contains('is-running')")), true);

const frame = "document.querySelector('#banzaiplay-generic-canvas iframe').contentWindow";
check(
	"game API is there and harmless",
	await b.evaluate(`(async () => { const api = ${frame}.BanzaiPlay; api.emit('complete', { score: 1 }); return [api.game, JSON.stringify(api.data), await api.user()]; })()`),
	["generic-canvas", "{}", null]
);
await sleep(500);
check("no results screen", await b.evaluate("!document.querySelector('.banzaiplay-results')"), true);
check("bad event names are refused", await b.evaluate(`(() => { try { ${frame}.BanzaiPlay.emit('no spaces'); return false; } catch (e) { return e instanceof ${frame}.TypeError; } })()`), true);

check("no failed requests or errors", b.problems().filter((p) => !p.includes("favicon")), []);

b.close();
process.exit(failed ? 1 : 0);

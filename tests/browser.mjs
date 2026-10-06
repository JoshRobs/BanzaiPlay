/**
 * Plays every game on the test page in headless Chrome or Edge, the way a
 * visitor would — real mouse clicks and key presses through the DevTools
 * protocol — and checks what the player and the game saw.
 *
 *   bash tests/e2e.sh                    # upload the fixtures
 *   node tests/browser.mjs               # http://localhost:8892/banzaiplay-tests/
 *   node tests/browser.mjs <page-url> [slug ...]
 *
 * For each game: Play is clicked; the player must reach "running" (or show
 * its error); keys must reach the game and not the page; Esc must give the
 * keyboard back to the page; and the game's own report (window.fixture) and
 * any failed request are printed. Screenshots go to tests/output/.
 */
import { writeFileSync } from "node:fs";
import { join } from "node:path";
import { launch, out, sleep } from "./cdp.mjs";

const pageUrl = process.argv[2] || "http://localhost:8892/banzaiplay-tests/";
const only = process.argv.slice(3);
const b = await launch();

await b.go(pageUrl);

// Count keys that reach the page itself.
await b.evaluate("window.pageKeys = []; window.addEventListener('keydown', e => window.pageKeys.push(e.key)); true");

const ids = await b.evaluate("[...document.querySelectorAll('.banzaiplay-container')].map(el => el.id)");
const frame = (sel, expression) => b.evaluate(`(() => { try { const w = document.querySelector('${sel} iframe').contentWindow; return ${expression}; } catch (e) { return 'n/a'; } })()`);
const results = [];

for (const id of ids) {
	const slug = id.replace(/^banzaiplay-/, "");

	if (only.length && !only.includes(slug)) {
		continue;
	}

	const sel = "#" + id;
	const row = { game: slug };

	b.problems();
	await b.click(`${sel} .banzaiplay-play-btn`);

	const started = Date.now();
	const state = await b.waitFor(`(() => { const el = document.querySelector('${sel}'); return el.classList.contains('is-running') ? 'running' : el.classList.contains('is-failed') ? 'failed' : ''; })()`, 90000);

	row.seconds = Math.round((Date.now() - started) / 100) / 10;

	row.state = state || "timeout: " + (await b.evaluate(`document.querySelector('${sel} .banzaiplay-progress-text').textContent`));

	if (state === "failed") {
		row.error = await b.evaluate(`document.querySelector('${sel} .banzaiplay-message-text').textContent`);
	}

	if (state === "running") {
		await b.waitFor(`(() => { try { const f = document.querySelector('${sel} iframe').contentWindow.fixture; return !f || f.ready; } catch (e) { return true; } })()`, 10000);
		await sleep(600);

		const before = await b.evaluate("window.pageKeys.length");
		await b.press("ArrowRight", "ArrowRight", 39);
		await b.press(" ", "Space", 32);
		row.keysInGame = await frame(sel, "w.fixture.keys.join(',')");
		row.keysOnPage = (await b.evaluate("window.pageKeys.length")) - before;
		row.focused = await b.evaluate(`document.querySelector('${sel}').classList.contains('is-focused')`);
		row.hint = await b.evaluate(`document.querySelector('${sel} .banzaiplay-hint').textContent`);
		await b.screenshot("play-" + slug);

		await b.press("Escape", "Escape", 27);
		const afterEsc = await b.evaluate("window.pageKeys.length");
		await b.press("ArrowRight", "ArrowRight", 39);
		row.releasedToPage = (await b.evaluate("window.pageKeys.length")) - afterEsc > 0;
		row.escSeenByGame = await frame(sel, "w.fixture.keys.includes('Escape')");
		row.fixture = await frame(sel, "JSON.stringify({ audio: w.fixture.audio && w.fixture.audio.state, chunk: w.fixture.chunk, runtime: w.fixture.runtime, godot: w.fixture.godot, config: w.fixture.config && { data: w.fixture.config.dataUrl, product: w.fixture.config.productName }, phaser: w.fixture.phaserLoaded })");
	} else {
		await b.screenshot("play-" + slug);
	}

	row.problems = b.problems();

	// One game at a time, as a visitor plays them: a real WebGL game left
	// running on software rendering slows every test after it.
	await b.evaluate(`document.querySelector('${sel}').banzaiPlay.unload(); true`);
	results.push(row);
	console.log(JSON.stringify(row));
}

writeFileSync(join(out, "browser.json"), JSON.stringify(results, null, 2));
b.close();

/**
 * Screenshots of the admin screens and the block editor, logged in as the
 * wp-env admin, with any failed request or JavaScript error printed.
 *
 *   node tests/screens.mjs [base-url]      # http://localhost:8892
 */
import { launch, sleep } from "./cdp.mjs";

const base = process.argv[2] || "http://localhost:8892";
const b = await launch({ width: 1400, height: 1000 });

await b.login(base);
b.problems();

const screens = {
	"admin-list": "/wp-admin/admin.php?page=banzaiplay",
	"admin-new": "/wp-admin/admin.php?page=banzaiplay-new",
	"admin-edit-unity": "/wp-admin/admin.php?page=banzaiplay&action=edit&game=unity-gzip",
	"admin-edit-dropout": "/wp-admin/admin.php?page=banzaiplay&action=edit&game=dropoutbuild",
	"admin-edit-nothing": "/wp-admin/admin.php?page=banzaiplay&action=edit&game=nothing",
};

for (const [name, path] of Object.entries(screens)) {
	await b.go(base + path);
	await sleep(400);
	await b.screenshot(name, true);
	console.log(name, JSON.stringify(b.problems()));
}

// The block editor: a post holding the block, opened for editing.
const postId = process.env.BLOCK_POST;

if (postId) {
	await b.go(`${base}/wp-admin/post.php?post=${postId}&action=edit`);
	await b.waitFor("document.querySelector('iframe[name=editor-canvas]') || document.querySelector('.banzaiplay-block-card, .wp-block-banzaiplay-game')", 30000);
	await sleep(3000);
	// Dismiss the welcome guide if it shows.
	await b.evaluate("document.querySelector('.components-modal__screen-overlay button[aria-label=Close]')?.click(); true");
	await sleep(500);
	await b.screenshot("block-editor");
	console.log("block-editor", JSON.stringify(b.problems()));
}

b.close();

/**
 * Uploads a zip through the Add New screen in a real browser, so admin.js's
 * XMLHttpRequest upload (progress, `ajax=1`, the JSON redirect) is what runs,
 * then prints the notices the edit screen shows.
 *
 *   node tests/upload.mjs [zip] [slug] [base-url]
 */
import { resolve, join } from "node:path";
import { launch, out, sleep } from "./cdp.mjs";

const zip = resolve(process.argv[2] || join(out, "generic-canvas.zip"));
const slug = process.argv[3] || "browser-upload";
const base = process.argv[4] || "http://localhost:8892";
const b = await launch();

await b.login(base);
await b.go(base + "/wp-admin/admin.php?page=banzaiplay-new");
b.problems();

await b.evaluate(`document.getElementById('bzpl-name').value = ${JSON.stringify(slug)}; document.getElementById('bzpl-slug').value = ${JSON.stringify(slug)}; true`);

const { root } = await b.cdp("DOM.getDocument", {});
const { nodeId } = await b.cdp("DOM.querySelector", { nodeId: root.nodeId, selector: "#bzpl-build" });
await b.cdp("DOM.setFileInputFiles", { nodeId, files: [zip] });
await b.evaluate("document.getElementById('bzpl-build').dispatchEvent(new Event('change')); true");

console.log("chosen:", await b.evaluate("document.querySelector('.bzpl-dropzone-file').textContent"));
await b.click(".bzpl-publish button[type=submit]");

const landed = await b.waitFor("location.search.includes('action=edit') && document.readyState === 'complete' && location.href", 60000);
await sleep(300);

console.log("landed:", landed);
console.log("notices:", JSON.stringify(await b.evaluate("[...document.querySelectorAll('.bzpl-wrap .notice:not(.inline) p')].map(p => p.textContent)")));
console.log("problems:", JSON.stringify(b.problems()));
await b.screenshot("upload-result");
b.close();

/**
 * A headless Chrome/Edge driven over the DevTools protocol, for the browser
 * tests. No dependencies: Node 22+ has WebSocket built in.
 */
import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

export const out = join(dirname(fileURLToPath(import.meta.url)), "output");
export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

mkdirSync(out, { recursive: true });

export async function launch({ width = 1280, height = 1000, port = 9333 } = {}) {
	const binary = [
		process.env.CHROME,
		"C:/Program Files/Google/Chrome/Application/chrome.exe",
		"C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
		"C:/Program Files/Microsoft/Edge/Application/msedge.exe",
		"/usr/bin/google-chrome",
		"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
	]
		.filter(Boolean)
		.find((path) => existsSync(path));

	if (!binary) {
		throw new Error("No Chrome or Edge found; set CHROME to its path.");
	}

	const browser = spawn(binary, [
		"--headless=new",
		`--remote-debugging-port=${port}`,
		`--user-data-dir=${mkdtempSync(join(tmpdir(), "bzpl-"))}`,
		`--window-size=${width},${height}`,
		"--no-first-run",
		"--no-default-browser-check",
		"about:blank",
	]);

	let version;

	for (let i = 0; i < 50 && !version; i++) {
		await sleep(200);
		version = await fetch(`http://127.0.0.1:${port}/json/version`).then((r) => r.json(), () => null);
	}

	const socket = new WebSocket(version.webSocketDebuggerUrl);
	await new Promise((resolve) => socket.addEventListener("open", resolve, { once: true }));

	let nextId = 1;
	const pending = new Map();
	const events = [];

	socket.addEventListener("message", (event) => {
		const message = JSON.parse(event.data);

		if (message.id && pending.has(message.id)) {
			const { resolve, reject } = pending.get(message.id);
			pending.delete(message.id);
			message.error ? reject(new Error(message.error.message)) : resolve(message.result);
		} else if (message.method) {
			events.push(message);
		}
	});

	function send(method, params = {}, sessionId) {
		const id = nextId++;
		socket.send(JSON.stringify({ id, method, params, sessionId }));
		return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
	}

	const { targetId } = await send("Target.createTarget", { url: "about:blank" });
	const { sessionId } = await send("Target.attachToTarget", { targetId, flatten: true });
	const cdp = (method, params) => send(method, params, sessionId);

	await cdp("Page.enable");
	await cdp("Runtime.enable");
	await cdp("Network.enable");
	await cdp("Emulation.setDeviceMetricsOverride", { width, height, deviceScaleFactor: 1, mobile: false });
	// A headless window is never "focused"; without this document.hasFocus() is false.
	await cdp("Emulation.setFocusEmulationEnabled", { enabled: true });

	async function evaluate(expression) {
		const result = await cdp("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });

		if (result.exceptionDetails) {
			throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
		}

		return result.result.value;
	}

	async function waitFor(expression, ms = 20000) {
		const end = Date.now() + ms;

		while (Date.now() < end) {
			const value = await evaluate(expression).catch(() => null);

			if (value) {
				return value;
			}

			await sleep(150);
		}

		return null;
	}

	async function go(url) {
		await cdp("Page.navigate", { url });
		await sleep(300);
		await waitFor("document.readyState === 'complete'");
	}

	async function screenshot(name, full = false) {
		const params = { format: "png" };

		if (full) {
			const { cssContentSize } = await cdp("Page.getLayoutMetrics");
			params.clip = { x: 0, y: 0, width: cssContentSize.width, height: Math.min(cssContentSize.height, 6000), scale: 1 };
			params.captureBeyondViewport = true;
		}

		const { data } = await cdp("Page.captureScreenshot", params);
		writeFileSync(join(out, name + ".png"), Buffer.from(data, "base64"));
	}

	async function click(selector) {
		const box = await evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); el.scrollIntoView({block: "center"}); const r = el.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`);

		for (const type of ["mouseMoved", "mousePressed", "mouseReleased"]) {
			await cdp("Input.dispatchMouseEvent", { type, x: box.x, y: box.y, button: "left", clickCount: 1 });
		}
	}

	async function press(key, code, keyCode, modifiers = 0) {
		await cdp("Input.dispatchKeyEvent", { type: "rawKeyDown", key, code, windowsVirtualKeyCode: keyCode, modifiers });
		await cdp("Input.dispatchKeyEvent", { type: "keyUp", key, code, windowsVirtualKeyCode: keyCode, modifiers });
		await sleep(120);
	}

	/** Requests that failed or answered with an error, and console errors, since the last call. */
	function problems() {
		const urls = new Map();
		const found = [];

		for (const event of events.splice(0)) {
			if (event.method === "Network.requestWillBeSent") {
				urls.set(event.params.requestId, event.params.request.url);
			} else if (event.method === "Network.responseReceived" && event.params.response.status >= 400) {
				found.push(event.params.response.status + " " + event.params.response.url);
			} else if (event.method === "Network.loadingFailed" && !event.params.canceled) {
				found.push(event.params.errorText + " " + (urls.get(event.params.requestId) || ""));
			} else if (event.method === "Runtime.exceptionThrown") {
				found.push("exception: " + (event.params.exceptionDetails.exception?.description || event.params.exceptionDetails.text).split("\n")[0]);
			} else if (event.method === "Runtime.consoleAPICalled" && event.params.type === "error") {
				found.push("console.error: " + event.params.args.map((a) => a.value ?? a.description).join(" ").slice(0, 200));
			}
		}

		return found;
	}

	async function login(base, user = "admin", pass = "password") {
		await go(base + "/wp-login.php");
		await evaluate(`document.getElementById('user_login').value = ${JSON.stringify(user)}; document.getElementById('user_pass').value = ${JSON.stringify(pass)}; document.getElementById('loginform').submit(); true`);
		await sleep(500);
		await waitFor("document.readyState === 'complete' && location.pathname.indexOf('wp-login') === -1");
	}

	function close() {
		socket.close();
		browser.kill();
	}

	return { cdp, evaluate, waitFor, go, screenshot, click, press, problems, login, close };
}

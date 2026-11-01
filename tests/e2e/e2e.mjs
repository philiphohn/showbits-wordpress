// The plugin in a real WordPress against a stand-in ShowBits (tests/e2e/run.sh starts WordPress and calls this).
// The stand-in answers like ShowBits does (docs/wordpress.md, docs/api.md): the consent page sends the browser back
// with a code, the exchange checks PKCE and the site, the library and its files need the token (files come from a
// "bucket" that must never see it), reports and the disconnect are recorded. A browser signs into WordPress, connects,
// imports from the library; then ShowBits pushes, WP-Cron imports, and the plugin reports back.

import { createHash, createHmac, randomBytes } from "node:crypto";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import https from "node:https";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const { chromium } = require("playwright");

const { E2E_HOST: HOST, E2E_WP_PORT: WP_PORT, E2E_API_PORT: API_PORT, E2E_CERT, E2E_KEY, E2E_MEDIA, E2E_SHOTS } = process.env;
const WP = `http://${HOST}:${WP_PORT}`;
const API = `https://${HOST}:${API_PORT}`;
const WS = { id: "5b0b0000-0000-4000-8001-000000000001", slug: "talk-am-abend", name: "Talk am Abend" };
const CLIP = "5b0b0000-0000-4000-8006-000000000004";
const CARD = "5b0b0000-0000-4000-8006-000000000009";
const LOCKED = "5b0b0000-0000-4000-8006-000000000005";
const PUSHED = "5b0b0000-0000-4000-8006-000000000006";

const failures = [];
const check = (ok, what) => {
  console.log(`${ok ? "ok  " : "FAIL"} ${what}`);
  if (!ok) failures.push(what);
};

// ---- The stand-in ShowBits

const state = { grants: new Map(), connection: null, results: [], leaves: 0, fileRequests: [], tokenOnFiles: false, apiCalls: [] };
const item = (id, kind, title, extra = {}) => ({
  id, kind, title, caption: kind === "quote" ? "Lokale Nachrichten werden wirklich geteilt." : "Ehrlich gesagt: Lokales wird geteilt.",
  hashtags: ["#Medien"], durationSec: kind === "quote" ? 0 : 36, format: kind === "quote" ? null : "9x16", status: "approved",
  releasedAt: "2026-11-01T10:00:00Z", episode: { id: "e142", title: "Folge 142 · Lokaljournalismus", language: "de" }, show: { id: "s1", name: "Talk am Abend" },
  quote: kind === "quote" ? { said: "Lokale Nachrichten werden wirklich geteilt.", name: "Lena Hoffmann" } : null, embargoUntil: null, version: "v1",
  files: kind === "quote" ? ["4x5", "9x16", "1x1"].map((variant) => ({ variant, contentType: "image/jpeg", width: 1080, height: 1080, size: null }))
    : [{ variant: "video", contentType: "video/mp4", width: 360, height: 640, size: null }, ...["16x9", "9x16"].map((variant) => ({ variant, contentType: "image/jpeg", width: 720, height: 1280, size: null }))],
  preview: null, ...extra,
});
const LIBRARY = [
  item(CLIP, "highlight", "Warum Lokaljournalismus gewinnt"),
  item(CARD, "quote", "Lokales wird geteilt"),
  item(LOCKED, "highlight", "Noch unter Embargo", { embargoUntil: "2027-01-01T06:00:00Z" }),
  item(PUSHED, "meme", "Der Moment danach"),
];
const problem = (res, status, reason) => {
  res.writeHead(status, { "content-type": "application/problem+json" });
  res.end(JSON.stringify({ type: `https://app.showbits.ai/problems/${reason}`, status, title: reason, reason }));
};
const json = (res, status, body) => {
  res.writeHead(status, { "content-type": "application/json" });
  res.end(JSON.stringify(body));
};
const read = (req) => new Promise((resolve) => {
  let body = "";
  req.on("data", (c) => (body += c));
  req.on("end", () => resolve(body));
});

const server = https.createServer({ cert: readFileSync(E2E_CERT), key: readFileSync(E2E_KEY) }, async (req, res) => {
  const url = new URL(req.url, API);
  const path = url.pathname;
  if (path === "/connect/wordpress") {
    // ShowBits' consent page, agreeing at once: a code for the plugin, back to its return address.
    const p = Object.fromEntries(url.searchParams);
    check(/^[A-Za-z0-9_-]{43}$/.test(p.challenge ?? "") && /^[A-Za-z0-9_-]{16,128}$/.test(p.state ?? ""), "the consent link carries a PKCE challenge and a state");
    check(p.site === WP && p.name === "Blog E2E" && p.return?.startsWith(`${WP}/wp-admin/admin-post.php?action=showbits_callback`), "the consent link names the site, its title and the way back");
    const code = randomBytes(32).toString("base64url");
    state.grants.set(code, { challenge: p.challenge, site: p.site });
    const back = new URL(p.return);
    back.searchParams.set("code", code);
    back.searchParams.set("state", p.state);
    res.writeHead(302, { location: back.toString() });
    return res.end();
  }
  if (path === "/api/wordpress/token" && req.method === "POST") {
    const body = JSON.parse(await read(req));
    const grant = state.grants.get(body.code);
    state.grants.delete(body.code);
    const pkce = grant && createHash("sha256").update(body.verifier).digest("base64url") === grant.challenge;
    check(!!pkce, "the exchange proves the verifier behind the challenge (PKCE)");
    check(body.site === grant?.site && body.push === `${WP}/wp-json/showbits/v1/push` && body.version === "1.0.0", "the exchange names the same site, its push address and the plugin's version");
    if (!pkce) return problem(res, 400, "invalid_grant");
    state.connection = { token: `sbk_${randomBytes(21).toString("hex")}`, secret: `whsec_${randomBytes(16).toString("hex")}`, siteId: "7a7a0000-0000-4000-8000-000000000001", push: body.push };
    return json(res, 200, { token: state.connection.token, pushSecret: state.connection.secret, siteId: state.connection.siteId, workspace: WS, expiresAt: "2027-11-02T00:00:00Z", api: `${API}/api` });
  }
  if (path.startsWith("/files/")) {
    // The bucket: the token must never come along.
    state.fileRequests.push(path);
    if (req.headers.authorization) state.tokenOnFiles = true;
    const name = path.slice("/files/".length);
    res.writeHead(200, { "content-type": name.endsWith(".mp4") ? "video/mp4" : "image/jpeg" });
    return res.end(readFileSync(`${E2E_MEDIA}/${name.endsWith(".mp4") ? "clip.mp4" : "picture.jpg"}`));
  }
  if (!path.startsWith("/api/v1/")) return problem(res, 404, "notFound");
  if (!state.connection || req.headers.authorization !== `Bearer ${state.connection.token}`) return problem(res, 401, "unauthorized");
  state.apiCalls.push(`${req.method} ${path}`);
  const library = path.match(/^\/api\/v1\/workspaces\/talk-am-abend\/library(?:\/([^/]+))?(\/file)?$/);
  if (library && !library[1]) return json(res, 200, { data: LIBRARY, nextCursor: null });
  if (library) {
    const found = LIBRARY.find((i) => i.id === library[1]);
    if (!found) return problem(res, 404, "notFound");
    if (!library[2]) return json(res, 200, found);
    if (found.embargoUntil) return problem(res, 409, "embargo");
    const variant = url.searchParams.get("variant");
    res.writeHead(302, { location: `${API}/files/${found.id}-${variant}.${variant === "video" ? "mp4" : "jpg"}?X-Amz-Signature=e2e` });
    return res.end();
  }
  if (path === "/api/v1/wordpress/site" && req.method === "GET") return json(res, 200, { id: state.connection.siteId, url: WP, name: "Blog E2E", workspace: WS, connectedAt: "2026-11-01T00:00:00Z", expiresAt: "2027-11-02T00:00:00Z" });
  if (path === "/api/v1/wordpress/site" && req.method === "DELETE") {
    state.leaves++;
    res.writeHead(204);
    return res.end();
  }
  const result = path.match(/^\/api\/v1\/wordpress\/pushes\/([^/]+)\/result$/);
  if (result && req.method === "POST") {
    state.results.push({ push: result[1], ...JSON.parse(await read(req)) });
    res.writeHead(204);
    return res.end();
  }
  return problem(res, 404, "notFound");
});
await new Promise((resolve) => server.listen(Number(API_PORT), "0.0.0.0", resolve));

// ---- Helpers: WP-CLI in its container, a push as worker/wordpress.ts sends it

const wp = (...args) => execFileSync("bash", [new URL("./wp.sh", import.meta.url).pathname, ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
/** WP-CLI where failing is an answer (no such option, plugin not active): null then. */
const wpMaybe = (...args) => {
  try {
    return wp(...args);
  } catch {
    return null;
  }
};

async function push(pushId, clipId, secret = state.connection.secret) {
  const body = JSON.stringify({ id: pushId, type: "clip.push", occurredAt: new Date().toISOString(), workspace: { id: WS.id, slug: WS.slug }, site: { id: state.connection.siteId }, object: { type: "clip", id: clipId, kind: "meme" } });
  const t = Math.floor(Date.now() / 1000);
  const response = await fetch(state.connection.push, {
    method: "POST",
    headers: { "content-type": "application/json", "showbits-event": "wordpress.push", "showbits-delivery": pushId, "showbits-signature": `t=${t},v1=${createHmac("sha256", secret).update(`${t}.${body}`).digest("hex")}` },
    body,
  });
  return { status: response.status, body: await response.json().catch(() => null) };
}

// ---- The run

const browser = await chromium.launch();
const page = await browser.newPage({ ignoreHTTPSErrors: true, locale: "de-DE" });
try {
  check(wpMaybe("plugin", "is-active", "showbits") !== null, "the plugin is active");
  check(wp("db", "query", "SHOW TABLES LIKE 'wp_showbits_imports'", "--skip-column-names") === "wp_showbits_imports", "activation made the import log's table");

  await page.goto(`${WP}/wp-login.php`);
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "e2e-password");
  await page.click("#wp-submit");
  await page.waitForURL(/wp-admin/);

  await page.goto(`${WP}/wp-admin/options-general.php?page=showbits-settings`);
  check(await page.locator("text=Mit ShowBits verbinden").count() > 0, "the settings page is in German, with the connect button");
  await page.fill("#showbits-app-url", API);
  await page.click("text=Mit ShowBits verbinden");
  await page.waitForURL(/showbits=connected/);
  check(await page.locator("text=Verbunden mit dem Arbeitsbereich „Talk am Abend“").count() > 0, "connected: the settings name the workspace");
  await page.screenshot({ path: `${E2E_SHOTS}/settings.png`, fullPage: true });
  const stored = wp("option", "get", "showbits_connection", "--format=json");
  check(!stored.includes(state.connection.token) && !stored.includes(state.connection.secret), "token and push secret are stored sealed, not in clear");

  await page.goto(`${WP}/wp-admin/upload.php?page=showbits`);
  await page.waitForSelector(".showbits-card");
  check(await page.locator(".showbits-card").count() === 4, "the library lists the released clips");
  const cardOf = (id) => page.locator(".showbits-card").filter({ has: page.locator(`#showbits-${id}`) });
  const locked = cardOf(LOCKED);
  check(await locked.locator("text=Embargo bis").count() > 0 && (await locked.locator("input[type=checkbox]").isDisabled()), "a clip under embargo says so and cannot be picked");
  await page.locator(`#showbits-${CLIP}`).check();
  await cardOf(CARD).locator("select").selectOption("1x1");
  await page.locator(`#showbits-${CARD}`).check();
  await page.click("text=Auswahl importieren (2)");
  await page.waitForSelector("text=Importiert: 2", { timeout: 60_000 });
  check(await page.locator(".showbits-badge.is-done").count() === 2, "both show as in the media library");
  await page.screenshot({ path: `${E2E_SHOTS}/library.png`, fullPage: true });

  const attachments = JSON.parse(wp("post", "list", "--post_type=attachment", "--fields=ID,post_title,post_mime_type,post_excerpt", "--format=json"));
  const video = attachments.find((a) => a.post_mime_type === "video/mp4");
  const poster = attachments.find((a) => a.post_title.endsWith("(Vorschaubild)"));
  const picture = attachments.find((a) => a.post_title === "Lokales wird geteilt");
  check(!!video && video.post_title === "Warum Lokaljournalismus gewinnt" && video.post_excerpt.startsWith("Ehrlich gesagt"), "the clip is a video attachment with its title and caption");
  check(!!poster && wp("post", "meta", "get", String(video?.ID), "_thumbnail_id") === String(poster?.ID), "its thumbnail is the video's poster image");
  check(!!picture && wp("post", "meta", "get", String(picture?.ID), "_wp_attachment_image_alt") === "„Lokale Nachrichten werden wirklich geteilt.“ – Lena Hoffmann", "the quote card's picture has the quote as alternative text");
  check(wp("post", "meta", "get", String(picture?.ID), "_showbits_variant") === "1x1" && wp("post", "meta", "get", String(video?.ID), "_showbits_clip_id") === CLIP, "attachments remember clip and variant");
  check(!state.tokenOnFiles && state.fileRequests.length === 3, "files came from the bucket without the token");

  // Importing the same version again keeps the one attachment.
  await page.reload();
  await page.waitForSelector(".showbits-card");
  check(await page.locator(".showbits-card").filter({ has: page.locator(`#showbits-${CLIP}`) }).locator(".showbits-badge.is-done").count() === 1, "the library knows what this site has already");

  // ShowBits pushes; WP-Cron imports and reports.
  const pushId = "9c9c0000-0000-4000-8000-000000000001";
  const first = await push(pushId, PUSHED);
  check(first.status === 202 && first.body?.accepted === true, "a signed push is accepted (202)");
  check((await push(pushId, PUSHED)).status === 202 && wp("db", "query", "SELECT COUNT(*) FROM wp_showbits_imports WHERE push_id = '" + pushId + "'", "--skip-column-names") === "1", "the same push again is taken once");
  check((await push("9c9c0000-0000-4000-8000-000000000002", PUSHED, "whsec_wrong")).status === 401, "a push with another secret is refused (401)");
  // WP-Cron may have run already (the push endpoint spawns it); whatever is still due runs now.
  wp("cron", "event", "run", "--due-now");
  for (let i = 0; i < 30 && !state.results.some((r) => r.push === pushId); i++) await new Promise((resolve) => setTimeout(resolve, 1000));
  const report = state.results.find((r) => r.push === pushId);
  check(report?.state === "imported" && /^https:\/\//.test(report.attachmentUrl ?? ""), "after WP-Cron the plugin reported the import with the attachment's https address");
  check(wp("db", "query", "SELECT status FROM wp_showbits_imports WHERE push_id = '" + pushId + "'", "--skip-column-names") === "imported", "the import log shows the push as imported");

  await page.goto(`${WP}/wp-admin/options-general.php?page=showbits-settings`);
  page.once("dialog", (dialog) => dialog.accept());
  await page.click("input[value=Trennen]");
  await page.waitForURL(/showbits=disconnected/);
  check(state.leaves === 1 && wpMaybe("option", "get", "showbits_connection") === null, "disconnecting ends the connection in ShowBits and forgets it here");
  check((await push("9c9c0000-0000-4000-8000-000000000003", PUSHED)).status === 410, "a push after disconnecting is answered 410 (ShowBits stops)");
} catch (error) {
  failures.push(String(error));
  console.error(error);
  await page.screenshot({ path: `${E2E_SHOTS}/failure.png`, fullPage: true }).catch(() => {});
} finally {
  await browser.close();
  server.close();
}
console.log(failures.length ? `\n${failures.length} check(s) failed` : "\nall checks passed");
process.exit(failures.length ? 1 : 0);

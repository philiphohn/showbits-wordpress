// The plugin in a real WordPress against a stand-in ShowBits (tests/e2e/run.sh starts WordPress and calls this).
// The stand-in answers like ShowBits does (docs/wordpress.md, lib/wordpress-door.ts): a site key made "in ShowBits" or
// handed back by the consent page (then with PKCE), the connect step that asks the site at its push address for proof
// of its pairing value, the library with the site's own key, imports that come as signed pushes with a ticket, file
// links only with that ticket (files from a "bucket" that must never see the key), reports and the disconnect. A
// browser signs into WordPress, connects both ways, imports from the library; then ShowBits pushes, WP-Cron imports,
// and the plugin reports back.

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
const PUSH_URL = `${WP}/wp-json/showbits/v1/push`;
const WS = { id: "5b0b0000-0000-4000-8001-000000000001", slug: "talk-am-abend", name: "Talk am Abend" };
const CLIP = "5b0b0000-0000-4000-8006-000000000004";
const CARD = "5b0b0000-0000-4000-8006-000000000009";
const LOCKED = "5b0b0000-0000-4000-8006-000000000005";
const PUSHED = "5b0b0000-0000-4000-8006-000000000006";
const SITE_ID = "7a7a0000-0000-4000-8000-000000000001";

const failures = [];
const check = (ok, what) => {
  console.log(`${ok ? "ok  " : "FAIL"} ${what}`);
  if (!ok) failures.push(what);
};

// ---- The stand-in ShowBits

const state = {
  keys: new Map(), // site key → { host, challenge? }
  connection: null, // { siteKey, secret }
  verifications: [], // what the site answered at its push address
  pushes: new Map(), // push id → { clip, ticket, variant }
  results: [],
  leaves: 0,
  fileRequests: [],
  keyOnFiles: false,
  wrongTickets: 0,
};
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
const json = (res, status, body) => {
  res.writeHead(status, { "content-type": "application/json", "cache-control": "no-store" });
  res.end(JSON.stringify(body));
};
const refuse = (res, status, error) => json(res, status, { error });
const read = (req) => new Promise((resolve) => {
  let body = "";
  req.on("data", (c) => (body += c));
  req.on("end", () => resolve(body));
});
const proofOf = (pairing, challenge) => createHmac("sha256", pairing).update(`showbits-site-verify:${challenge}`).digest("base64url");

/** A site key as an admin makes it on ShowBits' WordPress page. */
function makeKey(host) {
  const key = `sbsk_${randomBytes(32).toString("base64url")}`;
  state.keys.set(key, { host });
  return key;
}

/** A push as worker/wordpress.ts sends it: signed with the site's secret, with a fresh ticket for the clip's files. */
async function push(pushId, clipId, { secret = state.connection.secret, variant = null, from = "showbits" } = {}) {
  const ticket = randomBytes(32).toString("hex");
  state.pushes.set(pushId, { clip: clipId, ticket, variant });
  const body = JSON.stringify({ id: pushId, type: "clip.push", occurredAt: new Date().toISOString(), workspace: { id: WS.id, slug: WS.slug }, site: { id: SITE_ID }, object: { type: "clip", id: clipId, kind: LIBRARY.find((i) => i.id === clipId)?.kind ?? "highlight" }, from, variant, ticket });
  const t = Math.floor(Date.now() / 1000);
  const response = await fetch(PUSH_URL, {
    method: "POST",
    headers: { "content-type": "application/json", "showbits-event": "wordpress.push", "showbits-delivery": pushId, "showbits-signature": `t=${t},v1=${createHmac("sha256", secret).update(`${t}.${body}`).digest("hex")}` },
    body,
  });
  return { status: response.status, body: await response.json().catch(() => null) };
}

const server = https.createServer({ cert: readFileSync(E2E_CERT), key: readFileSync(E2E_KEY) }, async (req, res) => {
  const url = new URL(req.url, API);
  const path = url.pathname;
  if (path === "/connect/wordpress") {
    // ShowBits' consent page, agreeing at once: a site key bound to the PKCE challenge, back to the return address.
    const p = Object.fromEntries(url.searchParams);
    check(/^[A-Za-z0-9_-]{43}$/.test(p.challenge ?? "") && /^[A-Za-z0-9_-]{16,128}$/.test(p.state ?? ""), "the consent link carries a PKCE challenge and a state");
    check(p.site === WP && p.name === "Blog E2E" && p.return?.startsWith(`${WP}/wp-admin/admin-post.php?action=showbits_callback`), "the consent link names the site, its title and the way back");
    const key = makeKey(new URL(p.site).host);
    state.keys.get(key).challenge = p.challenge;
    const back = new URL(p.return);
    back.searchParams.set("code", key);
    back.searchParams.set("state", p.state);
    res.writeHead(302, { location: back.toString() });
    return res.end();
  }
  if (path.startsWith("/files/")) {
    // The bucket: the site's key must never come along.
    state.fileRequests.push(path);
    if (req.headers.authorization) state.keyOnFiles = true;
    const name = path.slice("/files/".length);
    res.writeHead(200, { "content-type": name.endsWith(".mp4") ? "video/mp4" : "image/jpeg" });
    return res.end(readFileSync(`${E2E_MEDIA}/${name.endsWith(".mp4") ? "clip.mp4" : "picture.jpg"}`));
  }
  if (path === "/api/wordpress/v1/connect" && req.method === "POST") {
    const body = JSON.parse(await read(req));
    const key = state.keys.get(body.key);
    state.keys.delete(body.key);
    if (!key) return refuse(res, 400, "key");
    if (key.challenge && createHash("sha256").update(body.verifier ?? "").digest("base64url") !== key.challenge) return refuse(res, 400, "key");
    check(body.site === WP && body.push === PUSH_URL && body.version === "1.0.0" && /^[A-Za-z0-9_-]{43}$/.test(body.pairing ?? ""), "connecting names the site, its push address, the plugin's version and a pairing value");
    if (new URL(body.site).host !== key.host) return refuse(res, 400, "otherSite");
    // The site proves it is the plugin that asked: the HMAC of a fresh challenge under the pairing value.
    const challenge = randomBytes(32).toString("base64url");
    const answer = await fetch(body.push, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "site.verify", challenge }) });
    const proof = answer.ok ? (await answer.json().catch(() => ({}))).proof : null;
    state.verifications.push({ status: answer.status, verified: proof === proofOf(body.pairing, challenge), consent: !!key.challenge });
    if (proof !== proofOf(body.pairing, challenge)) return refuse(res, 409, "notVerified");
    state.connection = { siteKey: `sbwp_${randomBytes(32).toString("base64url")}`, secret: `whsec_${randomBytes(16).toString("hex")}` };
    return json(res, 200, { siteKey: state.connection.siteKey, pushSecret: state.connection.secret, site: { id: SITE_ID }, workspace: WS });
  }
  if (!path.startsWith("/api/wordpress/v1/")) return refuse(res, 404, "notFound");
  if (!state.connection || req.headers.authorization !== `Bearer ${state.connection.siteKey}`) return refuse(res, 401, "unauthorized");
  const route = path.slice("/api/wordpress/v1".length);
  if (route === "/site" && req.method === "GET") return json(res, 200, { id: SITE_ID, url: WP, name: "Blog E2E", workspace: WS, connectedAt: "2026-11-01T00:00:00Z", pushesPerDay: 50, pushesToday: state.pushes.size });
  if (route === "/site" && req.method === "DELETE") {
    state.leaves++;
    state.connection = null;
    res.writeHead(204);
    return res.end();
  }
  if (route === "/library") return json(res, 200, { data: LIBRARY, nextCursor: null });
  const one = route.match(/^\/library\/([^/]+)$/);
  if (one) {
    const found = LIBRARY.find((i) => i.id === one[1]);
    return found ? json(res, 200, found) : refuse(res, 404, "notFound");
  }
  if (route === "/imports" && req.method === "POST") {
    // As ShowBits: queued as a push to the site, which the worker sends a moment later.
    const body = JSON.parse(await read(req));
    const found = LIBRARY.find((i) => i.id === body.clip);
    if (!found) return refuse(res, 404, "notFound");
    if (found.embargoUntil) return refuse(res, 409, "embargo");
    const pushId = `9d9d0000-0000-4000-8000-${String(state.pushes.size + 1).padStart(12, "0")}`;
    state.pushes.set(pushId, { clip: found.id, ticket: null, variant: body.variant ?? null });
    setTimeout(() => push(pushId, found.id, { variant: body.variant ?? null, from: "site" }).catch(() => {}), 300);
    return json(res, 202, { push: pushId });
  }
  const files = route.match(/^\/pushes\/([^/]+)\/files$/);
  if (files && req.method === "POST") {
    const body = JSON.parse(await read(req));
    const pushed = state.pushes.get(files[1]);
    if (!pushed || !pushed.ticket || pushed.ticket !== body.ticket) {
      state.wrongTickets++;
      return refuse(res, 403, "ticket");
    }
    return json(res, 200, { url: `${API}/files/${pushed.clip}-${body.variant}.${body.variant === "video" ? "mp4" : "jpg"}?X-Amz-Signature=e2e`, name: "file" });
  }
  const result = route.match(/^\/pushes\/([^/]+)\/result$/);
  if (result && req.method === "POST") {
    state.results.push({ push: result[1], ...JSON.parse(await read(req)) });
    res.writeHead(204);
    return res.end();
  }
  return refuse(res, 404, "notFound");
});
await new Promise((resolve) => server.listen(Number(API_PORT), "0.0.0.0", resolve));

// ---- Helpers: WP-CLI in its container

const wp = (...args) => execFileSync("bash", [new URL("./wp.sh", import.meta.url).pathname, ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
/** WP-CLI where failing is an answer (no such option, plugin not active): null then. */
const wpMaybe = (...args) => {
  try {
    return wp(...args);
  } catch {
    return null;
  }
};

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

  // A site key "made in ShowBits", pasted here.
  await page.goto(`${WP}/wp-admin/options-general.php?page=showbits-settings`);
  check(await page.locator("#showbits-site-key").count() === 1 && (await page.locator("text=Über ShowBits verbinden").count()) > 0, "the settings page is in German, with the site key field and the way through ShowBits");
  await page.fill("#showbits-app-url", API);
  await page.fill("#showbits-site-key", `sbsk_${"x".repeat(43)}`);
  await page.click(".showbits-connect input[type=submit]");
  await page.waitForURL(/showbits=connect_key/);
  check(await page.locator(".notice-error").count() > 0, "an unknown site key is refused with a notice");
  await page.fill("#showbits-site-key", makeKey("other.example"));
  await page.click(".showbits-connect input[type=submit]");
  await page.waitForURL(/showbits=connect_otherSite/);
  check(true, "a site key made for another address is refused");
  await page.fill("#showbits-site-key", makeKey(new URL(WP).host));
  await page.click(".showbits-connect input[type=submit]");
  await page.waitForURL(/showbits=connected/);
  check(await page.locator("text=Verbunden mit dem Arbeitsbereich „Talk am Abend“").count() > 0, "connected with a pasted site key: the settings name the workspace");
  check(state.verifications.at(-1)?.verified === true && state.verifications.at(-1)?.status === 200, "ShowBits checked the site at its push address and it proved its pairing value");
  check(!wpMaybe("transient", "get", "showbits_pairing"), "the pairing value is gone after connecting");
  const stored = wp("option", "get", "showbits_connection", "--format=json");
  check(!stored.includes(state.connection.siteKey) && !stored.includes(state.connection.secret), "the site's key and push secret are stored sealed, not in clear");
  await page.screenshot({ path: `${E2E_SHOTS}/settings.png`, fullPage: true });

  // A check while this site is not connecting gets no proof.
  const forged = await fetch(PUSH_URL, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "site.verify", challenge: "c".repeat(43) }) });
  check(forged.status === 404 && !(await forged.json()).proof, "without a connect attempt the site proves nothing");

  // Again, through ShowBits' consent page (PKCE): the same site renews in place.
  const before = state.connection.siteKey;
  await page.click(".showbits-consent input[type=submit]");
  await page.waitForURL(/showbits=connected/);
  check(state.verifications.at(-1)?.verified === true && state.verifications.at(-1)?.consent === true && state.connection.siteKey !== before, "connected again through ShowBits' consent page, with PKCE and the site check");

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
  await page.waitForSelector("text=Importiert: 2", { timeout: 90_000 });
  check(await page.locator(".showbits-badge.is-done").count() === 2, "both show as in the media library");
  await page.screenshot({ path: `${E2E_SHOTS}/library.png`, fullPage: true });

  const attachments = JSON.parse(wp("post", "list", "--post_type=attachment", "--fields=ID,post_title,post_mime_type,post_excerpt", "--format=json"));
  const video = attachments.find((a) => a.post_mime_type === "video/mp4");
  // Attachment texts are in the site's language (English here: WordPress' German language pack is not installed).
  const poster = attachments.find((a) => a.post_title.endsWith("(thumbnail)"));
  const picture = attachments.find((a) => a.post_title === "Lokales wird geteilt");
  check(!!video && video.post_title === "Warum Lokaljournalismus gewinnt" && video.post_excerpt.startsWith("Ehrlich gesagt"), "the clip is a video attachment with its title and caption");
  check(!!poster && wp("post", "meta", "get", String(video?.ID), "_thumbnail_id") === String(poster?.ID), "its thumbnail is the video's poster image");
  check(!!picture && wp("post", "meta", "get", String(picture?.ID), "_wp_attachment_image_alt") === "“Lokale Nachrichten werden wirklich geteilt.” – Lena Hoffmann", "the quote card's picture has the quote as alternative text, in the site's language");
  check(wp("post", "meta", "get", String(picture?.ID), "_showbits_variant") === "1x1" && wp("post", "meta", "get", String(video?.ID), "_showbits_clip_id") === CLIP, "attachments remember clip and variant");
  check(!state.keyOnFiles && state.fileRequests.length === 3 && state.wrongTickets === 0, "files came with each push's ticket, from the bucket without the key");
  check(state.results.filter((r) => r.state === "imported").length === 2, "both imports were reported to ShowBits");

  // Importing the same version again keeps the one attachment.
  await page.reload();
  await page.waitForSelector(".showbits-card");
  check(await cardOf(CLIP).locator(".showbits-badge.is-done").count() === 1, "the library knows what this site has already");

  // ShowBits pushes; WP-Cron imports and reports.
  const pushId = "9c9c0000-0000-4000-8000-000000000001";
  const first = await push(pushId, PUSHED);
  check(first.status === 202 && first.body?.accepted === true, "a signed push is accepted (202)");
  const again = await push(pushId, PUSHED);
  check(again.status === 202 && wp("db", "query", "SELECT COUNT(*) FROM wp_showbits_imports WHERE push_id = '" + pushId + "'", "--skip-column-names") === "1", "the same push again is taken once, with its new ticket");
  check((await push("9c9c0000-0000-4000-8000-000000000002", PUSHED, { secret: "whsec_wrong" })).status === 401, "a push with another secret is refused (401)");
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
  check((await push("9c9c0000-0000-4000-8000-000000000003", PUSHED, { secret: "whsec_any" })).status === 410, "a push after disconnecting is answered 410 (ShowBits stops)");
} catch (error) {
  failures.push(String(error));
  console.error(error);
  console.error(wpMaybe("db", "query", "SELECT id, source, push_id, status, error, ticket IS NOT NULL AS ticket FROM wp_showbits_imports"));
  console.error(JSON.stringify({ pushes: [...state.pushes.entries()], results: state.results, wrongTickets: state.wrongTickets, files: state.fileRequests }));
  await page.screenshot({ path: `${E2E_SHOTS}/failure.png`, fullPage: true }).catch(() => {});
} finally {
  await browser.close();
  server.close();
}
console.log(failures.length ? `\n${failures.length} check(s) failed` : "\nall checks passed");
process.exit(failures.length ? 1 : 0);

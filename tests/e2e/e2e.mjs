// The plugin in a real WordPress against a stand-in ShowBits (tests/e2e/run.sh starts WordPress and calls this).
// The stand-in answers like ShowBits does (docs/wordpress.md, lib/wordpress-door.ts): a site key made "in ShowBits" or
// handed back by the consent page (then with PKCE), the connect step that asks the site at its push address for proof
// of its pairing value, the library with the site's own key, imports that come as signed pushes with a ticket, file
// links only with that ticket (files from a "bucket" that must never see the key), reports and the disconnect. A
// browser signs into WordPress, connects both ways, imports from the library; then ShowBits pushes, WP-Cron imports,
// and the plugin reports back (a report lost on the way goes again); a download cut short fails; a clip another
// process imports waits its turn. Last, WordPress reads every translation as a language pack brings it, and the settings and the
// library are looked at in right-to-left and long-text languages.

import { createHash, createHmac, randomBytes } from "node:crypto";
import { execFile, execFileSync } from "node:child_process";
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
const LARGE = "5b0b0000-0000-4000-8006-000000000007";
const BUSY = "5b0b0000-0000-4000-8006-000000000008";
/** A quote card the editors animated (plugin 1.1.0): its pictures and its two videos. */
const ANIMATED = "5b0b0000-0000-4000-8006-00000000000a";
const SITE_ID = "7a7a0000-0000-4000-8000-000000000001";
// The version the plugin should name when it connects: its header (run.sh starts from wordpress/).
const VERSION = /^ \* Version:\s*(\S+)$/m.exec(readFileSync("showbits/showbits.php", "utf8"))[1];

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
  pairings: [], // the pairing values connect requests carried
  dropReports: new Set(), // pushes whose next report is lost on the way (503)
  droppedReports: 0,
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
  // Markup in ShowBits' texts must reach WordPress as plain text, even when an admin's library page imports it.
  item(CLIP, "highlight", "Warum <b>Lokaljournalismus</b> gewinnt", { caption: 'Ehrlich gesagt: <img src=x onerror="document.title=1">Lokales wird geteilt.' }),
  item(CARD, "quote", "Lokales wird geteilt"),
  item(LOCKED, "highlight", "Noch unter Embargo", { embargoUntil: "2027-01-01T06:00:00Z" }),
  item(PUSHED, "meme", "Der Moment danach"),
  item(LARGE, "highlight", "Zu groß für diese Website"),
  item(BUSY, "highlight", "Gerade woanders im Import"),
  item(ANIMATED, "quote", "Mut heißt anfangen", {
    files: [
      ...["4x5", "9x16", "1x1"].map((variant) => ({ variant, contentType: "image/jpeg", width: 1080, height: 1080, size: null })),
      ...["motion-9x16", "motion-4x5"].map((variant) => ({ variant, contentType: "video/mp4", width: 720, height: variant === "motion-9x16" ? 1280 : 900, size: null, durationSec: 9.7 })),
    ],
  }),
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
/** How ShowBits names a pairing value in its check (lib/wordpress-verify.ts pairingId). */
const pairingIdOf = (pairing) => createHash("sha256").update(pairing).digest("base64url");

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
  const send = () => {
    const t = Math.floor(Date.now() / 1000);
    return fetch(PUSH_URL, {
      method: "POST",
      headers: { "content-type": "application/json", "showbits-event": "wordpress.push", "showbits-delivery": pushId, "showbits-signature": `t=${t},v1=${createHmac("sha256", secret).update(`${t}.${body}`).digest("hex")}` },
      body,
    });
  };
  // A kept-alive connection Apache closes just then fails the request ("other side closed"): ShowBits delivers again,
  // and the plugin takes a push once.
  const response = await send().catch((error) => {
    if (error?.cause?.code !== "UND_ERR_SOCKET") throw error;
    return send();
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
    check(body.site === WP && body.push === PUSH_URL && body.version === VERSION && /^[A-Za-z0-9_-]{43}$/.test(body.pairing ?? ""), "connecting names the site, its push address, the plugin's version and a pairing value");
    state.pairings.push(body.pairing);
    if (new URL(body.site).host !== key.host) return refuse(res, 400, "otherSite");
    // The site proves it is the plugin that asked: the HMAC of a fresh challenge under the pairing value it names.
    const challenge = randomBytes(32).toString("base64url");
    const answer = await fetch(body.push, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "site.verify", challenge, pairingId: pairingIdOf(body.pairing) }) });
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
  // As ShowBits: a clip under embargo is not in the library at all, not even by its id.
  const listed = LIBRARY.filter((i) => !i.embargoUntil);
  if (route === "/library") return json(res, 200, { data: listed, nextCursor: null });
  const one = route.match(/^\/library\/([^/]+)$/);
  if (one) {
    const found = listed.find((i) => i.id === one[1]);
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
    const video = body.variant === "video" || String(body.variant).startsWith("motion-");
    return json(res, 200, { url: `${API}/files/${pushed.clip}-${body.variant}.${video ? "mp4" : "jpg"}?X-Amz-Signature=e2e`, name: "file" });
  }
  const result = route.match(/^\/pushes\/([^/]+)\/result$/);
  if (result && req.method === "POST") {
    const reported = JSON.parse(await read(req));
    if (state.dropReports.delete(result[1])) {
      state.droppedReports++;
      return refuse(res, 503, "failed");
    }
    state.results.push({ push: result[1], ...reported });
    res.writeHead(204);
    return res.end();
  }
  return refuse(res, 404, "notFound");
});
await new Promise((resolve) => server.listen(Number(API_PORT), "0.0.0.0", resolve));

// ---- Helpers: WP-CLI in its container

const wp = (...args) => execFileSync("bash", [new URL("./wp.sh", import.meta.url).pathname, ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
/** WP-CLI for what calls the stand-in, which answers from this process: execFileSync would block it until timeouts. */
const wpAsync = (...args) =>
  new Promise((resolve, reject) =>
    execFile("bash", [new URL("./wp.sh", import.meta.url).pathname, ...args], { encoding: "utf8" }, (error, stdout) => (error ? reject(error) : resolve(stdout.trim()))),
  );
/** One run of the import queue, as WP-Cron's would, right now (the next scheduled one may be minutes away). */
const runQueue = () => wpAsync("eval", "ShowBits\\WordPress\\Importer::process_queue();");
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
  check(state.pairings.length > 0 && !wpMaybe("transient", "get", `showbits_pairing_${pairingIdOf(state.pairings.at(-1))}`) && !wpMaybe("transient", "get", "showbits_pairing"), "the pairing value is gone after connecting");
  check((await page.locator("text=SHOWBITS_ENCRYPTION_KEY").count()) === 0 && wp("eval", "echo ShowBits\\WordPress\\Crypto::key_in_config() ? 'config' : 'database';") === "config", "with salts of its own in wp-config.php the settings page warns of nothing");
  const stored = wp("option", "get", "showbits_connection", "--format=json");
  check(!stored.includes(state.connection.siteKey) && !stored.includes(state.connection.secret), "the site's key and push secret are stored sealed, not in clear");
  await page.screenshot({ path: `${E2E_SHOTS}/settings.png`, fullPage: true });

  // A check while this site is not connecting gets no proof, not for an earlier attempt's value either.
  const forged = await fetch(PUSH_URL, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ type: "site.verify", challenge: "c".repeat(43), pairingId: pairingIdOf(state.pairings.at(-1)) }) });
  check(forged.status === 404 && !(await forged.json()).proof, "without a connect attempt the site proves nothing");

  // Again, through ShowBits' consent page (PKCE): the same site renews in place.
  const before = state.connection.siteKey;
  await page.click(".showbits-consent input[type=submit]");
  await page.waitForURL(/showbits=connected/);
  check(state.verifications.at(-1)?.verified === true && state.verifications.at(-1)?.consent === true && state.connection.siteKey !== before, "connected again through ShowBits' consent page, with PKCE and the site check");

  await page.goto(`${WP}/wp-admin/upload.php?page=showbits`);
  await page.waitForSelector(".showbits-card");
  check(await page.locator(".showbits-card").count() === LIBRARY.filter((i) => !i.embargoUntil).length, "the library lists the released clips");
  const cardOf = (id) => page.locator(".showbits-card").filter({ has: page.locator(`#showbits-${id}`) });
  check(await cardOf(LOCKED).count() === 0, "a clip under embargo is not in the library at all");
  check(await page.locator(".showbits-card", { hasText: "<b>Lokaljournalismus</b>" }).count() === 1 && await page.locator(".showbits-card b").count() === 0, "markup in a title shows as text in the library, never as markup");
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
  check(!!video && video.post_title === "Warum Lokaljournalismus gewinnt" && video.post_excerpt === "Ehrlich gesagt: Lokales wird geteilt.", "the clip is a video attachment with its title and caption, as plain text (imported by an admin)");
  check(!!poster && wp("post", "meta", "get", String(video?.ID), "_thumbnail_id") === String(poster?.ID), "its thumbnail is the video's poster image");
  check(!!picture && wp("post", "meta", "get", String(picture?.ID), "_wp_attachment_image_alt") === "“Lokale Nachrichten werden wirklich geteilt.” – Lena Hoffmann", "the quote card's picture has the quote as alternative text, in the site's language");
  check(wp("post", "meta", "get", String(picture?.ID), "_showbits_variant") === "1x1" && wp("post", "meta", "get", String(video?.ID), "_showbits_clip_id") === CLIP, "attachments remember clip and variant");
  check(!state.keyOnFiles && state.fileRequests.length === 3 && state.wrongTickets === 0, "files came with each push's ticket, from the bucket without the key");
  check(state.results.filter((r) => r.state === "imported").length === 2, "both imports were reported to ShowBits");

  // An animated card (1.1.0): offered as "Animated 9:16", imported as its video with the 9:16 picture as the poster.
  check(await cardOf(CARD).locator("option").count() === 3 && await cardOf(ANIMATED).locator("option").count() === 5, "only an animated card offers its animations as formats");
  await cardOf(ANIMATED).locator("select").selectOption("motion-9x16");
  await page.locator(`#showbits-${ANIMATED}`).check();
  await page.click("text=Auswahl importieren (1)");
  await page.waitForSelector("text=Importiert: 1", { timeout: 90_000 });
  const animated = JSON.parse(wp("post", "list", "--post_type=attachment", "--meta_key=_showbits_clip_id", `--meta_value=${ANIMATED}`, "--fields=ID,post_title,post_mime_type,post_excerpt", "--format=json"));
  const motion = animated.find((a) => a.post_mime_type === "video/mp4");
  const still = animated.find((a) => a.post_mime_type === "image/jpeg");
  check(!!motion && wp("post", "meta", "get", String(motion.ID), "_showbits_variant") === "motion-9x16" && motion.post_excerpt === "Lokale Nachrichten werden wirklich geteilt." && !wpMaybe("post", "meta", "get", String(motion.ID), "_wp_attachment_image_alt"), "the animated card is a video attachment with the quote as its caption");
  check(!!still && wp("post", "meta", "get", String(motion?.ID), "_thumbnail_id") === String(still.ID) && wp("post", "meta", "get", String(still.ID), "_showbits_variant") === "9x16", "the card's 9:16 picture is its poster image");

  // Importing the same version again keeps the one attachment.
  await page.reload();
  await page.waitForSelector(".showbits-card");
  check(await cardOf(CLIP).locator(".showbits-badge.is-done").count() === 1, "the library knows what this site has already");

  // ShowBits pushes; WP-Cron imports and reports. The first report is lost on the way (ShowBits answers 503).
  const pushId = "9c9c0000-0000-4000-8000-000000000001";
  const imports = (where) => wp("db", "query", `SELECT ${where} FROM wp_showbits_imports WHERE push_id = '${pushId}'`, "--skip-column-names");
  state.dropReports.add(pushId);
  const first = await push(pushId, PUSHED);
  check(first.status === 202 && first.body?.accepted === true, "a signed push is accepted (202)");
  const again = await push(pushId, PUSHED);
  check(again.status === 202 && wp("db", "query", "SELECT COUNT(*) FROM wp_showbits_imports WHERE push_id = '" + pushId + "'", "--skip-column-names") === "1", "the same push again is taken once, with its new ticket");
  check((await push("9c9c0000-0000-4000-8000-000000000002", PUSHED, { secret: "whsec_wrong" })).status === 401, "a push with another secret is refused (401)");
  // WP-Cron may have run already (the push endpoint spawns it); whatever is still due runs now.
  await wpAsync("cron", "event", "run", "--due-now");
  for (let i = 0; i < 30 && !state.droppedReports; i++) await new Promise((resolve) => setTimeout(resolve, 1000));
  check(state.droppedReports === 1 && imports("status") === "imported" && imports("report IS NOT NULL") === "1", "a report ShowBits did not take waits in the import log");
  // The next run (WP-Cron has one scheduled for it) sends it again.
  await runQueue();
  for (let i = 0; i < 30 && !state.results.some((r) => r.push === pushId); i++) await new Promise((resolve) => setTimeout(resolve, 1000));
  const report = state.results.find((r) => r.push === pushId);
  check(report?.state === "imported" && /^https:\/\//.test(report.attachmentUrl ?? ""), "after WP-Cron the plugin reported the import with the attachment's https address, the lost report again");
  check(imports("status") === "imported" && imports("report IS NULL") === "1", "the import log shows the push as imported and reported");

  // A download cut short at the site's size limit (WordPress still answers 200) is no import.
  wp("option", "update", "showbits_e2e_max_bytes", "200");
  const largeId = "9c9c0000-0000-4000-8000-000000000004";
  check((await push(largeId, LARGE)).status === 202, "a push of a clip larger than the site takes is accepted");
  await runQueue();
  for (let i = 0; i < 30 && !state.results.some((r) => r.push === largeId); i++) await new Promise((resolve) => setTimeout(resolve, 1000));
  wp("option", "delete", "showbits_e2e_max_bytes");
  check(state.results.find((r) => r.push === largeId)?.state === "failed" && state.results.find((r) => r.push === largeId)?.reason === "download", "a download cut short at the size limit is reported as failed (download), not imported");
  check(JSON.parse(wp("post", "list", "--post_type=attachment", "--meta_key=_showbits_clip_id", `--meta_value=${LARGE}`, "--format=json")).length === 0, "and nothing of it is in the media library");

  // Another process imports a clip right now (its lock): an import of the same clip waits its turn, then runs once.
  const lock = `showbits_lock_${createHash("md5").update(BUSY).digest("hex")}`;
  wp("option", "add", lock, String(Math.floor(Date.now() / 1000)), "--autoload=no");
  const busyId = "9c9c0000-0000-4000-8000-000000000005";
  const filesBefore = state.fileRequests.length;
  check((await push(busyId, BUSY)).status === 202, "a push of a clip another process imports is accepted");
  await runQueue();
  // (A run WP-Cron started meanwhile puts it back just the same, at once.)
  const waitingOf = () => wp("db", "query", `SELECT CONCAT(status, '/', attempts) FROM wp_showbits_imports WHERE push_id = '${busyId}'`, "--skip-column-names");
  let waiting = waitingOf();
  for (let i = 0; i < 10 && waiting !== "queued/0"; i++) {
    await new Promise((resolve) => setTimeout(resolve, 500));
    waiting = waitingOf();
  }
  check(waiting === "queued/0" && !state.results.some((r) => r.push === busyId) && state.fileRequests.length === filesBefore, "while the clip's lock is held the import waits in the queue, not failed and not counted");
  wp("option", "delete", lock);
  await runQueue();
  for (let i = 0; i < 30 && !state.results.some((r) => r.push === busyId); i++) await new Promise((resolve) => setTimeout(resolve, 1000));
  check(state.results.find((r) => r.push === busyId)?.state === "imported" && JSON.parse(wp("post", "list", "--post_type=attachment", "--meta_key=_showbits_clip_id", `--meta_value=${BUSY}`, "--format=json")).length === 2, "once the lock is gone it is imported once (video and poster)");
  check(!wpMaybe("option", "get", lock), "the import gave its lock back");

  // Translations: WordPress reads every catalog as a language pack brings it, as .l10n.php and as .mo, alike; right-to-left
  // locales lay out right to left, and long texts keep the settings and the library in shape, wide and narrow.
  const catalogs = JSON.parse(wp("showbits-e2e-translations"));
  const locales = Object.keys(catalogs.locales);
  const unread = locales.filter((locale) => !catalogs.locales[locale].complete || !catalogs.locales[locale].language || !catalogs.locales[locale].plurals);
  check(locales.length > 1 && unread.length === 0, `WordPress reads all ${locales.length} translations (${catalogs.strings} strings each) from .l10n.php and .mo alike, with their language and plural forms` + (unread.length ? `: not ${unread.join(", ")}` : ""));
  const overflows = () =>
    page.evaluate(() => {
      const wide = (el) => el.scrollWidth > el.clientWidth + 1;
      return wide(document.documentElement) || [...document.querySelectorAll(".showbits-card, .showbits-toolbar, .showbits-badges, .form-table td")].some(wide);
    });
  const RTL = ["ar", "fa_IR", "ckb", "ug_CN"];
  const misshapen = [];
  for (const locale of [...RTL, "fi", "el", "ru_RU", "ml_IN", "my_MM", "ja"]) {
    wp("user", "meta", "update", "admin", "locale", locale);
    const { Connection: heading, "Import selected": button } = catalogs.locales[locale].samples;
    for (const width of [1280, 600]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${WP}/wp-admin/options-general.php?page=showbits-settings`);
      const dir = await page.evaluate(() => document.documentElement.dir || "ltr");
      const settings = (await page.locator("h2", { hasText: heading }).count()) > 0 && dir === (RTL.includes(locale) ? "rtl" : "ltr") && !(await overflows());
      await page.screenshot({ path: `${E2E_SHOTS}/settings-${locale}-${width}.png`, fullPage: true });
      await page.goto(`${WP}/wp-admin/upload.php?page=showbits`);
      await page.waitForSelector(".showbits-card");
      const library = (await page.locator("button", { hasText: button }).count()) > 0 && !(await overflows());
      await page.screenshot({ path: `${E2E_SHOTS}/library-${locale}-${width}.png`, fullPage: true });
      if (!settings || !library) misshapen.push(`${locale} at ${width}px (${settings ? "" : "settings "}${library ? "" : "library"})`);
    }
  }
  check(misshapen.length === 0, "settings and library translated and in shape at 1280 and 600 px in Arabic, Persian, Kurdish, Uyghur (right to left), Finnish, Greek, Russian, Malayalam, Burmese and Japanese" + (misshapen.length ? `: not ${misshapen.join(", ")}` : ""));
  wp("user", "meta", "update", "admin", "locale", "de_DE");
  await page.setViewportSize({ width: 1280, height: 720 });

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

// Page events for a site that writes to the shared dataset meirdick_events (binding EVENTS).
// Copy of site-events/events.js in meirdick.com. Same blob order as meirdick.com's /api/event:
// what, outcome, detail, visit id, country, path, source, site.
// No IP, no cookie. The site is the host of this request, never a value from the browser.
const NAMES = new Set(["page_view"]);
const SAFE = /^[a-z0-9/_.-]{0,80}$/;
const SID = /^[a-z0-9]{12}$/;

const reply = (status) => new Response(null, { status, headers: { "Cache-Control": "no-store" } });

/** Answer POST /api/event. Returns 204, or 400 / 403 / 405 / 413. */
export async function handleEvent(req, env) {
	if (req.method !== "POST") return reply(405);
	const url = new URL(req.url);
	// same-origin only: the page's own script is the only caller
	const origin = req.headers.get("Origin");
	if (origin) {
		let host = "";
		try {
			host = new URL(origin).host;
		} catch {}
		if (host !== url.host) return reply(403);
	}
	const text = await req.text();
	if (text.length > 1000) return reply(413);
	let body;
	try {
		body = JSON.parse(text);
	} catch {
		return reply(400);
	}
	const str = (v) => (v === undefined || v === null ? "" : v);
	const name = body?.name;
	const detail = str(body?.detail);
	const source = str(body?.source);
	const path = str(body?.path);
	const session = str(body?.session);
	if (typeof name !== "string" || !NAMES.has(name)) return reply(400);
	for (const v of [detail, source, path]) if (typeof v !== "string" || !SAFE.test(v)) return reply(400);
	if (typeof session !== "string" || (session && !SID.test(session))) return reply(400);
	const country = typeof req.cf?.country === "string" && /^[A-Z0-9]{2}$/.test(req.cf.country) ? req.cf.country : "";
	const site = url.hostname.replace(/^www\./, "").slice(0, 80);
	try {
		env.EVENTS?.writeDataPoint({ indexes: [name], blobs: [name, "ok", detail, session, country, path, source, site], doubles: [1, 0, 0] });
	} catch (err) {
		console.error("event write failed", err.message);
	}
	return reply(204);
}

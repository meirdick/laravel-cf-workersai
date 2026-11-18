// Anonymous page view for the owner. Copy of site-events/t.js in meirdick.com.
// No cookie, no localStorage: a random visit id lives in this tab's sessionStorage.
// Nothing is sent when the browser asks not to be tracked (Global Privacy Control or Do Not Track).
(() => {
	const n = navigator;
	if (n.globalPrivacyControl === true || n.doNotTrack === "1" || window.doNotTrack === "1") return;
	const SAFE = /[^a-z0-9/_.-]+/g;
	const safe = (v) => v.toLowerCase().replace(SAFE, "-").slice(0, 80);
	const abc = "abcdefghijklmnopqrstuvwxyz0123456789";
	const make = () => {
		const out = [];
		while (out.length < 12) for (const b of crypto.getRandomValues(new Uint8Array(16))) if (b < 252 && out.length < 12) out.push(abc[b % 36]);
		return out.join("");
	};
	let sid = "";
	try {
		sid = sessionStorage.getItem("os:sid") || "";
		if (!/^[a-z0-9]{12}$/.test(sid)) sessionStorage.setItem("os:sid", (sid = make()));
	} catch {
		sid = make();
	}
	// the referring site's hostname, only when it is another site; never the full URL
	let source = "";
	try {
		const h = new URL(document.referrer).hostname.replace(/^www\./, "");
		if (h && h !== location.hostname.replace(/^www\./, "")) source = safe(h);
	} catch {}
	const body = JSON.stringify({ name: "page_view", detail: "", source, path: safe(location.pathname), session: sid });
	try {
		if (n.sendBeacon?.("/api/event", new Blob([body], { type: "text/plain;charset=UTF-8" }))) return;
	} catch {}
	fetch("/api/event", { method: "POST", body, keepalive: true, headers: { "Content-Type": "text/plain;charset=UTF-8" } }).catch(() => {});
})();

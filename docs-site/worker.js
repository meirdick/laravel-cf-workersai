// Worker for a static-assets site: answers /api/event, serves everything else from assets.
import { handleEvent } from "./events.js";

export default {
	fetch(req, env) {
		if (new URL(req.url).pathname === "/api/event") return handleEvent(req, env);
		return env.ASSETS.fetch(req);
	},
};

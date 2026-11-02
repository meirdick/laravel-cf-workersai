import { defineConfig } from "astro/config";
import tailwindcss from "@tailwindcss/vite";
import nimbus, {
  defineConfig as defineNimbusConfig,
} from "@cloudflare/nimbus-docs";
import { tableScroll } from "@cloudflare/nimbus-docs/markdown";

const nimbusConfig = defineNimbusConfig({
  // TODO: set this to the canonical origin of the home site (no trailing
  // slash) before deploy. Drives canonical URLs, OG image URLs, robots.txt,
  // sitemap and /llms.txt links. The docs are served under `base` below.
  site: "https://example.com",
  title: "laravel-cf-workersai",
  description:
    "Documentation for laravel-cf-workersai, a Laravel AI provider for Cloudflare Workers AI and AI Gateway.",
  locale: "en",
  // CHANGE_ME: your repository — shown as the GitHub link in the header.
  github: "https://github.com/meirdick/laravel-cf-workersai",
  socialImageAlt: "laravel-cf-workersai documentation",
  // The home page leads the sidebar; every other page joins it automatically.
  sidebar: {
    items: ["index", { autogenerate: { collection: "docs" } }],
  },
});

export default defineConfig({
  // nimbus:adapter
  output: "static",
  // The home site serves these docs at /docs.
  base: "/docs",
  // Tailwind v4 via its Vite plugin (the integration Astro recommends for
  // Tailwind v4 — replaces the PostCSS plugin, which doesn't build under
  // Astro 7's Vite 8 bundler).
  vite: {
    plugins: [tailwindcss()],
  },
  // Hover-prefetch link targets so full-page navigations feel instant without
  // a client-side router.
  prefetch: {
    prefetchAll: true,
    defaultStrategy: "hover",
  },
  integrations: [
    nimbus(nimbusConfig, {
      // Authoring rules are opt-in by design — your repo, your taste. The
      // two below are the load-bearing pair: frontmatter has to validate
      // against the content schema for the page to render properly, and
      // broken internal links are 404s for your readers. Add the others
      // (heading hierarchy, code-block language, style, etc.) when you're
      // ready to enforce them — see `nimbus-docs lint --help`.
      rules: {
        "nimbus/frontmatter-shape": "error",
        "nimbus/internal-link": "error",
        "nimbus/description-required": "error",
        "nimbus/code-block-lang": "error",
        "nimbus/heading-hierarchy": "error",
        "nimbus/single-h1": "error",
        "nimbus/bare-url": "error",
        "nimbus/duplicate-heading-text": "error",
      },
      markdown: {
        // Wrap wide tables so they scroll instead of overflowing the page
        // (styled by `.nb-table-scroll` in src/styles/prose.css).
        hastPlugins: [tableScroll()],
        // `## Install {#install}` gives a heading a fixed id. A heading that
        // ends in other `{…}` text, such as `{account_id}` in a .md file, is
        // read as attributes too; remove this to keep it as text.
        features: { headingAttributes: true },
      },
    }),
  ],
});

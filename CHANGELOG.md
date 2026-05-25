# Changelog

All notable changes to `meirdick/laravel-cf-workersai` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-05-25

Initial release. Native `laravel/ai` gateway for Cloudflare Workers AI with AI Gateway support.

### Added

- Native `laravel/ai` gateway for Cloudflare Workers AI (text, embeddings, structured output, tools, streaming).
- AI Gateway routing via `account_id` + `gateway` config.
- Direct Workers AI API routing via `account_id` only.
- Raw `url` escape hatch for `/compat` endpoints or custom Cloudflare paths.
- Reasoning content replay across tool-call turns via `providerContentBlocks`.
- Strict JSON schema opt-in via the `#[Strict]` attribute (laravel/ai v0.7+).
- Provider options pass-through for both text generation and embeddings.
- Sub-agent tools via `CanActAsTool` — dynamic names resolved through `ToolNameResolver`.
- Retry policy with exponential backoff for transient 5xx errors.
- Session affinity for cached AI Gateway responses.
- Image attachment support on vision-capable endpoints (`image/jpeg`, `image/png`, `image/gif`, `image/webp`).
- Provider keys: `workers-ai` (primary) and `workersai` (alias).

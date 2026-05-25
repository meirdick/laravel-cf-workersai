# Changelog

All notable changes to `meirdick/laravel-cf-workersai` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-05-25

Defuses two Cloudflare `/v1/chat/completions` footguns that quietly truncate structured output.

### Added

- **`default_max_tokens` provider config** (defaults to `4096`). Cloudflare's `/v1/chat/completions` defaults to **256 tokens** when `max_completion_tokens` is omitted — far too small for any non-trivial structured output. The package now sends `4096` by default. Set the value in your `config/ai.php` provider block to override, or `null` to fall back to Cloudflare's endpoint default. Per-call `#[MaxTokens]` (or `TextGenerationOptions::$maxTokens`) still takes precedence.
- **Truncation heuristic in finish-reason mapping.** Cloudflare misreports truncated completions as `finish_reason: "stop"` when it should be `"length"`. When `completion_tokens` is at or above the requested budget, the package now normalizes the reason to `FinishReason::Length` so laravel/ai's length-aware retry / continuation primitives can react. Applies to both the non-streaming response path and the streaming `StreamEnd` event.

### Changed

- `extractFinishReason()` signature gained two optional parameters (`?int $completionTokens`, `?int $requestedMaxTokens`). Subclasses overriding this protected hook may need to update.

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

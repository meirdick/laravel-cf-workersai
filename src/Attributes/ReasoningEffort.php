<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Attributes;

use Attribute;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Declares how much of its completion budget a reasoning model may spend on
 * its chain of thought before answering.
 *
 * Cloudflare's `/compat` endpoint accepts OpenAI's `reasoning_effort` field.
 * Left unset, a reasoning model decides for itself, and the models on Workers
 * AI decide badly: measured live on `@cf/openai/gpt-oss-120b` (2026-09-09),
 * one two-sentence question produced 1,564 characters of reasoning and took
 * 7.4s at `high`, 104 characters and 2.6s unset, and 20 characters and 1.4s
 * at `low`. The `low` answer was not worse. On `@cf/zai-org/glm-5.3-flash`
 * the same prompt spent 1,240 characters and 7.5s of reasoning unset versus
 * none at all and 5.1s at `low`.
 *
 * Cloudflare rejects any value outside low/medium/high with HTTP 400, so the
 * value is validated here rather than at the edge.
 *
 * Not every model honours the field. `@cf/openai/gpt-oss-120b` grades it
 * properly; `@cf/zai-org/glm-5.3-flash` treats any value as "off"; and
 * `@cf/zai-org/glm-4.7-flash` ignores it outright. See the README.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ReasoningEffort
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    /**
     * The values Cloudflare's /compat endpoint accepts. `none` and `minimal`
     * are OpenAI-only and return HTTP 400 from Workers AI (verified live).
     *
     * @var list<string>
     */
    public const ALLOWED = [self::LOW, self::MEDIUM, self::HIGH];

    public function __construct(public string $value)
    {
        self::validate($value);
    }

    /**
     * Throw if the effort level is not one Workers AI accepts.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $value): void
    {
        if (! in_array($value, self::ALLOWED, true)) {
            throw new InvalidArgumentException(sprintf(
                "Workers AI `reasoning_effort` must be one of: %s. Got '%s'. "
                .'Cloudflare rejects any other value with HTTP 400 — OpenAI\'s '
                .'`none` and `minimal` levels are not available on Workers AI.',
                implode(', ', self::ALLOWED),
                $value,
            ));
        }
    }

    /**
     * Resolve the effort level declared on an agent class, if any.
     */
    public static function resolveFrom(?object $agent): ?string
    {
        if (is_null($agent)) {
            return null;
        }

        $attributes = (new ReflectionClass($agent))->getAttributes(self::class);

        return $attributes === []
            ? null
            : $attributes[0]->newInstance()->value;
    }
}

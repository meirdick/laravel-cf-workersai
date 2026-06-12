<?php

use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Agents\ToolUsingAgent;

beforeEach(function () {
    config(['ai.providers.workersai' => [
        ...config('ai.providers.workersai'),
        'key' => 'test-key',
        'account_id' => 'test-account',
    ]]);
});

/**
 * Build a tool-call response whose assistant message carries reasoning under
 * the given field name. Kimi K2.5 emitted `reasoning_content`; K2.6 renamed it
 * to `reasoning`. Cloudflare's /compat layer has surfaced both across model
 * versions, so the parser must accept either and replay it on the follow-up.
 */
function toolCallResponseWithReasoning(string $field, string $reasoning): array
{
    $response = fakeWorkersAiToolCallResponse();
    $response['choices'][0]['message'][$field] = $reasoning;

    return $response;
}

function followUpAssistantMessage(): ?array
{
    $followUp = Http::recorded()[1][0]->data();

    return collect($followUp['messages'])->firstWhere('role', 'assistant');
}

test('K2.6 reasoning field is captured and replayed on the tool-call follow-up', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(toolCallResponseWithReasoning('reasoning', 'I should call the generator.')),
            Http::response(workersAiTextResponse('The number is 72019')),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'workersai');

    expect(followUpAssistantMessage()['reasoning_content'])->toBe('I should call the generator.');
});

test('K2.5 reasoning_content field is still captured and replayed', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(toolCallResponseWithReasoning('reasoning_content', 'Prior-version thinking.')),
            Http::response(workersAiTextResponse('The number is 72019')),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'workersai');

    expect(followUpAssistantMessage()['reasoning_content'])->toBe('Prior-version thinking.');
});

test('a response without reasoning omits reasoning_content from the follow-up', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::sequence([
            Http::response(fakeWorkersAiToolCallResponse()),
            Http::response(workersAiTextResponse('The number is 72019')),
        ]),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt('Generate a number', provider: 'workersai');

    expect(followUpAssistantMessage())->not->toHaveKey('reasoning_content');
});

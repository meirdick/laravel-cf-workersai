<?php

declare(strict_types=1);

namespace Meirdick\WorkersAi\Gateway;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;
use Meirdick\WorkersAi\Cloudflare\RunEndpoint;
use Meirdick\WorkersAi\Cloudflare\SdkCompat;
use Meirdick\WorkersAi\Gateway\Concerns\MapsFailoverErrors;
use Meirdick\WorkersAi\Gateway\Concerns\PostsWorkersAiRun;

/**
 * Reranking through `@cf/baai/bge-reranker-base`.
 *
 * The body is `{query, contexts: [{text}], top_k}`. The reply is
 * `{response: [{id, score}]}`, where `id` is the index of the document.
 */
class WorkersAiRerankingGateway implements RerankingGateway
{
    use MapsFailoverErrors;
    use PostsWorkersAiRun;

    public function __construct(protected Dispatcher $events)
    {
        //
    }

    /**
     * @param  array<int, string>  $documents
     * @param  array<string, mixed>  $providerOptions
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): RerankingResponse {
        $model = RunEndpoint::bareModel($model);
        $documents = array_values($documents);

        $body = array_merge($providerOptions, [
            'query' => $query,
            'contexts' => array_map(fn (string $text): array => ['text' => $text], $documents),
        ]);

        if ($limit !== null) {
            $body['top_k'] = $limit;
        }

        [$data] = $this->postRun($provider, $model, $timeout, $body, 'response');

        $results = [];

        foreach ($data['response'] as $ranked) {
            $index = (int) ($ranked['id'] ?? -1);

            if (array_key_exists($index, $documents)) {
                $results[] = new RankedDocument($index, $documents[$index], (float) ($ranked['score'] ?? 0));
            }
        }

        usort($results, fn (RankedDocument $a, RankedDocument $b): int => $b->score <=> $a->score);

        if ($limit !== null) {
            $results = array_slice($results, 0, $limit);
        }

        return SdkCompat::rerankingResponse($results, $data['usage'] ?? null, $provider->name(), $model);
    }
}

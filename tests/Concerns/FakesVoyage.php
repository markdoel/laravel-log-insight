<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Concerns;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MarkusDoell\LogInsight\Models\Cluster;
use MarkusDoell\LogInsight\Models\ClusterEmbedding;

trait FakesVoyage
{
    use CreatesClusters;

    protected function voyageUrl(): string
    {
        return 'https://ai.mongodb.com/v1/embeddings';
    }

    protected function enableSimilarity(): void
    {
        config()->set('log-insight.similarity.enabled', true);
        config()->set('log-insight.similarity.api_key', 'voyage-test-key');
    }

    /**
     * An embeddings response with one item per vector.
     *
     * @param  list<list<float>>  $vectors
     */
    protected function voyageResponse(array $vectors, string $model = 'voyage-4'): PromiseInterface
    {
        return Http::response([
            'object' => 'list',
            'data' => array_map(static fn (array $vector, int $index): array => [
                'object' => 'embedding',
                'embedding' => $vector,
                'index' => $index,
            ], $vectors, array_keys($vectors)),
            'model' => $model,
            'usage' => ['total_tokens' => 10],
        ]);
    }

    protected function voyageError(int $status, string $detail = 'Something went wrong'): PromiseInterface
    {
        return Http::response(['detail' => $detail], $status);
    }

    /**
     * Decoded JSON body of the n-th recorded request.
     *
     * @return array<array-key, mixed>
     */
    protected function sentVoyageBody(int $index = 0): array
    {
        $request = Http::recorded()[$index][0] ?? null;
        $this->assertInstanceOf(Request::class, $request);

        return $request->data();
    }

    /**
     * @param  list<float>  $vector
     */
    protected function embed(Cluster $cluster, array $vector, string $model = 'voyage-4'): ClusterEmbedding
    {
        return ClusterEmbedding::query()->create([
            'cluster_id' => $cluster->id,
            'model' => $model,
            'dimensions' => count($vector),
            'vector' => $vector,
        ]);
    }
}

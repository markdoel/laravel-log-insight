<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Concerns;

use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Models\Cluster;

trait FakesAnthropic
{
    /**
     * Decoded JSON body of the n-th recorded request.
     *
     * @return array<array-key, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        $request = Http::recorded()[$index][0] ?? null;
        $this->assertInstanceOf(Request::class, $request);

        return $request->data();
    }

    /**
     * Messages of the n-th recorded request.
     *
     * @return list<array{role: string, content: string}>
     */
    protected function sentMessages(int $index = 0): array
    {
        $messages = $this->sentBody($index)['messages'] ?? null;
        $this->assertIsArray($messages);

        $result = [];

        foreach ($messages as $message) {
            $this->assertIsArray($message);
            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;
            $this->assertIsString($role);
            $this->assertIsString($content);

            $result[] = ['role' => $role, 'content' => $content];
        }

        return $result;
    }

    protected function enableAnthropic(): void
    {
        config()->set('log-insight.llm.anthropic.api_key', 'test-key');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function analysisPayload(array $overrides = []): array
    {
        return [
            'root_cause' => 'The order was deleted before the customer opened the link.',
            'suggested_fix' => 'Use find() and return a 404 view when the order is missing.',
            'affected_files' => ['app/Http/Controllers/OrderController.php'],
            'severity' => 'medium',
            'confidence' => 0.82,
            ...$overrides,
        ];
    }

    /**
     * A Messages API response whose text block carries the given answer.
     *
     * @param  array<string, mixed>|string  $answer
     * @param  array<string, mixed>  $overrides
     */
    protected function anthropicResponse(array|string $answer, array $overrides = [], int $status = 200): PromiseInterface
    {
        return Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => is_string($answer) ? $answer : (string) json_encode($answer)],
            ],
            'stop_reason' => 'end_turn',
            'stop_details' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 350],
            ...$overrides,
        ], $status);
    }

    protected function anthropicError(int $status, string $type, string $message = 'Something went wrong'): PromiseInterface
    {
        return Http::response(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]], $status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeCluster(array $attributes = []): Cluster
    {
        return Cluster::query()->create([
            'fingerprint' => sha1((string) random_int(0, PHP_INT_MAX)),
            'type' => 'exception',
            'level' => LogLevel::Error,
            'exception_class' => 'Illuminate\Database\Eloquent\ModelNotFoundException',
            'message' => 'No query results for model [App\Models\Order] {n}',
            'sample_message' => 'No query results for model [App\Models\Order] 48213',
            'file' => '/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php',
            'line' => 780,
            'app_frame' => 'app/Http/Controllers/OrderController.php:24',
            'stack_trace' => "#0 /var/www/html/app/Http/Controllers/OrderController.php(24): Illuminate\\Database\\Eloquent\\Model::__callStatic('findOrFail', Array)\n#1 {main}",
            'occurrence_count' => 2,
            'first_seen_at' => CarbonImmutable::parse('2026-10-25 09:30:55'),
            'last_seen_at' => CarbonImmutable::parse('2026-10-25 12:30:12'),
            ...$attributes,
        ]);
    }
}

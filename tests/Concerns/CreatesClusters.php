<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Concerns;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Models\Cluster;

trait CreatesClusters
{
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeAnalysis(Cluster $cluster, array $attributes = []): Analysis
    {
        return $cluster->analyses()->create([
            'provider' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'root_cause' => 'The order was deleted before the customer opened the link.',
            'suggested_fix' => 'Use find() and return a 404 view when the order is missing.',
            'affected_files' => ['app/Http/Controllers/OrderController.php'],
            'severity' => Severity::Medium,
            'confidence' => 0.82,
            ...$attributes,
        ]);
    }
}

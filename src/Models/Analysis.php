<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MarkusDoell\LogInsight\Models\Concerns\UsesLogInsightConnection;

/**
 * The AI provider's assessment of a cluster.
 *
 * @property int $id
 * @property int $cluster_id
 * @property string $provider
 * @property string $model
 * @property string $root_cause
 * @property string $suggested_fix
 * @property list<string> $affected_files
 * @property string $severity
 * @property float $confidence
 * @property array<array-key, mixed>|null $raw_response
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Analysis extends Model
{
    use UsesLogInsightConnection;

    protected $table = 'log_insight_analyses';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Cluster, $this>
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affected_files' => 'array',
            'confidence' => 'float',
            'raw_response' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MarkusDoell\LogInsight\Models\Concerns\UsesLogInsightConnection;

/**
 * The embedding vector of a cluster, for one embedding model.
 *
 * @property int $id
 * @property int $cluster_id
 * @property string $model
 * @property int $dimensions
 * @property list<float> $vector
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ClusterEmbedding extends Model
{
    use UsesLogInsightConnection;

    protected $table = 'log_insight_cluster_embeddings';

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
            'dimensions' => 'integer',
            'vector' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

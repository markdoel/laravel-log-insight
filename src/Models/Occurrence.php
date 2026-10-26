<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Models\Concerns\UsesLogInsightConnection;

/**
 * A single log entry that belongs to a cluster.
 *
 * @property int $id
 * @property int $cluster_id
 * @property string $hash
 * @property CarbonImmutable $occurred_at
 * @property LogLevel $level
 * @property string $channel
 * @property string $message
 * @property CarbonImmutable|null $created_at
 */
class Occurrence extends Model
{
    use UsesLogInsightConnection;

    public const UPDATED_AT = null;

    protected $table = 'log_insight_occurrences';

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
            'level' => LogLevel::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}

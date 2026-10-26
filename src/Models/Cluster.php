<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use MarkusDoell\LogInsight\Data\LogLevel;
use MarkusDoell\LogInsight\Models\Concerns\UsesLogInsightConnection;

/**
 * A group of log entries that share a fingerprint.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $type exception|message
 * @property LogLevel $level
 * @property string|null $exception_class
 * @property string $message normalized message
 * @property string $sample_message original message of the most recent occurrence
 * @property string|null $file
 * @property int|null $line
 * @property string|null $app_frame
 * @property string|null $stack_trace
 * @property int $occurrence_count
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Cluster extends Model
{
    use UsesLogInsightConnection;

    protected $table = 'log_insight_clusters';

    protected $guarded = ['id'];

    /**
     * @return HasMany<Occurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }

    /**
     * @return HasMany<Analysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class);
    }

    /**
     * @return HasOne<Analysis, $this>
     */
    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(Analysis::class)->latestOfMany();
    }

    public function isException(): bool
    {
        return $this->type === 'exception';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => LogLevel::class,
            'line' => 'integer',
            'occurrence_count' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

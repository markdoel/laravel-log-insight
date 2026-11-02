<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Similarity\EmbeddingScheduler;

final class EmbedCommand extends Command
{
    protected $signature = 'log-insight:embed';

    protected $description = 'Queue an embedding for every stored cluster that has none, e.g. after enabling similar clusters';

    public function handle(EmbeddingScheduler $scheduler): int
    {
        if (! $scheduler->isEnabled()) {
            $this->components->error('Similar clusters are disabled. Set LOG_INSIGHT_SIMILARITY_ENABLED=true and VOYAGE_API_KEY.');

            return self::FAILURE;
        }

        try {
            $queued = $scheduler->scheduleMissing();
        } catch (LogInsightException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($queued === 0
            ? 'Every cluster has an embedding already.'
            : sprintf('Queued %d %s for embedding.', $queued, Str::plural('cluster', $queued)));

        return self::SUCCESS;
    }
}

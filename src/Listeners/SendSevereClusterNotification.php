<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Listeners;

use MarkusDoell\LogInsight\Events\AnalysisCompleted;
use MarkusDoell\LogInsight\Notifications\ClusterNotifier;

/**
 * Registered only when log-insight.notifications.enabled is true.
 */
final readonly class SendSevereClusterNotification
{
    public function __construct(
        private ClusterNotifier $notifier,
    ) {}

    public function handle(AnalysisCompleted $event): void
    {
        $this->notifier->notify($event->analysis);
    }
}

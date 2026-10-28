<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Notifications;

use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Contracts\Redactor;
use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Models\Cluster;
use Psr\Log\LoggerInterface;

/**
 * Sends one queued notification for an analysis at or above the minimum
 * severity, to the configured mail recipients and Slack route.
 */
final readonly class ClusterNotifier
{
    /**
     * @param  list<string>  $mailRecipients
     * @param  string|null  $slackRoute  webhook URL or channel name
     */
    public function __construct(
        private Dispatcher $notifications,
        private Redactor $redactor,
        private LoggerInterface $logger,
        private SlackChannelSupport $slack,
        private Severity $minSeverity,
        private array $mailRecipients,
        private ?string $slackRoute,
        private ?string $connection = null,
        private ?string $queue = null,
    ) {}

    /**
     * @return bool whether a notification was queued
     */
    public function notify(Analysis $analysis): bool
    {
        if ($analysis->severity->rank() < $this->minSeverity->rank()) {
            return false;
        }

        $cluster = $analysis->cluster;

        if (! $cluster instanceof Cluster) {
            return false;
        }

        $notifiable = new AnonymousNotifiable;
        $channels = [];

        if ($this->mailRecipients !== []) {
            $notifiable->route('mail', $this->mailRecipients);
            $channels[] = 'mail';
        }

        if ($this->slackRoute !== null) {
            if ($this->slack->installed) {
                $notifiable->route('slack', $this->slackRoute);
                $channels[] = 'slack';
            } else {
                $this->logger->warning(sprintf(
                    'Log Insight: a Slack route is configured, but %s is not installed, so no Slack notification was sent. Run "composer require %s" or remove log-insight.notifications.slack.',
                    SlackChannelSupport::PACKAGE,
                    SlackChannelSupport::PACKAGE,
                ));
            }
        }

        if ($channels === []) {
            return false;
        }

        $notification = new SevereClusterNotification($this->alert($cluster, $analysis), $channels);
        $notification->onConnection($this->connection)->onQueue($this->queue);

        $this->notifications->send($notifiable, $notification);

        return true;
    }

    private function alert(Cluster $cluster, Analysis $analysis): ClusterAlert
    {
        return new ClusterAlert(
            clusterId: $cluster->id,
            error: $cluster->exception_class ?? strtoupper($cluster->level->value),
            message: $this->redactor->redact($cluster->message),
            location: $cluster->app_frame === null ? null : $this->redactor->redact($cluster->app_frame),
            occurrences: $cluster->occurrence_count,
            severity: $analysis->severity,
            confidence: $analysis->confidence,
            rootCause: $this->redactor->redact($analysis->root_cause),
            suggestedFix: $this->redactor->redact($analysis->suggested_fix),
        );
    }
}

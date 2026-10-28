<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;
use MarkusDoell\LogInsight\Ai\Severity;

/**
 * Tells the team about a cluster whose analysis reached the configured severity.
 */
final class SevereClusterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Slack rejects section texts above 3000 characters. */
    private const SLACK_TEXT_LIMIT = 2900;

    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly ClusterAlert $alert,
        public readonly array $channels,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $alert = $this->alert;

        $mail = (new MailMessage)
            ->subject('[Log Insight] '.$alert->summary())
            ->greeting(sprintf('%s: %s', ucfirst($alert->severity->value), $alert->errorName()))
            ->line('**Error:** '.$alert->error)
            ->line('**Message:** '.$alert->message)
            ->line('**Location:** '.($alert->location ?? 'unknown'))
            ->line('**Occurrences:** '.$alert->occurrences)
            ->line(sprintf('**Severity:** %s (%d%% confidence)', $alert->severity->value, $alert->confidencePercent()))
            ->line('**Root cause:** '.$alert->rootCause)
            ->line('**Suggested fix:** '.$alert->suggestedFix)
            ->line(sprintf('Run `%s` for the full report.', $alert->reportCommand()));

        return $alert->severity === Severity::Critical ? $mail->error() : $mail;
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $alert = $this->alert;

        return (new SlackMessage)
            ->text($alert->summary())
            ->headerBlock(Str::limit($alert->summary(), 145))
            ->sectionBlock(function (SectionBlock $block) use ($alert): void {
                $block->field("*Error*\n".self::escape($alert->error))->markdown();
                $block->field("*Location*\n".self::escape($alert->location ?? 'unknown'))->markdown();
                $block->field("*Occurrences*\n".$alert->occurrences)->markdown();
                $block->field(sprintf("*Severity*\n%s (%d%% confidence)", $alert->severity->value, $alert->confidencePercent()))->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($alert): void {
                $block->text("*Message*\n".self::escape(Str::limit($alert->message, self::SLACK_TEXT_LIMIT)))->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($alert): void {
                $block->text("*Root cause*\n".self::escape(Str::limit($alert->rootCause, self::SLACK_TEXT_LIMIT)))->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) use ($alert): void {
                $block->text("*Suggested fix*\n".self::escape(Str::limit($alert->suggestedFix, self::SLACK_TEXT_LIMIT)))->markdown();
            })
            ->contextBlock(function (ContextBlock $block) use ($alert): void {
                $block->text(sprintf('Run `%s` for the full report.', $alert->reportCommand()))->markdown();
            });
    }

    /**
     * Slack treats &, < and > as control characters in mrkdwn.
     */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}

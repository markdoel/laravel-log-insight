<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Notifications;

use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Whether the optional laravel/slack-notification-channel package is installed.
 */
final readonly class SlackChannelSupport
{
    public const PACKAGE = 'laravel/slack-notification-channel';

    public function __construct(
        public bool $installed,
    ) {}

    public static function detect(): self
    {
        return new self(class_exists(SlackMessage::class));
    }
}

<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Events\AnalysisCompleted;
use MarkusDoell\LogInsight\Tests\Concerns\CreatesClusters;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class NotificationsDisabledTest extends TestCase
{
    use CreatesClusters;
    use RefreshDatabase;

    #[Test]
    public function notifications_are_disabled_by_default(): void
    {
        $this->assertFalse(config('log-insight.notifications.enabled'));
        $this->assertSame('critical', config('log-insight.notifications.min_severity'));
    }

    #[Test]
    public function it_sends_nothing_when_disabled(): void
    {
        Notification::fake();
        config()->set('log-insight.notifications.mail.to', ['ops@example.com']);

        $this->assertFalse(Event::hasListeners(AnalysisCompleted::class));

        event(new AnalysisCompleted($this->makeAnalysis($this->makeCluster(), ['severity' => Severity::Critical])));

        Notification::assertNothingSent();
    }
}

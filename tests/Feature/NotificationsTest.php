<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use MarkusDoell\LogInsight\Ai\ClusterAnalyzer;
use MarkusDoell\LogInsight\Ai\Severity;
use MarkusDoell\LogInsight\Events\AnalysisCompleted;
use MarkusDoell\LogInsight\Exceptions\LogInsightException;
use MarkusDoell\LogInsight\Models\Analysis;
use MarkusDoell\LogInsight\Notifications\ClusterNotifier;
use MarkusDoell\LogInsight\Notifications\SevereClusterNotification;
use MarkusDoell\LogInsight\Notifications\SlackChannelSupport;
use MarkusDoell\LogInsight\Tests\Concerns\FakesAnthropic;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;

final class NotificationsTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The listener is registered while the provider boots, so this cannot wait for setUp().
        $app['config']->set('log-insight.notifications.enabled', true);
        $app['config']->set('log-insight.notifications.mail.to', ['ops@example.com']);
    }

    #[Test]
    public function it_registers_the_listener_when_enabled(): void
    {
        $this->assertTrue(Event::hasListeners(AnalysisCompleted::class));
    }

    #[Test]
    public function it_sends_nothing_below_the_minimum_severity(): void
    {
        event(new AnalysisCompleted($this->criticalAnalysis(['severity' => Severity::High])));

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_uses_the_configured_minimum_severity(): void
    {
        config()->set('log-insight.notifications.min_severity', 'high');
        event(new AnalysisCompleted($this->criticalAnalysis(['severity' => Severity::Medium])));
        event(new AnalysisCompleted($this->criticalAnalysis(['severity' => Severity::High])));

        Notification::assertSentOnDemandTimes(SevereClusterNotification::class, 1);
    }

    #[Test]
    public function it_rejects_an_unknown_minimum_severity(): void
    {
        config()->set('log-insight.notifications.min_severity', 'urgent');
        $this->expectException(LogInsightException::class);
        $this->expectExceptionMessage('Unsupported notification severity [urgent]');

        resolve(ClusterNotifier::class);
    }

    #[Test]
    public function it_sends_nothing_without_recipients(): void
    {
        config()->set('log-insight.notifications.mail.to', []);

        event(new AnalysisCompleted($this->criticalAnalysis()));

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_mails_one_queued_notification_per_analysis(): void
    {
        $this->enableAnthropic();
        Http::fake(['*' => $this->anthropicResponse($this->analysisPayload(['severity' => 'critical']))]);

        resolve(ClusterAnalyzer::class)->analyze($this->makeCluster());

        Notification::assertSentOnDemandTimes(SevereClusterNotification::class, 1);
        Notification::assertSentOnDemand(
            SevereClusterNotification::class,
            function (SevereClusterNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                $this->assertInstanceOf(ShouldQueue::class, $notification);
                $this->assertSame(['mail'], $channels);
                $this->assertSame(['ops@example.com'], $notifiable->routes['mail']);

                return true;
            },
        );
    }

    #[Test]
    public function the_mail_contains_the_analysis(): void
    {
        $mail = $this->sentNotification()->toMail(new AnonymousNotifiable);
        $content = implode("\n", array_filter([$mail->greeting, ...$mail->introLines], is_string(...)));

        $this->assertSame('[Log Insight] Critical ModelNotFoundException in app/Http/Controllers/OrderController.php:24 (2×)', $mail->subject);
        $this->assertSame('error', $mail->level);
        $this->assertStringContainsString('Illuminate\Database\Eloquent\ModelNotFoundException', $content);
        $this->assertStringContainsString('No query results for model [App\Models\Order] {n}', $content);
        $this->assertStringContainsString('**Location:** app/Http/Controllers/OrderController.php:24', $content);
        $this->assertStringContainsString('**Occurrences:** 2', $content);
        $this->assertStringContainsString('critical (82% confidence)', $content);
        $this->assertStringContainsString('The order was deleted before the customer opened the link.', $content);
        $this->assertStringContainsString('Use find() and return a 404 view when the order is missing.', $content);
        $this->assertStringContainsString('php artisan log-insight:report --severity=critical', $content);
        $this->assertStringContainsString('OrderController.php:24', (string) $mail->render());
    }

    #[Test]
    public function it_redacts_the_content(): void
    {
        $analysis = $this->criticalAnalysis([
            'root_cause' => 'The login of jane@example.com failed with password=hunter2.',
        ]);

        event(new AnalysisCompleted($analysis));

        $rootCause = $this->sentNotification()->alert->rootCause;
        $this->assertStringNotContainsString('jane@example.com', $rootCause);
        $this->assertStringNotContainsString('hunter2', $rootCause);
    }

    #[Test]
    public function it_posts_to_a_slack_webhook(): void
    {
        config()->set('log-insight.notifications.mail.to', []);
        config()->set('log-insight.notifications.slack.webhook_url', 'https://hooks.slack.com/services/T000/B000/XXXX');
        event(new AnalysisCompleted($this->criticalAnalysis()));

        Notification::assertSentOnDemand(
            SevereClusterNotification::class,
            function (SevereClusterNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                $this->assertSame(['slack'], $channels);
                $this->assertSame('https://hooks.slack.com/services/T000/B000/XXXX', $notifiable->routes['slack']);

                return true;
            },
        );

        $message = (string) json_encode($this->sentNotification()->toSlack(new AnonymousNotifiable)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Critical ModelNotFoundException in app/Http/Controllers/OrderController.php:24 (2×)', $message);
        $this->assertStringContainsString('critical (82% confidence)', $message);
        $this->assertStringContainsString('No query results for model [App\\\\Models\\\\Order] {n}', $message);
        $this->assertStringContainsString('The order was deleted before the customer opened the link.', $message);
        $this->assertStringContainsString('Use find() and return a 404 view when the order is missing.', $message);
        $this->assertStringContainsString('php artisan log-insight:report --severity=critical', $message);
    }

    #[Test]
    public function it_sends_to_a_slack_channel_and_mail(): void
    {
        config()->set('log-insight.notifications.slack.channel', '#errors');
        event(new AnalysisCompleted($this->criticalAnalysis()));

        Notification::assertSentOnDemand(
            SevereClusterNotification::class,
            function (SevereClusterNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
                $this->assertSame(['mail', 'slack'], $channels);
                $this->assertSame('#errors', $notifiable->routes['slack']);

                return true;
            },
        );
    }

    #[Test]
    public function it_warns_and_still_mails_when_the_slack_package_is_missing(): void
    {
        config()->set('log-insight.notifications.slack.webhook_url', 'https://hooks.slack.com/services/T000/B000/XXXX');
        $this->instance(SlackChannelSupport::class, new SlackChannelSupport(installed: false));
        $logger = $this->instance(LoggerInterface::class, new class extends AbstractLogger
        {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                if ($level === LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        });

        event(new AnalysisCompleted($this->criticalAnalysis()));

        Notification::assertSentOnDemand(
            SevereClusterNotification::class,
            fn (SevereClusterNotification $notification, array $channels): bool => $channels === ['mail'],
        );
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('composer require laravel/slack-notification-channel', $logger->warnings[0]);
    }

    #[Test]
    public function it_detects_the_installed_slack_package(): void
    {
        $this->assertTrue(SlackChannelSupport::detect()->installed);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function criticalAnalysis(array $attributes = []): Analysis
    {
        return $this->makeAnalysis($this->makeCluster(), ['severity' => Severity::Critical, ...$attributes]);
    }

    private function sentNotification(): SevereClusterNotification
    {
        if (Notification::sentNotifications() === []) {
            event(new AnalysisCompleted($this->criticalAnalysis()));
        }

        $notification = Notification::sent(new AnonymousNotifiable, SevereClusterNotification::class)->first();
        $this->assertInstanceOf(SevereClusterNotification::class, $notification);

        return $notification;
    }
}

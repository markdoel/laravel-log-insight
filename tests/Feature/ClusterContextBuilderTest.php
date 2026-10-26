<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MarkusDoell\LogInsight\Ai\ClusterContextBuilder;
use MarkusDoell\LogInsight\Tests\Concerns\FakesAnthropic;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClusterContextBuilderTest extends TestCase
{
    use FakesAnthropic;
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('log-insight.context.base_path', __DIR__.'/../Fixtures/app');
        $app['config']->set('log-insight.context.lines', 3);
    }

    #[Test]
    public function it_describes_the_error(): void
    {
        $context = $this->builder()->build($this->makeCluster());

        $this->assertStringContainsString("<error>\ntype: exception\nlevel: error\n", $context);
        $this->assertStringContainsString('exception_class: Illuminate\Database\Eloquent\ModelNotFoundException', $context);
        $this->assertStringContainsString('message: No query results for model [App\Models\Order] 48213', $context);
        $this->assertStringContainsString('thrown_at: /var/www/html/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:780', $context);
        $this->assertStringContainsString('application_frame: app/Http/Controllers/OrderController.php:24', $context);
        $this->assertStringContainsString('occurrences: 2 between 2026-10-25 09:30:55 and 2026-10-25 12:30:12', $context);
        $this->assertStringContainsString('<stack_trace>', $context);
    }

    #[Test]
    public function it_adds_the_source_around_the_affected_line(): void
    {
        $context = $this->builder()->build($this->makeCluster());

        $this->assertStringContainsString(implode("\n", [
            '<source file="app/Http/Controllers/OrderController.php" line="24">',
            '  21 |     public function show(Request $request, int $id)',
            '  22 |     {',
            '  23 |         // Contact for questions: [REDACTED_EMAIL]',
            '> 24 |         $order = Order::findOrFail($id);',
            '  25 | ',
            '  26 |         $this->authorize(\'view\', $order);',
            '  27 | ',
            '</source>',
        ]), $context);
    }

    #[Test]
    public function it_clamps_the_excerpt_at_the_start_of_the_file(): void
    {
        $context = $this->builder()->build($this->makeCluster(['app_frame' => 'app/Http/Controllers/OrderController.php:1']));

        $this->assertStringContainsString("> 1 | <?php\n  2 | \n  3 | namespace App\\Http\\Controllers;\n  4 | \n</source>", $context);
    }

    #[Test]
    public function it_skips_the_source_when_it_is_not_available(): void
    {
        foreach ([
            'app/Http/Controllers/MissingController.php:10',
            'app/Http/Controllers/OrderController.php:999',
            'app/../../../../../../etc/hostname:1',
            'app/Http/Controllers/OrderController.php',
        ] as $frame) {
            $this->assertStringNotContainsString('<source', $this->builder()->build($this->makeCluster(['app_frame' => $frame])), $frame);
        }

        $this->assertStringNotContainsString('<source', $this->builder()->build($this->makeCluster(['app_frame' => null])));
    }

    #[Test]
    public function it_redacts_everything_it_sends(): void
    {
        $context = $this->builder()->build($this->makeCluster([
            'sample_message' => 'Login failed for jane@example.com from 203.0.113.9 with password=hunter2',
            'stack_trace' => "#0 /var/www/html/app/Services/AuthService.php(31): App\\Auth\\Guard->attempt('jane', 'hunter2')\n#1 {main}",
        ]));

        $this->assertStringNotContainsString('jane@example.com', $context);
        $this->assertStringNotContainsString('203.0.113.9', $context);
        $this->assertStringNotContainsString('hunter2', $context);
        $this->assertStringNotContainsString('support@example.com', $context);
        $this->assertStringContainsString("attempt('[REDACTED]', '[REDACTED]')", $context);
    }

    #[Test]
    public function it_shortens_long_stack_traces(): void
    {
        config()->set('log-insight.context.max_trace_lines', 5);
        $trace = implode("\n", array_map(static fn (int $i): string => "#{$i} /var/www/html/app/Foo.php({$i}): App\\Foo->bar()", range(0, 19)));

        $context = $this->builder()->build($this->makeCluster(['stack_trace' => $trace]));

        $this->assertStringContainsString("#4 /var/www/html/app/Foo.php(4): App\\Foo->bar()\n… 15 more lines omitted\n</stack_trace>", $context);
        $this->assertStringNotContainsString('#5 ', $context);
    }

    #[Test]
    public function plain_messages_have_no_exception_details(): void
    {
        $context = $this->builder()->build($this->makeCluster([
            'type' => 'message',
            'exception_class' => null,
            'file' => null,
            'line' => null,
            'app_frame' => null,
            'stack_trace' => null,
            'sample_message' => 'Payment gateway returned an unexpected response',
        ]));

        $this->assertStringNotContainsString('exception_class', $context);
        $this->assertStringNotContainsString('<stack_trace>', $context);
        $this->assertStringContainsString('type: message', $context);
    }

    private function builder(): ClusterContextBuilder
    {
        return resolve(ClusterContextBuilder::class);
    }
}

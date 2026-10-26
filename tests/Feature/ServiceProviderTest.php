<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use MarkusDoell\LogInsight\LogInsightServiceProvider;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function it_merges_the_package_config(): void
    {
        $this->assertSame('anthropic', config('log-insight.llm.driver'));
        $this->assertSame(15, config('log-insight.context.lines'));
    }

    #[Test]
    public function it_registers_the_config_for_publishing(): void
    {
        $paths = ServiceProvider::pathsToPublish(LogInsightServiceProvider::class, 'log-insight-config');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('config/log-insight.php', (string) array_key_first($paths));
    }
}

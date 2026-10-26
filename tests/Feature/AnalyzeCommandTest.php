<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Feature;

use Carbon\CarbonImmutable;
use MarkusDoell\LogInsight\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class AnalyzeCommandTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/logs';

    #[Test]
    public function it_analyzes_a_single_file(): void
    {
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log'])
            ->expectsOutputToContain('Parsed 8 entries (4 with exception) from 1 file.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_analyzes_the_configured_log_directory(): void
    {
        config()->set('log-insight.logs.path', self::FIXTURES.'/daily');

        $this->command('log-insight:analyze')
            ->expectsOutputToContain('Parsed 5 entries (2 with exception) from 2 files.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_limits_the_analysis_with_since(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00'));
        config()->set('log-insight.logs.path', self::FIXTURES.'/daily');

        $this->command('log-insight:analyze', ['--since' => '6h'])
            ->expectsOutputToContain('Parsed 3 entries (1 with exception) from 1 file.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_announces_a_dry_run(): void
    {
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
    }

    #[Test]
    public function it_reports_when_no_files_are_found(): void
    {
        config()->set('log-insight.logs.path', self::FIXTURES.'/does-not-exist');

        $this->command('log-insight:analyze')
            ->expectsOutputToContain('No log files found.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_for_a_missing_file(): void
    {
        $this->command('log-insight:analyze', ['--file' => '/nowhere/laravel.log'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_for_an_invalid_since_value(): void
    {
        $this->command('log-insight:analyze', ['--file' => self::FIXTURES.'/single/laravel.log', '--since' => 'yesterday'])
            ->expectsOutputToContain('Invalid --since value')
            ->assertFailed();
    }
}

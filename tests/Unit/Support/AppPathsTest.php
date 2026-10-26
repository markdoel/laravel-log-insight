<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Support;

use MarkusDoell\LogInsight\Support\AppPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppPathsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function paths(): iterable
    {
        yield 'inside base path' => ['/var/www/html/app/Models/Order.php', 'app/Models/Order.php'];
        yield 'routes' => ['/var/www/html/routes/web.php', 'routes/web.php'];
        yield 'vendor inside base path' => ['/var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php', null];
        yield 'not an app directory' => ['/var/www/html/public/index.php', null];
        yield 'other server' => ['/home/forge/shop.example.com/app/Jobs/SendInvoiceJob.php', 'app/Jobs/SendInvoiceJob.php'];
        yield 'release directory' => ['/home/deploy/releases/20261025093000/app/Services/Billing.php', 'app/Services/Billing.php'];
        yield 'docker app root' => ['/app/app/Models/User.php', 'app/Models/User.php'];
        yield 'vendor package src' => ['/srv/vendor/acme/sdk/src/Client.php', null];
        yield 'windows path' => ['C:\\projects\\shop\\app\\Http\\Kernel.php', 'app/Http/Kernel.php'];
        yield 'unrelated file' => ['/usr/share/php/Foo.php', null];
    }

    #[Test]
    #[DataProvider('paths')]
    public function it_resolves_application_relative_paths(string $file, ?string $expected): void
    {
        $paths = new AppPaths('/var/www/html', ['app/', 'routes', '/src/']);

        $this->assertSame($expected, $paths->relative($file));
        $this->assertSame($expected !== null, $paths->isApplicationFile($file));
    }

    #[Test]
    public function it_builds_local_paths(): void
    {
        $paths = new AppPaths('/var/www/html/', ['app/']);

        $this->assertSame('/var/www/html/app/Models/Order.php', $paths->local('app/Models/Order.php'));
    }
}

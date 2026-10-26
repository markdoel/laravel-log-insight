<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Fingerprinting;

use MarkusDoell\LogInsight\Fingerprinting\MessageNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'numeric ids' => [
            'No query results for model [App\Models\Order] 48213',
            'No query results for model [App\Models\Order] {n}',
        ];
        yield 'several numbers and decimals' => [
            'Order 17 has 3 items, total 129.95',
            'Order {n} has {n} items, total {n}',
        ];
        yield 'uuid' => [
            'Subscription 9b2f3c1e-4d5a-4e6f-8a7b-0c1d2e3f4a5b not found',
            'Subscription {uuid} not found',
        ];
        yield 'ulid' => [
            'Job 01HZY3K8F7Q2V5N9W6X4T1R0SM failed',
            'Job {ulid} failed',
        ];
        yield 'email' => [
            "Duplicate entry 'jane.doe+test@example.co.uk' for key 'users_email_unique'",
            "Duplicate entry '{email}' for key 'users_email_unique'",
        ];
        yield 'ip with port' => [
            'Could not connect to cache server at 10.0.0.12:6379',
            'Could not connect to cache server at {ip}',
        ];
        yield 'datetime and date' => [
            'Booking for 2026-10-25 10:44:09 overlaps with 2026-10-26',
            'Booking for {datetime} overlaps with {date}',
        ];
        yield 'iso datetime' => [
            'Token expired at 2026-10-24T22:15:03.123456+02:00',
            'Token expired at {datetime}',
        ];
        yield 'url' => [
            'Request to https://api.example.com/v1/orders/48213?expand=items failed',
            'Request to {url} failed',
        ];
        yield 'hex hash' => [
            'Cache key 5d41402abc4b2a76b9719d911017c592 missing',
            'Cache key {hash} missing',
        ];
        yield 'random token' => [
            'Invalid signature for token Zq3xT9vB2mN7kL4pR8sW1yC6',
            'Invalid signature for token {token}',
        ];
        yield 'paths with variable segments' => [
            'File /var/www/storage/app/uploads/2026/10/invoice-a8f3k29x.pdf not readable',
            'File /var/www/storage/app/uploads/{n}/{n}/{var}.pdf not readable',
        ];
        yield 'release directories' => [
            'Failed to open /home/deploy/releases/20261025093000/bootstrap/cache/config.php',
            'Failed to open /home/deploy/releases/{n}/bootstrap/cache/config.php',
        ];
        yield 'php upload temp files' => [
            'Unable to move /tmp/phpA1b2C3 to storage',
            'Unable to move /tmp/{var} to storage',
        ];
        yield 'query exception sql and bindings' => [
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry (Connection: mysql, SQL: insert into `users` (`email`) values (jane@example.com))',
            'SQLSTATE[{n}]: Integrity constraint violation: {n} Duplicate entry (SQL)',
        ];
        yield 'query exception with host details' => [
            'SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: shop, SQL: select * from `orders` where `id` = 5)',
            'SQLSTATE[HY000] [{n}] Connection refused (SQL)',
        ];
        yield 'whitespace and newlines' => [
            "Import failed for row 12\n  Expected column \"sku\"",
            'Import failed for row {n} Expected column "sku"',
        ];
        yield 'identifiers with digits stay' => [
            'Call to undefined method App\Services\Oauth2Client::refresh()',
            'Call to undefined method App\Services\Oauth2Client::refresh()',
        ];
    }

    #[Test]
    #[DataProvider('messages')]
    public function it_replaces_variable_parts(string $message, string $expected): void
    {
        $this->assertSame($expected, (new MessageNormalizer)->normalize($message));
    }

    #[Test]
    public function it_produces_the_same_text_for_the_same_error_with_different_data(): void
    {
        $normalizer = new MessageNormalizer;

        $this->assertSame(
            $normalizer->normalize('User 17 (anna@example.com) exceeded quota on 2026-10-24'),
            $normalizer->normalize('User 2048 (bob@example.org) exceeded quota on 2026-10-25'),
        );
    }

    #[Test]
    public function it_limits_the_length(): void
    {
        $this->assertSame(1000, mb_strlen((new MessageNormalizer)->normalize(str_repeat('x', 5000))));
    }
}

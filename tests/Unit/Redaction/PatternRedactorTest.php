<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Redaction;

use MarkusDoell\LogInsight\Exceptions\InvalidRedactionPattern;
use MarkusDoell\LogInsight\Redaction\PatternRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        // Passwords and other key-value secrets
        yield 'password query string' => ['POST /login?user=jane&password=hunter2&remember=1', 'POST /login?user=jane&password=[REDACTED]&remember=1'];
        yield 'json password' => ['{"email_verified":true,"password":"s3cr3t \"quoted\""}', '{"email_verified":true,"password":"[REDACTED]"}'];
        yield 'escaped json in exception context' => ['{\"user\":\"jane\",\"api_key\":\"abc123\"}', '{\"user\":\"jane\",\"api_key\":\"[REDACTED]\"}'];
        yield 'php array export' => ["['username' => 'jane', 'password' => 'hunter2']", "['username' => 'jane', 'password' => '[REDACTED]']"];
        yield 'yaml style' => ['client_secret: 8f7d6c5b4a', 'client_secret: [REDACTED]'];
        yield 'env style' => ['DB_PASSWORD=Sup3rS3cret!', 'DB_PASSWORD=[REDACTED]'];
        yield 'prefixed keys' => ['stripe_webhook_secret=whsec_123 and x-api-key: k-987', 'stripe_webhook_secret=[REDACTED] and x-api-key: [REDACTED]'];
        yield 'token in sql' => ['select * from `users` where `remember_token` = abc123def limit 1', 'select * from `users` where `remember_token` = [REDACTED] limit 1'];
        yield 'case insensitive' => ['Password: hunter2', 'Password: [REDACTED]'];
        yield 'cookie and session id' => ['cookie=laravel_session%3Dabc; session_id=xyz', 'cookie=[REDACTED]; session_id=[REDACTED]'];

        // Headers and bearer tokens
        yield 'authorization bearer' => ['Authorization: Bearer abc.def.ghi', 'Authorization: Bearer [REDACTED]'];
        yield 'authorization basic' => ['Authorization: Basic dXNlcjpwYXNz', 'Authorization: Basic [REDACTED]'];
        yield 'authorization raw' => ['authorization=raw-token-value', 'authorization=[REDACTED]'];
        yield 'json authorization' => ['{"authorization":"Basic dXNlcjpwYXNz"}', '{"authorization":"[REDACTED]"}'];
        yield 'bearer alone' => ['Request failed with Bearer 9f8e7d6c5b4a3210', 'Request failed with Bearer [REDACTED]'];

        // Well-known token formats
        yield 'jwt' => ['jwt eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U rejected', 'jwt [REDACTED_JWT] rejected'];
        yield 'aws access key' => ['Using AKIAIOSFODNN7EXAMPLE for upload', 'Using [REDACTED_TOKEN] for upload'];
        yield 'github token' => ['ghp_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789 expired', '[REDACTED_TOKEN] expired'];
        yield 'github fine grained token' => ['github_pat_11ABCDEFG0123456789_abcdefghijklmnop', '[REDACTED_TOKEN]'];
        yield 'slack token' => ['xoxb-1234567890-abcdefghij', '[REDACTED_TOKEN]'];
        yield 'stripe key' => ['Stripe error for sk_live_51HxYzAbCdEfGhIjKl', 'Stripe error for [REDACTED_TOKEN]'];
        yield 'anthropic key' => ['x sk-ant-api03-AbCdEfGhIjKlMnOpQrStUv-123 y', 'x [REDACTED_TOKEN] y'];
        yield 'openai key' => ['key sk-proj-AbCdEfGhIjKlMnOpQrStUvWx was revoked', 'key [REDACTED_TOKEN] was revoked'];
        yield 'google api key' => ['AIzaSyA-1234567890abcdefghijklmnopqrstu', '[REDACTED_TOKEN]'];
        yield 'laravel app key' => ['Key base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10= invalid', 'Key [REDACTED_TOKEN] invalid'];
        yield 'private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEow\nIBAAK\n-----END RSA PRIVATE KEY-----", '[REDACTED_PRIVATE_KEY]'];

        // Credentials in URLs
        yield 'database url' => ['mysql://forge:s3cret@db.internal:3306/shop', 'mysql://[REDACTED]@db.internal:3306/shop'];
        yield 'redis url' => ['Connection to rediss://default:p4ss@cache.example.net failed', 'Connection to rediss://[REDACTED]@cache.example.net failed'];

        // Personal data
        yield 'email' => ["Duplicate entry 'jane.doe+news@example.co.uk' for key 'users_email_unique'", "Duplicate entry '[REDACTED_EMAIL]' for key 'users_email_unique'"];
        yield 'several emails' => ['From anna@example.com to bob@mail.example.org', 'From [REDACTED_EMAIL] to [REDACTED_EMAIL]'];
        yield 'ipv4' => ['Too many attempts from 203.0.113.42', 'Too many attempts from [REDACTED_IP]'];
        yield 'ipv4 with port' => ['Could not connect to 10.0.0.12:6379', 'Could not connect to [REDACTED_IP]:6379'];
        yield 'ipv6 full' => ['Client 2001:0db8:85a3:0000:0000:8a2e:0370:7334 blocked', 'Client [REDACTED_IP] blocked'];
        yield 'ipv6 compressed' => ['Request from 2001:db8::1 and ::1', 'Request from [REDACTED_IP] and [REDACTED_IP]'];
    }

    #[Test]
    #[DataProvider('secrets')]
    public function it_redacts_secrets_and_personal_data(string $input, string $expected): void
    {
        $this->assertSame($expected, (new PatternRedactor)->redact($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmlessText(): iterable
    {
        yield 'class names with static calls' => ['App\Support\Token::create() called Feed::add() and Cache::get()'];
        yield 'times and dates' => ['Job started at 2026-10-25 10:44:09 and ran until 10:45:00'];
        yield 'version numbers' => ['Running PHP 8.3.6 with Laravel 13.38.0'];
        yield 'file paths and lines' => ['/var/www/html/app/Http/Controllers/OrderController.php:42'];
        yield 'words containing keywords' => ['The author passed the passport to the tokenizer'];
        yield 'keyword without value' => ['Invalid token provided, password reset required'];
        yield 'comparison' => ['if ($token == $expected)'];
        yield 'mac address' => ['Device 00:1a:2b:3c:4d:5e connected'];
        yield 'uuid' => ['Order 9b2f3c1e-4d5a-4e6f-8a7b-0c1d2e3f4a5b failed'];
        yield 'invalid ipv4' => ['Value 999.1.1.1 out of range'];
    }

    #[Test]
    #[DataProvider('harmlessText')]
    public function it_leaves_harmless_text_alone(string $input): void
    {
        $this->assertSame($input, (new PatternRedactor)->redact($input));
    }

    #[Test]
    public function it_redacts_string_arguments_in_stack_frames(): void
    {
        $trace = implode("\n", [
            "#0 /var/www/html/app/Services/AuthService.php(31): App\\Auth\\Guard->attempt('jane', 'hunter2', true)",
            "#1 [internal function]: App\\Http\\Controllers\\LoginController->store(Object(Illuminate\\Http\\Request), 'it\\'s secret')",
            '#2 /var/www/html/vendor/laravel/framework/src/Illuminate/Routing/Route.php(212): App\Foo->bar(42, NULL, Array)',
            '#3 {main}',
            "Message 'quoted' outside frames stays",
        ]);

        $this->assertSame(implode("\n", [
            "#0 /var/www/html/app/Services/AuthService.php(31): App\\Auth\\Guard->attempt('[REDACTED]', '[REDACTED]', true)",
            "#1 [internal function]: App\\Http\\Controllers\\LoginController->store(Object(Illuminate\\Http\\Request), '[REDACTED]')",
            '#2 /var/www/html/vendor/laravel/framework/src/Illuminate/Routing/Route.php(212): App\Foo->bar(42, NULL, Array)',
            '#3 {main}',
            "Message 'quoted' outside frames stays",
        ]), (new PatternRedactor)->redact($trace));
    }

    #[Test]
    public function trace_argument_redaction_can_be_disabled(): void
    {
        $frame = "#0 /var/www/html/app/Models/Order.php(12): App\\Models\\Order::findOrFail('48213')";

        $this->assertSame($frame, (new PatternRedactor(redactTraceArguments: false))->redact($frame));
    }

    #[Test]
    public function secrets_in_stack_frames_are_redacted_even_without_argument_redaction(): void
    {
        $frame = "#0 /var/www/html/app/Mail/Notifier.php(12): App\\Mail\\Notifier->send('jane@example.com')";

        $this->assertSame(
            "#0 /var/www/html/app/Mail/Notifier.php(12): App\\Mail\\Notifier->send('[REDACTED_EMAIL]')",
            (new PatternRedactor(redactTraceArguments: false))->redact($frame),
        );
    }

    #[Test]
    public function it_applies_custom_patterns(): void
    {
        $redactor = new PatternRedactor([
            '/\bORD-\d{6}\b/' => '[ORDER]',
            '/customer #\d+/i' => 'customer #[ID]',
        ]);

        $this->assertSame(
            'Order [ORDER] of customer #[ID] failed for [REDACTED_EMAIL]',
            $redactor->redact('Order ORD-123456 of Customer #991 failed for jane@example.com'),
        );
    }

    #[Test]
    public function it_rejects_invalid_custom_patterns(): void
    {
        $this->expectException(InvalidRedactionPattern::class);
        $this->expectExceptionMessage('[/unclosed(/]');

        new PatternRedactor(['/unclosed(/' => 'x']);
    }

    #[Test]
    public function it_redacts_several_secrets_in_one_text(): void
    {
        $input = "Login failed for jane@example.com from 198.51.100.7\npassword=hunter2 token: abc123";

        $this->assertSame(
            "Login failed for [REDACTED_EMAIL] from [REDACTED_IP]\npassword=[REDACTED] token: [REDACTED]",
            (new PatternRedactor)->redact($input),
        );
    }

    #[Test]
    public function redacting_twice_changes_nothing(): void
    {
        $redactor = new PatternRedactor;
        $once = $redactor->redact('Authorization: Bearer abc password=x mysql://u:p@h jane@example.com 10.0.0.1');

        $this->assertSame($once, $redactor->redact($once));
    }

    #[Test]
    public function it_handles_empty_text(): void
    {
        $this->assertSame('', (new PatternRedactor)->redact(''));
    }
}

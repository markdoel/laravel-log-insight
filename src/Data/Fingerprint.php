<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Data;

final readonly class Fingerprint
{
    public const TYPE_EXCEPTION = 'exception';

    public const TYPE_MESSAGE = 'message';

    /**
     * @param  string  $hash  stable identifier of the cluster (sha1)
     * @param  string|null  $appFrame  topmost application frame, e.g. "app/Http/Controllers/OrderController.php:42",
     *                                 or "app/Http/Controllers/OrderController.php::App\Http\Controllers\OrderController::show" in method mode
     */
    public function __construct(
        public string $hash,
        public string $type,
        public string $normalizedMessage,
        public ?string $appFrame,
    ) {}
}

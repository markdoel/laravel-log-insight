<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Fingerprinting;

/**
 * How the topmost application frame enters the fingerprint.
 */
enum FrameMode: string
{
    /** "app/Http/Controllers/OrderController.php:42" */
    case Line = 'line';

    /** "app/Http/Controllers/OrderController.php::App\Http\Controllers\OrderController::show" */
    case Method = 'method';
}

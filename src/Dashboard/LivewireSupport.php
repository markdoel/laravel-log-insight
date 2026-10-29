<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Dashboard;

use Livewire\Component;

/**
 * Whether the optional livewire/livewire package is installed.
 */
final readonly class LivewireSupport
{
    public const PACKAGE = 'livewire/livewire';

    public function __construct(
        public bool $installed,
    ) {}

    public static function detect(): self
    {
        return new self(class_exists(Component::class));
    }
}

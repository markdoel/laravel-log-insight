<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Data;

/**
 * A single frame of a PHP stack trace, e.g.
 * "#2 /var/www/app/Http/Controllers/OrderController.php(42): App\Foo->bar()".
 */
final readonly class StackFrame
{
    public function __construct(
        public int $index,
        public ?string $file,
        public ?int $line,
        public string $call,
    ) {}

    public function isInternal(): bool
    {
        return $this->file === null;
    }
}

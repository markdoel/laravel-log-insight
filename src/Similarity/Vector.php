<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Similarity;

/**
 * A stored embedding with its precomputed length, for cosine similarity.
 */
final readonly class Vector
{
    /**
     * @param  list<float>  $values
     */
    private function __construct(
        public array $values,
        public float $norm,
    ) {}

    /**
     * Null for anything that is not a non-empty list of numbers with a length above zero.
     */
    public static function from(mixed $values): ?self
    {
        if (! is_array($values) || $values === [] || ! array_is_list($values)) {
            return null;
        }

        $floats = [];
        $sum = 0.0;

        foreach ($values as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return null;
            }

            $floats[] = (float) $value;
            $sum += $value * $value;
        }

        $norm = sqrt($sum);

        return $norm > 0.0 ? new self($floats, $norm) : null;
    }

    public function dimensions(): int
    {
        return count($this->values);
    }

    /**
     * Cosine similarity from -1 to 1, or null when the dimensions differ.
     *
     * Voyage AI vectors are normalized, so this equals the dot product, but
     * dividing by the lengths keeps other providers and truncated vectors right.
     */
    public function cosine(self $other): ?float
    {
        if ($other->dimensions() !== $this->dimensions()) {
            return null;
        }

        $dot = 0.0;

        foreach ($this->values as $i => $value) {
            $dot += $value * $other->values[$i];
        }

        return max(-1.0, min(1.0, $dot / ($this->norm * $other->norm)));
    }
}

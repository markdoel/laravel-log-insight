<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Tests\Unit\Similarity;

use MarkusDoell\LogInsight\Similarity\Vector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VectorTest extends TestCase
{
    #[Test]
    public function it_computes_the_cosine_similarity(): void
    {
        $a = Vector::from([1.0, 0.0]);
        $b = Vector::from([3, 4]);
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $this->assertEqualsWithDelta(0.6, $a->cosine($b), 1e-12);
        $this->assertEqualsWithDelta(0.6, $b->cosine($a), 1e-12);
        $this->assertEqualsWithDelta(1.0, $b->cosine($b), 1e-12);
        $this->assertSame(5.0, $b->norm);
        $this->assertSame(2, $b->dimensions());
    }

    #[Test]
    public function it_does_not_depend_on_the_length(): void
    {
        $a = Vector::from([0.2, 0.4, 0.4]);
        $b = Vector::from([2.0, 4.0, 4.0]);
        $opposite = Vector::from([-1.0, -2.0, -2.0]);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotNull($opposite);

        $this->assertEqualsWithDelta(1.0, $a->cosine($b), 1e-12);
        $this->assertEqualsWithDelta(-1.0, $a->cosine($opposite), 1e-12);
    }

    #[Test]
    public function different_dimensions_are_not_comparable(): void
    {
        $a = Vector::from([1.0, 0.0]);
        $b = Vector::from([1.0, 0.0, 0.0]);
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $this->assertNull($a->cosine($b));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalid(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['[1, 2]'];
        yield 'empty' => [[]];
        yield 'zero length' => [[0.0, 0.0]];
        yield 'not numeric' => [[1.0, '2']];
        yield 'not a list' => [['a' => 1.0]];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function invalid_values_are_rejected(mixed $values): void
    {
        $this->assertNull(Vector::from($values));
    }
}

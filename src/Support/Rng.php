<?php
declare(strict_types=1);

namespace MeNews\Support;

/** Small deterministic PRNG (xorshift32) so daily puzzles are identical for every visitor. */
final class Rng
{
    private int $state;

    public function __construct(int|string $seed)
    {
        $s = is_int($seed) ? $seed : crc32((string)$seed);
        $this->state = ($s & 0xffffffff) ?: 0x9E3779B9;
    }

    /** Float in [0, 1). */
    public function next(): float
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xffffffff;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xffffffff;
        $this->state = $x & 0xffffffff;
        return $this->state / 4294967296;
    }

    public function int(int $min, int $max): int
    {
        return $min + (int)floor($this->next() * ($max - $min + 1));
    }

    public function pick(array $items): mixed
    {
        return $items[array_keys($items)[$this->int(0, count($items) - 1)]];
    }

    public function shuffle(array $items): array
    {
        $items = array_values($items);
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }
}

<?php

namespace App\Domains\Knowledge\Support;

/**
 * Packing, unpacking and comparing vectors.
 *
 * STORED AS FLOAT32, NOT JSON. A 1536-dimension vector is 6 KB packed and
 * about 20 KB as JSON text; a knowledge base is tens of thousands of them, and
 * that ratio decides whether a search reads 60 MB or 200 MB off disk. The
 * precision lost going from PHP's float64 to float32 is far below the
 * precision that similarity ranking can distinguish.
 */
final class Vector
{
    /** @param array<int, float> $vector */
    public static function pack(array $vector): string
    {
        // 'g' is float32 little-endian — fixed byte order, so a database
        // copied between machines is still readable.
        return pack('g*', ...array_map('floatval', $vector));
    }

    /** @return array<int, float> */
    public static function unpack(string $packed, int $dimensions): array
    {
        if ($packed === '' || $dimensions <= 0) {
            return [];
        }

        $values = unpack('g'.$dimensions, $packed);

        return $values === false ? [] : array_values($values);
    }

    /**
     * Cosine similarity, in the range −1 to 1.
     *
     * Cosine rather than Euclidean distance because embedding models are
     * trained so that DIRECTION carries the meaning and magnitude does not —
     * two passages saying the same thing at different lengths should score as
     * the same thing.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $count = count($a);

        // Vectors from two different models are not comparable at all, and
        // comparing the overlapping part would produce a plausible number for
        // a meaningless comparison.
        if ($count === 0 || $count !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}

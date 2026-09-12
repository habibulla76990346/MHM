<?php

namespace Tests\Support;

/**
 * Embeddings that genuinely encode meaning-by-overlap, for tests.
 *
 * A fake returning random or constant vectors would let "retrieval returns
 * relevant chunks" pass for no reason — the test would be asserting that the
 * first row came back first. This maps each word onto a fixed hash space and
 * normalises, so cosine similarity between two texts really is a function of
 * the words they share.
 *
 * That makes the retrieval assertions real: the passage about grace periods
 * ranks first for a question about grace periods because the arithmetic says
 * so, not because the fixture was ordered helpfully.
 */
final class FakeEmbedder
{
    public const DIMENSIONS = 256;

    /** @return array<int, float> */
    public static function vector(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);

        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $slot = abs(crc32($word)) % self::DIMENSIONS;

            // Short words are overwhelmingly "the", "is", "of". A real
            // embedding model learns to ignore them; raw term frequency over a
            // hash space does the opposite and lets them dominate, which would
            // make every passage look alike and the relevance assertions
            // meaningless. Down-weighting them approximates what a real model
            // does well enough for the test to mean something.
            $vector[$slot] += mb_strlen($word) <= 3 ? 0.15 : 1.0;
        }

        $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        if ($norm <= 0.0) {
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(fn (float $v) => $v / $norm, $vector);
    }

    /**
     * An OpenAI-shaped embeddings response for a request body.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function response(array $body): array
    {
        $data = [];

        foreach (array_values((array) ($body['input'] ?? [])) as $index => $text) {
            $data[] = ['index' => $index, 'embedding' => self::vector((string) $text)];
        }

        return ['object' => 'list', 'data' => $data, 'model' => $body['model'] ?? 'fake-embed'];
    }
}

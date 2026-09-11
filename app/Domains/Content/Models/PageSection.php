<?php

namespace App\Domains\Content\Models;

use App\Domains\Content\Support\SectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageSection extends Model
{
    protected $table = 'content_sections';

    protected $fillable = ['page_id', 'type', 'sort_order', 'is_visible', 'payload'];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /** One field of the payload, as a string. */
    public function value(string $key, string $default = ''): string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * A payload field treated as a LINK.
     *
     * An administrator types these, and an administrator is trusted — but the
     * result is rendered for every visitor, so a javascript: or data: URL
     * typed by mistake (or pasted from somewhere unhelpful) would be
     * everyone's problem. Only site-relative paths and http(s) survive.
     */
    public function url(string $key): string
    {
        $url = trim($this->value($key));

        if ($url === '') {
            return '#';
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '#';
    }

    /**
     * The repeating rows of this section, with anything malformed dropped.
     *
     * A renderer must never have to defend itself against its own payload: the
     * shape is normalised once, here.
     *
     * @return array<int, array<string, string>>
     */
    public function items(): array
    {
        $repeats = SectionType::repeats($this->type);

        if (! $repeats) {
            return [];
        }

        $rows = $this->payload[$repeats['key']] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $out = [];

        foreach (array_slice($rows, 0, $repeats['max']) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];

            foreach ($repeats['fields'] as $field => $definition) {
                $clean[$field] = is_scalar($row[$field] ?? null) ? (string) $row[$field] : '';
            }

            // A row with nothing in it is not a row.
            if (implode('', $clean) !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }
}

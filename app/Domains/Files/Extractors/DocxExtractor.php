<?php

namespace App\Domains\Files\Extractors;

use App\Domains\Files\Contracts\TextExtractor;
use App\Domains\Files\Extraction\ExtractedText;
use App\Domains\Files\Models\File;
use ZipArchive;

/**
 * Word documents, read natively.
 *
 * NO LIBRARY, DELIBERATELY. A .docx is a ZIP holding XML, and the two
 * extensions needed to open it — `zip` and `dom` — are already on the
 * required list because the rest of the platform needs them. Adding a Word
 * library would be a dependency for one file format the platform can already
 * open.
 *
 * The XML is walked rather than regex-stripped, because Word splits a single
 * sentence across arbitrarily many `<w:t>` runs whenever formatting changes
 * mid-word — a regex over the raw markup reassembles "important" as
 * "im port ant".
 */
class DocxExtractor implements TextExtractor
{
    public const KEY = 'docx';

    private const DOCUMENT = 'word/document.xml';

    public function key(): string
    {
        return self::KEY;
    }

    public function extensions(): array
    {
        return ['docx'];
    }

    public function handles(File $file): bool
    {
        return strtolower((string) $file->extension) === 'docx';
    }

    public function extract(File $file, string $path): ExtractedText
    {
        if (! class_exists(ZipArchive::class)) {
            return ExtractedText::unusable(self::KEY, __('Word documents cannot be read on this server: the zip extension is missing.'));
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return ExtractedText::unusable(self::KEY, self::notAWordFile());
        }

        $xml = $zip->getFromName(self::DOCUMENT);
        $zip->close();

        if ($xml === false) {
            return ExtractedText::unusable(self::KEY, self::notAWordFile());
        }

        return ExtractedText::of($this->walk($xml), self::KEY);
    }

    /**
     * One sentence for every way this can go wrong.
     *
     * A .doc renamed to .docx, a corrupt archive, and a zip with no document
     * inside are three different faults and the same remedy. Telling them
     * apart would be telling the customer something they cannot act on, and
     * the commonest cause by far is the old format — so the message names it.
     */
    private static function notAWordFile(): string
    {
        return __('This file is not a readable Word document. If it was saved in the older .doc format, open it and save it again as .docx.');
    }

    /**
     * Paragraph by paragraph, run by run.
     *
     * `w:t` holds text, `w:tab` a tab, `w:br` a line break, and `w:p` ends a
     * paragraph. Everything else in the file is formatting.
     */
    private function walk(string $xml): string
    {
        $reader = new \XMLReader;

        // LIBXML_NONET and no entity substitution: this is a file somebody
        // uploaded, and an XML parser that resolves external entities is how
        // a document reads /etc/passwd.
        if (! @$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_NOENT & 0)) {
            return '';
        }

        $out = '';

        while (@$reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                $out .= match ($reader->localName) {
                    't' => $reader->readString(),
                    'tab' => ' ',
                    'br' => "\n",
                    default => '',
                };
            }

            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'p') {
                $out .= "\n";
            }
        }

        $reader->close();

        return $out;
    }
}

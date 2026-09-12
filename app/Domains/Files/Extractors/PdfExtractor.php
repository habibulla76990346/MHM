<?php

namespace App\Domains\Files\Extractors;

use App\Domains\Files\Contracts\TextExtractor;
use App\Domains\Files\Extraction\ExtractedText;
use App\Domains\Files\Models\File;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * PDFs, page by page.
 *
 * A LIBRARY HERE AND NOWHERE ELSE. PDF is the one format on the list that
 * cannot be read with the extensions already required — it is a container
 * with its own compression, font encodings and text-positioning model, and
 * hand-rolling that produces an extractor that works on the three files it
 * was tested against. `smalot/pdfparser` is pure PHP with no extension
 * requirements of its own, so it ships in `vendor/` and adds nothing to what
 * a server must provide.
 *
 * PAGES ARE KEPT AS MARKERS. "Page 7" is a citation somebody can open the
 * document and check, which is the difference between an answer and an
 * assertion — and it costs one integer per page to record.
 *
 * A SCANNED PDF PARSES PERFECTLY AND YIELDS NOTHING. That is not a failure to
 * retry; it is a sentence the customer needs to read. `ExtractedText` says so.
 */
class PdfExtractor implements TextExtractor
{
    public const KEY = 'pdf';

    /**
     * A ceiling, because extraction happens on the queue and a queue worker on
     * shared hosting has a minute. A 900-page manual is a legitimate document
     * and also not something to discover halfway through a 55-second run.
     */
    private const MAX_PAGES = 400;

    public function key(): string
    {
        return self::KEY;
    }

    public function extensions(): array
    {
        return ['pdf'];
    }

    public function handles(File $file): bool
    {
        return strtolower((string) $file->extension) === 'pdf';
    }

    public function extract(File $file, string $path): ExtractedText
    {
        try {
            $document = (new Parser)->parseFile($path);
        } catch (Throwable) {
            // Encrypted, malformed, or a format the parser does not know.
            // The customer gets a sentence, not a stack trace.
            return ExtractedText::unusable(
                self::KEY,
                __('This PDF could not be read. It may be password-protected or damaged.'),
            );
        }

        $pages = $document->getPages();

        if ($pages === []) {
            return ExtractedText::unusable(self::KEY, __('This PDF has no pages.'));
        }

        $text = '';
        $markers = [];
        $number = 0;

        foreach ($pages as $page) {
            $number++;

            if ($number > self::MAX_PAGES) {
                $text .= "\n".__('… the rest of this document was not indexed: it has more than :max pages.', ['max' => self::MAX_PAGES]);
                break;
            }

            try {
                $content = trim((string) $page->getText());
            } catch (Throwable) {
                // One unreadable page does not condemn the document.
                continue;
            }

            if ($content === '') {
                continue;
            }

            $markers[] = ['locator' => __('page :n', ['n' => $number]), 'offset' => mb_strlen($text)];
            $text .= $content."\n\n";
        }

        return ExtractedText::of($text, self::KEY, $markers);
    }
}

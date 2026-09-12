<?php

namespace Tests\Feature\Files;

use App\Domains\Files\Extraction\ExtractedText;
use App\Domains\Files\Extractors\CsvExtractor;
use App\Domains\Files\Extractors\DocxExtractor;
use App\Domains\Files\Extractors\PdfExtractor;
use App\Domains\Files\Extractors\PlainTextExtractor;
use App\Domains\Files\Models\File;
use App\Domains\Files\Services\ExtractorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Phase 8 gate item: **each supported file type extracts correctly.**
 *
 * Against REAL FILES, not strings pretending to be files. A .docx fixture is a
 * genuine ZIP of WordprocessingML with a sentence deliberately split across
 * two runs; the .pdf is a genuine PDF with a real content stream. Testing
 * extraction against hand-written text would prove only that the test author
 * knows what they expect.
 */
class TextExtractionTest extends TestCase
{
    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/documents/'.$name);
    }

    private function file(string $name, string $extension): File
    {
        return new File(['extension' => $extension, 'original_name' => $name]);
    }

    private function registry(): ExtractorRegistry
    {
        return app(ExtractorRegistry::class);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>}> */
    public static function supportedTypes(): array
    {
        return [
            'plain text' => ['notes.txt', 'txt', PlainTextExtractor::KEY, ['PHP 8.4', 'grace period']],
            'csv' => ['plans.csv', 'csv', CsvExtractor::KEY, ['Plan: Pro', 'Price: 999', 'Credits: 500']],
            'word' => ['handbook.docx', 'docx', DocxExtractor::KEY, ['help@example.test', 'seven working days']],
            'pdf' => ['report.pdf', 'pdf', PdfExtractor::KEY, ['knowledge base test document', 'INV']],
        ];
    }

    /** @param array<int, string> $expected */
    #[DataProvider('supportedTypes')]
    public function test_each_supported_type_extracts_its_real_content(
        string $name,
        string $extension,
        string $expectedKey,
        array $expected,
    ): void {
        $file = $this->file($name, $extension);
        $extractor = $this->registry()->for($file);

        $this->assertNotNull($extractor, "Nothing can read a {$extension} file.");
        $this->assertSame($expectedKey, $extractor->key());

        $result = $extractor->extract($file, $this->fixture($name));

        $this->assertTrue($result->usable, $name.' produced nothing usable: '.$result->reason);

        foreach ($expected as $needle) {
            $this->assertStringContainsString($needle, $result->text,
                $name.' lost content that is in the file.');
        }
    }

    public function test_word_structure_survives_because_the_xml_is_walked(): void
    {
        $result = app(DocxExtractor::class)->extract(
            $this->file('handbook.docx', 'docx'),
            $this->fixture('handbook.docx'),
        );

        // Word splits a single sentence across arbitrarily many <w:t> runs
        // whenever formatting changes.
        $this->assertStringContainsString('The support address is help@example.test.', $result->text);

        // AND THE PART A REGEX CANNOT DO. A tab and a line break carry meaning
        // and have no text content at all, so stripping tags silently welds
        // the words on either side together — "Region<w:tab/>Bengaluru"
        // becomes "RegionBengaluru", which is one nonsense token in every
        // table in the document.
        //
        // This assertion exists because the first version of this test passed
        // against a strip_tags implementation: the fixture had no tabs, so the
        // gate could not fail.
        $this->assertStringContainsString('Region Bengaluru', $result->text);
        $this->assertStringNotContainsString('RegionBengaluru', $result->text);
        $this->assertStringContainsString("First line\nsecond line", $result->text);
    }

    public function test_a_csv_row_keeps_its_column_names(): void
    {
        // A raw CSV loses which column is which by the fortieth row, and a
        // question about "the price of the second plan" then has no anchor.
        $result = app(CsvExtractor::class)->extract(
            $this->file('plans.csv', 'csv'),
            $this->fixture('plans.csv'),
        );

        $this->assertStringContainsString('Plan: Lite · Price: 499 · Credits: 200', $result->text);

        // And each row carries a citation somebody can open the file and check.
        $this->assertSame('row 1', $result->markers[0]['locator']);
    }

    public function test_a_pdf_records_the_page_a_passage_came_from(): void
    {
        $result = app(PdfExtractor::class)->extract(
            $this->file('report.pdf', 'pdf'),
            $this->fixture('report.pdf'),
        );

        $this->assertTrue($result->usable);
        $this->assertNotSame([], $result->markers);
        $this->assertSame('page 1', $result->markers[0]['locator']);
    }

    // -- what happens when it cannot be read ---------------------------------

    public function test_an_unreadable_file_says_so_rather_than_throwing(): void
    {
        // A .doc renamed to .docx. The queue must not retry this three times
        // and fail identically; the customer must read a sentence.
        $path = tempnam(sys_get_temp_dir(), 'notdocx').'.docx';
        file_put_contents($path, 'this is not a zip archive at all');

        $result = app(DocxExtractor::class)->extract($this->file('fake.docx', 'docx'), $path);

        $this->assertFalse($result->usable);
        $this->assertStringContainsString('.docx', (string) $result->reason);

        unlink($path);
    }

    public function test_a_pdf_with_no_text_layer_is_reported_not_indexed(): void
    {
        // A scanned page parses perfectly and yields nothing. Indexing it
        // would put an empty document in the base; retrying it would waste a
        // worker. Saying so is the only useful outcome.
        $result = ExtractedText::of('   ', PdfExtractor::KEY);

        $this->assertFalse($result->usable);
        $this->assertStringContainsString('scan', (string) $result->reason);
    }

    public function test_a_type_nothing_can_read_returns_no_extractor(): void
    {
        // Null, never an exception: "we cannot read .xlsx yet" is information.
        $this->assertNull($this->registry()->for($this->file('sheet.xlsx', 'xlsx')));
        $this->assertNull($this->registry()->for($this->file('photo.png', 'png')));
    }

    public function test_the_advertised_extensions_come_from_the_extractors(): void
    {
        // The admin screen lists what can go in a knowledge base. Reading it
        // from the extractors means the list cannot drift from the code.
        $extensions = $this->registry()->extensions();

        foreach (['txt', 'md', 'csv', 'docx', 'pdf'] as $expected) {
            $this->assertContains($expected, $extensions);
        }

        $this->assertNotContains('xlsx', $extensions);
    }

    public function test_extraction_normalises_whitespace_without_losing_paragraphs(): void
    {
        // Paragraph breaks survive because the chunker splits on them; runs of
        // spaces do not, because PDFs produce them by the thousand and every
        // one costs a token.
        $result = ExtractedText::of("A   paragraph  with    spaces.\n\n\n\nAnd another one entirely.", 'test');

        $this->assertSame("A paragraph with spaces.\n\nAnd another one entirely.", $result->text);
    }

    public function test_a_windows_encoded_file_becomes_valid_utf8(): void
    {
        // A file typed on a Windows machine is as likely to be Windows-1252 as
        // UTF-8, and invalid UTF-8 reaching a provider is either a rejected
        // request or mojibake in the answer.
        $path = tempnam(sys_get_temp_dir(), 'cp1252').'.txt';
        file_put_contents($path, mb_convert_encoding(
            'The fee is 250 – 400 rupees, per the “standard” rate card for all customers.',
            'Windows-1252',
            'UTF-8',
        ));

        $result = app(PlainTextExtractor::class)->extract($this->file('w.txt', 'txt'), $path);

        $this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
        $this->assertStringContainsString('250', $result->text);

        unlink($path);
    }
}

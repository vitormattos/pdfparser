<?php

namespace PHPUnitTests\Integration;

use PHPUnitTests\TestCase;
use Smalot\PdfParser\Parser;

/**
 * Test suite for validating page dimensions extraction (POC)
 * 
 * This test suite validates that the parser can extract both:
 * - Page count (number of pages)
 * - Page dimensions (width and height from MediaBox)
 */
class PageDimensionsTest extends TestCase
{
    /**
     * Test that a simple PDF returns correct page count and valid dimensions for all pages
     */
    public function testExtractPageCountAndDimensions(): void
    {
        $filename = $this->rootDir.'/samples/Document1_pdfcreator_nocompressed.pdf';
        $parser = $this->getParserInstance();
        $document = $parser->parseFile($filename);
        $pages = $document->getPages();

        // Validate page count
        $this->assertGreaterThan(0, count($pages), 'Document should have at least one page');
        
        // Validate that all pages have valid dimensions
        foreach ($pages as $pageNum => $page) {
            $this->assertPageHasValidDimensions($page);
        }
    }

    /**
     * Test that dimensions are consistent per page
     */
    public function testPageDimensionsConsistency(): void
    {
        $filename = $this->rootDir.'/samples/SimpleInvoiceFilledExample1.pdf';
        $parser = $this->getParserInstance();
        $document = $parser->parseFile($filename);
        $pages = $document->getPages();

        $this->assertGreaterThan(0, count($pages), 'Document should have pages');

        $firstPageDims = null;
        foreach ($pages as $page) {
            $dims = $this->getPageDimensions($page);
            $this->assertNotNull($dims, 'All pages should have extractable dimensions');
            
            if ($firstPageDims === null) {
                $firstPageDims = $dims;
            }
            
            // Most PDFs have uniform page sizes
            $this->assertEquals($firstPageDims['w'], $dims['w'], 'Page widths should match');
            $this->assertEquals($firstPageDims['h'], $dims['h'], 'Page heights should match');
        }
    }

    /**
     * Data provider for testing various sample PDFs
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function provideSamplePdfFixtures(): array
    {
        return [
            'Document1_pdfcreator_nocompressed' => ['Document1_pdfcreator_nocompressed.pdf', 1],
            'Document1_pdfcreator' => ['Document1_pdfcreator.pdf', 1],
            'Document2_pdfcreator_nocompressed' => ['Document2_pdfcreator_nocompressed.pdf', 3],
            'InternationalChars' => ['InternationalChars.pdf', 2],
        ];
    }

    /**
     * @dataProvider provideSamplePdfFixtures
     */
    public function testSamplePdfPageCountAndDimensions(string $fixture, int $expectedPageCount): void
    {
        $filename = $this->rootDir.'/samples/'.$fixture;
        if (!file_exists($filename)) {
            $this->markTestSkipped("Sample PDF not found: $filename");
        }

        $parser = $this->getParserInstance();
        $document = $parser->parseFile($filename);
        $pages = $document->getPages();

        // Validate page count matches expectation
        $this->assertCount($expectedPageCount, $pages, "PDF should have $expectedPageCount pages");

        // Validate all pages have dimensions
        foreach ($pages as $page) {
            $this->assertPageHasValidDimensions($page);
        }
    }
}

<?php
/**
 * PDF Dataset Analysis Tool - Extract page count and dimensions
 * 
 * Analyzes all PDFs in dev-tools/pdf-dataset/pdfs/ using pdfparser
 * and optionally pdfinfo (if available).
 * 
 * Output format: JSONL with {file, parser_pages, parser_dims, pdfinfo_pages, pdfinfo_dims, status}
 */

require __DIR__ . '/../vendor/autoload.php';

use Smalot\PdfParser\Parser;

class DatasetAnalyzer
{
    private Parser $parser;
    private string $pdfDir;
    private string $outputFile;
    private int $totalFiles = 0;
    private int $successCount = 0;
    private int $failureCount = 0;

    public function __construct()
    {
        $this->parser = new Parser();
        $this->pdfDir = __DIR__ . '/pdf-dataset/pdfs';
        $this->outputFile = __DIR__ . '/pdf-dataset/results/pdfjs/analysis_' . date('YmdHis') . '.jsonl';
    }

    public function run(): void
    {
        if (!is_dir($this->pdfDir)) {
            echo "Error: PDF directory not found: {$this->pdfDir}\n";
            exit(1);
        }

        $files = $this->getAllPdfFiles();
        echo "Found " . count($files) . " PDF files to analyze\n";

        foreach ($files as $file) {
            $this->analyzeFile($file);
        }

        $this->printSummary();
    }

    private function getAllPdfFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->pdfDir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'pdf') {
                $files[] = $file->getRealPath();
            }
        }

        sort($files);
        return $files;
    }

    private function analyzeFile(string $filePath): void
    {
        $this->totalFiles++;
        $relPath = str_replace($this->pdfDir . '/', '', $filePath);

        $result = [
            'file' => $relPath,
            'parser_pages' => null,
            'parser_dims' => [],
            'pdfinfo_pages' => null,
            'pdfinfo_dims' => [],
            'status' => 'pending',
        ];

        try {
            // Try pdfinfo first (if available)
            $pdfInfoResult = $this->extractWithPdfInfo($filePath);
            if ($pdfInfoResult) {
                $result['pdfinfo_pages'] = $pdfInfoResult['pages'];
                $result['pdfinfo_dims'] = $pdfInfoResult['dims'];
            }

            // Try pdfparser
            $parserResult = $this->extractWithPdfParser($filePath);
            if ($parserResult) {
                $result['parser_pages'] = $parserResult['pages'];
                $result['parser_dims'] = $parserResult['dims'];
                $result['status'] = 'success';
                $this->successCount++;
            } else {
                $result['status'] = 'parser_failed';
                $this->failureCount++;
            }
        } catch (Throwable $e) {
            $result['status'] = 'error: ' . $e->getMessage();
            $this->failureCount++;
        }

        $this->writeResult($result);
        printf(".");
        if ($this->totalFiles % 50 === 0) {
            printf(" [%d/%d]\n", $this->totalFiles, count($this->getAllPdfFiles()));
        }
    }

    private function extractWithPdfParser(string $filePath): ?array
    {
        try {
            $document = $this->parser->parseFile($filePath);
            $pages = $document->getPages();
            $pageCount = count($pages);

            $dims = [];
            foreach ($pages as $page) {
                $dim = $this->getPageDimensions($page);
                if ($dim) {
                    $dims[] = $dim;
                }
            }

            return [
                'pages' => $pageCount,
                'dims' => $dims,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function getPageDimensions($page): ?array
    {
        try {
            $details = $page->getDetails();
            if (isset($details['MediaBox'])) {
                $box = $details['MediaBox'];
                if (is_array($box) && count($box) >= 4) {
                    return [
                        'w' => (float)$box[2],
                        'h' => (float)$box[3],
                    ];
                }
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function extractWithPdfInfo(string $filePath): ?array
    {
        if (shell_exec('which pdfinfo') === null) {
            return null;
        }

        try {
            $output = shell_exec('pdfinfo ' . escapeshellarg($filePath) . ' -l -1 2>&1');
            if (!$output) {
                return null;
            }

            // Extract page count from first match
            if (!preg_match('/Pages:\s+(\d+)/', $output, $pageMatch)) {
                return null;
            }
            $pages = (int)$pageMatch[1];

            // Extract page dimensions
            $dims = [];
            if (preg_match_all('/Page\s+\d+\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/', $output, $matches)) {
                for ($i = 0; $i < count($matches[1]); $i++) {
                    $dims[] = [
                        'w' => (float)$matches[1][$i],
                        'h' => (float)$matches[2][$i],
                    ];
                }
            }

            return [
                'pages' => $pages,
                'dims' => $dims,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    private function writeResult(array $result): void
    {
        $line = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->outputFile, $line, FILE_APPEND);
    }

    private function printSummary(): void
    {
        echo "\n\n=== Analysis Summary ===\n";
        echo "Total files analyzed: {$this->totalFiles}\n";
        echo "Successful: {$this->successCount}\n";
        echo "Failed: {$this->failureCount}\n";
        echo "Success rate: " . round(($this->successCount / $this->totalFiles) * 100, 2) . "%\n";
        echo "Results written to: {$this->outputFile}\n";
    }
}

$analyzer = new DatasetAnalyzer();
$analyzer->run();

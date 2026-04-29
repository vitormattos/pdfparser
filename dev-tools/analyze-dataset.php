<?php

declare(strict_types=1);

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
}

if (!class_exists('Smalot\\PdfParser\\Parser') && file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
}

if (!class_exists('Smalot\\PdfParser\\Parser') && file_exists(__DIR__.'/../alt_autoload.php-dist')) {
    require __DIR__.'/../alt_autoload.php-dist';
}

use Smalot\PdfParser\Parser;

final class DatasetAnalyzer
{
    private const DIM_TOLERANCE = 1.0;

    private Parser $parser;
    private string $pdfRoot;
    private string $scope;
    private int $limit;
    private string $runId;
    private string $outputFile;
    private string $summaryFile;
    private bool $pdfinfoAvailable;
    private string $scriptPath;
    /** @var array<string,int|float|string|bool> */
    private array $summary;

    public function __construct(string $scope, int $limit)
    {
        $this->parser = new Parser();
        $this->pdfRoot = __DIR__.'/pdf-dataset/pdfs';
        $this->scope = $scope;
        $this->limit = $limit;
        $this->runId = gmdate('Ymd\\THis\\Z');
        $this->scriptPath = realpath(__FILE__) ?: __FILE__;

        $dir = __DIR__.'/pdf-dataset/results/'.($scope === 'all' ? 'full' : 'pdfjs').'/runs';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->outputFile = $dir.'/'.$this->runId.'.jsonl';
        $this->summaryFile = $dir.'/'.$this->runId.'.summary.json';
        $this->pdfinfoAvailable = trim((string) shell_exec('command -v pdfinfo 2>/dev/null')) !== '';

        $this->summary = [
            'scope' => $scope,
            'run_id' => $this->runId,
            'total_files' => 0,
            'parser_success' => 0,
            'parser_failed' => 0,
            'pdfinfo_available' => $this->pdfinfoAvailable,
            'pdfinfo_comparable_files' => 0,
            'page_match' => 0,
            'page_mismatch' => 0,
            'dimension_match' => 0,
            'dimension_mismatch' => 0,
            'dimension_partial_or_missing' => 0,
            'parser_total_pages' => 0,
            'parser_pages_with_dimensions' => 0,
            'parser_dimension_coverage_pct' => 0.0,
            'started_at' => gmdate('c'),
            'finished_at' => '',
        ];
    }

    public function run(): void
    {
        $files = $this->getPdfFiles();
        $total = count($files);

        if ($total === 0) {
            fwrite(STDERR, "No PDF files found for scope '{$this->scope}'.\n");
            exit(1);
        }

        echo "Running shadow analysis for scope '{$this->scope}' on {$total} files\n";
        if (!$this->pdfinfoAvailable) {
            echo "pdfinfo not found: comparison fields will remain null\n";
        }

        foreach ($files as $index => $filePath) {
            $record = $this->analyzeFile($filePath);
            $this->writeJsonl($record);

            if ((($index + 1) % 50) === 0 || $index + 1 === $total) {
                echo sprintf("Progress: %d/%d\n", $index + 1, $total);
            }
        }

        $this->finalizeSummary();
        $this->writeSummary();
        $this->printSummary();
    }

    /** @return list<string> */
    private function getPdfFiles(): array
    {
        $basePath = $this->scope === 'pdfjs' ? $this->pdfRoot.'/pdfjs' : $this->pdfRoot;
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $fileInfo) {
            if (strtolower($fileInfo->getExtension()) === 'pdf') {
                $files[] = $fileInfo->getRealPath();
            }
        }

        sort($files);

        if ($this->limit > 0) {
            return array_slice($files, 0, $this->limit);
        }

        return $files;
    }

    /** @return array<string,mixed> */
    private function analyzeFile(string $filePath): array
    {
        $this->summary['total_files']++;
        $relativePath = str_replace($this->pdfRoot.'/', '', $filePath);

        $record = [
            'file' => $relativePath,
            'sha256' => hash_file('sha256', $filePath),
            'status' => 'parser_failed',
            'error' => null,
            'parser_pages' => null,
            'parser_dimensions' => [],
            'parser_dimensions_count' => 0,
            'pdfinfo_pages' => null,
            'pdfinfo_dimensions' => null,
            'page_match' => null,
            'dimensions_match' => null,
        ];

        $pdfinfo = $this->extractWithPdfInfo($filePath);
        if ($pdfinfo !== null) {
            $record['pdfinfo_pages'] = $pdfinfo['pages'];
            $record['pdfinfo_dimensions'] = $pdfinfo['dimensions'];
            $this->summary['pdfinfo_comparable_files']++;
        }

        try {
            $parser = $this->extractWithWorker($filePath);
            if ($parser === null) {
                throw new RuntimeException('Worker did not return parser payload');
            }
            $record['status'] = 'ok';
            $record['parser_pages'] = $parser['pages'];
            $record['parser_dimensions'] = $parser['dimensions'];
            $record['parser_dimensions_count'] = count($parser['dimensions']);

            $this->summary['parser_success']++;
            $this->summary['parser_total_pages'] += $parser['pages'];
            $this->summary['parser_pages_with_dimensions'] += count($parser['dimensions']);

            if ($pdfinfo !== null) {
                $pageMatch = $parser['pages'] === $pdfinfo['pages'];
                $record['page_match'] = $pageMatch;

                if ($pageMatch) {
                    $this->summary['page_match']++;
                } else {
                    $this->summary['page_mismatch']++;
                }

                $dimComparison = $this->compareDimensions($parser['dimensions'], $pdfinfo['dimensions']);
                $record['dimensions_match'] = $dimComparison;

                if ($dimComparison === true) {
                    $this->summary['dimension_match']++;
                } elseif ($dimComparison === false) {
                    $this->summary['dimension_mismatch']++;
                } else {
                    $this->summary['dimension_partial_or_missing']++;
                }
            }
        } catch (Throwable $e) {
            $record['error'] = $e->getMessage();
            $this->summary['parser_failed']++;
        }

        return $record;
    }

    /** @return array{pages:int,dimensions:list<array{w:float,h:float}>}|null */
    private function extractWithWorker(string $filePath): ?array
    {
        $command = sprintf(
            '%s -d memory_limit=768M %s --worker-file=%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->scriptPath),
            escapeshellarg($filePath)
        );

        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return null;
        }

        $line = implode("\n", $output);
        /** @var mixed $decoded */
        $decoded = json_decode($line, true);
        if (!is_array($decoded) || !isset($decoded['pages']) || !isset($decoded['dimensions'])) {
            return null;
        }

        return [
            'pages' => (int) $decoded['pages'],
            'dimensions' => is_array($decoded['dimensions']) ? $decoded['dimensions'] : [],
        ];
    }

    /** @return array{pages:int,dimensions:list<array{w:float,h:float}>} */
    private function extractWithPdfParser(string $filePath): array
    {
        $document = $this->parser->parseFile($filePath);
        $pages = $document->getPages();
        $dimensions = [];

        foreach ($pages as $page) {
            $dim = $this->extractPageDimension($page);
            if ($dim !== null) {
                $dimensions[] = $dim;
            }
        }

        return [
            'pages' => count($pages),
            'dimensions' => $dimensions,
        ];
    }

    /** @return array{w:float,h:float}|null */
    private function extractPageDimension(object $page): ?array
    {
        // Prefer direct MediaBox access to avoid deep getDetails() recursion on malformed font trees.
        if (method_exists($page, 'get')) {
            try {
                $mediaBox = $page->get('MediaBox');
                if (is_object($mediaBox) && method_exists($mediaBox, 'getContent')) {
                    $content = $mediaBox->getContent();
                    if (
                        is_array($content)
                        && count($content) >= 4
                        && is_numeric($content[2])
                        && is_numeric($content[3])
                    ) {
                        return [
                            'w' => (float) $content[2],
                            'h' => (float) $content[3],
                        ];
                    }
                }
            } catch (Throwable $e) {
                // Fall back to details extraction below.
            }
        }

        try {
            $details = $page->getDetails();
        } catch (Throwable $e) {
            return null;
        }

        if (!isset($details['MediaBox']) || !is_array($details['MediaBox']) || count($details['MediaBox']) < 4) {
            return null;
        }

        if (!is_numeric($details['MediaBox'][2]) || !is_numeric($details['MediaBox'][3])) {
            return null;
        }

        return [
            'w' => (float) $details['MediaBox'][2],
            'h' => (float) $details['MediaBox'][3],
        ];
    }

    /** @return array{pages:int,dimensions:list<array{w:float,h:float}>}|null */
    private function extractWithPdfInfo(string $filePath): ?array
    {
        if (!$this->pdfinfoAvailable) {
            return null;
        }

        $command = sprintf('pdfinfo -f 1 -l 999999 %s 2>&1', escapeshellarg($filePath));
        $output = shell_exec($command);
        if (!is_string($output) || $output === '') {
            return null;
        }

        if (!preg_match('/Pages:\s+(\d+)/', $output, $pageMatch)) {
            return null;
        }

        $dimensions = [];
        if (preg_match_all('/Page\s+\d+\s+size:\s+([\d.]+)\s+x\s+([\d.]+)/', $output, $matches)) {
            $count = count($matches[1]);
            for ($i = 0; $i < $count; $i++) {
                $dimensions[] = [
                    'w' => (float) $matches[1][$i],
                    'h' => (float) $matches[2][$i],
                ];
            }
        }

        return [
            'pages' => (int) $pageMatch[1],
            'dimensions' => $dimensions,
        ];
    }

    /**
     * Returns true for full match, false for mismatch, null when dimensions are not comparable.
     *
     * @param list<array{w:float,h:float}> $parserDimensions
     * @param list<array{w:float,h:float}> $pdfinfoDimensions
     */
    private function compareDimensions(array $parserDimensions, array $pdfinfoDimensions): ?bool
    {
        if ($parserDimensions === [] || $pdfinfoDimensions === []) {
            return null;
        }

        if (count($parserDimensions) !== count($pdfinfoDimensions)) {
            return false;
        }

        $count = count($parserDimensions);
        for ($i = 0; $i < $count; $i++) {
            $widthDelta = abs($parserDimensions[$i]['w'] - $pdfinfoDimensions[$i]['w']);
            $heightDelta = abs($parserDimensions[$i]['h'] - $pdfinfoDimensions[$i]['h']);
            if ($widthDelta > self::DIM_TOLERANCE || $heightDelta > self::DIM_TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $record */
    private function writeJsonl(array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->outputFile, $json."\n", FILE_APPEND);
    }

    private function finalizeSummary(): void
    {
        $this->summary['finished_at'] = gmdate('c');

        if ($this->summary['parser_total_pages'] > 0) {
            $this->summary['parser_dimension_coverage_pct'] = round(
                ((float) $this->summary['parser_pages_with_dimensions'] / (float) $this->summary['parser_total_pages']) * 100,
                2
            );
        }
    }

    private function writeSummary(): void
    {
        $json = json_encode($this->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->summaryFile, $json."\n");
    }

    private function printSummary(): void
    {
        echo "\nShadow analysis finished\n";
        echo "Run ID: {$this->runId}\n";
        echo "Total files: {$this->summary['total_files']}\n";
        echo "Parser success: {$this->summary['parser_success']}\n";
        echo "Parser failed: {$this->summary['parser_failed']}\n";
        echo "Page matches (vs pdfinfo): {$this->summary['page_match']}\n";
        echo "Page mismatches (vs pdfinfo): {$this->summary['page_mismatch']}\n";
        echo "Dimension matches (vs pdfinfo): {$this->summary['dimension_match']}\n";
        echo "Dimension mismatches (vs pdfinfo): {$this->summary['dimension_mismatch']}\n";
        echo "Dimension partial/missing: {$this->summary['dimension_partial_or_missing']}\n";
        echo "Dimension coverage: {$this->summary['parser_dimension_coverage_pct']}%\n";
        echo "JSONL output: {$this->outputFile}\n";
        echo "Summary output: {$this->summaryFile}\n";
    }
}

/** @var array<string,string|false> $options */
$options = getopt('', ['scope::', 'limit::', 'worker-file:']);
$workerFile = $options['worker-file'] ?? null;

if (is_string($workerFile) && $workerFile !== '') {
    $parser = new Parser();
    $document = $parser->parseFile($workerFile);
    $pages = $document->getPages();
    $dimensions = [];

    foreach ($pages as $page) {
        try {
            $mediaBox = method_exists($page, 'get') ? $page->get('MediaBox') : null;
            $content = is_object($mediaBox) && method_exists($mediaBox, 'getContent') ? $mediaBox->getContent() : null;
            if (
                is_array($content)
                && count($content) >= 4
                && is_numeric($content[2])
                && is_numeric($content[3])
            ) {
                $dimensions[] = [
                    'w' => (float) $content[2],
                    'h' => (float) $content[3],
                ];
                continue;
            }

            $details = $page->getDetails();
            if (isset($details['MediaBox']) && is_array($details['MediaBox']) && count($details['MediaBox']) >= 4
                && is_numeric($details['MediaBox'][2]) && is_numeric($details['MediaBox'][3])) {
                $dimensions[] = [
                    'w' => (float) $details['MediaBox'][2],
                    'h' => (float) $details['MediaBox'][3],
                ];
            }
        } catch (Throwable $e) {
            // Ignore dimension extraction failures for individual pages in worker mode.
        }
    }

    echo json_encode([
        'pages' => count($pages),
        'dimensions' => $dimensions,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit(0);
}

$scope = isset($options['scope']) && $options['scope'] === 'all' ? 'all' : 'pdfjs';
$limit = isset($options['limit']) ? max(0, (int) $options['limit']) : 0;

$analyzer = new DatasetAnalyzer($scope, $limit);
$analyzer->run();

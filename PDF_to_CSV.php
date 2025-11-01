<?php
require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

// ---------- CONFIG ----------
$pdfPath  = __DIR__ . '/input.pdf';   // <-- your PDF file
$csvPath  = __DIR__ . '/output.csv';  // <-- where CSV will be saved
$password = null;                     // set if PDF is password-protected
// --------------------------------

if (!file_exists($pdfPath)) {
    die("PDF file not found: $pdfPath\n");
}

// 1. Parse the PDF
$parser = new Parser();
$pdf    = $parser->parseFile($pdfPath, $password);

// 2. Extract *all* text (we'll split it into rows/columns later)
$pages = $pdf->getPages();
$allRows = [];

// Helper: clean a cell string (trim, replace multiple spaces)
function cleanCell(string $cell): string {
    return trim(preg_replace('/\s+/', ' ', $cell));
}

// ------------------------------------------------------------------
// 3. Table detection heuristics (works for most simple tables)
// ------------------------------------------------------------------
foreach ($pages as $pageNumber => $page) {
    $text = $page->getText();

    // Split into lines
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $lines = array_filter($lines, fn($l) => trim($l) !== '');

    // ---- Heuristic: assume a table row contains at least 2 columns
    //      separated by 2+ spaces or tabs.
    $potentialRows = [];
    foreach ($lines as $line) {
        // Replace tabs with multiple spaces for uniform splitting
        $line = str_replace("\t", '    ', $line);

        // Split on 2+ spaces (adjust if your table uses a different delimiter)
        $cols = preg_split('/ {2,}/', $line, -1, PREG_SPLIT_NO_EMPTY);
        $cols = array_map('cleanCell', $cols);

        if (count($cols) >= 2) {
            $potentialRows[] = $cols;
        }
    }

    // ---- Find the header row (the one with the most columns)
    if (!empty($potentialRows)) {
        usort($potentialRows, fn($a,$b) => count($b) <=> count($a));
        $header = array_shift($potentialRows);   // widest row → header
        $colCount = count($header);

        // Normalize every row to the same column count (pad with empty cells)
        foreach ($potentialRows as &$row) {
            $row = array_pad($row, $colCount, '');
        }

        // Store header only on the first page that has a table
        if (empty($allRows)) {
            $allRows[] = $header;
        }
        $allRows = array_merge($allRows, $potentialRows);
    }
}

// ------------------------------------------------------------------
// 4. Write CSV
// ------------------------------------------------------------------
if (empty($allRows)) {
    die("No table data detected in the PDF.\n");
}

$fp = fopen($csvPath, 'w');
foreach ($allRows as $row) {
    fputcsv($fp, $row);
}
fclose($fp);

echo "CSV successfully created: $csvPath\n";
echo "Rows: " . count($allRows) . "\n";

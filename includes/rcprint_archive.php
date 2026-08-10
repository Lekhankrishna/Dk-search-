<?php
// Archives every agent's RC Print result to a shared file on the D: drive
// (2026-08-10) - same "running record independent of the CRM's own
// database" reasoning as includes/pan_india_archive.php and friends, but RC
// Print's result is a generated PDF document, not field/value data, so what
// gets archived here is the actual PDF file itself (decoded from the data
// URI rc_print.py returns), indexed by a small CSV log - not a CSV of
// extracted fields like the other archives.
//
// No dedup by design (unlike the field-based archives): re-printing the
// same vehicle later is a legitimate, separate snapshot (registration/
// ownership can change over time), and RC Print already has its own
// per-agent monthly search cap, so archive volume is naturally bounded.

const RCPRINT_ARCHIVE_DIR = 'D:/RcPrintSearchArchive';
const RCPRINT_ARCHIVE_PDF_DIR = RCPRINT_ARCHIVE_DIR . '/pdfs';
const RCPRINT_ARCHIVE_CSV = RCPRINT_ARCHIVE_DIR . '/records.csv';

// Best-effort archive: never let a D:-drive/permission problem break the
// actual search response an agent is waiting on. $pdfDataUri is the
// "data:application/pdf;base64,..." string from Gas/lpg_web/rc_print.py.
function archiveRcPrintResult(string $pdfDataUri, string $vehicleNumber, string $searchedBy): void {
    try {
        if (!is_dir(RCPRINT_ARCHIVE_PDF_DIR) && !@mkdir(RCPRINT_ARCHIVE_PDF_DIR, 0777, true)) return;

        if (!preg_match('#^data:application/pdf;base64,(.+)$#s', $pdfDataUri, $m)) return;
        $pdfBytes = base64_decode($m[1], true);
        if ($pdfBytes === false || $pdfBytes === '') return;

        $safeVehicle = preg_replace('/[^A-Za-z0-9]/', '', $vehicleNumber) ?: 'UNKNOWN';
        $filename = $safeVehicle . '_' . date('Y-m-d_His') . '.pdf';
        if (@file_put_contents(RCPRINT_ARCHIVE_PDF_DIR . '/' . $filename, $pdfBytes) === false) return;

        $isNewFile = !is_file(RCPRINT_ARCHIVE_CSV);
        $fh = @fopen(RCPRINT_ARCHIVE_CSV, 'a');
        if ($fh && flock($fh, LOCK_EX)) {
            if ($isNewFile) fputcsv($fh, ['Timestamp', 'Searched By', 'Vehicle Number', 'PDF Filename']);
            fputcsv($fh, [date('Y-m-d H:i:s'), $searchedBy, $vehicleNumber, 'pdfs/' . $filename]);
            flock($fh, LOCK_UN);
        }
        if ($fh) fclose($fh);
    } catch (Throwable $e) {
        // Archiving is best-effort - a D:-drive or permission issue must
        // never surface as a search failure to the agent.
    }
}

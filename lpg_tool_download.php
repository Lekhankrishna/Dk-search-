<?php
// No login session here on purpose — this is fetched by a standalone
// PowerShell process (the downloaded LPG-Tool-Install.bat), which has no
// browser cookies to send. Gated instead by a short-lived signed token
// minted by lpg_tool_bootstrap.php (which IS behind a real login) — trying
// to require a real session here just silently served the "not logged in"
// error page in place of the zip, which produced a corrupt-looking download
// with no indication why (found 2026-07-21: Expand-Archive failed with
// "End of Central Directory record could not be found").
require_once __DIR__ . '/config/secrets.php';
$token = $_GET['token'] ?? '';
$parts = explode('.', $token, 2);
$validToken = false;
if (count($parts) === 2) {
    [$expires, $sig] = $parts;
    if (ctype_digit($expires) && (int) $expires >= time()) {
        $expected = hash_hmac('sha256', 'lpg-dl.' . $expires, $LPG_ENC_KEY);
        $validToken = hash_equals($expected, $sig);
    }
}
if (!$validToken) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "This download link has expired or is invalid.\nGo back to the CRM's LPG Search page and let it download the installer again.";
    exit;
}

// Zips the distributable Gas/lpg_web folder on the fly and serves it — the
// single source of truth for what gets installed on each computer stays
// this one folder on the server; nothing here is hand-copied elsewhere.
$sourceDir = __DIR__ . '/Gas/lpg_web';
$zipPath   = sys_get_temp_dir() . '/lpg_tool_' . substr(md5($sourceDir), 0, 8) . '.zip';

// Cheap on-disk cache — the folder only changes when this app is updated,
// no need to re-zip on every download.
$needsRebuild = !file_exists($zipPath);
if (!$needsRebuild) {
    // __FILE__'s own mtime is included here too (not just $sourceDir's
    // contents) - found 2026-07-26: a bug fix to the zipping logic itself
    // (mixed / and \ path separators in zip entries) wouldn't have
    // invalidated the cache otherwise, since nothing inside $sourceDir
    // actually changed - silently continuing to serve the old, still-buggy
    // cached zip indefinitely despite the source fix being deployed.
    $newest = filemtime(__FILE__);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir)) as $f) {
        if ($f->isFile()) $newest = max($newest, $f->getMTime());
    }
    $needsRebuild = $newest > filemtime($zipPath);
}

if ($needsRebuild) {
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        // Skip __pycache__ / compiled artifacts — only source files are needed.
        if (strpos($file->getPathname(), '__pycache__') !== false) continue;
        // str_replace('\\', '/', ...) - found 2026-07-26: RecursiveDirectoryIterator
        // returns Windows-native paths with backslashes, so a subfolder entry like
        // templates/bulk.html was being written into the zip as the mixed
        // "lpg_web/templates\bulk.html" instead of "lpg_web/templates/bulk.html".
        // The zip spec requires forward slashes for internal paths regardless of
        // host OS - Windows tools are usually lenient about this, but not always
        // consistently so, and it's simply invalid to write it that way.
        $rel = 'lpg_web/' . str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir) + 1));
        $file->isDir() ? $zip->addEmptyDir($rel) : $zip->addFile($file->getPathname(), $rel);
    }
    $zip->close();
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="lpg_web.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);

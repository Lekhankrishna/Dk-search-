<?php
// Single Search and Bulk Search are now one page with mode tabs
// (lpg_search.php, 2026-08-11 - same pattern as hp_gas.php's Single/Bulk
// tabs) instead of two separate pages. This file stays as a redirect rather
// than being deleted outright, so any old bookmarks/links to the standalone
// bulk page still land somewhere useful.
header('Location: lpg_search.php?mode=bulk');
exit;

<?php
$user = currentUser();
$bp = $basePath ?? '';
$currentPage = basename($_SERVER['SCRIPT_NAME']);

$searchRegions = [
    ['label' => 'Tamil Nadu',     'state' => 'Tamil Nadu'],
    ['label' => 'Andhra Pradesh', 'state' => 'Andhra Pradesh'],
    ['label' => 'Karnataka',      'state' => 'Karnataka'],
    ['label' => 'Kerala',         'state' => 'Kerala'],
];
// E Commerce isn't a customer state — different data shape (delivery_date,
// coordinates, no father's name/DOB/identity), so it's its own page rather
// than another index.php?state= entry. 'href' set here means "not a live
// state" everywhere below: skip the index.php routing and the
// sidebar-state-item class that index.php's JS uses to intercept clicks for
// same-page state switching (a real navigation here, not a state swap).
$searchRegionsExtra = [
    ['label' => 'E Commerce', 'href' => 'ecommerce.php'],
    // Open to every logged-in user (pan_india.php only calls requireLogin(),
    // no per-user grant like LPG Search) - unconditional, unlike the block
    // below. (Re-added 2026-08-04: this got silently deleted the same way
    // the PAN India CSS did - a dev-tree sync overwrote this file, and dev
    // never had this entry since pan_india.php only exists on live.)
    ['label' => 'Pan India', 'href' => 'pan_india.php'],
];
// LPG Search is opt-in per account (Admin > Agents > "LPG Search Access") —
// only add the menu item at all when the current user has been granted it.
// lpg_search.php enforces the same check server-side (403) regardless, so
// this is purely about not showing a link the user can't use, not the
// actual access control.
if (hasLpgSearchAccess()) {
    // lpg_search.php (2026-07-21) embeds the Flask bulk-search tool
    // (Gas/lpg_web/app.py, port 9196) in an iframe instead of linking
    // straight to it, so it opens inside the CRM's own layout/sidebar rather
    // than as a separate tab/window pointed at a bare port number.
    $searchRegionsExtra[] = ['label' => 'LPG Search', 'href' => 'lpg_search.php'];
    // Separate page (2026-07-24) - single-number quick search above, the
    // original multi-number textarea tool here. Same Flask server, just a
    // different route ("/bulk" vs "/") - see lpg_bulk_search.php.
    $searchRegionsExtra[] = ['label' => 'LPG Bulk Search', 'href' => 'lpg_bulk_search.php'];
}
$selectedState = $_GET['state'] ?? '';

// Sidebar colour-coding (2026-08-03) - same 12-hue palette as the results
// tables (pan_india.php, index.php), cycled by nav position so every item
// gets a distinct colour instead of one flat accent purple. Inline style
// rather than CSS nth-child - some items (LPG Search/Bulk Search, the
// whole Account section) only render conditionally, so a fixed position
// count in CSS would be fragile; incrementing an index in PHP as each
// item is actually printed is not.
const SIDEBAR_NAV_COLORS = [
    [219, 39, 119], [124, 58, 237], [234, 88, 12], [5, 150, 105], [13, 148, 136],
    [37, 99, 235], [79, 70, 229], [217, 119, 6], [225, 29, 72], [2, 132, 199],
    [101, 163, 13], [192, 38, 211],
];
function sidebarNavColor(int $i): string {
    [$r, $g, $b] = SIDEBAR_NAV_COLORS[$i % count(SIDEBAR_NAV_COLORS)];
    return "$r,$g,$b";
}

$expiresAt    = $user['expires_at'] ?? null;
$expiresLabel = $expiresAt ? date('d-F-Y', strtotime($expiresAt)) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>lookup — Data Search</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= $bp ?>assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?: time() ?>">
</head>
<body>

<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar__top">
      <a class="sidebar__brand" href="<?= $bp ?>index.php">
        <span class="sidebar__brand-text">lookup</span>
      </a>
      <button class="sidebar__hamburger" id="sidebar-toggle" type="button" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
      </button>
    </div>

    <div class="sidebar__section-label">Search Regions</div>
    <nav class="sidebar__group">
      <?php
      $navColorIndex = 0;
      foreach ($searchRegions as $region):
        $isActive = $currentPage === 'index.php' && $selectedState === $region['state'];
        $thisColorIndex = $navColorIndex++;
      ?>
        <a href="<?= $bp ?>index.php<?= $region['state'] !== '' ? '?state=' . urlencode($region['state']) : '' ?>"
           class="sidebar__item sidebar-state-item<?= $isActive ? ' active' : '' ?>"
           data-state="<?= htmlspecialchars($region['state']) ?>">
          <span class="sidebar__avatar" style="background:rgb(<?= sidebarNavColor($thisColorIndex) ?>)"><?= strtoupper(substr($region['label'], 0, 1)) ?></span>
          <?= htmlspecialchars($region['label']) ?>
        </a>
      <?php endforeach; ?>
      <?php foreach ($searchRegionsExtra as $region):
        $isActive = $currentPage === basename($region['href']);
        $thisColorIndex = $navColorIndex++;
      ?>
        <a href="<?= preg_match('#^https?://#', $region['href']) ? $region['href'] : $bp . $region['href'] ?>"
           class="sidebar__item<?= $isActive ? ' active' : '' ?>"
           <?= !empty($region['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
          <span class="sidebar__avatar" style="background:rgb(<?= sidebarNavColor($thisColorIndex) ?>)"><?= strtoupper(substr($region['label'], 0, 1)) ?></span>
          <?= htmlspecialchars($region['label']) ?>
          <?php if (!empty($region['external'])): ?>
            <i class="bi bi-box-arrow-up-right" style="margin-left:auto;font-size:11px;opacity:.6"></i>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar__section-label">Account</div>
    <nav class="sidebar__group">
      <?php if ($user['role'] === 'admin'):
        $adminNavItems = [
          ['page' => 'agents.php',             'href' => 'admin/agents.php',             'icon' => 'bi-people-fill',       'label' => 'Agents'],
          ['page' => 'logs.php',               'href' => 'admin/logs.php',               'icon' => 'bi-journal-text',      'label' => 'Audit Log'],
          ['page' => 'import.php',             'href' => 'admin/import.php',             'icon' => 'bi-cloud-upload-fill', 'label' => 'Import'],
          ['page' => 'ecommerce_import.php',   'href' => 'admin/ecommerce_import.php',   'icon' => 'bi-cart-fill',         'label' => 'E-Comm Import'],
          ['page' => 'lpg_settings.php',       'href' => 'admin/lpg_settings.php',       'icon' => 'bi-key-fill',          'label' => 'LPG Settings'],
          ['page' => 'whatsapp_settings.php',  'href' => 'admin/whatsapp_settings.php',  'icon' => 'bi-whatsapp',          'label' => 'WhatsApp Settings'],
        ];
        foreach ($adminNavItems as $item):
          $isActive = $currentPage === $item['page'];
          $thisColorIndex = $navColorIndex++;
      ?>
        <a href="<?= $bp . $item['href'] ?>" class="sidebar__item<?= $isActive ? ' active' : '' ?>">
          <span class="sidebar__icon" style="color:rgb(<?= sidebarNavColor($thisColorIndex) ?>)"><i class="bi <?= $item['icon'] ?>"></i></span> <?= htmlspecialchars($item['label']) ?>
        </a>
      <?php endforeach; endif; ?>
    </nav>

    <div class="sidebar__footer">
      <div class="sidebar__footer-avatar"><?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?></div>
      <div class="sidebar__footer-info">
        <div class="sidebar__footer-name"><?= htmlspecialchars($user['full_name'] ?? '') ?></div>
        <div class="sidebar__footer-email"><?= htmlspecialchars($user['username'] ?? '') ?></div>
      </div>
      <a href="<?= $bp ?>logout.php" class="sidebar__footer-logout" title="Logout">
        <i class="bi bi-box-arrow-right"></i>
      </a>
    </div>
  </aside>

  <div class="app-main">
    <div class="app-topbar">
      <div class="app-topbar__expiry">
        <i class="bi bi-calendar3"></i>
        <?= $expiresLabel ? 'Expires: ' . htmlspecialchars($expiresLabel) : 'No expiry' ?>
      </div>
      <span class="topbar__role topbar__role--<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span>
      <a href="<?= $bp ?>logout.php" class="app-topbar__logout">
        <i class="bi bi-box-arrow-right"></i> Logout
      </a>
    </div>
    <main class="container">

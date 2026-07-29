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
    ['label' => 'PAN India', 'href' => 'pan_india.php'],
    ['label' => 'E Commerce', 'href' => 'ecommerce.php'],
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

$expiresAt    = $user['expires_at'] ?? null;
$expiresLabel = $expiresAt ? date('d-F-Y', strtotime($expiresAt)) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'dark');</script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CRM Portal — Data Search</title>
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
        <div class="sidebar__brand-icon"><i class="bi bi-diagram-3-fill"></i></div>
        CRM Portal
      </a>
      <button class="sidebar__hamburger" id="sidebar-toggle" type="button" aria-label="Toggle menu">
        <i class="bi bi-list"></i>
      </button>
    </div>

    <div class="sidebar__section-label">Search Regions</div>
    <nav class="sidebar__group">
      <?php foreach ($searchRegions as $region): ?>
        <a href="<?= $bp ?>index.php<?= $region['state'] !== '' ? '?state=' . urlencode($region['state']) : '' ?>"
           class="sidebar__item sidebar-state-item<?= ($currentPage === 'index.php' && $selectedState === $region['state']) ? ' active' : '' ?>"
           data-state="<?= htmlspecialchars($region['state']) ?>">
          <span class="sidebar__avatar"><?= strtoupper(substr($region['label'], 0, 1)) ?></span>
          <?= htmlspecialchars($region['label']) ?>
        </a>
      <?php endforeach; ?>
      <?php foreach ($searchRegionsExtra as $region): ?>
        <a href="<?= preg_match('#^https?://#', $region['href']) ? $region['href'] : $bp . $region['href'] ?>"
           class="sidebar__item<?= $currentPage === basename($region['href']) ? ' active' : '' ?>"
           <?= !empty($region['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
          <span class="sidebar__avatar"><?= strtoupper(substr($region['label'], 0, 1)) ?></span>
          <?= htmlspecialchars($region['label']) ?>
          <?php if (!empty($region['external'])): ?>
            <i class="bi bi-box-arrow-up-right" style="margin-left:auto;font-size:11px;opacity:.6"></i>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar__section-label">Account</div>
    <nav class="sidebar__group">
      <?php if ($user['role'] === 'admin'): ?>
        <a href="<?= $bp ?>admin/agents.php" class="sidebar__item<?= $currentPage === 'agents.php' ? ' active' : '' ?>">
          <span class="sidebar__icon"><i class="bi bi-people-fill"></i></span> Agents
        </a>
        <a href="<?= $bp ?>admin/logs.php" class="sidebar__item<?= $currentPage === 'logs.php' ? ' active' : '' ?>">
          <span class="sidebar__icon"><i class="bi bi-journal-text"></i></span> Audit Log
        </a>
        <a href="<?= $bp ?>admin/import.php" class="sidebar__item<?= $currentPage === 'import.php' ? ' active' : '' ?>">
          <span class="sidebar__icon"><i class="bi bi-cloud-upload-fill"></i></span> Import
        </a>
        <a href="<?= $bp ?>admin/ecommerce_import.php" class="sidebar__item<?= $currentPage === 'ecommerce_import.php' ? ' active' : '' ?>">
          <span class="sidebar__icon"><i class="bi bi-cart-fill"></i></span> E-Comm Import
        </a>
        <a href="<?= $bp ?>admin/lpg_settings.php" class="sidebar__item<?= $currentPage === 'lpg_settings.php' ? ' active' : '' ?>">
          <span class="sidebar__icon"><i class="bi bi-key-fill"></i></span> LPG Settings
        </a>
      <?php endif; ?>
      <button type="button" id="theme-toggle-btn" class="sidebar__item">
        <span class="sidebar__icon"><i class="bi bi-moon-stars-fill" id="theme-toggle-icon"></i></span>
        <span id="theme-toggle-label">Dark Mode</span>
      </button>
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

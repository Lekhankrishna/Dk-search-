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
];
// Advanced Search is opt-in per account (Admin > Agents > "Advanced Search
// Access") - same pattern as RC Print below. Placed right after Kerala in
// the sidebar (i.e. first in this array, since $searchRegions - the
// customer-lookup states - renders immediately before this one) per
// explicit instruction, rather than grouped with the other external tools
// further down.
if (hasAdvancedSearchAccess()) {
    array_unshift($searchRegionsExtra, ['label' => 'Advanced Search', 'href' => 'advanced_search.php']);
}
// Tracing 2.0 is opt-in per account (Admin > Agents > "Tracing 2.0
// Access") - drives every locateme.services tool (Mobile Info, Vehicle
// Intelligence, UPI Finder, etc. - see includes/tracing2_tools.php) as
// tabs on one page, via Gas/lpg_web/tracing2_tools.py's generic scraper.
// RC Print and HP Gas Advanced (2026-08-17) are folded in here as tabs
// too, replacing their own former standalone sidebar entries below - each
// still gated by its own pre-existing access flag
// (hasRcPrintAccess()/hasHpGasAccess()), checked inside tracing2.php
// itself for tab visibility rather than here, since they're not separate
// nav items anymore. Placed directly under Advanced Search per explicit
// instruction - inserted at index 1 so it lands right after Advanced
// Search regardless of whether Advanced Search itself was unshifted above
// (index 0) or this account doesn't have that access (in which case it
// simply becomes the new first item).
if (hasTracing2Access()) {
    $tracing2Index = hasAdvancedSearchAccess() ? 1 : 0;
    array_splice($searchRegionsExtra, $tracing2Index, 0, [['label' => 'Tracing 2.0', 'href' => 'tracing2.php']]);
}
// Pan India is opt-in per account (Admin > Agents > "Pan India Access"),
// same as LPG Search below (2026-08-19 - previously unconditional for every
// logged-in user; migrate_add_pan_india_access.sql defaults existing
// accounts to still-granted, so this doesn't change anyone's access on its
// own, it just makes it revocable). pan_india.php/api/pan_india.php enforce
// the same check server-side, so this is purely about not showing a link
// the user can't use, not the actual access control.
if (hasPanIndiaAccess()) {
    $searchRegionsExtra[] = ['label' => 'Pan India', 'href' => 'pan_india.php'];
}
// Advance Pan India is opt-in per account (Admin > Agents > "Advance Pan
// India Access") - backed by theeagleeye.biz's Advanced Search tool via
// includes/eagleeye_client.php (plain PHP+curl, no browser automation
// needed - see that file's own comment on why).
if (hasEagleEyeAccess()) {
    $searchRegionsExtra[] = ['label' => 'Advance Pan India', 'href' => 'advance_pan_india.php'];
}
// Night Out is opt-in per account (Admin > Agents > "Night Out
// Access") - backed by a third-party JSON search API via
// includes/pan_india_pro_client.php (plain PHP+curl, no browser automation
// needed - see that file's own comment on why). Positioned right below
// Advance Pan India per explicit instruction.
if (hasPanIndiaProAccess()) {
    $searchRegionsExtra[] = ['label' => 'Night Out', 'href' => 'pan_india_pro.php'];
}
// LPG Search is opt-in per account (Admin > Agents > "LPG Search Access") —
// only add the menu item at all when the current user has been granted it.
// The pages/APIs enforce the same check server-side (403) regardless, so
// this is purely about not showing a link the user can't use, not the
// actual access control. Labelled "Indian LPG Search" (not just "LPG
// Search") now that HP Gas Search also exists, so the two aren't ambiguous
// in the sidebar.
if (false && hasLpgSearchAccess()) {
    // Single Search and Bulk Search used to be two separate pages/sidebar
    // entries (lpg_search.php / lpg_bulk_search.php); combined into one page
    // with mode tabs (2026-08-11, same tabbed pattern as hp_gas.php) since
    // both hit the same Flask backend and render an identical results table -
    // lpg_bulk_search.php now just redirects here with ?mode=bulk for any
    // old bookmarks/links.
    //
    // Sidebar link hidden per explicit instruction (2026-08-18) - the
    // `false &&` above is deliberate so this is a one-line revert (just
    // remove it) rather than deleting the block. hasLpgSearchAccess(),
    // lpg_search.php, and lpg_search_api.php are untouched - a direct
    // link/bookmark still works for anyone who already has access.
    $searchRegionsExtra[] = ['label' => 'Indian LPG Search', 'href' => 'lpg_search.php'];
}
// Dedicated single-purpose page for the "Indane Gas Info" tool
// (indane_gas_info.php), placed directly below Indian LPG Search since
// agents look up both for the same customer. Has its own dedicated access
// flag + count-based monthly quota (indane_gas_access, promoted out of the
// generic Tracing 2.0 per-tool checklist 2026-08-18 - see
// includes/tracing2_tools.php's own comment on why), same pattern as RC
// Print/HP Gas Search.
if (hasIndaneGasAccess()) {
    $searchRegionsExtra[] = ['label' => 'Indane Gas', 'href' => 'indane_gas_info.php'];
}
// HP Gas Search's own standalone sidebar entry (moved below Indane Gas per
// explicit instruction, 2026-08-19) - alongside its HP Gas Advanced tab
// inside Tracing 2.0 above, same hasHpGasAccess() flag gates both.
if (hasHpGasAccess()) {
    $searchRegionsExtra[] = ['label' => 'HP Gas Search', 'href' => 'hp_gas.php'];
}
// RC Print's own standalone sidebar entry (moved below HP Gas Search per
// explicit instruction, 2026-08-19) - alongside its RC Print tab inside
// Tracing 2.0 above, same hasRcPrintAccess() flag gates both.
if (hasRcPrintAccess()) {
    $searchRegionsExtra[] = ['label' => 'RC Print', 'href' => 'rc_print.php'];
}
// Tata Play is opt-in per account (Admin > Agents > "Tata Play Access") -
// same pattern as HP Gas Search above (own tataplay.py Selenium automation
// against the distributor's mysso.tataplay.com SSO login, proxied through
// tataplay_api.php). Placed directly below Indian LPG Search per explicit
// instruction.
if (hasTataPlayAccess()) {
    $searchRegionsExtra[] = ['label' => 'TATA SKY DTH', 'href' => 'tataplay.php'];
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
  <script>document.documentElement.setAttribute('data-theme', localStorage.getItem('crm-theme') || 'light');</script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DK Search — Data Search</title>
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
        <img src="<?= $bp ?>assets/img/dk-search-mark.png" alt="DK Search" class="sidebar__brand-logo">
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
        // Advanced Search's avatar is pinned dark per explicit instruction,
        // rather than the auto-cycled palette every other item uses -
        // $navColorIndex still increments normally so it doesn't shift any
        // other item's color.
        $avatarColor = $region['label'] === 'Advanced Search' ? '31,41,55' : sidebarNavColor($thisColorIndex);
      ?>
        <a href="<?= preg_match('#^https?://#', $region['href']) ? $region['href'] : $bp . $region['href'] ?>"
           class="sidebar__item<?= $isActive ? ' active' : '' ?>"
           <?= !empty($region['external']) ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
          <span class="sidebar__avatar" style="background:rgb(<?= $avatarColor ?>)"><?= strtoupper(substr($region['label'], 0, 1)) ?></span>
          <?php if ($region['label'] === 'Advanced Search'): ?>
            <span style="font-weight:700"><?= htmlspecialchars($region['label']) ?></span>
          <?php else: ?>
            <?= htmlspecialchars($region['label']) ?>
          <?php endif; ?>
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
      <!-- Mobile-only (see .app-topbar__menu-btn's @media rule) - the
           sidebar's own #sidebar-toggle button lives inside <aside>, which
           is exactly what's translated off-screen on narrow viewports
           (see .sidebar's @media(max-width:768px) rule), so without a
           second toggle out here the sidebar becomes completely
           unreachable below that width, not just hidden. -->
      <button class="app-topbar__menu-btn" id="mobile-sidebar-toggle" type="button" aria-label="Open menu">
        <i class="bi bi-list"></i>
      </button>
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

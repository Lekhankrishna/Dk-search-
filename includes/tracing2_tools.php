<?php
// Shared tool registry for Tracing 2.0 (locateme.services) - mirrors
// Gas/lpg_web/tracing2_tools.py's TOOL_REGISTRY (label + input placeholder +
// credit cost), used by tracing2.php (tool picker UI), tracing2_api.php
// (server-side allowlist), and admin/agents.php (per-tool access
// checklist) so all three can never drift out of sync with each other.
// whatsapp-dp is a known exception - it returns an image, not label/value
// fields, so it's left in for completeness but its result will just show
// as raw page text (see tracing2_tools.py's module docstring).
//
// rc-print and hp-gas-advanced (2026-08-17, per explicit instruction) are
// folded in here as tabs too, replacing the standalone RC Print/HP Gas
// Search sidebar pages - but each keeps its OWN pre-existing access flag
// and monthly-limit quota ('requiresAccess' below), rather than falling
// under tracing2_access's quota. Both were already separately granted per
// agent (Admin > Agents > "RC Print"/"HP Gas Search") before this change,
// and both spend far more credits per search (150 each) than a typical
// Tracing 2.0 tool - folding them into the shared tracing2_monthly_limit
// would either strip existing agents of access they already have, or let
// every Tracing 2.0 user suddenly burn through RC Print/HP Gas's expensive
// per-search budget. tracing2.php only shows these two tabs to agents who
// already have the specific matching access; tracing2_api.php enforces
// the same specific access + quota server-side, same as their old
// standalone pages did. tracing2SelectableTools() below excludes both from
// the per-tool checklist for the same reason - they're managed by their
// own existing checkboxes in Admin > Agents, not this new one.
//
// indane-gas-info was promoted the same way (2026-08-18) - it used to be a
// generic per-tool-checklist entry, but is common/important enough to get
// its own dedicated Feature Access checkbox + count-based monthly quota
// (indane_gas_access/indane_gas_monthly_limit, migrate_add_indane_gas_access.sql)
// instead of drawing from the shared tracing2_monthly_limit credit budget.
const TRACING2_TOOLS = [
    'mobile-info'             => ['label' => 'Mobile Info',            'placeholder' => 'Enter Mobile Number',   'credits' => 100],
    'rc-print'                => ['label' => 'RC PRINT',               'placeholder' => 'Enter Vehicle Number',  'credits' => 150, 'requiresAccess' => 'rc_print'],
    'hp-gas-advanced'         => ['label' => 'HP Gas Advanced',        'placeholder' => 'Enter Mobile Number',   'credits' => 150, 'requiresAccess' => 'hp_gas'],
    'vehicle-info'            => ['label' => 'Vehicle Intelligence',   'placeholder' => 'Enter Vehicle Number',  'credits' => 100],
    'aadhaar-info'            => ['label' => 'Aadhaar Info',           'placeholder' => 'Enter Aadhaar Number',  'credits' => 100],
    'sms-header-decode'       => ['label' => 'SMS Header Decode',      'placeholder' => 'e.g. SGILTD',           'credits' => 1],
    'imei-info'               => ['label' => 'IMEI Info',              'placeholder' => 'Enter 15-digit IMEI',   'credits' => null],
    'aadhaar-to-ration'       => ['label' => 'Aadhaar to Ration',      'placeholder' => 'Enter Aadhaar Number',  'credits' => 50],
    'number-to-name'          => ['label' => 'Number to Name',         'placeholder' => 'Enter Mobile Number',   'credits' => 10],
    'number-to-facebook'      => ['label' => 'Number to Facebook',     'placeholder' => 'Enter Mobile Number',   'credits' => 5],
    'whatsapp-dp'             => ['label' => 'WhatsApp DP Downloader', 'placeholder' => 'Enter Mobile Number',   'credits' => null],
    'aadhaar-to-pan'          => ['label' => 'Aadhaar to PAN',         'placeholder' => 'Enter Aadhaar Number',  'credits' => 50],
    'pan-to-gst'              => ['label' => 'PAN to GST',             'placeholder' => 'e.g. ARCPV7418G',       'credits' => 15],
    'vehicle-to-number'       => ['label' => 'Vehicle to Number',      'placeholder' => 'e.g. UP70HQ2225',       'credits' => 50],
    'indane-gas-info'         => ['label' => 'Indane Gas',             'placeholder' => 'Enter 10-digit Number', 'credits' => 100, 'requiresAccess' => 'indane_gas'],
    'indane-gas-verification' => ['label' => 'Indane Gas v2',          'placeholder' => 'Enter Mobile Number',   'credits' => 75],
    'bharat-gas-info'         => ['label' => 'Bharat Gas Info',        'placeholder' => 'Enter Number',          'credits' => null],
    'gmail-info'              => ['label' => 'Gmail Info',             'placeholder' => 'example@gmail.com',     'credits' => 35],
    'pan-info'                => ['label' => 'PAN Info',               'placeholder' => 'Enter PAN Number',      'credits' => 10],
    'vehicle-fastag'          => ['label' => 'Vehicle Fastag',         'placeholder' => 'e.g. DL10C1234',        'credits' => 50],
    'upi-finder'              => ['label' => 'UPI Finder',             'placeholder' => 'e.g. 7982966659',       'credits' => 25],
    'gst-info'                => ['label' => 'GST Info',               'placeholder' => 'e.g. 09AAKCD6139J1Z8',  'credits' => 25],
    'ifsc-info'               => ['label' => 'IFSC Info',              'placeholder' => 'e.g. SBIN0005383',      'credits' => null],
    'ip-info'                 => ['label' => 'IP Info',                'placeholder' => 'e.g. 8.8.8.8',          'credits' => null],
    'email-leak-check'        => ['label' => 'Email Leak Check',       'placeholder' => 'user@example.com',      'credits' => null],
    'sim-carrier-checker'     => ['label' => 'SIM Carrier Checker',    'placeholder' => 'Enter Mobile Number',   'credits' => null],
];

// The subset Admin > Agents' per-tool checklist actually offers - every
// TRACING2_TOOLS entry except rc-print/hp-gas-advanced (see the const's own
// comment on why those two are excluded).
function tracing2SelectableTools(): array {
    return array_filter(TRACING2_TOOLS, fn($t) => !isset($t['requiresAccess']));
}

// A handful of tools have never actually been searched live, so their real
// locateme.services cost is unconfirmed (credits => null above - see each
// one's discovery note in Gas/lpg_web/tracing2_tools.py's TOOL_REGISTRY).
// tracing2_api.php's credit-budget quota (2026-08-17) still needs SOME
// number to charge against the monthly limit for these, rather than
// treating them as free - a mid-range placeholder, roughly the average of
// the confirmed costs (1-150), errs toward not undercharging. Update the
// registry's real 'credits' value directly once a tool's actual cost is
// observed from a real search, same as every already-confirmed entry was.
const TRACING2_UNKNOWN_COST_CREDITS = 30;

function tracing2CreditsFor(string $toolSlug): int {
    $credits = TRACING2_TOOLS[$toolSlug]['credits'] ?? null;
    return $credits ?? TRACING2_UNKNOWN_COST_CREDITS;
}

// Same as tracing2CreditsFor(), but checks the searching agent's own
// per-tool override first (Admin > Agents > "Tracing 2.0 — Select Tools",
// users.tracing2_tool_credits - see migrate_add_tracing2_tool_credits.sql
// and getUserTracing2ToolCredits()). $userOverrides is null (agent has
// never had any overrides saved) or a partial slug => credits map; a slug
// missing from that map still falls back to the global default.
function tracing2CreditsForUser(string $toolSlug, ?array $userOverrides): int {
    if ($userOverrides !== null && isset($userOverrides[$toolSlug])) {
        return (int) $userOverrides[$toolSlug];
    }
    return tracing2CreditsFor($toolSlug);
}

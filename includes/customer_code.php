<?php
// Allocates CUST#### numbers without scanning the target customer table.
//
// The old approach — SELECT customer_code ... WHERE customer_code REGEXP
// '^CUST[0-9]+$' ORDER BY CAST(SUBSTRING(customer_code, 5) AS UNSIGNED) DESC
// LIMIT 1 — can't use an index (REGEXP + a computed ORDER BY expression force
// a full table scan + sort) and measured 110s on an 80M-row table, well past
// PHP's execution time limit on the web importer and just wasted time on the
// CLI one. customer_code_counters tracks the last-allocated number per state
// instead, updated atomically here.
//
// state_key is the STATE's primary table name (e.g. 'customers_karnataka'),
// even when allocating for a "_part2" table — codes must stay unique across
// a state's main table and any part2 table together, so they share one
// counter (matches the convention already used by cli/import_karnataka_bdata.php
// and cli/import_kerala_dhanush.php, which read next-code from the main table).

// Reserves a block of $blockSize consecutive numbers and returns the last
// number BEFORE the reserved block — the caller's first usable code is
// reserveCustomerCodeBlock(...) + 1, and it may hand out up to $blockSize
// codes total from that starting point without needing to reserve again.
function reserveCustomerCodeBlock(PDO $pdo, string $stateKey, int $blockSize): int {
    $wasInTransaction = $pdo->inTransaction();
    if (!$wasInTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT last_code_num FROM customer_code_counters WHERE state_key = :k FOR UPDATE');
        $stmt->execute(['k' => $stateKey]);
        $row = $stmt->fetch();
        $current = $row ? (int) $row['last_code_num'] : 0;
        $new = $current + $blockSize;

        if ($row) {
            $pdo->prepare('UPDATE customer_code_counters SET last_code_num = :new WHERE state_key = :k')
                ->execute(['new' => $new, 'k' => $stateKey]);
        } else {
            // No counter row yet for this state (e.g. a new state added after this
            // migration) — start from 0 rather than fail the import.
            $pdo->prepare('INSERT INTO customer_code_counters (state_key, last_code_num) VALUES (:k, :new)')
                ->execute(['k' => $stateKey, 'new' => $new]);
        }
        if (!$wasInTransaction) $pdo->commit();
        return $current;
    } catch (Throwable $e) {
        if (!$wasInTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

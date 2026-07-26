<?php
require_once __DIR__ . '/../config/secrets.php';

// AES-256-GCM: authenticated encryption, so a tampered ciphertext fails to
// decrypt rather than silently returning garbage. IV + auth tag are stored
// alongside the ciphertext (both safe to store in the clear — GCM's security
// doesn't depend on hiding them, only the key).
function lpgEncrypt(string $plaintext): array {
    global $LPG_ENC_KEY;
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plaintext, 'aes-256-gcm', $LPG_ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return ['ciphertext' => $ct, 'iv' => $iv, 'tag' => $tag];
}

function lpgDecrypt(string $ciphertext, string $iv, string $tag): ?string {
    global $LPG_ENC_KEY;
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $LPG_ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? null : $plaintext;
}

// The value embedded in an agent's bookmarklet — a revocable capability key,
// not the SDMS password itself. Generated once per agent and reused after
// that; regenerate (Admin > Agents) to invalidate a leaked bookmarklet
// without having to rotate the shared SDMS password for everyone else.
function lpgEnsureBookmarkletKey(PDO $pdo, int $userId): string {
    $stmt = $pdo->prepare('SELECT lpg_bookmarklet_key FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $key = $stmt->fetchColumn();
    if ($key) return $key;
    $key = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET lpg_bookmarklet_key = :key WHERE id = :id')
        ->execute(['key' => $key, 'id' => $userId]);
    return $key;
}

<?php
declare(strict_types=1);

/** Gibt einen neuen Zufallsschlüssel für config/config.php aus (app.secret). */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
echo "app.secret          = " . base64_encode(random_bytes(32)) . "\n";
echo "\nWICHTIG: Änderung von app.secret macht TOTP-Secrets (verschlüsselt), Recovery-Codes, pairwise 'sub'-Werte und WebAuthn-User-Handles ungültig bzw. anders.\n"
   . "Siehe docs/BETRIEB.md.\n";

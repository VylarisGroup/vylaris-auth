<?php
declare(strict_types=1);

/**
 * Notfall-/Wiederherstellungswerkzeug (nur CLI): legt einen Superadmin an oder setzt Passwort/MFA/Sperre eines Kontos zurück.
 *   php bin/create-admin.php anna@example.org "Anna Muster"        (Passwort wird abgefragt)
 *   php bin/create-admin.php anna@example.org --reset-mfa
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
require $root . '/src/autoload.php';

$app = Vylaris\Core\Bootstrap::app($root);
$email = Vylaris\Repository\UserRepository::normalizeEmail($argv[1] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Aufruf: php bin/create-admin.php <email> [\"Name\"|--reset-mfa]\n");
    exit(2);
}
$user = $app->users->findByEmail($email);
if (in_array('--reset-mfa', $argv, true)) {
    if ($user === null) {
        fwrite(STDERR, "Konto nicht gefunden.\n");
        exit(1);
    }
    $id = (int) $user['id'];
    foreach (['mfa_totp', 'webauthn_credentials', 'mfa_recovery_codes'] as $t) {
        $app->db->run("DELETE FROM $t WHERE user_id = ?", [$id]);
    }
    $app->sessions->revokeAll($id);
    $app->audit->log('cli.mfa_reset', $id);
    echo "MFA zurückgesetzt, Sitzungen beendet.\n";
    exit(0);
}
echo 'Neues Passwort (mind. 12 Zeichen): ';
if (stripos(PHP_OS, 'WIN') !== 0) {
    system('stty -echo');
}
$pw = trim((string) fgets(STDIN));
if (stripos(PHP_OS, 'WIN') !== 0) {
    system('stty echo');
}
echo "\n";
if (mb_strlen($pw) < 12) {
    fwrite(STDERR, "Passwort zu kurz.\n");
    exit(1);
}
$hash = $app->passwords->hash($pw);
if ($user === null) {
    $name = trim($argv[2] ?? '') ?: 'Administrator';
    $id = $app->users->create($email, $hash, $name, true);
    $app->users->assignRole($id, 'user');
} else {
    $id = (int) $user['id'];
    $app->users->setPasswordHash($id, $hash);
    $app->users->setLocked($id, false);
    $app->sessions->revokeAll($id);
}
$app->users->assignRole($id, 'superadmin');
$app->audit->log('cli.admin_created_or_reset', $id);
echo "Superadmin bereit: $email\n";

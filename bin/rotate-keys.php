<?php
declare(strict_types=1);

/** Erzeugt einen neuen OIDC-Signaturschlüssel; der alte bleibt 24 h im JWKS. Aufruf: php bin/rotate-keys.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
require $root . '/src/autoload.php';

$app = Vylaris\Core\Bootstrap::app($root);
$key = $app->keys->rotate();
$app->audit->log('admin.key_rotated', null, ['kid' => $key['kid'], 'via' => 'cli']);
echo "Neuer aktiver Schlüssel: {$key['kid']}\n";

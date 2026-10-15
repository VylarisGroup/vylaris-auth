<?php
declare(strict_types=1);

namespace Vylaris\Security;

/** Verschlüsselt ruhende Geheimnisse (libsodium secretbox, Schlüssel aus app.secret je Verwendungszweck abgeleitet). */
final class SecretBox
{
    private string $key;

    public function __construct(string $masterKey, string $context)
    {
        $this->key = sodium_crypto_generichash($context, $masterKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Beschädigtes Geheimnis.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Geheimnis konnte nicht entschlüsselt werden (app.secret geändert?).');
        }
        return $plain;
    }
}

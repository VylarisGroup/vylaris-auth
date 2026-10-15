<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

use Vylaris\Core\{Clock, Config, Database};

/** Signaturschlüssel (RSA-2048, RS256): Erzeugung, Rotation, JWKS. Private Schlüssel liegen als Dateien außerhalb des Webroots. */
final class KeyService
{
    /** Nach der Rotation bleibt der alte öffentliche Schlüssel so lange im JWKS (Token-Laufzeit + Puffer). */
    public const RETIRED_GRACE_SECONDS = 86400;

    public function __construct(private Database $db, private Config $config)
    {
    }

    private function dir(): string
    {
        return (string) $this->config->get('oidc.private_key_dir', dirname(__DIR__, 2) . '/var/keys');
    }

    /** Stellt sicher, dass ein aktiver Schlüssel existiert. */
    public function ensureActiveKey(): array
    {
        $active = $this->active();
        if ($active !== null && !is_readable($this->privatePath($active))) {
            // Datenbankeintrag vorhanden, Schlüsseldatei fehlt (z. B. var/keys/ beim Update überschrieben oder
            // Datenbank aus anderer Installation). Ohne privaten Schlüssel kann nichts mehr signiert werden:
            // alten Eintrag ausmustern (der öffentliche Teil bleibt zur Prüfung bereits ausgestellter Tokens im JWKS)
            // und einen neuen Schlüssel erzeugen.
            $this->db->run("UPDATE oidc_keys SET status = 'retired', retired_at = ? WHERE kid = ?", [Clock::db(), $active['kid']]);
            return $this->generate();
        }
        return $active ?? $this->generate();
    }

    public function active(): ?array
    {
        return $this->db->one("SELECT * FROM oidc_keys WHERE status = 'active' ORDER BY created_at DESC, kid DESC LIMIT 1");
    }

    public function generate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('Schlüsselerzeugung fehlgeschlagen (OpenSSL).');
        }
        openssl_pkey_export($key, $privatePem);
        $public = openssl_pkey_get_details($key)['key'];
        $kid = substr(hash('sha256', $public), 0, 16);

        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Schlüsselverzeichnis kann nicht angelegt werden.');
        }
        $file = $kid . '.pem';
        $path = $dir . '/' . $file;
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $privatePem, LOCK_EX) === false) {
            throw new \RuntimeException('Privater Schlüssel kann nicht geschrieben werden.');
        }
        @chmod($tmp, 0600);
        rename($tmp, $path);
        // Öffentlicher Schlüssel zusätzlich als Datei (bequem für Diagnose; maßgeblich ist die Tabelle oidc_keys).
        file_put_contents($dir . '/' . $kid . '.pub', $public, LOCK_EX);

        $this->db->insert('oidc_keys', [
            'kid' => $kid,
            'public_pem' => $public,
            'private_file' => $file,
            'status' => 'active',
            'created_at' => Clock::db(),
        ]);
        return $this->db->one('SELECT * FROM oidc_keys WHERE kid = ?', [$kid]);
    }

    /** Rotation: neuer aktiver Schlüssel, bisherige werden "retired" (bleiben vorerst im JWKS). */
    public function rotate(): array
    {
        $this->db->run("UPDATE oidc_keys SET status = 'retired', retired_at = ? WHERE status = 'active'", [Clock::db()]);
        return $this->generate();
    }

    public function privatePem(array $keyRow): string
    {
        $path = $this->dir() . '/' . basename($keyRow['private_file']);
        $pem = @file_get_contents($path);
        if ($pem === false) {
            throw new \RuntimeException('Privater Schlüssel nicht lesbar: ' . basename($path));
        }
        return $pem;
    }

    public function publicPath(array $keyRow): string
    {
        $path = $this->dir() . '/' . basename($keyRow['kid']) . '.pub';
        if (!is_file($path)) {
            file_put_contents($path, $keyRow['public_pem'], LOCK_EX);
        }
        return $path;
    }

    public function privatePath(array $keyRow): string
    {
        return $this->dir() . '/' . basename($keyRow['private_file']);
    }

    /** Schlüssel, die für die Prüfung noch gültig sind (aktiv + kürzlich zurückgezogen). */
    public function verificationKeys(): array
    {
        return $this->db->all(
            "SELECT * FROM oidc_keys WHERE status = 'active' OR retired_at > ? ORDER BY created_at DESC, kid",
            [Clock::db(Clock::now() - self::RETIRED_GRACE_SECONDS)]
        );
    }

    /** JWKS (RFC 7517) – nur öffentliche Bestandteile. */
    public function jwks(): array
    {
        $keys = [];
        foreach ($this->verificationKeys() as $row) {
            $pub = openssl_pkey_get_public($row['public_pem']);
            $d = $pub ? openssl_pkey_get_details($pub) : false;
            if (!$d || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
                continue;
            }
            $keys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => $row['kid'],
                'n' => self::b64u($d['rsa']['n']),
                'e' => self::b64u($d['rsa']['e']),
            ];
        }
        return ['keys' => $keys];
    }

    /** Entfernt abgelaufene, zurückgezogene Schlüssel samt Datei. */
    public function purgeRetired(): int
    {
        $rows = $this->db->all("SELECT kid, private_file FROM oidc_keys WHERE status = 'retired' AND retired_at <= ?", [Clock::db(Clock::now() - self::RETIRED_GRACE_SECONDS)]);
        foreach ($rows as $r) {
            @unlink($this->dir() . '/' . basename($r['private_file']));
            @unlink($this->dir() . '/' . basename($r['kid']) . '.pub');
            $this->db->run('DELETE FROM oidc_keys WHERE kid = ?', [$r['kid']]);
        }
        return count($rows);
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}

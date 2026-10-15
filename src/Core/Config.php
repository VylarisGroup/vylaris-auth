<?php
declare(strict_types=1);

namespace Vylaris\Core;

final class Config
{
    public function __construct(private array $values)
    {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Konfigurationsdatei fehlt: ' . basename($file));
        }
        $values = require $file;
        if (!is_array($values)) {
            throw new \RuntimeException('Ungültige Konfiguration.');
        }
        return new self($values);
    }

    /** Überschreibt einen Wert zur Laufzeit (nicht persistent), z. B. Markenname aus dem Branding. */
    public function override(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $node = &$this->values;
        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = $node[$part] ?? [];
                if (!is_array($node[$part])) {
                    $node[$part] = [];
                }
            }
            $node = &$node[$part];
        }
        $node = $value;
    }

    /** Punkt-Notation, z. B. get('db.host'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->get('app.base_url', ''), '/');
    }

    public function secretKey(): string
    {
        $raw = base64_decode((string) $this->get('app.secret', ''), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \RuntimeException('app.secret fehlt oder ist zu kurz (mind. 32 Byte, base64).');
        }
        return $raw;
    }
}

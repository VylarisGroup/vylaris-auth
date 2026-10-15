<?php
declare(strict_types=1);

namespace Vylaris\Service;

final class PasswordService
{
    public function __construct(private int $minLength = 12)
    {
    }

    private function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private function options(): array
    {
        return defined('PASSWORD_ARGON2ID')
            ? ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1] // OWASP-Empfehlung (19 MiB, t=2)
            : ['cost' => 12];
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algo(), $this->options());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo(), $this->options());
    }

    /** Hash für Timing-Angleichung bei unbekannten Konten. */
    public function dummyHash(): string
    {
        static $h = null;
        return $h ??= $this->hash(bin2hex(random_bytes(16)));
    }

    /** @return string[] Fehlermeldungen (leer = ok) */
    public function validate(string $password, array $context = []): array
    {
        $errors = [];
        if (mb_strlen($password) < $this->minLength) {
            $errors[] = "Das Passwort muss mindestens {$this->minLength} Zeichen lang sein.";
        }
        if (strlen($password) > 1024) {
            $errors[] = 'Das Passwort ist zu lang.';
        }
        if (preg_match('/^(.)\1+$/u', $password)) {
            $errors[] = 'Das Passwort darf nicht aus einem einzigen wiederholten Zeichen bestehen.';
        }
        $lower = mb_strtolower($password);
        foreach ($context as $c) {
            $c = mb_strtolower(trim((string) $c));
            if (mb_strlen($c) >= 4 && str_contains($lower, $c)) {
                $errors[] = 'Das Passwort darf keine persönlichen Angaben (Name, E-Mail) enthalten.';
                break;
            }
        }
        if (in_array($lower, ['passwordpassword', 'password1234', '123456789012', 'qwertzuiopasdf', 'vylarisvylaris'], true)) {
            $errors[] = 'Dieses Passwort ist zu häufig.';
        }
        return $errors;
    }
}

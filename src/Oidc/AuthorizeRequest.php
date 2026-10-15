<?php
declare(strict_types=1);

namespace Vylaris\Oidc;

/** Ergebnis der Vorprüfung eines Authorization-Requests. */
final class AuthorizeRequest
{
    /** @param string[] $scopes @param string[] $prompt */
    public function __construct(
        public readonly ?array $client = null,
        public readonly string $redirectUri = '',
        public readonly array $scopes = [],
        public readonly string $state = '',
        public readonly string $nonce = '',
        public readonly array $prompt = [],
        public readonly ?int $maxAge = null,
        public readonly ?string $fatal = null,
        public readonly ?string $error = null,
        public readonly ?string $errorDescription = null,
    ) {
    }

    /** Fataler Fehler: dem Benutzer anzeigen, NIEMALS auf die (nicht vertrauenswürdige) redirect_uri umleiten. */
    public function isFatal(): bool
    {
        return $this->fatal !== null;
    }

    /** Fehler, der per Redirect (mit state) an den registrierten Client gemeldet wird. */
    public function isRedirectError(): bool
    {
        return $this->fatal === null && $this->error !== null;
    }

    public function ok(): bool
    {
        return $this->fatal === null && $this->error === null;
    }
}

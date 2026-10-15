<?php
declare(strict_types=1);

namespace Vylaris\Social;

final class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function json(): ?array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : null;
    }
}

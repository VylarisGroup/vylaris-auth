<?php
declare(strict_types=1);

namespace Vylaris\Social;

/** Minimaler ausgehender HTTP-Client (austauschbar, damit Tests ohne Netzwerk laufen). */
interface HttpClient
{
    /** @param string[] $headers "Name: Wert" */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

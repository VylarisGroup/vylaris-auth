<?php
declare(strict_types=1);

namespace Vylaris\Social;

use Vylaris\Core\Clock;
use Vylaris\Oidc\Jwt;

/** Prüft ID-Tokens externer Anbieter: Signatur (RS256 gegen JWKS), iss, aud/azp, exp, iat, nonce. */
final class IdTokenVerifier
{
    private const LEEWAY = 60;

    /**
     * @param array<int,array> $jwks   Schlüssel aus dem JWKS ("keys")
     * @param callable(array):bool $issuerOk  prüft den iss-Claim (ggf. abhängig von weiteren Claims)
     * @return array Claims
     * @throws SocialException
     */
    public static function verify(string $idToken, array $jwks, callable $issuerOk, string $clientId, string $nonce): array
    {
        $parsed = Jwt::parse($idToken);
        if ($parsed === null) {
            throw new SocialException('id_token_malformed');
        }
        [$header, $claims, $sig, $input] = $parsed;
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new SocialException('id_token_alg');
        }

        $pem = self::selectKey($header, $jwks);
        if ($pem === null || !Jwt::verifySignature($header, $sig, $input, $pem)) {
            throw new SocialException('id_token_signature');
        }

        $now = Clock::now();
        if (!is_string($claims['iss'] ?? null) || !$issuerOk($claims)) {
            throw new SocialException('id_token_iss');
        }
        $aud = $claims['aud'] ?? null;
        $audList = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $audList, true)) {
            throw new SocialException('id_token_aud');
        }
        if (count($audList) > 1 && ($claims['azp'] ?? null) !== $clientId) {
            throw new SocialException('id_token_azp');
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] + self::LEEWAY < $now) {
            throw new SocialException('id_token_expired');
        }
        if (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] - self::LEEWAY > $now)) {
            throw new SocialException('id_token_iat');
        }
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw new SocialException('id_token_nonce');
        }
        if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw new SocialException('id_token_sub');
        }
        return $claims;
    }

    private static function selectKey(array $header, array $jwks): ?string
    {
        $kid = $header['kid'] ?? null;
        $candidates = [];
        foreach ($jwks as $jwk) {
            if (!is_array($jwk) || ($jwk['kty'] ?? null) !== 'RSA' || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
                continue;
            }
            if ($kid !== null ? ($jwk['kid'] ?? null) === $kid : true) {
                $candidates[] = $jwk;
            }
        }
        // Ohne kid nur akzeptieren, wenn der Schlüssel eindeutig ist.
        if (count($candidates) !== 1) {
            return null;
        }
        return Jwk::rsaToPem($candidates[0]);
    }
}

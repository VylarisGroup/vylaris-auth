<?php
declare(strict_types=1);

namespace Vylaris\Service\WebAuthn;

/**
 * Strikte WebAuthn-Prüfung (Level 2/3, Attestation "none"): Challenge, Origin, RP-ID-Hash,
 * User-Presence/-Verification, Signatur (ES256/RS256 via OpenSSL) und Signaturzähler.
 * Attestation-Statements werden bewusst nicht vertraut (Privacy, "none"-Semantik).
 */
final class Verifier
{
    public const FLAG_UP = 0x01;
    public const FLAG_UV = 0x04;
    public const FLAG_AT = 0x40;

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]*$/', $s)) {
            throw new WebAuthnException('Ungültige Base64url-Daten.');
        }
        $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($bin === false) {
            throw new WebAuthnException('Ungültige Base64url-Daten.');
        }
        return $bin;
    }

    /**
     * @param array $credential PublicKeyCredential als JSON-Array (id, rawId, type, response{clientDataJSON, attestationObject})
     * @return array{credentialId:string,publicKeyPem:string,alg:int,signCount:int,aaguid:string,uv:bool,backupEligible:bool}
     */
    public function verifyRegistration(array $credential, string $expectedChallenge, string $origin, string $rpId, bool $requireUv = false): array
    {
        $response = $credential['response'] ?? null;
        if (($credential['type'] ?? null) !== 'public-key' || !is_array($response)
            || !is_string($response['clientDataJSON'] ?? null) || !is_string($response['attestationObject'] ?? null)
            || !is_string($credential['rawId'] ?? null)) {
            throw new WebAuthnException('Ungültige Registrierungsantwort.');
        }
        $this->checkClientData(self::b64uDecode($response['clientDataJSON']), 'webauthn.create', $expectedChallenge, $origin);

        try {
            $att = Cbor::decode(self::b64uDecode($response['attestationObject']));
        } catch (\InvalidArgumentException $e) {
            throw new WebAuthnException('Ungültiges Attestation-Objekt.');
        }
        if (!is_array($att) || !is_string($att['authData'] ?? null)) {
            throw new WebAuthnException('Attestation ohne authData.');
        }
        $authData = $att['authData'];
        $parsed = $this->parseAuthData($authData, $rpId);
        if (!($parsed['flags'] & self::FLAG_UP)) {
            throw new WebAuthnException('Benutzeranwesenheit (UP) fehlt.');
        }
        if ($requireUv && !($parsed['flags'] & self::FLAG_UV)) {
            throw new WebAuthnException('Benutzerverifikation (UV) erforderlich.');
        }
        if (!($parsed['flags'] & self::FLAG_AT)) {
            throw new WebAuthnException('Keine Credential-Daten enthalten.');
        }

        $o = 37;
        if (strlen($authData) < $o + 18) {
            throw new WebAuthnException('authData zu kurz.');
        }
        $aaguid = bin2hex(substr($authData, $o, 16));
        $idLen = unpack('n', substr($authData, $o + 16, 2))[1];
        if ($idLen < 16 || $idLen > 1023 || strlen($authData) < $o + 18 + $idLen) {
            throw new WebAuthnException('Ungültige Credential-ID-Länge.');
        }
        $credentialId = substr($authData, $o + 18, $idLen);
        if (!hash_equals($credentialId, self::b64uDecode($credential['rawId']))) {
            throw new WebAuthnException('Credential-ID stimmt nicht überein.');
        }
        try {
            [$cose] = Cbor::decodePartial($authData, $o + 18 + $idLen);
        } catch (\InvalidArgumentException $e) {
            throw new WebAuthnException('Ungültiger öffentlicher Schlüssel.');
        }
        if (!is_array($cose)) {
            throw new WebAuthnException('Ungültiger öffentlicher Schlüssel.');
        }
        $key = CoseKey::toPem($cose);

        return [
            'credentialId' => self::b64uEncode($credentialId),
            'publicKeyPem' => $key['pem'],
            'alg' => $key['alg'],
            'signCount' => $parsed['signCount'],
            'aaguid' => $aaguid,
            'uv' => (bool) ($parsed['flags'] & self::FLAG_UV),
            'backupEligible' => (bool) ($parsed['flags'] & 0x08),
        ];
    }

    /**
     * @return array{signCount:int,uv:bool,userHandle:?string}
     */
    public function verifyAssertion(array $credential, string $expectedChallenge, string $origin, string $rpId, string $publicKeyPem, int $alg, int $storedSignCount, bool $requireUv): array
    {
        $response = $credential['response'] ?? null;
        if (($credential['type'] ?? null) !== 'public-key' || !is_array($response)
            || !is_string($response['clientDataJSON'] ?? null) || !is_string($response['authenticatorData'] ?? null)
            || !is_string($response['signature'] ?? null)) {
            throw new WebAuthnException('Ungültige Anmeldeantwort.');
        }
        $clientDataRaw = self::b64uDecode($response['clientDataJSON']);
        $this->checkClientData($clientDataRaw, 'webauthn.get', $expectedChallenge, $origin);

        $authData = self::b64uDecode($response['authenticatorData']);
        $parsed = $this->parseAuthData($authData, $rpId);
        if (!($parsed['flags'] & self::FLAG_UP)) {
            throw new WebAuthnException('Benutzeranwesenheit (UP) fehlt.');
        }
        if ($requireUv && !($parsed['flags'] & self::FLAG_UV)) {
            throw new WebAuthnException('Benutzerverifikation (UV) erforderlich.');
        }

        $signedData = $authData . hash('sha256', $clientDataRaw, true);
        $signature = self::b64uDecode($response['signature']);
        $opensslAlg = match ($alg) {
            CoseKey::ALG_ES256, CoseKey::ALG_RS256 => OPENSSL_ALGO_SHA256,
            default => throw new WebAuthnException('Nicht unterstützter Algorithmus.'),
        };
        if (openssl_verify($signedData, $signature, $publicKeyPem, $opensslAlg) !== 1) {
            throw new WebAuthnException('Signaturprüfung fehlgeschlagen.');
        }

        $new = $parsed['signCount'];
        if (($new !== 0 || $storedSignCount !== 0) && $new <= $storedSignCount) {
            throw new WebAuthnException('Signaturzähler zurückgegangen (möglicherweise geklonter Authenticator).');
        }

        $userHandle = isset($response['userHandle']) && is_string($response['userHandle']) && $response['userHandle'] !== ''
            ? $response['userHandle'] : null;
        return ['signCount' => $new, 'uv' => (bool) ($parsed['flags'] & self::FLAG_UV), 'userHandle' => $userHandle];
    }

    private function checkClientData(string $raw, string $type, string $expectedChallenge, string $origin): void
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new WebAuthnException('clientDataJSON ungültig.');
        }
        if (($data['type'] ?? null) !== $type) {
            throw new WebAuthnException('Falscher Operationstyp.');
        }
        if (!is_string($data['challenge'] ?? null) || !hash_equals($expectedChallenge, $data['challenge'])) {
            throw new WebAuthnException('Challenge stimmt nicht überein.');
        }
        if (!is_string($data['origin'] ?? null) || !hash_equals($origin, $data['origin'])) {
            throw new WebAuthnException('Origin stimmt nicht überein.');
        }
        if (($data['crossOrigin'] ?? false) === true) {
            throw new WebAuthnException('Cross-Origin-Aufrufe sind nicht erlaubt.');
        }
    }

    /** @return array{flags:int,signCount:int} */
    private function parseAuthData(string $authData, string $rpId): array
    {
        if (strlen($authData) < 37) {
            throw new WebAuthnException('authData zu kurz.');
        }
        if (!hash_equals(hash('sha256', $rpId, true), substr($authData, 0, 32))) {
            throw new WebAuthnException('RP-ID-Hash stimmt nicht überein.');
        }
        return [
            'flags' => ord($authData[32]),
            'signCount' => unpack('N', substr($authData, 33, 4))[1],
        ];
    }
}

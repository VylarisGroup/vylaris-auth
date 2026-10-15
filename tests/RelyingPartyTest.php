<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Tests\Support\{Factory, TestCase};

require_once dirname(__DIR__) . '/examples/rp-php/lib.php';

/** Prüft die Referenz-Relying-Party gegen echte JWKS von KeyService (RS256-Signatur, Claims-Validierung). */
final class RelyingPartyTest extends TestCase
{
    private object $ctx;
    private array $jwks;
    private string $privPem;
    private string $kid;
    private const ISS = 'https://auth.test';
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/vk-rp-' . bin2hex(random_bytes(3));
        $this->ctx = Factory::app(['oidc' => ['private_key_dir' => $dir]]);
        $key = $this->ctx->app->keys->ensureActiveKey();
        $this->kid = $key['kid'];
        $this->privPem = $this->ctx->app->keys->privatePem($key);
        $this->jwks = $this->ctx->app->keys->jwks();
        $this->dir = $dir;
    }

    private string $dir = '';

    public function __destruct()
    {
        if ($this->dir !== '' && str_starts_with($this->dir, sys_get_temp_dir())) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function token(array $claims = [], array $header = [], ?string $pem = null): string
    {
        $h = $header + ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid];
        $c = $claims + ['iss' => self::ISS, 'aud' => 'vy_app', 'sub' => 'abc', 'iat' => self::NOW, 'nbf' => self::NOW, 'exp' => self::NOW + 600, 'nonce' => 'n-1'];
        $in = b64u_encode(json_encode($h)) . '.' . b64u_encode(json_encode($c));
        openssl_sign($in, $sig, $pem ?? $this->privPem, OPENSSL_ALGO_SHA256);
        return $in . '.' . b64u_encode($sig);
    }

    private function verify(string $jwt, string $nonce = 'n-1', string $aud = 'vy_app', string $iss = self::ISS): array
    {
        return verify_id_token($jwt, $this->jwks, $iss, $aud, $nonce, self::NOW);
    }

    public function testValidTokenAccepted(): void
    {
        $this->assertSame('abc', $this->verify($this->token())['sub']);
    }

    public function testRejectsEveryTamperingVariant(): void
    {
        $cases = [
            'falscher Issuer' => fn () => $this->verify($this->token(['iss' => 'https://evil.test'])),
            'falsche Audience' => fn () => $this->verify($this->token(['aud' => 'andere_app'])),
            'abgelaufen' => fn () => $this->verify($this->token(['exp' => self::NOW - 120])),
            'falsche Nonce' => fn () => $this->verify($this->token(), 'anderer'),
            'fehlende Nonce' => fn () => $this->verify($this->token(['nonce' => ''])),
            'alg none' => fn () => $this->verify(b64u_encode('{"alg":"none","kid":"' . $this->kid . '"}') . '.' . b64u_encode('{"iss":"' . self::ISS . '","aud":"vy_app","sub":"x","iat":' . self::NOW . ',"exp":' . (self::NOW + 99) . ',"nonce":"n-1"}') . '.'),
            'HS256 mit Public Key' => fn () => $this->verify($this->hs256()),
            'unbekannte kid' => fn () => $this->verify($this->token([], ['kid' => 'fremd'])),
            'fremder Schlüssel' => fn () => $this->verify($this->token([], [], $this->foreignKey())),
            'iat in Zukunft' => fn () => $this->verify($this->token(['iat' => self::NOW + 3600])),
            'kein sub' => fn () => $this->verify($this->token(['sub' => ''])),
            'Multi-aud ohne azp' => fn () => $this->verify($this->token(['aud' => ['vy_app', 'other']])),
            'Müll' => fn () => $this->verify('a.b'),
        ];
        foreach ($cases as $name => $fn) {
            $this->assertThrows(\RuntimeException::class, $fn, $name);
        }
    }

    public function testSignatureManipulationDetected(): void
    {
        [$h, $c, $s] = explode('.', $this->token());
        $forged = b64u_encode(json_encode(['iss' => self::ISS, 'aud' => 'vy_app', 'sub' => 'admin', 'iat' => self::NOW, 'exp' => self::NOW + 600, 'nonce' => 'n-1']));
        $this->assertThrows(\RuntimeException::class, fn () => $this->verify("$h.$forged.$s"));
    }

    private function hs256(): string
    {
        $in = b64u_encode(json_encode(['alg' => 'HS256', 'kid' => $this->kid])) . '.' . b64u_encode(json_encode(['iss' => self::ISS, 'aud' => 'vy_app', 'sub' => 'x', 'iat' => self::NOW, 'exp' => self::NOW + 600, 'nonce' => 'n-1']));
        return $in . '.' . b64u_encode(hash_hmac('sha256', $in, jwk_to_pem($this->jwks['keys'][0]), true));
    }

    private function foreignKey(): string
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($k, $pem);
        return $pem;
    }

    public function testPkceChallengeIsS256OfVerifier(): void
    {
        [$v, $c] = pkce_pair();
        $this->assertSame(b64u_encode(hash('sha256', $v, true)), $c);
        $this->assertTrue(strlen($v) >= 43);
    }
}

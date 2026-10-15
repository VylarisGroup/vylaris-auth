<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\{Clock, Session};
use Vylaris\Repository\UserRepository;
use Vylaris\Service\{MfaService, PasskeyService, TotpService};
use Vylaris\Service\WebAuthn\{Cbor, Verifier, WebAuthnException};
use Vylaris\Tests\Support\{Factory, SoftAuthenticator, TestCase};

final class PasskeyTest extends TestCase
{
    private object $s;
    private PasskeyService $pk;
    private int $uid;
    private Session $session;
    private const ORIGIN = 'https://auth.test';

    protected function setUp(): void
    {
        $this->s = Factory::auth();
        $mfa = new MfaService($this->s->db, $this->s->config, new TotpService(), $this->s->limiter, $this->s->audit);
        $this->pk = new PasskeyService($this->s->db, $this->s->config, $this->s->users, new Verifier(), $mfa, $this->s->audit);
        $this->uid = Factory::user($this->s);
        $this->session = new Session([], 'sid');
    }

    private function register(SoftAuthenticator $a, array $o = []): void
    {
        $opts = $this->pk->registrationOptions($this->session, $this->uid);
        $this->pk->finishRegistration($this->session, $this->uid, $a->register($opts, self::ORIGIN, $o), 'Mein Key');
    }

    private function expectFail(callable $fn, string $needle): void
    {
        $e = $this->assertThrows(WebAuthnException::class, $fn);
        $this->assertStringContains($needle, $e->getMessage());
    }

    public function testCborDecoderBasics(): void
    {
        $this->assertSame(['a' => 1, 'b' => [true, null, -5]], Cbor::decode(SoftAuthenticator::cMap([
            [SoftAuthenticator::cText('a'), SoftAuthenticator::cInt(1)],
            [SoftAuthenticator::cText('b'), "\x83\xf5\xf6" . SoftAuthenticator::cInt(-5)],
        ])));
        $this->assertThrows(\InvalidArgumentException::class, fn () => Cbor::decode("\x18"));
        $this->assertThrows(\InvalidArgumentException::class, fn () => Cbor::decode("\x01\x02"));
        $this->assertThrows(\InvalidArgumentException::class, fn () => Cbor::decode("\xa2\x01\x01\x01\x02"), 'doppelter Key');
    }

    public function testRegistrationAndAssertionEs256(): void
    {
        $a = new SoftAuthenticator('ES256');
        $this->register($a);
        $this->assertSame(1, $this->pk->count($this->uid));

        $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
        $this->assertSame($a->credentialIdB64(), $opts['allowCredentials'][0]['id']);
        $uid = $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN));
        $this->assertSame($this->uid, $uid);
    }

    public function testRegistrationAndAssertionRs256(): void
    {
        $a = new SoftAuthenticator('RS256');
        $this->register($a);
        $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
        $this->assertSame($this->uid, $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN)));
    }

    public function testRegistrationRejectsWrongChallengeOriginRpIdAndType(): void
    {
        $list = [
            [['challenge' => Verifier::b64uEncode(random_bytes(32))], 'Challenge'],
            [['rpId' => 'evil.test'], 'RP-ID'],
            [['type' => 'webauthn.get'], 'Operationstyp'],
            [['flags' => Verifier::FLAG_AT], '(UP)'],
        ];
        foreach ($list as [$override, $needle]) {
            $a = new SoftAuthenticator();
            $this->expectFail(fn () => $this->register($a, $override), $needle);
        }
        $a = new SoftAuthenticator();
        $opts = $this->pk->registrationOptions($this->session, $this->uid);
        $this->expectFail(fn () => $this->pk->finishRegistration($this->session, $this->uid, $a->register($opts, 'https://evil.test'), 'x'), 'Origin');
        $this->assertSame(0, $this->pk->count($this->uid));
    }

    public function testRegistrationRejectsMismatchedRawId(): void
    {
        $a = new SoftAuthenticator();
        $this->expectFail(fn () => $this->register($a, ['rawId' => Verifier::b64uEncode(random_bytes(32))]), 'Credential-ID');
    }

    public function testChallengeIsSingleUse(): void
    {
        $a = new SoftAuthenticator();
        $opts = $this->pk->registrationOptions($this->session, $this->uid);
        $cred = $a->register($opts, self::ORIGIN);
        $this->pk->finishRegistration($this->session, $this->uid, $cred, 'x');
        $this->expectFail(fn () => $this->pk->finishRegistration($this->session, $this->uid, $cred, 'x'), 'abgelaufen');
    }

    public function testChallengeExpires(): void
    {
        $a = new SoftAuthenticator();
        $opts = $this->pk->registrationOptions($this->session, $this->uid);
        $cred = $a->register($opts, self::ORIGIN);
        Clock::advance(301);
        $this->expectFail(fn () => $this->pk->finishRegistration($this->session, $this->uid, $cred, 'x'), 'abgelaufen');
    }

    public function testChallengePurposeCannotBeSwapped(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $this->pk->registrationOptions($this->session, $this->uid); // Register-Challenge aktiv
        $opts = ['challenge' => $this->session->get('webauthn')['c'], 'rpId' => 'auth.test'];
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN)), 'abgelaufen');
    }

    public function testAssertionRejectsBadSignatureWrongOriginRpIdChallengeAndMissingUp(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $list = [
            [['tamper' => true], self::ORIGIN, 'Signatur'],
            [[], 'https://evil.test', 'Origin'],
            [['rpId' => 'evil.test'], self::ORIGIN, 'RP-ID'],
            [['challenge' => Verifier::b64uEncode(random_bytes(32))], self::ORIGIN, 'Challenge'],
            [['flags' => Verifier::FLAG_UV], self::ORIGIN, '(UP)'],
            [['type' => 'webauthn.create'], self::ORIGIN, 'Operationstyp'],
        ];
        foreach ($list as [$override, $origin, $needle]) {
            $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
            $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, $origin, null, $override)), $needle);
        }
    }

    public function testSignatureFromOtherKeyIsRejected(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $attacker = new SoftAuthenticator();
        $attacker->credentialId = $a->credentialId; // gleiche Credential-ID, anderer Schlüssel
        $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'mfa', $attacker->assertion($opts, self::ORIGIN)), 'Signatur');
    }

    public function testSignCountRollbackIsDetected(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
        $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN, null, ['count' => 10]));
        $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN, null, ['count' => 10])), 'zähler');
        $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
        $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN, null, ['count' => 11]));
    }

    public function testZeroCounterAuthenticatorsAreAccepted(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        for ($i = 0; $i < 2; $i++) {
            $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
            $this->assertSame($this->uid, $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN, null, ['count' => 0])));
        }
    }

    public function testPasskeyOfAnotherAccountCannotCompleteMfa(): void
    {
        $other = Factory::user($this->s, 'bob@example.org', 'Correct-Horse-Battery-9', 'Bob');
        $a = new SoftAuthenticator();
        $opts = $this->pk->registrationOptions($this->session, $other);
        $this->pk->finishRegistration($this->session, $other, $a->register($opts, self::ORIGIN), 'Bobs Key');
        $opts = $this->pk->authenticationOptions($this->session, 'mfa', $this->uid);
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'mfa', $a->assertion($opts, self::ORIGIN)), 'anderen Konto');
    }

    public function testPasswordlessLoginRequiresUserVerificationAndMatchingHandle(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $handle = $this->pk->userHandle($this->uid);

        $opts = $this->pk->authenticationOptions($this->session, 'login');
        $this->assertSame('required', $opts['userVerification']);
        $this->assertSame($this->uid, $this->pk->finishAuthentication($this->session, 'login', $a->assertion($opts, self::ORIGIN, $handle)));

        $opts = $this->pk->authenticationOptions($this->session, 'login');
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'login', $a->assertion($opts, self::ORIGIN, $handle, ['flags' => Verifier::FLAG_UP])), 'Benutzerverifikation');

        $opts = $this->pk->authenticationOptions($this->session, 'login');
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'login', $a->assertion($opts, self::ORIGIN, Verifier::b64uEncode(random_bytes(32)))), 'User-Handle');

        $opts = $this->pk->authenticationOptions($this->session, 'login');
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'login', $a->assertion($opts, self::ORIGIN, null)), 'User-Handle');
    }

    public function testPasswordlessLoginBlockedForLockedAccount(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $this->s->users->setLocked($this->uid, true, 'x');
        $opts = $this->pk->authenticationOptions($this->session, 'login');
        $this->expectFail(fn () => $this->pk->finishAuthentication($this->session, 'login', $a->assertion($opts, self::ORIGIN, $this->pk->userHandle($this->uid))), 'Konto');
    }

    public function testDuplicateCredentialIsRejectedAndFirstPasskeyIssuesRecoveryCodes(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $codes = $this->session->flash('new_recovery_codes');
        $this->assertSame(10, count($codes));
        $this->expectFail(fn () => $this->register($a), 'bereits registriert');
    }

    public function testDeleteOnlyOwnCredential(): void
    {
        $a = new SoftAuthenticator();
        $this->register($a);
        $other = Factory::user($this->s, 'bob@example.org', 'Correct-Horse-Battery-9', 'Bob');
        $rowId = (int) $this->pk->credentials($this->uid)[0]['id'];
        $this->assertFalse($this->pk->delete($other, $rowId));
        $this->assertTrue($this->pk->delete($this->uid, $rowId));
        $this->assertFalse($this->s->auth->hasMfa($this->uid));
    }

    public function testUserHandleIsNotTheRawUserId(): void
    {
        $h = $this->pk->userHandle($this->uid);
        $this->assertTrue($h !== (string) $this->uid && strlen($h) >= 40);
    }

    public function testMalformedInputNeverCrashes(): void
    {
        $v = new Verifier();
        foreach ([[], ['type' => 'public-key'], ['type' => 'public-key', 'rawId' => '!!', 'response' => ['clientDataJSON' => '??', 'attestationObject' => 'x']]] as $bad) {
            $this->assertThrows(WebAuthnException::class, fn () => $v->verifyRegistration($bad, 'c', self::ORIGIN, 'auth.test'));
            $this->assertThrows(WebAuthnException::class, fn () => $v->verifyAssertion($bad, 'c', self::ORIGIN, 'auth.test', 'pem', -7, 0, false));
        }
    }
}

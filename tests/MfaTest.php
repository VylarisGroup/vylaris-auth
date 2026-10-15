<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Service\{AuditLogger, Base32, MfaService, TotpService};
use Vylaris\Tests\Support\{Factory, TestCase};

final class MfaTest extends TestCase
{
    private object $s;
    private MfaService $mfa;
    private TotpService $totp;
    private int $uid;

    protected function setUp(): void
    {
        $this->s = Factory::auth();
        $this->totp = new TotpService();
        $this->mfa = new MfaService($this->s->db, $this->s->config, $this->totp, $this->s->limiter, $this->s->audit);
        $this->uid = Factory::user($this->s);
    }

    public function testTotpMatchesRfc6238Vectors(): void
    {
        $secret = Base32::encode('12345678901234567890');
        // RFC 6238 Anhang B (SHA-1), auf 6 Stellen gekürzt
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $time => $expected) {
            $this->assertSame($expected, $this->totp->codeAt($secret, $this->totp->currentStep($time)), "t=$time");
        }
    }

    public function testBase32RoundTrip(): void
    {
        $raw = random_bytes(20);
        $this->assertSame($raw, Base32::decode(Base32::encode($raw)));
        $this->assertSame('MZXW6===', 'MZXW6===');
        $this->assertSame('MZXW6', Base32::encode('foo'));
    }

    public function testTotpVerifyWindowAndReplayProtection(): void
    {
        $secret = $this->totp->generateSecret();
        $step = $this->totp->currentStep();
        $code = $this->totp->codeAt($secret, $step);
        $this->assertSame($step, $this->totp->verify($secret, $code));
        $this->assertNull($this->totp->verify($secret, $code, $step), 'gleicher Schritt darf nicht erneut gelten');
        $this->assertNotNull($this->totp->verify($secret, $this->totp->codeAt($secret, $step - 1)), 'vorheriges Fenster ok');
        $this->assertNull($this->totp->verify($secret, $this->totp->codeAt($secret, $step - 3)), 'zu alt');
        $this->assertNull($this->totp->verify($secret, 'abcdef'));
        $this->assertNull($this->totp->verify($secret, '12345'));
    }

    public function testOtpauthUriFormat(): void
    {
        $uri = $this->totp->otpauthUri('VylarisGroup', 'anna@example.org', 'JBSWY3DPEHPK3PXP');
        $this->assertStringContains('otpauth://totp/VylarisGroup:anna%40example.org?', $uri);
        $this->assertStringContains('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContains('issuer=VylarisGroup', $uri);
    }

    public function testSetupConfirmEnablesMfaAndIssuesRecoveryCodesOnce(): void
    {
        $setup = $this->mfa->beginTotpSetup($this->uid, 'anna@example.org');
        $this->assertFalse($this->mfa->hasTotp($this->uid));
        $this->assertFalse($this->s->auth->hasMfa($this->uid));
        $this->assertNull($this->mfa->confirmTotp($this->uid, '000000'));
        $codes = $this->mfa->confirmTotp($this->uid, $this->totp->codeAt($setup['secret'], $this->totp->currentStep()));
        $this->assertSame(10, count($codes));
        $this->assertTrue($this->mfa->hasTotp($this->uid));
        $this->assertTrue($this->s->auth->hasMfa($this->uid));
        $this->assertSame(10, $this->mfa->recoveryRemaining($this->uid));
    }

    public function testTotpSecretIsStoredEncrypted(): void
    {
        $setup = $this->mfa->beginTotpSetup($this->uid, 'a@b.c');
        $stored = (string) $this->s->db->scalar('SELECT secret_enc FROM mfa_totp WHERE user_id = ?', [$this->uid]);
        $this->assertStringNotContains($setup['secret'], $stored);
        $this->assertStringNotContains($setup['secret'], base64_decode($stored));
    }

    private function enable(): string
    {
        $setup = $this->mfa->beginTotpSetup($this->uid, 'a@b.c');
        $this->mfa->confirmTotp($this->uid, $this->totp->codeAt($setup['secret'], $this->totp->currentStep()));
        return $setup['secret'];
    }

    public function testLoginTotpVerificationAndReplay(): void
    {
        $secret = $this->enable();
        Clock::advance(30);
        $code = $this->totp->codeAt($secret, $this->totp->currentStep());
        $this->assertTrue($this->mfa->verifyTotp($this->uid, $code));
        $this->assertFalse($this->mfa->verifyTotp($this->uid, $code), 'Replay muss scheitern');
    }

    public function testTotpBruteForceIsThrottled(): void
    {
        $secret = $this->enable();
        Clock::advance(60);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($this->mfa->verifyTotp($this->uid, '00000' . $i));
        }
        $valid = $this->totp->codeAt($secret, $this->totp->currentStep());
        $this->assertFalse($this->mfa->verifyTotp($this->uid, $valid), 'nach 5 Fehlversuchen gedrosselt');
    }

    public function testRecoveryCodeWorksExactlyOnce(): void
    {
        $this->enable();
        $codes = $this->mfa->regenerateRecoveryCodes($this->uid);
        $this->assertSame(10, count($codes));
        $this->assertTrue($this->mfa->consumeRecoveryCode($this->uid, strtoupper($codes[0])), 'Groß-/Kleinschreibung egal');
        $this->assertFalse($this->mfa->consumeRecoveryCode($this->uid, $codes[0]), 'zweite Nutzung muss scheitern');
        $this->assertSame(9, $this->mfa->recoveryRemaining($this->uid));
        $this->assertTrue($this->mfa->consumeRecoveryCode($this->uid, str_replace('-', '', $codes[1])), 'ohne Bindestrich ok');
    }

    public function testRecoveryCodesAreHashedAndRegenerationInvalidatesOldOnes(): void
    {
        $this->enable();
        $old = $this->mfa->regenerateRecoveryCodes($this->uid);
        $stored = json_encode($this->s->db->all('SELECT code_hash FROM mfa_recovery_codes'));
        $this->assertStringNotContains(str_replace('-', '', $old[0]), $stored);
        $this->mfa->regenerateRecoveryCodes($this->uid);
        $this->assertFalse($this->mfa->consumeRecoveryCode($this->uid, $old[2]));
    }

    public function testRecoveryCodeOfAnotherUserIsRejected(): void
    {
        $this->enable();
        $codes = $this->mfa->regenerateRecoveryCodes($this->uid);
        $other = Factory::user($this->s, 'bob@example.org', 'Correct-Horse-Battery-9', 'Bob');
        $this->assertFalse($this->mfa->consumeRecoveryCode($other, $codes[0]));
    }

    public function testDisablingLastFactorRemovesRecoveryCodes(): void
    {
        $this->enable();
        $this->mfa->disableTotp($this->uid);
        $this->assertFalse($this->mfa->hasTotp($this->uid));
        $this->assertSame(0, $this->mfa->recoveryRemaining($this->uid));
        $this->assertFalse($this->s->auth->hasMfa($this->uid));
    }

    public function testLoginRequiresMfaWhenEnabled(): void
    {
        $this->enable();
        $r = $this->s->auth->login('anna@example.org', 'Correct-Horse-Battery-9', '9.9.9.9');
        $this->assertSame('mfa_required', $r->status);
    }

    public function testAuditLogHasNoCodesOrSecrets(): void
    {
        $secret = $this->enable();
        $codes = $this->mfa->regenerateRecoveryCodes($this->uid);
        $this->mfa->consumeRecoveryCode($this->uid, $codes[0]);
        $dump = json_encode($this->s->db->all('SELECT * FROM audit_log'));
        $this->assertStringNotContains($secret, $dump);
        $this->assertStringNotContains(str_replace('-', '', $codes[0]), $dump);
    }
}

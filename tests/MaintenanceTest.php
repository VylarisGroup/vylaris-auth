<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\Clock;
use Vylaris\Service\Maintenance;
use Vylaris\Tests\Support\{Factory, TestCase};

final class MaintenanceTest extends TestCase
{
    public function testCleanupRemovesExpiredDataAndKeepsCurrent(): void
    {
        $ctx = Factory::app(['oidc' => ['private_key_dir' => sys_get_temp_dir() . '/vk-' . bin2hex(random_bytes(3))]]);
        $a = $ctx->app;
        $uid = $a->users->create('a@example.org', $a->passwords->hash('Correct-Horse-Battery-9'), 'A', true);
        $c = $a->clients->create('Wiki App', ['https://w.example/cb']);
        $old = Clock::db(Clock::now() - 40 * 86400);
        $fut = Clock::db(Clock::now() + 600);
        foreach ([['old', $old], ['new', $fut]] as [$n, $exp]) {
            $a->db->insert('oauth_auth_codes', ['id' => str_pad($n, 64, 'c'), 'client_pk' => $c['id'], 'user_id' => $uid, 'scopes' => 'openid', 'redirect_uri' => 'https://w.example/cb', 'code_challenge' => 'x', 'auth_time' => $old, 'expires_at' => $exp]);
            $a->db->insert('oauth_access_tokens', ['id' => str_pad($n, 64, 'a'), 'client_pk' => $c['id'], 'user_id' => $uid, 'scopes' => 'openid', 'expires_at' => $exp, 'created_at' => $old]);
        }
        $a->db->insert('audit_log', ['created_at' => Clock::db(Clock::now() - 400 * 86400), 'event' => 'old.event']);
        $a->audit->log('fresh.event');
        $a->mailer->queue('a@example.org', 'Hallo', 'Text');

        $m = new Maintenance($a->db, $a->mailer, $a->sessions, $a->tokens, $a->limiter, $a->audit, $a->keys, 365);
        $res = $m->run();

        $this->assertSame(1, $res['mails_sent']);
        $this->assertSame(1, $res['auth_codes_purged']);
        $this->assertSame(1, $res['access_tokens_purged']);
        $this->assertSame(1, $res['audit_purged']);
        $this->assertSame(1, (int) $a->db->scalar('SELECT COUNT(*) FROM oauth_auth_codes'));
        $this->assertSame(1, (int) $a->db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'fresh.event'"));
        exec('rm -rf ' . escapeshellarg((string) $a->config->get('oidc.private_key_dir')));
    }
}

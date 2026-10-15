<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\{Clock, DbSessionHandler};
use Vylaris\Service\SessionService;
use Vylaris\Tests\Support\{Factory, TestCase};

final class SessionRevocationTest extends TestCase
{
    private object $s;
    private int $uid;

    protected function setUp(): void
    {
        $this->s = Factory::auth();
        $this->uid = Factory::user($this->s);
    }

    private function handler(string $ua = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0'): DbSessionHandler
    {
        return new DbSessionHandler($this->s->db, 1800, 86400, '10.0.0.1', $ua);
    }

    public function testHandlerStoresHashedIdAndReadsBack(): void
    {
        $h = $this->handler();
        $h->setUserId($this->uid);
        $h->write('plain-session-id', 'a:1:{s:1:"x";i:1;}');
        $this->assertSame('a:1:{s:1:"x";i:1;}', $h->read('plain-session-id'));
        $this->assertNull($this->s->db->one('SELECT 1 FROM sessions WHERE id = ?', ['plain-session-id']));
        $this->assertNotNull($this->s->db->one('SELECT 1 FROM sessions WHERE id = ?', [hash('sha256', 'plain-session-id')]));
    }

    public function testIdleTimeoutExpiresSession(): void
    {
        $h = $this->handler();
        $h->write('sid', 'data');
        Clock::advance(1801);
        $this->assertSame('', $h->read('sid'));
    }

    public function testAbsoluteLifetimeCapsExtension(): void
    {
        $h = $this->handler();
        $h->write('sid', 'data');
        for ($i = 0; $i < 60; $i++) {
            Clock::advance(1700);
            $h->write('sid', 'data'); // hält die Sitzung am Leben
        }
        // 60 * 1700 s > 86400 s: absolutes Limit muss gegriffen haben
        $this->assertSame('', $h->read('sid'));
    }

    public function testRevokedSessionReturnsNoDataAndCannotBeRevived(): void
    {
        $h = $this->handler();
        $h->setUserId($this->uid);
        $h->write('sid', 'secret-data');
        $this->assertTrue($this->s->sessions->revoke($this->uid, hash('sha256', 'sid')));
        $this->assertSame('', $h->read('sid'));
        $h->write('sid', 'new-data');
        $this->assertSame('', $h->read('sid'));
        $this->assertFalse($this->s->sessions->isActive(hash('sha256', 'sid'), $this->uid));
    }

    public function testCannotRevokeForeignSession(): void
    {
        $other = Factory::user($this->s, 'bob@example.org', 'Correct-Horse-Battery-9', 'Bob Beispiel');
        $h = $this->handler();
        $h->setUserId($other);
        $h->write('bobs-sid', 'x');
        $this->assertFalse($this->s->sessions->revoke($this->uid, hash('sha256', 'bobs-sid')));
        $this->assertTrue($this->s->sessions->isActive(hash('sha256', 'bobs-sid'), $other));
    }

    public function testRevokeAllKeepsCurrent(): void
    {
        foreach (['a', 'b', 'c'] as $id) {
            $h = $this->handler();
            $h->setUserId($this->uid);
            $h->write($id, 'x');
        }
        $this->assertSame(2, $this->s->sessions->revokeAll($this->uid, hash('sha256', 'a')));
        $this->assertTrue($this->s->sessions->isActive(hash('sha256', 'a'), $this->uid));
        $this->assertFalse($this->s->sessions->isActive(hash('sha256', 'b'), $this->uid));
        $list = $this->s->sessions->listForUser($this->uid, hash('sha256', 'a'));
        $this->assertSame(1, count($list));
        $this->assertTrue($list[0]['current']);
        $this->assertSame('Firefox auf Linux', $list[0]['device']);
    }

    public function testRevokingSessionRevokesBoundOAuthTokens(): void
    {
        $sid = hash('sha256', 'sid');
        $h = $this->handler();
        $h->setUserId($this->uid);
        $h->write('sid', 'x');
        $this->s->db->insert('oauth_clients', ['client_id' => 'c1', 'name' => 'C1', 'created_at' => Clock::db()]);
        $clientPk = (int) $this->s->db->scalar('SELECT id FROM oauth_clients WHERE client_id = ?', ['c1']);
        $this->s->db->insert('oauth_access_tokens', ['id' => 'at1', 'client_pk' => $clientPk, 'user_id' => $this->uid, 'session_id' => $sid, 'scopes' => 'openid', 'expires_at' => Clock::db(Clock::now() + 600), 'created_at' => Clock::db()]);
        $this->s->db->insert('oauth_refresh_tokens', ['id' => 'rt1', 'access_token_id' => 'at1', 'client_pk' => $clientPk, 'user_id' => $this->uid, 'session_id' => $sid, 'scopes' => 'openid', 'expires_at' => Clock::db(Clock::now() + 600), 'created_at' => Clock::db()]);
        $this->s->sessions->revoke($this->uid, $sid);
        $this->assertNotNull($this->s->db->scalar('SELECT revoked_at FROM oauth_access_tokens WHERE id = ?', ['at1']));
        $this->assertNotNull($this->s->db->scalar('SELECT revoked_at FROM oauth_refresh_tokens WHERE id = ?', ['rt1']));
    }

    public function testLockedUserSessionIsNotAccepted(): void
    {
        $session = new \Vylaris\Core\Session([], 'live');
        $h = $this->handler();
        $h->setUserId($this->uid);
        $h->write('live', 'x');
        $session->bindUser($this->uid);
        $this->assertNotNull($this->s->auth->currentUser($session));
        $this->s->users->setLocked($this->uid, true, 'Missbrauch');
        $this->assertNull($this->s->auth->currentUser($session));
    }
}

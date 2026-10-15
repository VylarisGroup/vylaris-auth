<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Service\AuthorizationException;
use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

/** Rollen- und Rechtemodell, Admin-Aktionen, Admin-Oberfläche und Anwendungs-API. */
final class AdminTest extends TestCase
{
    private object $ctx;
    private int $super;
    private int $admin;
    private int $user;
    private int $user2;
    private const PW = 'Correct-Horse-Battery-9';

    protected function setUp(): void
    {
        $this->ctx = Factory::app();
        $a = $this->ctx->app;
        $h = $a->passwords->hash(self::PW);
        $this->super = $a->users->create('root@example.org', $h, 'Root', true);
        $a->users->assignRole($this->super, 'superadmin');
        $this->admin = $a->users->create('admin@example.org', $h, 'Admin', true);
        $a->users->assignRole($this->admin, 'admin');
        $this->user = $a->users->create('anna@example.org', $h, 'Anna', true);
        $a->users->assignRole($this->user, 'user');
        $this->user2 = $a->users->create('bob@example.org', $h, 'Bob', true);
        $a->users->assignRole($this->user2, 'user');
    }

    private function login(string $email, ?TestBrowser $b = null): TestBrowser
    {
        $b ??= new TestBrowser($this->ctx->app);
        $b->post('/login', ['email' => $email, 'password' => self::PW]);
        return $b;
    }

    // ------------------------------------------------------------ Service / Policy

    public function testAdminCanLockAndUnlockNormalUser(): void
    {
        $svc = $this->ctx->app->admin;
        $svc->lock($this->admin, $this->user, 'Missbrauch');
        $this->assertTrue($this->ctx->app->users->isLocked($this->ctx->app->users->findById($this->user)));
        $svc->unlock($this->admin, $this->user);
        $this->assertFalse($this->ctx->app->users->isLocked($this->ctx->app->users->findById($this->user)));
    }

    public function testNormalUserCannotUseAdminService(): void
    {
        $this->assertThrows(AuthorizationException::class, fn () => $this->ctx->app->admin->lock($this->user, $this->user2, 'x'));
        $this->assertThrows(AuthorizationException::class, fn () => $this->ctx->app->admin->grantRole($this->user, $this->user2, 'admin'));
        $this->assertThrows(AuthorizationException::class, fn () => $this->ctx->app->admin->createUser($this->user, 'neu@example.org', 'Neu'));
    }

    public function testAdminCannotManageAdminsOrGrantAdminRole(): void
    {
        $svc = $this->ctx->app->admin;
        $this->assertThrows(AuthorizationException::class, fn () => $svc->lock($this->admin, $this->super, 'x'));
        $this->assertThrows(AuthorizationException::class, fn () => $svc->grantRole($this->admin, $this->user, 'admin'));
        $this->assertThrows(AuthorizationException::class, fn () => $svc->grantRole($this->admin, $this->user, 'superadmin'));
        $this->assertFalse($this->ctx->app->policy->isAdmin($this->user));
    }

    public function testSuperadminManagesAdminRoles(): void
    {
        $svc = $this->ctx->app->admin;
        $svc->grantRole($this->super, $this->user, 'admin');
        $this->assertTrue($this->ctx->app->policy->isAdmin($this->user));
        $svc->revokeRole($this->super, $this->user, 'admin');
        $this->assertFalse($this->ctx->app->policy->isAdmin($this->user));
    }

    public function testNoSelfLockNoSelfRoleChange(): void
    {
        $svc = $this->ctx->app->admin;
        $this->assertThrows(AuthorizationException::class, fn () => $svc->lock($this->super, $this->super, 'x'));
        $this->assertThrows(AuthorizationException::class, fn () => $svc->revokeRole($this->super, $this->super, 'superadmin'));
        $this->assertThrows(AuthorizationException::class, fn () => $svc->grantRole($this->admin, $this->admin, 'superadmin'));
        $this->assertTrue($this->ctx->app->policy->isSuperAdmin($this->super));
    }

    public function testUnknownRoleRejected(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => $this->ctx->app->admin->grantRole($this->super, $this->user, 'godmode'));
    }

    public function testLockRevokesSessionsAndAccessTokens(): void
    {
        $a = $this->ctx->app;
        $b = $this->login('anna@example.org');
        $this->assertSame(200, $b->get('/account')->status);
        $client = $a->clients->create('Wiki', ['https://wiki.example/cb']);
        $a->db->insert('oauth_access_tokens', [
            'id' => str_repeat('a', 64), 'client_pk' => $client['id'], 'user_id' => $this->user, 'session_id' => null,
            'scopes' => 'openid', 'expires_at' => \Vylaris\Core\Clock::db(\Vylaris\Core\Clock::now() + 600), 'created_at' => \Vylaris\Core\Clock::db(),
        ]);
        $a->admin->lock($this->admin, $this->user, 'Test');
        $this->assertSame(302, $b->get('/account')->status, 'Sitzung nach Sperre ungültig');
        $this->assertNotNull($a->db->scalar('SELECT revoked_at FROM oauth_access_tokens WHERE id = ?', [str_repeat('a', 64)]), 'Token widerrufen');
    }

    public function testResetMfaRemovesFactorsAndEndsSessions(): void
    {
        $a = $this->ctx->app;
        $a->db->insert('mfa_totp', ['user_id' => $this->user, 'secret_enc' => 'x', 'confirmed_at' => \Vylaris\Core\Clock::db(), 'last_used_step' => 0, 'created_at' => \Vylaris\Core\Clock::db()]);
        $b = $this->login('anna@example.org');
        $this->assertTrue($a->mfa->hasTotp($this->user));
        $a->admin->resetMfa($this->admin, $this->user);
        $this->assertFalse($a->mfa->hasTotp($this->user));
        $this->assertSame(302, $b->get('/account')->status);
    }

    public function testAdminActionsAreAuditedWithoutSecrets(): void
    {
        $a = $this->ctx->app;
        $a->admin->lock($this->admin, $this->user, 'Grund');
        $a->admin->createUser($this->admin, 'neu@example.org', 'Neu Person');
        $events = array_column($a->audit->recent(50), 'event');
        $this->assertTrue(in_array('admin.user_locked', $events, true));
        $this->assertTrue(in_array('admin.user_created', $events, true));
        $dump = json_encode($a->audit->recent(50));
        $this->assertStringNotContains('token=', $dump);
        $this->assertStringNotContains('$argon2', $dump);
    }

    public function testCreateUserSendsInviteAndRejectsDuplicates(): void
    {
        $a = $this->ctx->app;
        $id = $a->admin->createUser($this->admin, 'Neu@Example.org', 'Neu Person');
        $a->mailer->flush();
        $this->assertSame(1, count($this->ctx->mail->sent));
        $this->assertStringContains('/password/reset?token=', $this->ctx->mail->sent[0]['body']);
        $this->assertTrue(in_array('user', $a->users->roleNames($id), true));
        $this->assertThrows(\InvalidArgumentException::class, fn () => $a->admin->createUser($this->admin, 'neu@example.org', 'Doppelt'));
    }

    // ------------------------------------------------------------ Weboberfläche

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/admin');
        $this->assertSame(302, $r->status);
        $this->assertStringContains('/login', $r->headers['Location']);
    }

    public function testNormalUserGetsForbiddenOnAllAdminRoutes(): void
    {
        $b = $this->login('anna@example.org');
        foreach (['/admin', '/admin/users', '/admin/users/' . $this->admin, '/admin/apps', '/admin/audit', '/admin/keys'] as $p) {
            $this->assertSame(403, $b->get($p)->status, $p);
        }
        $this->assertSame(403, $b->post('/admin/users/' . $this->user2 . '/action', ['action' => 'lock', 'reason' => 'x'])->status);
        $this->assertFalse($this->ctx->app->users->isLocked($this->ctx->app->users->findById($this->user2)));
    }

    public function testAdminAreaRendersForAdmin(): void
    {
        $b = $this->login('admin@example.org');
        foreach (['/admin', '/admin/users', '/admin/users/' . $this->user, '/admin/apps', '/admin/audit'] as $p) {
            $this->assertSame(200, $b->get($p)->status, $p);
        }
        $this->assertStringNotContains('$argon2', $b->get('/admin/users/' . $this->user)->body);
        $this->assertSame(403, $b->get('/admin/keys')->status, 'Schlüsselverwaltung nur Superadmin');
    }

    public function testAdminPostRequiresCsrf(): void
    {
        $b = $this->login('admin@example.org');
        $r = $b->post('/admin/users/' . $this->user . '/action', ['action' => 'lock', 'reason' => 'x'], false);
        $this->assertSame(403, $r->status);
        $this->assertFalse($this->ctx->app->users->isLocked($this->ctx->app->users->findById($this->user)));
    }

    public function testAdminLocksUserViaWebAndCannotLockSuperadmin(): void
    {
        $b = $this->login('admin@example.org');
        $b->post('/admin/users/' . $this->user . '/action', ['action' => 'lock', 'reason' => 'Test']);
        $this->assertTrue($this->ctx->app->users->isLocked($this->ctx->app->users->findById($this->user)));
        $r = $b->post('/admin/users/' . $this->super . '/action', ['action' => 'lock', 'reason' => 'Putsch']);
        $this->assertSame(403, $r->status);
        $this->assertFalse($this->ctx->app->users->isLocked($this->ctx->app->users->findById($this->super)));
    }

    public function testInvalidActionRedirectsBackWithError(): void
    {
        $b = $this->login('admin@example.org');
        $r = $b->post('/admin/users/' . $this->user . '/action', ['action' => 'format_disk']);
        $this->assertSame(302, $r->status);
        $this->assertSame('/admin/users/' . $this->user, $r->headers['Location']);
    }

    public function testAdminMfaGate(): void
    {
        $ctx = Factory::app(['security' => ['admin_requires_mfa' => true]]);
        $a = $ctx->app;
        $id = $a->users->create('gate@example.org', $a->passwords->hash(self::PW), 'Gate', true);
        $a->users->assignRole($id, 'admin');
        $b = new TestBrowser($a);
        $b->post('/login', ['email' => 'gate@example.org', 'password' => self::PW]);
        $r = $b->get('/admin');
        $this->assertSame(302, $r->status, 'ohne MFA kein Admin-Zugang');
        $this->assertStringContains('/account', $r->headers['Location']);
    }

    public function testAppRegistrationShowsSecretOnceOnly(): void
    {
        $b = $this->login('admin@example.org');
        $r = $b->post('/admin/apps', ['name' => 'Wiki', 'redirect_uris' => "https://wiki.example/cb\nhttps://wiki.example/cb2", 'logout_uris' => '', 'scopes' => 'openid profile email roles', 'type' => 'confidential', 'access_policy' => 'all']);
        $this->assertSame(201, $r->status);
        $this->assertStringContains('Client-Secret', $r->body);
        $c = $this->ctx->app->clients->all()[0];
        $this->assertSame(2, count($this->ctx->app->clients->uris((int) $c['id'])));
        $list = $b->get('/admin/apps');
        $this->assertStringNotContains('Client-Secret', $list->body);
        $detail = $b->get('/admin/apps/' . $c['id']);
        $this->assertStringNotContains('secret_hash', $detail->body);
        $this->assertStringNotContains('$argon2', $detail->body);
    }

    public function testAppRegistrationRejectsInsecureRedirect(): void
    {
        $b = $this->login('admin@example.org');
        $r = $b->post('/admin/apps', ['name' => 'Evil', 'redirect_uris' => 'http://evil.example/cb', 'scopes' => 'openid', 'type' => 'confidential']);
        $this->assertSame(302, $r->status);
        $this->assertSame(0, count($this->ctx->app->clients->all()));
    }

    public function testAppRoleAssignmentAndAccessPolicy(): void
    {
        $a = $this->ctx->app;
        $c = $a->clients->create('Wiki', ['https://wiki.example/cb'], [], true, 'openid roles', false, 'assigned');
        $client = $a->clients->findByPk($c['id']);
        $roleId = $a->clients->createRole((int) $c['id'], 'editor');
        $this->assertFalse($a->clients->userMayAccess($this->user, $client));
        $b = $this->login('admin@example.org');
        $b->post('/admin/users/' . $this->user . '/app-role', ['client' => (string) $c['id'], 'role' => (string) $roleId, 'op' => 'assign']);
        $this->assertTrue($a->clients->userMayAccess($this->user, $client));
        $this->assertSame(['editor'], $a->clients->userRoles($this->user, (int) $c['id']));
        $b->post('/admin/users/' . $this->user . '/app-role', ['client' => (string) $c['id'], 'role' => (string) $roleId, 'op' => 'unassign']);
        $this->assertFalse($a->clients->userMayAccess($this->user, $client));
    }

    public function testRoleOfOtherAppCannotBeAssigned(): void
    {
        $a = $this->ctx->app;
        $c1 = $a->clients->create('App A', ['https://a.example/cb']);
        $c2 = $a->clients->create('App B', ['https://b.example/cb']);
        $roleB = $a->clients->createRole((int) $c2['id'], 'boss');
        $this->assertFalse($a->clients->assignRole($this->user, (int) $c1['id'], $roleB));
        $this->assertSame([], $a->clients->userRoles($this->user, (int) $c1['id']));
    }

    public function testKeyRotationOnlySuperadmin(): void
    {
        $b = $this->login('admin@example.org');
        $this->assertSame(403, $b->post('/admin/keys/rotate')->status);
    }

    // ------------------------------------------------------------ Anwendungs-API

    private function basic(string $id, string $secret): array
    {
        return ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode($id . ':' . $secret)];
    }

    public function testHealthEndpoint(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $this->assertSame(['status' => 'ok'], $this->json($b->get('/api/v1/health')));
    }

    private function json(\Vylaris\Core\Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    public function testClientApiListsRolesAndUsersOfOwnAppOnly(): void
    {
        $a = $this->ctx->app;
        $c = $a->clients->create('Wiki', ['https://wiki.example/cb'], [], true, 'openid profile email roles');
        $other = $a->clients->create('Other', ['https://o.example/cb']);
        $editor = $a->clients->createRole((int) $c['id'], 'editor');
        $foreign = $a->clients->createRole((int) $other['id'], 'secret-role');
        $a->clients->assignRole($this->user, (int) $c['id'], $editor);
        $a->clients->assignRole($this->user2, (int) $other['id'], $foreign);
        $b = new TestBrowser($a);
        $roles = $this->json($b->get('/api/v1/app/roles', [], $this->basic($c['client_id'], $c['client_secret'])));
        $this->assertSame(['editor'], array_column($roles['roles'], 'name'));
        $users = $this->json($b->get('/api/v1/app/users', [], $this->basic($c['client_id'], $c['client_secret'])));
        $this->assertSame(1, count($users['users']));
        $this->assertSame('anna@example.org', $users['users'][0]['email']);
        $this->assertSame(['editor'], $users['users'][0]['roles']);
        $this->assertTrue(!isset($users['users'][0]['password_hash']));
    }

    public function testClientApiRejectsBadCredentials(): void
    {
        $a = $this->ctx->app;
        $c = $a->clients->create('Wiki', ['https://wiki.example/cb']);
        $pub = $a->clients->create('SPA', ['https://spa.example/cb'], [], false);
        $b = new TestBrowser($a);
        $this->assertSame(401, $b->get('/api/v1/app/roles')->status, 'ohne Authentifizierung');
        $this->assertSame(401, $b->get('/api/v1/app/roles', [], $this->basic($c['client_id'], 'falsch'))->status);
        $this->assertSame(401, $b->get('/api/v1/app/roles', [], $this->basic('vy_unbekannt', 'x'))->status);
        $this->assertSame(401, $b->get('/api/v1/app/roles', [], $this->basic($pub['client_id'], ''))->status, 'öffentlicher Client');
        $this->assertSame(401, $b->get('/api/v1/app/roles', [], ['HTTP_AUTHORIZATION' => 'Bearer abc'])->status);
        $a->clients->setActive((int) $c['id'], false);
        $this->assertSame(401, $b->get('/api/v1/app/roles', [], $this->basic($c['client_id'], $c['client_secret']))->status, 'deaktiviert');
    }

    public function testClientApiExcludesLockedUsers(): void
    {
        $a = $this->ctx->app;
        $c = $a->clients->create('Wiki', ['https://wiki.example/cb']);
        $role = $a->clients->createRole((int) $c['id'], 'member');
        $a->clients->assignRole($this->user, (int) $c['id'], $role);
        $a->clients->assignRole($this->user2, (int) $c['id'], $role);
        $a->admin->lock($this->admin, $this->user2, 'x');
        $b = new TestBrowser($a);
        $users = $this->json($b->get('/api/v1/app/users', [], $this->basic($c['client_id'], $c['client_secret'])));
        $this->assertSame(['anna@example.org'], array_column($users['users'], 'email'));
    }

    public function testMeRequiresBearerToken(): void
    {
        $b = new TestBrowser($this->ctx->app);
        $r = $b->get('/api/v1/me');
        $this->assertTrue(in_array($r->status, [401, 503], true));
        $r2 = $b->get('/api/v1/me', [], ['HTTP_AUTHORIZATION' => 'Bearer not.a.jwt']);
        $this->assertTrue(in_array($r2->status, [401, 503], true));
    }

    public function testUserSearchTreatsWildcardsLiterally(): void
    {
        $u = $this->ctx->app->users;
        $this->assertSame(1, count($u->search('anna@')));
        $this->assertSame(0, count($u->search('%')), 'Prozentzeichen ist kein Platzhalter');
        $this->assertSame(0, count($u->search('_nna')), 'Unterstrich ist kein Platzhalter');
        $this->assertSame(0, count($u->search("a'; DROP TABLE users;--")));
        $this->assertSame(4, count($u->search('')));
    }
}

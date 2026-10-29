<?php
declare(strict_types=1);

namespace Vylaris\Tests;

use Vylaris\Core\{Clock, Migrator};
use Vylaris\Tests\Support\{Factory, TestBrowser, TestCase};

/** Das frühere Lizenzsystem ist weg: Aufräum-Migration und uneingeschränkte Nutzung. */
final class RemoveLicensingTest extends TestCase
{
    public function testMigrationDropsLegacyTablesAndAuditEntries(): void
    {
        $ctx = Factory::app();
        $db = $ctx->db;
        $db->run('CREATE TABLE user_licenses (user_id INT)');
        $db->run('CREATE TABLE license_releases (id INT)');
        $db->run('CREATE TABLE license_pool (id INT)');
        $db->insert('audit_log', ['created_at' => Clock::db(), 'event' => 'license.moved']);
        $db->insert('audit_log', ['created_at' => Clock::db(), 'event' => 'auth.register_license_refused']);
        $db->insert('audit_log', ['created_at' => Clock::db(), 'event' => 'auth.register']);
        $db->run('DELETE FROM schema_migrations WHERE version = 8');
        (new Migrator($db, dirname(__DIR__) . '/migrations'))->migrate();
        foreach (['user_licenses', 'license_releases', 'license_pool'] as $t) {
            $this->assertThrows(\PDOException::class, fn () => $db->run("SELECT 1 FROM $t"), $t);
        }
        $this->assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM audit_log WHERE event = 'auth.register'"));
        $this->assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM audit_log WHERE event LIKE 'license.%' OR event = 'auth.register_license_refused'"));
    }

    public function testRegistrationAndAdminCreationWorkWithoutAnyLicense(): void
    {
        $ctx = Factory::app();
        $a = $ctx->app;
        $res = $a->auth->register('frei@example.org', 'Correct-Horse-Battery-9', 'Frei Nutzer', '', true, '1');
        $this->assertTrue($res['ok'], implode(' ', $res['errors']));
        $this->assertNotNull($a->users->findByEmail('frei@example.org'));
        $admin = $a->users->create('boss@example.org', $a->passwords->hash('Correct-Horse-Battery-9'), 'Boss', true);
        $a->users->assignRole($admin, 'superadmin');
        $id = $a->admin->createUser($admin, 'neu@example.org', 'Neu Nutzer');
        $this->assertTrue($id > 0);
    }

    public function testNoLicenceRoutesRemain(): void
    {
        $ctx = Factory::app();
        $b = new TestBrowser($ctx->app);
        foreach (['/account/license', '/admin/license'] as $path) {
            $this->assertSame(404, $b->get($path)->status, $path);
        }
    }
}

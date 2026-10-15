<?php
declare(strict_types=1);

namespace Vylaris\Core;

/** Versionierte Migrationen aus migrations/NNN_name.php. */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return string[] angewendete Migrationsnamen */
    public function migrate(): array
    {
        $this->db->run('CREATE TABLE IF NOT EXISTS schema_migrations (
            version INT NOT NULL PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            applied_at DATETIME NOT NULL
        )' . $this->tail());

        $done = array_map('intval', array_column($this->db->all('SELECT version FROM schema_migrations'), 'version'));
        $applied = [];
        $files = glob(rtrim($this->dir, '/') . '/[0-9][0-9][0-9]_*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $version = (int) substr(basename($file), 0, 3);
            if (in_array($version, $done, true)) {
                continue;
            }
            $migration = require $file;
            foreach ($migration['statements'] as $sql) {
                // DDL ist in MySQL nicht transaktional – Fehler brechen mit klarer Meldung ab.
                $this->db->run($this->translate($sql));
            }
            $this->db->insert('schema_migrations', [
                'version' => $version,
                'name' => $migration['name'],
                'applied_at' => Clock::db(),
            ]);
            $applied[] = sprintf('%03d_%s', $version, $migration['name']);
        }
        return $applied;
    }

    private function tail(): string
    {
        return $this->db->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    }

    private function translate(string $sql): string
    {
        $pk = $this->db->driver() === 'mysql'
            ? 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        return str_replace(['{PK}', '{TAIL}'], [$pk, $this->tail()], $sql);
    }
}

<?php
/**
 * Migration 007 – Lizenzpool mit Statuscache: Zustand der hinterlegten Schlüssel (gültig, Plan, Plätze, Ablauf)
 * wird regelmäßig beim Lizenzserver geprüft und hier zwischengespeichert. Bereits genutzte Schlüssel
 * (user_licenses) werden in den Pool übernommen, damit er die Quelle aller Lizenzen ist.
 */
return [
    'name' => 'license_pool_status',
    'statements' => [
        "ALTER TABLE license_pool ADD COLUMN valid TINYINT NULL",
        "ALTER TABLE license_pool ADD COLUMN code VARCHAR(32) NULL",
        "ALTER TABLE license_pool ADD COLUMN plan VARCHAR(80) NULL",
        "ALTER TABLE license_pool ADD COLUMN max_devices INT NULL",
        "ALTER TABLE license_pool ADD COLUMN devices INT NULL",
        "ALTER TABLE license_pool ADD COLUMN expires_at DATETIME NULL",
        "ALTER TABLE license_pool ADD COLUMN checked_at DATETIME NULL",
        "INSERT INTO license_pool (license_key, created_at) SELECT license_key, MIN(activated_at) FROM user_licenses WHERE license_key NOT IN (SELECT license_key FROM license_pool) GROUP BY license_key",
    ],
];

<?php
/**
 * Migration 006 – Lizenzschlüssel-Pool: Schlüssel, die einmal hinterlegt werden und danach automatisch
 * für jedes neue Konto verwendet werden, bis alle Plätze (Geräte) aufgebraucht sind.
 */
return [
    'name' => 'license_pool',
    'statements' => [
        "CREATE TABLE license_pool (
            id {PK},
            license_key VARCHAR(64) NOT NULL,
            added_by BIGINT NULL,
            created_at DATETIME NOT NULL
        ){TAIL}",
        "CREATE UNIQUE INDEX uq_license_pool_key ON license_pool (license_key)",
    ],
];

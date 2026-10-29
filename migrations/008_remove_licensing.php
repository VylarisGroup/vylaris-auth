<?php
/**
 * Migration 008 – Entfernt die Reste des früheren Lizenzsystems (Tabellen und zugehörige Protokolleinträge).
 * Auf frischen Installationen passiert hier nichts.
 */
return [
    'name' => 'remove_licensing',
    'statements' => [
        'DROP TABLE IF EXISTS user_licenses',
        'DROP TABLE IF EXISTS license_releases',
        'DROP TABLE IF EXISTS license_pool',
        "DELETE FROM audit_log WHERE event LIKE 'license.%' OR event = 'auth.register_license_refused'",
    ],
];

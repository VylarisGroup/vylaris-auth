<?php
/**
 * Migration 005 – Änderungsprotokoll: merkt sich je Administrator, bis zu welcher Version er die Neuerungen gesehen hat.
 */
return [
    'name' => 'changelog',
    'statements' => [
        "CREATE TABLE admin_changelog_seen (
            user_id BIGINT NOT NULL PRIMARY KEY,
            version VARCHAR(32) NOT NULL,
            seen_at DATETIME NOT NULL
        ){TAIL}",
    ],
];

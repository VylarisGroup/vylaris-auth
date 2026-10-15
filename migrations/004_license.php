<?php
/**
 * Migration 004 – Lizenzsystem (TRS License).
 *  - user_licenses: Lizenzschlüssel eines Nutzers, abgeleitete Geräte-ID (hwid) und zuletzt geprüfter Status (Cache).
 *  - license_releases: Geräteplätze, die noch beim Lizenzserver freigegeben werden müssen
 *    (z. B. Konto gelöscht, Lizenzserver gerade nicht erreichbar) – der Cron-Job wiederholt das.
 */
return [
    'name' => 'license',
    'statements' => [
        "CREATE TABLE user_licenses (
            user_id BIGINT NOT NULL PRIMARY KEY,
            license_key VARCHAR(64) NOT NULL,
            hwid VARCHAR(64) NOT NULL,
            valid TINYINT NOT NULL DEFAULT 1,
            code VARCHAR(32) NOT NULL DEFAULT 'OK',
            plan VARCHAR(80) NULL,
            features TEXT NULL,
            expires_at DATETIME NULL,
            max_devices INT NULL,
            activated_at DATETIME NOT NULL,
            checked_at DATETIME NOT NULL,
            ok_at DATETIME NULL
        ){TAIL}",
        "CREATE INDEX idx_user_licenses_key ON user_licenses (license_key)",
        "CREATE INDEX idx_user_licenses_checked ON user_licenses (checked_at)",
        "CREATE TABLE license_releases (
            id {PK},
            license_key VARCHAR(64) NOT NULL,
            hwid VARCHAR(64) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ){TAIL}",
    ],
];

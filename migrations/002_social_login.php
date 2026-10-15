<?php
/**
 * Migration 002 – Social Login (föderierte Anmeldung über externe Identitätsanbieter).
 *  - social_providers: vom Admin konfigurierte Anbieter (Client-Secret verschlüsselt)
 *  - social_identities: Verknüpfung externer Identität (Anbieter + Subject) <-> lokales Konto
 *  - users.password_set: 0 = Konto wurde über Social Login angelegt und hat (noch) kein eigenes Passwort
 */
return [
    'name' => 'social_login',
    'statements' => [
        "CREATE TABLE social_providers (
            id {PK},
            provider_key VARCHAR(40) NOT NULL,
            type VARCHAR(20) NOT NULL,
            label VARCHAR(80) NOT NULL,
            client_id VARCHAR(255) NOT NULL,
            client_secret_enc TEXT NOT NULL,
            issuer VARCHAR(255) NULL,
            enabled TINYINT NOT NULL DEFAULT 0,
            allow_signup TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE (provider_key)
        ){TAIL}",

        "CREATE TABLE social_identities (
            id {PK},
            user_id BIGINT NOT NULL,
            provider_key VARCHAR(40) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            display_name VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            UNIQUE (provider_key, subject),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){TAIL}",
        "CREATE INDEX idx_social_identities_user ON social_identities (user_id)",

        "ALTER TABLE users ADD COLUMN password_set TINYINT NOT NULL DEFAULT 1",
    ],
];

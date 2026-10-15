<?php
/**
 * Migration 001 – Grundschema.
 * Platzhalter: {PK} = Auto-Increment-Primärschlüssel, {TAIL} = Tabellenoptionen (MySQL: InnoDB/utf8mb4).
 * Indizes stehen in eigenen CREATE INDEX-Anweisungen, damit dasselbe SQL auf MySQL/MariaDB
 * und (für Tests) SQLite läuft.
 */
return [
    'name' => 'init',
    'statements' => [
        "CREATE TABLE users (
            id {PK},
            email VARCHAR(255) NOT NULL,
            email_verified_at DATETIME NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            locked_until DATETIME NULL,
            locked_reason VARCHAR(255) NULL,
            failed_logins INT NOT NULL DEFAULT 0,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE (email)
        ){TAIL}",

        "CREATE TABLE user_tokens (
            id {PK},
            user_id BIGINT NOT NULL,
            type VARCHAR(30) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE (token_hash),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){TAIL}",
        "CREATE INDEX idx_user_tokens_user ON user_tokens (user_id, type)",

        "CREATE TABLE sessions (
            id CHAR(64) NOT NULL PRIMARY KEY,
            user_id BIGINT NULL,
            data TEXT NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL
        ){TAIL}",
        "CREATE INDEX idx_sessions_user ON sessions (user_id)",
        "CREATE INDEX idx_sessions_expires ON sessions (expires_at)",

        "CREATE TABLE mfa_totp (
            user_id BIGINT NOT NULL PRIMARY KEY,
            secret_enc TEXT NOT NULL,
            confirmed_at DATETIME NULL,
            last_used_step BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){TAIL}",

        "CREATE TABLE mfa_recovery_codes (
            id {PK},
            user_id BIGINT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){TAIL}",
        "CREATE INDEX idx_recovery_user ON mfa_recovery_codes (user_id)",

        "CREATE TABLE webauthn_credentials (
            id {PK},
            user_id BIGINT NOT NULL,
            credential_id VARCHAR(512) NOT NULL,
            credential_source TEXT NOT NULL,
            sign_count BIGINT NOT NULL DEFAULT 0,
            name VARCHAR(120) NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            UNIQUE (credential_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){TAIL}",
        "CREATE INDEX idx_webauthn_user ON webauthn_credentials (user_id)",

        "CREATE TABLE roles (
            id {PK},
            name VARCHAR(60) NOT NULL,
            description VARCHAR(255) NULL,
            UNIQUE (name)
        ){TAIL}",
        "CREATE TABLE user_roles (
            user_id BIGINT NOT NULL,
            role_id BIGINT NOT NULL,
            PRIMARY KEY (user_id, role_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ){TAIL}",

        "CREATE TABLE oauth_scopes (
            id {PK},
            name VARCHAR(60) NOT NULL,
            description VARCHAR(255) NULL,
            UNIQUE (name)
        ){TAIL}",

        "CREATE TABLE oauth_clients (
            id {PK},
            client_id VARCHAR(80) NOT NULL,
            name VARCHAR(120) NOT NULL,
            secret_hash VARCHAR(255) NULL,
            is_confidential TINYINT NOT NULL DEFAULT 1,
            is_active TINYINT NOT NULL DEFAULT 1,
            allowed_scopes VARCHAR(500) NOT NULL DEFAULT 'openid profile email',
            skip_consent TINYINT NOT NULL DEFAULT 0,
            access_policy VARCHAR(20) NOT NULL DEFAULT 'all',
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            UNIQUE (client_id)
        ){TAIL}",
        "CREATE TABLE oauth_client_uris (
            id {PK},
            client_pk BIGINT NOT NULL,
            type VARCHAR(20) NOT NULL,
            uri VARCHAR(500) NOT NULL,
            FOREIGN KEY (client_pk) REFERENCES oauth_clients(id) ON DELETE CASCADE
        ){TAIL}",
        "CREATE INDEX idx_client_uris ON oauth_client_uris (client_pk, type)",

        "CREATE TABLE oauth_auth_codes (
            id CHAR(64) NOT NULL PRIMARY KEY,
            client_pk BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            session_id CHAR(64) NULL,
            scopes VARCHAR(500) NOT NULL,
            redirect_uri VARCHAR(500) NOT NULL,
            code_challenge VARCHAR(128) NOT NULL,
            nonce VARCHAR(255) NULL,
            auth_time DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL
        ){TAIL}",

        "CREATE TABLE oauth_access_tokens (
            id CHAR(64) NOT NULL PRIMARY KEY,
            client_pk BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            session_id CHAR(64) NULL,
            code_id CHAR(64) NULL,
            scopes VARCHAR(500) NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL
        ){TAIL}",
        "CREATE INDEX idx_access_user ON oauth_access_tokens (user_id)",
        "CREATE INDEX idx_access_code ON oauth_access_tokens (code_id)",
        "CREATE INDEX idx_access_session ON oauth_access_tokens (session_id)",

        "CREATE TABLE oauth_refresh_tokens (
            id CHAR(64) NOT NULL PRIMARY KEY,
            access_token_id CHAR(64) NOT NULL,
            client_pk BIGINT NOT NULL,
            user_id BIGINT NOT NULL,
            session_id CHAR(64) NULL,
            code_id CHAR(64) NULL,
            scopes VARCHAR(500) NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            replaced_by CHAR(64) NULL,
            created_at DATETIME NOT NULL
        ){TAIL}",
        "CREATE INDEX idx_refresh_user ON oauth_refresh_tokens (user_id)",

        "CREATE TABLE oauth_consents (
            user_id BIGINT NOT NULL,
            client_pk BIGINT NOT NULL,
            scopes VARCHAR(500) NOT NULL,
            granted_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, client_pk),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (client_pk) REFERENCES oauth_clients(id) ON DELETE CASCADE
        ){TAIL}",

        "CREATE TABLE app_roles (
            id {PK},
            client_pk BIGINT NOT NULL,
            name VARCHAR(60) NOT NULL,
            description VARCHAR(255) NULL,
            UNIQUE (client_pk, name),
            FOREIGN KEY (client_pk) REFERENCES oauth_clients(id) ON DELETE CASCADE
        ){TAIL}",
        "CREATE TABLE user_app_roles (
            user_id BIGINT NOT NULL,
            app_role_id BIGINT NOT NULL,
            PRIMARY KEY (user_id, app_role_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (app_role_id) REFERENCES app_roles(id) ON DELETE CASCADE
        ){TAIL}",

        "CREATE TABLE oidc_keys (
            kid VARCHAR(64) NOT NULL PRIMARY KEY,
            public_pem TEXT NOT NULL,
            private_file VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            retired_at DATETIME NULL
        ){TAIL}",

        "CREATE TABLE audit_log (
            id {PK},
            created_at DATETIME NOT NULL,
            event VARCHAR(80) NOT NULL,
            user_id BIGINT NULL,
            actor_id BIGINT NULL,
            client_id VARCHAR(80) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            details TEXT NULL
        ){TAIL}",
        "CREATE INDEX idx_audit_created ON audit_log (created_at)",
        "CREATE INDEX idx_audit_event ON audit_log (event)",
        "CREATE INDEX idx_audit_user ON audit_log (user_id)",

        "CREATE TABLE rate_limits (
            bucket CHAR(64) NOT NULL,
            window_start BIGINT NOT NULL,
            hits INT NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, window_start)
        ){TAIL}",

        "CREATE TABLE mail_queue (
            id {PK},
            to_email VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_text TEXT NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            last_error VARCHAR(500) NULL
        ){TAIL}",
        "CREATE INDEX idx_mail_pending ON mail_queue (sent_at, attempts)",

        "CREATE TABLE settings (
            k VARCHAR(100) NOT NULL PRIMARY KEY,
            v TEXT NULL
        ){TAIL}",

        "INSERT INTO roles (name, description) VALUES ('superadmin', 'Vollzugriff inkl. Admin-Verwaltung')",
        "INSERT INTO roles (name, description) VALUES ('admin', 'Benutzer- und Anwendungsverwaltung')",
        "INSERT INTO roles (name, description) VALUES ('user', 'Standardbenutzer')",
        "INSERT INTO oauth_scopes (name, description) VALUES ('openid', 'Anmeldung via OpenID Connect')",
        "INSERT INTO oauth_scopes (name, description) VALUES ('profile', 'Name und Profilangaben')",
        "INSERT INTO oauth_scopes (name, description) VALUES ('email', 'E-Mail-Adresse und Verifizierungsstatus')",
        "INSERT INTO oauth_scopes (name, description) VALUES ('roles', 'Rollen in der Anwendung')",
        "INSERT INTO oauth_scopes (name, description) VALUES ('offline_access', 'Refresh Token ausstellen')",
    ],
];

<?php
/**
 * Migration 003 – Datenschutz (DSGVO).
 *  - users.privacy_accepted_at / privacy_version: Nachweis, dass die Datenschutzerklärung bei der Registrierung zur Kenntnis genommen wurde (Art. 7 Abs. 1 / 13)
 *  - user_tokens.payload: Zusatzdaten zu Einmal-Tokens (z. B. neue E-Mail-Adresse bei Änderung, Art. 16 Berichtigung)
 *  - users.inactivity_warned_at: Zeitpunkt der Vorwarnung vor automatischer Löschung inaktiver Konten (Speicherbegrenzung, Art. 5 Abs. 1 lit. e)
 */
return [
    'name' => 'privacy',
    'statements' => [
        "ALTER TABLE users ADD COLUMN privacy_accepted_at DATETIME NULL",
        "ALTER TABLE users ADD COLUMN privacy_version VARCHAR(20) NULL",
        "ALTER TABLE users ADD COLUMN inactivity_warned_at DATETIME NULL",
        "ALTER TABLE user_tokens ADD COLUMN payload VARCHAR(255) NULL",
    ],
];

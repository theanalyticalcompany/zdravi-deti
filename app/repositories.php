<?php

declare(strict_types=1);

const RUNTIME_SCHEMA_VERSION = '2026-06-29-auth-hardening-1';

function ensure_runtime_schema(): void
{
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    $schemaCacheFile = runtime_schema_cache_file($driver);
    if ($schemaCacheFile && runtime_schema_cache_is_current($schemaCacheFile)) {
        return;
    }

    ensure_users_email_allows_duplicates($driver);
    ensure_users_email_verification_columns($driver);
    if ($driver === 'sqlite') {
        $columns = array_column(db()->query('PRAGMA table_info(children)')->fetchAll(), 'name');
        if (!in_array('weight_kg', $columns, true)) {
            db()->exec('ALTER TABLE children ADD COLUMN weight_kg REAL NULL');
        }
        if (!in_array('allergies', $columns, true)) {
            db()->exec('ALTER TABLE children ADD COLUMN allergies TEXT NULL');
        }
        $medColumns = array_column(db()->query('PRAGMA table_info(medications)')->fetchAll(), 'name');
        foreach ([
            'system_key' => 'TEXT NULL',
            'dosage_form' => 'TEXT NULL',
            'strength' => 'TEXT NULL',
            'dosing_info' => 'TEXT NULL',
            'source_url' => 'TEXT NULL',
        ] as $column => $definition) {
            if (!in_array($column, $medColumns, true)) {
                db()->exec("ALTER TABLE medications ADD COLUMN {$column} {$definition}");
            }
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS family_invitations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                family_id INTEGER NOT NULL,
                invited_email TEXT NOT NULL,
                invited_by_user_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                registered_at TEXT NULL,
                accepted_at TEXT NULL,
                FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
                FOREIGN KEY (invited_by_user_id) REFERENCES users(id)
            )'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at TEXT NOT NULL,
                used_at TEXT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_password_resets_expires ON password_resets(expires_at)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                family_id INTEGER NULL,
                action TEXT NOT NULL,
                entity_type TEXT NULL,
                entity_id INTEGER NULL,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                meta_json TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_user ON audit_logs(user_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_family ON audit_logs(family_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rate_key TEXT NOT NULL,
                action TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                first_attempt_at TEXT NOT NULL,
                last_attempt_at TEXT NOT NULL,
                blocked_until TEXT NULL,
                UNIQUE (rate_key, action)
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_rate_limits_blocked ON rate_limits(blocked_until)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS user_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_id_hash TEXT NOT NULL UNIQUE,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at TEXT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions(user_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_user_sessions_revoked ON user_sessions(revoked_at)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS healthcare_providers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source TEXT NOT NULL DEFAULT "NRPZS",
                source_id TEXT NOT NULL,
                name TEXT NOT NULL,
                provider_name TEXT NULL,
                facility_type TEXT NULL,
                care_field TEXT NULL,
                care_form TEXT NULL,
                care_type TEXT NULL,
                search_text TEXT NULL,
                city TEXT NULL,
                zip TEXT NULL,
                street TEXT NULL,
                house_number TEXT NULL,
                region TEXT NULL,
                district TEXT NULL,
                phone TEXT NULL,
                email TEXT NULL,
                web TEXT NULL,
                representative TEXT NULL,
                gps TEXT NULL,
                last_modified TEXT NULL,
                imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (source, source_id)
            )'
        );
        $providerColumns = array_column(db()->query('PRAGMA table_info(healthcare_providers)')->fetchAll(), 'name');
        if (!in_array('search_text', $providerColumns, true)) {
            db()->exec('ALTER TABLE healthcare_providers ADD COLUMN search_text TEXT NULL');
        }
        db()->exec('CREATE INDEX IF NOT EXISTS idx_healthcare_providers_name ON healthcare_providers(name)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_healthcare_providers_search_text ON healthcare_providers(search_text)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_healthcare_providers_care_field ON healthcare_providers(care_field)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_healthcare_providers_city ON healthcare_providers(city)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS healthcare_provider_specialties (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider_id INTEGER NOT NULL,
                specialty TEXT NOT NULL,
                UNIQUE (provider_id, specialty),
                FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE CASCADE
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_healthcare_provider_specialties_specialty ON healthcare_provider_specialties(specialty)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS child_doctors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                child_id INTEGER NOT NULL,
                provider_id INTEGER NOT NULL,
                role_label TEXT NULL,
                note TEXT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (child_id, provider_id),
                FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE CASCADE
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_child_doctors_child ON child_doctors(child_id)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS child_documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                child_id INTEGER NOT NULL,
                provider_id INTEGER NULL,
                title TEXT NOT NULL,
                note TEXT NULL,
                original_filename TEXT NOT NULL,
                storage_path TEXT NOT NULL,
                mime_type TEXT NULL,
                storage_mode TEXT NOT NULL DEFAULT \'plain\',
                encryption_algo TEXT NULL,
                size_bytes INTEGER NOT NULL DEFAULT 0,
                uploaded_by_user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_child_documents_child ON child_documents(child_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_child_documents_provider ON child_documents(provider_id)');
        $documentColumns = array_column(db()->query('PRAGMA table_info(child_documents)')->fetchAll(), 'name');
        foreach ([
            'document_type' => "TEXT NOT NULL DEFAULT 'general'",
            'is_sensitive' => 'INTEGER NOT NULL DEFAULT 0',
            'storage_mode' => "TEXT NOT NULL DEFAULT 'plain'",
            'encryption_algo' => 'TEXT NULL',
        ] as $column => $definition) {
            if (!in_array($column, $documentColumns, true)) {
                db()->exec("ALTER TABLE child_documents ADD COLUMN {$column} {$definition}");
            }
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS child_appointments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                child_id INTEGER NOT NULL,
                provider_id INTEGER NULL,
                title TEXT NOT NULL,
                appointment_type TEXT NOT NULL DEFAULT \'Kontrola\',
                scheduled_at TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'planned\',
                pre_note TEXT NULL,
                result_note TEXT NULL,
                recommendation TEXT NULL,
                created_by_user_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by_user_id) REFERENCES users(id)
            )'
        );
        db()->exec('CREATE INDEX IF NOT EXISTS idx_child_appointments_child ON child_appointments(child_id)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_child_appointments_scheduled ON child_appointments(scheduled_at)');
        db()->exec('CREATE INDEX IF NOT EXISTS idx_child_appointments_provider ON child_appointments(provider_id)');
        db()->exec(
            'CREATE TABLE IF NOT EXISTS appointment_documents (
                appointment_id INTEGER NOT NULL,
                document_id INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (appointment_id, document_id),
                FOREIGN KEY (appointment_id) REFERENCES child_appointments(id) ON DELETE CASCADE,
                FOREIGN KEY (document_id) REFERENCES child_documents(id) ON DELETE CASCADE
            )'
        );
        $recordColumns = array_column(db()->query('PRAGMA table_info(record_types)')->fetchAll(), 'name');
        foreach ([
            'is_quick' => 'INTEGER NOT NULL DEFAULT 1',
            'sort_order' => 'INTEGER NOT NULL DEFAULT 100',
        ] as $column => $definition) {
            if (!in_array($column, $recordColumns, true)) {
                db()->exec("ALTER TABLE record_types ADD COLUMN {$column} {$definition}");
            }
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS symptom_records (
                health_record_id INTEGER PRIMARY KEY,
                symptoms TEXT NOT NULL,
                severity TEXT NULL,
                FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE
            )'
        );
        ensure_special_record_types();
        return;
    }

    if ($driver === 'mysql') {
        $stmt = db()->query("SHOW COLUMNS FROM children LIKE 'weight_kg'");
        if (!$stmt->fetch()) {
            db()->exec('ALTER TABLE children ADD COLUMN weight_kg DECIMAL(5,2) NULL AFTER date_of_birth');
        }
        $stmt = db()->query("SHOW COLUMNS FROM children LIKE 'allergies'");
        if (!$stmt->fetch()) {
            db()->exec('ALTER TABLE children ADD COLUMN allergies TEXT NULL AFTER weight_kg');
        }
        foreach ([
            'system_key' => 'VARCHAR(80) NULL AFTER family_id',
            'dosage_form' => 'VARCHAR(120) NULL AFTER name',
            'strength' => 'VARCHAR(120) NULL AFTER dosage_form',
            'dosing_info' => 'TEXT NULL AFTER strength',
            'source_url' => 'VARCHAR(500) NULL AFTER dosing_info',
        ] as $column => $definition) {
            $stmt = db()->query("SHOW COLUMNS FROM medications LIKE '{$column}'");
            if (!$stmt->fetch()) {
                db()->exec("ALTER TABLE medications ADD COLUMN {$column} {$definition}");
            }
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS family_invitations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                family_id INT UNSIGNED NOT NULL,
                invited_email VARCHAR(190) NOT NULL,
                invited_by_user_id INT UNSIGNED NOT NULL,
                token VARCHAR(120) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                registered_at DATETIME NULL,
                accepted_at DATETIME NULL,
                KEY idx_family_invitations_email (invited_email),
                CONSTRAINT fk_family_invitations_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
                CONSTRAINT fk_family_invitations_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS password_resets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                KEY idx_password_resets_user (user_id),
                KEY idx_password_resets_expires (expires_at),
                CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                family_id INT UNSIGNED NULL,
                action VARCHAR(120) NOT NULL,
                entity_type VARCHAR(80) NULL,
                entity_id INT UNSIGNED NULL,
                ip_address VARCHAR(80) NULL,
                user_agent VARCHAR(255) NULL,
                meta_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_audit_logs_user (user_id),
                KEY idx_audit_logs_family (family_id),
                KEY idx_audit_logs_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS rate_limits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rate_key CHAR(64) NOT NULL,
                action VARCHAR(80) NOT NULL,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                first_attempt_at DATETIME NOT NULL,
                last_attempt_at DATETIME NOT NULL,
                blocked_until DATETIME NULL,
                UNIQUE KEY uq_rate_limits_key_action (rate_key, action),
                KEY idx_rate_limits_blocked (blocked_until)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS user_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                session_id_hash CHAR(64) NOT NULL UNIQUE,
                ip_address VARCHAR(80) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                KEY idx_user_sessions_user (user_id),
                KEY idx_user_sessions_revoked (revoked_at),
                CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS healthcare_providers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(40) NOT NULL DEFAULT "NRPZS",
                source_id VARCHAR(80) NOT NULL,
                name VARCHAR(255) NOT NULL,
                provider_name VARCHAR(255) NULL,
                facility_type VARCHAR(255) NULL,
                care_field VARCHAR(255) NULL,
                care_form VARCHAR(255) NULL,
                care_type VARCHAR(255) NULL,
                search_text TEXT NULL,
                city VARCHAR(160) NULL,
                zip VARCHAR(20) NULL,
                street VARCHAR(160) NULL,
                house_number VARCHAR(80) NULL,
                region VARCHAR(160) NULL,
                district VARCHAR(160) NULL,
                phone VARCHAR(120) NULL,
                email VARCHAR(190) NULL,
                web VARCHAR(255) NULL,
                representative VARCHAR(255) NULL,
                gps VARCHAR(120) NULL,
                last_modified DATETIME NULL,
                imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_healthcare_provider_source (source, source_id),
                KEY idx_healthcare_providers_name (name),
                KEY idx_healthcare_providers_search_text (search_text(255)),
                KEY idx_healthcare_providers_care_field (care_field),
                KEY idx_healthcare_providers_city (city)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $stmt = db()->query("SHOW COLUMNS FROM healthcare_providers LIKE 'search_text'");
        if (!$stmt->fetch()) {
            db()->exec('ALTER TABLE healthcare_providers ADD COLUMN search_text TEXT NULL AFTER care_type');
            db()->exec('CREATE INDEX idx_healthcare_providers_search_text ON healthcare_providers(search_text(255))');
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS healthcare_provider_specialties (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider_id INT UNSIGNED NOT NULL,
                specialty VARCHAR(255) NOT NULL,
                UNIQUE KEY uq_healthcare_provider_specialty (provider_id, specialty),
                KEY idx_healthcare_provider_specialties_specialty (specialty),
                CONSTRAINT fk_provider_specialties_provider FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS child_doctors (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_id INT UNSIGNED NOT NULL,
                provider_id INT UNSIGNED NOT NULL,
                role_label VARCHAR(160) NULL,
                note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_child_doctor_provider (child_id, provider_id),
                KEY idx_child_doctors_child (child_id),
                CONSTRAINT fk_child_doctors_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                CONSTRAINT fk_child_doctors_provider FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS child_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_id INT UNSIGNED NOT NULL,
                provider_id INT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                note TEXT NULL,
                document_type VARCHAR(40) NOT NULL DEFAULT \'general\',
                is_sensitive TINYINT(1) NOT NULL DEFAULT 0,
                original_filename VARCHAR(255) NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(160) NULL,
                storage_mode VARCHAR(20) NOT NULL DEFAULT \'plain\',
                encryption_algo VARCHAR(80) NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                uploaded_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_child_documents_child (child_id),
                KEY idx_child_documents_provider (provider_id),
                CONSTRAINT fk_child_documents_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                CONSTRAINT fk_child_documents_provider FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE SET NULL,
                CONSTRAINT fk_child_documents_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        foreach ([
            'document_type' => "VARCHAR(40) NOT NULL DEFAULT 'general' AFTER note",
            'is_sensitive' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER document_type',
            'storage_mode' => "VARCHAR(20) NOT NULL DEFAULT 'plain' AFTER mime_type",
            'encryption_algo' => 'VARCHAR(80) NULL AFTER storage_mode',
        ] as $column => $definition) {
            $stmt = db()->query("SHOW COLUMNS FROM child_documents LIKE '{$column}'");
            if (!$stmt->fetch()) {
                db()->exec("ALTER TABLE child_documents ADD COLUMN {$column} {$definition}");
            }
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS child_appointments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                child_id INT UNSIGNED NOT NULL,
                provider_id INT UNSIGNED NULL,
                title VARCHAR(255) NOT NULL,
                appointment_type VARCHAR(120) NOT NULL DEFAULT \'Kontrola\',
                scheduled_at DATETIME NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT \'planned\',
                pre_note TEXT NULL,
                result_note TEXT NULL,
                recommendation TEXT NULL,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_child_appointments_child (child_id),
                KEY idx_child_appointments_scheduled (scheduled_at),
                KEY idx_child_appointments_provider (provider_id),
                CONSTRAINT fk_child_appointments_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
                CONSTRAINT fk_child_appointments_provider FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE SET NULL,
                CONSTRAINT fk_child_appointments_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        db()->exec(
            'CREATE TABLE IF NOT EXISTS appointment_documents (
                appointment_id INT UNSIGNED NOT NULL,
                document_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (appointment_id, document_id),
                CONSTRAINT fk_appointment_documents_appointment FOREIGN KEY (appointment_id) REFERENCES child_appointments(id) ON DELETE CASCADE,
                CONSTRAINT fk_appointment_documents_document FOREIGN KEY (document_id) REFERENCES child_documents(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        foreach ([
            'is_quick' => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER is_system',
            'sort_order' => 'INT NOT NULL DEFAULT 100 AFTER is_quick',
        ] as $column => $definition) {
            $stmt = db()->query("SHOW COLUMNS FROM record_types LIKE '{$column}'");
            if (!$stmt->fetch()) {
                db()->exec("ALTER TABLE record_types ADD COLUMN {$column} {$definition}");
            }
        }
        db()->exec(
            'CREATE TABLE IF NOT EXISTS symptom_records (
                health_record_id INT UNSIGNED PRIMARY KEY,
                symptoms TEXT NOT NULL,
                severity VARCHAR(40) NULL,
                CONSTRAINT fk_symptom_records_health FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        ensure_special_record_types();
        runtime_schema_cache_mark_current($schemaCacheFile);
    }
}

function runtime_schema_cache_file(string $driver): ?string
{
    if ($driver !== 'mysql') {
        return null;
    }

    $dir = dirname(__DIR__) . '/var';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    $dsnHash = substr(hash('sha256', (string)cfg('db.dsn', '')), 0, 16);
    return $dir . '/runtime-schema-' . $driver . '-' . $dsnHash . '.version';
}

function runtime_schema_cache_is_current(?string $path): bool
{
    return $path !== null && is_file($path) && trim((string)@file_get_contents($path)) === RUNTIME_SCHEMA_VERSION;
}

function runtime_schema_cache_mark_current(?string $path): void
{
    if ($path !== null) {
        @file_put_contents($path, RUNTIME_SCHEMA_VERSION . "\n", LOCK_EX);
    }
}

function ensure_users_email_allows_duplicates(string $driver): void
{
    if ($driver === 'sqlite') {
        $indexes = db()->query('PRAGMA index_list(users)')->fetchAll();
        $emailUnique = false;
        foreach ($indexes as $index) {
            if ((int)($index['unique'] ?? 0) !== 1) {
                continue;
            }
            $name = (string)$index['name'];
            $columns = db()->query('PRAGMA index_info(' . db()->quote($name) . ')')->fetchAll();
            if (count($columns) === 1 && ($columns[0]['name'] ?? '') === 'email') {
                $emailUnique = true;
                break;
            }
        }
        if ($emailUnique) {
            db()->exec('PRAGMA foreign_keys = OFF');
            db()->beginTransaction();
            try {
                db()->exec(
                    'CREATE TABLE users_new (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        email TEXT NOT NULL,
                        display_name TEXT NOT NULL,
                        password_hash TEXT NULL,
                        google_subject_id TEXT NULL UNIQUE,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        last_login_at TEXT NULL,
                        is_active INTEGER NOT NULL DEFAULT 1
                    )'
                );
                db()->exec(
                    'INSERT INTO users_new (id, email, display_name, password_hash, google_subject_id, created_at, updated_at, last_login_at, is_active)
                     SELECT id, email, display_name, password_hash, google_subject_id, created_at, updated_at, last_login_at, is_active FROM users'
                );
                db()->exec('DROP TABLE users');
                db()->exec('ALTER TABLE users_new RENAME TO users');
                db()->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)');
                db()->commit();
            } catch (Throwable $e) {
                db()->rollBack();
                db()->exec('PRAGMA foreign_keys = ON');
                throw $e;
            }
            db()->exec('PRAGMA foreign_keys = ON');
        } else {
            db()->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)');
        }
        return;
    }

    if ($driver === 'mysql') {
        $stmt = db()->query("SHOW INDEX FROM users WHERE Column_name = 'email' AND Non_unique = 0");
        foreach ($stmt->fetchAll() as $index) {
            $keyName = (string)$index['Key_name'];
            if ($keyName !== 'PRIMARY') {
                db()->exec('ALTER TABLE users DROP INDEX `' . str_replace('`', '``', $keyName) . '`');
            }
        }
        $stmt = db()->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_email'");
        if (!$stmt->fetch()) {
            db()->exec('CREATE INDEX idx_users_email ON users(email)');
        }
    }
}

function ensure_users_email_verification_columns(string $driver): void
{
    if ($driver === 'sqlite') {
        $columns = array_column(db()->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        $addedVerifiedAt = false;
        foreach ([
            'email_verified_at' => 'TEXT NULL',
            'email_verification_token_hash' => 'TEXT NULL',
            'email_verification_expires_at' => 'TEXT NULL',
        ] as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                db()->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
                $addedVerifiedAt = $addedVerifiedAt || $column === 'email_verified_at';
            }
        }
        if ($addedVerifiedAt) {
            db()->exec("UPDATE users SET email_verified_at = COALESCE(last_login_at, created_at, CURRENT_TIMESTAMP) WHERE is_active = 1 AND email_verified_at IS NULL");
        }
        db()->exec('CREATE INDEX IF NOT EXISTS idx_users_email_verification_token ON users(email_verification_token_hash)');
        return;
    }

    if ($driver === 'mysql') {
        $addedVerifiedAt = false;
        foreach ([
            'email_verified_at' => 'DATETIME NULL AFTER google_subject_id',
            'email_verification_token_hash' => 'CHAR(64) NULL AFTER email_verified_at',
            'email_verification_expires_at' => 'DATETIME NULL AFTER email_verification_token_hash',
        ] as $column => $definition) {
            $stmt = db()->query("SHOW COLUMNS FROM users LIKE '{$column}'");
            if (!$stmt->fetch()) {
                db()->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
                $addedVerifiedAt = $addedVerifiedAt || $column === 'email_verified_at';
            }
        }
        if ($addedVerifiedAt) {
            db()->exec('UPDATE users SET email_verified_at = COALESCE(last_login_at, created_at, NOW()) WHERE is_active = 1 AND email_verified_at IS NULL');
        }
        $stmt = db()->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_email_verification_token'");
        if (!$stmt->fetch()) {
            db()->exec('CREATE INDEX idx_users_email_verification_token ON users(email_verification_token_hash)');
        }
    }
}

function find_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM users
         WHERE email = ? AND is_active = 1
         ORDER BY google_subject_id IS NOT NULL DESC, last_login_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([text_lower(trim($email))]);
    return $stmt->fetch() ?: null;
}

function find_password_user_by_email(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM users
         WHERE email = ? AND password_hash IS NOT NULL AND is_active = 1
         ORDER BY last_login_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([text_lower(trim($email))]);
    return $stmt->fetch() ?: null;
}

function find_user_by_google_subject(string $googleSubject): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE google_subject_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$googleSubject]);
    return $stmt->fetch() ?: null;
}

function user_owned_family_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM families WHERE owner_user_id = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function create_user(string $email, string $name, ?string $password, ?string $googleSubject = null, bool $emailVerified = false): int
{
    $hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    $verifiedAt = $emailVerified ? now_sql() : null;
    $stmt = db()->prepare('INSERT INTO users (email, display_name, password_hash, google_subject_id, email_verified_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([text_lower(trim($email)), trim($name), $hash, $googleSubject, $verifiedAt]);
    return (int)db()->lastInsertId();
}

function user_email_is_verified(array $user): bool
{
    return !empty($user['email_verified_at']);
}

function mark_user_email_verified(int $userId): void
{
    db()->prepare(
        'UPDATE users
         SET email_verified_at = COALESCE(email_verified_at, ?),
             email_verification_token_hash = NULL,
             email_verification_expires_at = NULL,
             updated_at = ?
         WHERE id = ? AND is_active = 1'
    )->execute([now_sql(), now_sql(), $userId]);
}

function create_email_verification_token(int $userId): string
{
    $rawToken = bin2hex(random_bytes(32));
    $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
    db()->prepare(
        'UPDATE users
         SET email_verification_token_hash = ?, email_verification_expires_at = ?, updated_at = ?
         WHERE id = ? AND is_active = 1'
    )->execute([hash('sha256', $rawToken), $expiresAt, now_sql(), $userId]);
    return $rawToken;
}

function email_verification_by_token(string $token): ?array
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT *
         FROM users
         WHERE email_verification_token_hash = ?
           AND email_verification_expires_at >= ?
           AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token), now_sql()]);
    return $stmt->fetch() ?: null;
}

function consume_email_verification_token(string $token): ?array
{
    $user = email_verification_by_token($token);
    if (!$user) {
        return null;
    }

    mark_user_email_verified((int)$user['id']);
    $verifiedUser = find_user((int)$user['id']);
    if (!$verifiedUser) {
        return null;
    }
    $invitations = accept_pending_invitations_for_user((int)$verifiedUser['id'], (string)$verifiedUser['email']);
    return ['user' => $verifiedUser, 'invitations' => $invitations];
}

function delete_user_account(int $userId): void
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch() ?: null;
    if (!$user) {
        return;
    }
    $now = now_sql();
    $oldEmail = (string)$user['email'];
    $deletedEmail = 'deleted-user-' . $userId . '-' . bin2hex(random_bytes(6)) . '@deleted.local';

    db()->beginTransaction();
    try {
        // Remove side families owned by this user. Families where the user is only a parent remain intact.
        db()->prepare('DELETE FROM families WHERE owner_user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM child_access WHERE user_id = ?')->execute([$userId]);
        db()->prepare('DELETE FROM family_members WHERE user_id = ?')->execute([$userId]);
        db()->prepare('UPDATE family_invitations SET invited_email = ? WHERE invited_email = ?')
            ->execute([$deletedEmail, text_lower(trim($oldEmail))]);
        db()->prepare(
            'UPDATE users
             SET email = ?, display_name = ?, password_hash = NULL, google_subject_id = NULL, is_active = 0, updated_at = ?
             WHERE id = ?'
        )->execute([$deletedEmail, 'Smazaný účet', $now, $userId]);
        db()->commit();
        forget_user_request_cache($userId);
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function remember_user_session(int $userId): void
{
    $sessionHash = current_session_hash();
    $stmt = db()->prepare('SELECT id FROM user_sessions WHERE session_id_hash = ? LIMIT 1');
    $stmt->execute([$sessionHash]);
    $sessionId = $stmt->fetchColumn();
    if ($sessionId) {
        db()->prepare(
            'UPDATE user_sessions
             SET user_id = ?, ip_address = ?, user_agent = ?, last_seen_at = ?, revoked_at = NULL
             WHERE id = ?'
        )->execute([$userId, client_ip(), user_agent(), now_sql(), $sessionId]);
    } else {
        db()->prepare(
            'INSERT INTO user_sessions (user_id, session_id_hash, ip_address, user_agent, created_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $sessionHash, client_ip(), user_agent(), now_sql(), now_sql()]);
    }
    $_SESSION['session_seen_at'] = time();
}

function touch_user_session(int $userId): void
{
    if (!empty($_SESSION['session_seen_at']) && time() - (int)$_SESSION['session_seen_at'] < 60) {
        return;
    }
    remember_user_session($userId);
}

function user_session_revoked(int $userId): bool
{
    $stmt = db()->prepare(
        'SELECT revoked_at FROM user_sessions
         WHERE user_id = ? AND session_id_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$userId, current_session_hash()]);
    $revokedAt = $stmt->fetchColumn();
    return $revokedAt !== false && $revokedAt !== null && $revokedAt !== '';
}

function active_user_sessions(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM user_sessions
         WHERE user_id = ? AND revoked_at IS NULL
         ORDER BY last_seen_at DESC, created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function revoke_user_session(int $userId, int $sessionId): ?array
{
    $stmt = db()->prepare('SELECT * FROM user_sessions WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1');
    $stmt->execute([$sessionId, $userId]);
    $session = $stmt->fetch() ?: null;
    if (!$session || hash_equals((string)$session['session_id_hash'], current_session_hash())) {
        return null;
    }
    db()->prepare('UPDATE user_sessions SET revoked_at = ? WHERE id = ? AND user_id = ?')
        ->execute([now_sql(), $sessionId, $userId]);
    return $session;
}

function revoke_other_user_sessions(int $userId): int
{
    $stmt = db()->prepare(
        'UPDATE user_sessions
         SET revoked_at = ?
         WHERE user_id = ? AND session_id_hash <> ? AND revoked_at IS NULL'
    );
    $stmt->execute([now_sql(), $userId, current_session_hash()]);
    return $stmt->rowCount();
}

function revoke_current_user_session(int $userId): void
{
    db()->prepare(
        'UPDATE user_sessions SET revoked_at = ?
         WHERE user_id = ? AND session_id_hash = ? AND revoked_at IS NULL'
    )->execute([now_sql(), $userId, current_session_hash()]);
}

function create_password_reset_token(int $userId): string
{
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

    db()->prepare('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL')
        ->execute([now_sql(), $userId]);
    db()->prepare('DELETE FROM password_resets WHERE expires_at < ?')
        ->execute([now_sql()]);
    db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, $tokenHash, $expiresAt]);

    return $rawToken;
}

function password_reset_by_token(string $token): ?array
{
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT pr.*, u.email, u.display_name
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at >= ? AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token), now_sql()]);
    return $stmt->fetch() ?: null;
}

function consume_password_reset_token(string $token, string $password): bool
{
    $reset = password_reset_by_token($token);
    if (!$reset) {
        return false;
    }

    db()->beginTransaction();
    try {
        $usedAt = now_sql();
        $stmt = db()->prepare('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL');
        $stmt->execute([$usedAt, $reset['id']]);
        if ($stmt->rowCount() !== 1) {
            db()->rollBack();
            return false;
        }

        db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $usedAt, $reset['user_id']]);
        db()->commit();
        return true;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function current_family(int $userId): ?array
{
    global $requestFamilyCache;
    if (!is_array($requestFamilyCache ?? null)) {
        $requestFamilyCache = [];
    }
    if (isset($requestFamilyCache[$userId])) {
        return $requestFamilyCache[$userId];
    }

    $stmt = db()->prepare(
        'SELECT f.*, fm.role
         FROM families f
         JOIN family_members fm ON fm.family_id = f.id
         WHERE fm.user_id = ?
         ORDER BY fm.created_at DESC, fm.id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $family = $stmt->fetch() ?: null;
    if ($family) {
        ensure_default_medications((int)$family['id']);
        $requestFamilyCache[$userId] = $family;
    }
    return $family;
}

function forget_user_request_cache(int $userId): void
{
    global $requestFamilyCache, $requestChildrenCache;
    if (is_array($requestFamilyCache ?? null)) {
        unset($requestFamilyCache[$userId]);
    }
    if (is_array($requestChildrenCache ?? null)) {
        unset($requestChildrenCache[$userId]);
    }
}

function pending_invitations_for_email(string $email): array
{
    $stmt = db()->prepare(
        'SELECT fi.*, f.name AS family_name, u.email AS inviter_email, u.display_name AS inviter_name
         FROM family_invitations fi
         JOIN families f ON f.id = fi.family_id
         JOIN users u ON u.id = fi.invited_by_user_id
         WHERE fi.invited_email = ? AND fi.accepted_at IS NULL
         ORDER BY fi.created_at DESC'
    );
    $stmt->execute([text_lower(trim($email))]);
    return $stmt->fetchAll();
}

function pending_family_invitations(int $familyId): array
{
    $stmt = db()->prepare(
        'SELECT fi.*, u.display_name AS inviter_name, u.email AS inviter_email,
                (
                    SELECT ru.id
                    FROM users ru
                    WHERE ru.email = fi.invited_email AND ru.is_active = 1
                    ORDER BY ru.google_subject_id IS NOT NULL DESC, ru.last_login_at DESC, ru.id DESC
                    LIMIT 1
                ) AS registered_user_id,
                (
                    SELECT ru.display_name
                    FROM users ru
                    WHERE ru.email = fi.invited_email AND ru.is_active = 1
                    ORDER BY ru.google_subject_id IS NOT NULL DESC, ru.last_login_at DESC, ru.id DESC
                    LIMIT 1
                ) AS registered_display_name,
                (
                    SELECT ru.email_verified_at
                    FROM users ru
                    WHERE ru.email = fi.invited_email AND ru.is_active = 1
                    ORDER BY ru.google_subject_id IS NOT NULL DESC, ru.last_login_at DESC, ru.id DESC
                    LIMIT 1
                ) AS registered_email_verified_at
         FROM family_invitations fi
         JOIN users u ON u.id = fi.invited_by_user_id
         WHERE fi.family_id = ? AND fi.accepted_at IS NULL
         ORDER BY fi.created_at DESC'
    );
    $stmt->execute([$familyId]);
    return $stmt->fetchAll();
}

function pending_family_invitation_by_email(int $familyId, string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM family_invitations
         WHERE family_id = ? AND invited_email = ? AND accepted_at IS NULL
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$familyId, text_lower(trim($email))]);
    return $stmt->fetch() ?: null;
}

function cancel_family_invitation(int $familyId, int $invitationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM family_invitations WHERE id = ? AND family_id = ? AND accepted_at IS NULL');
    $stmt->execute([$invitationId, $familyId]);
    $invitation = $stmt->fetch() ?: null;
    if (!$invitation) {
        return null;
    }
    db()->prepare('DELETE FROM family_invitations WHERE id = ? AND family_id = ? AND accepted_at IS NULL')
        ->execute([$invitationId, $familyId]);
    return $invitation;
}

function pending_family_invitation_by_id(int $familyId, int $invitationId): ?array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM family_invitations
         WHERE id = ? AND family_id = ? AND accepted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$invitationId, $familyId]);
    return $stmt->fetch() ?: null;
}

function create_family_invitation(int $familyId, int $inviterUserId, string $email): array
{
    $token = bin2hex(random_bytes(24));
    $stmt = db()->prepare('INSERT INTO family_invitations (family_id, invited_email, invited_by_user_id, token) VALUES (?, ?, ?, ?)');
    $stmt->execute([$familyId, text_lower(trim($email)), $inviterUserId, $token]);
    return ['token' => $token];
}

function mark_invitations_registered(string $email): array
{
    $items = pending_invitations_for_email($email);
    if ($items) {
        db()->prepare('UPDATE family_invitations SET registered_at = ? WHERE invited_email = ? AND registered_at IS NULL')
            ->execute([now_sql(), text_lower(trim($email))]);
    }
    return $items;
}

function accept_pending_invitations_for_user(int $userId, string $email): array
{
    $user = find_user($userId);
    if (!$user || !user_email_is_verified($user)) {
        mark_invitations_registered($email);
        return [];
    }

    $items = pending_invitations_for_email($email);
    foreach ($items as $invitation) {
        add_user_to_family((int)$invitation['family_id'], $userId);
        db()->prepare('UPDATE family_invitations SET registered_at = COALESCE(registered_at, ?), accepted_at = COALESCE(accepted_at, ?) WHERE id = ?')
            ->execute([now_sql(), now_sql(), $invitation['id']]);
    }
    return $items;
}

function accept_registered_family_invitation(int $familyId, int $invitationId): ?array
{
    $invitation = pending_family_invitation_by_id($familyId, $invitationId);
    if (!$invitation) {
        return null;
    }
    $user = find_user_by_email((string)$invitation['invited_email']);
    if (!$user || !user_email_is_verified($user)) {
        return null;
    }

    add_user_to_family($familyId, (int)$user['id']);
    db()->prepare('UPDATE family_invitations SET registered_at = COALESCE(registered_at, ?), accepted_at = COALESCE(accepted_at, ?) WHERE id = ?')
        ->execute([now_sql(), now_sql(), $invitationId]);

    return ['invitation' => $invitation, 'user' => $user];
}

function add_user_to_family(int $familyId, int $userId, bool $grantChildAccess = false): void
{
    $insertIgnore = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    db()->beginTransaction();
    try {
        db()->prepare($insertIgnore . ' INTO family_members (family_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$familyId, $userId, 'PARENT']);
        if ($grantChildAccess) {
            $children = db()->prepare('SELECT id FROM children WHERE family_id = ?');
            $children->execute([$familyId]);
            $access = db()->prepare($insertIgnore . ' INTO child_access (child_id, user_id, can_view, can_create_record, can_edit_record, can_delete_record) VALUES (?, ?, 1, 1, 1, 1)');
            foreach ($children->fetchAll() as $child) {
                $access->execute([$child['id'], $userId]);
            }
        }
        db()->prepare('UPDATE family_invitations SET accepted_at = ? WHERE family_id = ? AND invited_email = (SELECT email FROM users WHERE id = ?)')
            ->execute([now_sql(), $familyId, $userId]);
        db()->commit();
        forget_user_request_cache($userId);
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function ensure_family(int $userId, string $userName): array
{
    $family = current_family($userId);
    if ($family) {
        update_system_record_type_names((int)$family['id']);
        ensure_special_record_types((int)$family['id']);
        ensure_default_medications((int)$family['id']);
        return $family;
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('INSERT INTO families (name, owner_user_id) VALUES (?, ?)');
        $stmt->execute(['Rodina ' . $userName, $userId]);
        $familyId = (int)db()->lastInsertId();

        $stmt = db()->prepare('INSERT INTO family_members (family_id, user_id, role) VALUES (?, ?, ?)');
        $stmt->execute([$familyId, $userId, 'OWNER']);

        create_system_record_types($familyId);
        update_system_record_type_names($familyId);
        ensure_special_record_types($familyId);
        ensure_default_medications($familyId);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    return current_family($userId);
}

function default_medication_rows(): array
{
    $safety = 'Informativní údaj. Vždy ověřte aktuální příbalovou informaci a při nejasnostech kontaktujte lékaře nebo lékárníka.';
    return [
        [
            'key' => 'nurofen_deti_sus_20',
            'name' => 'Nurofen pro děti',
            'form' => 'suspenze',
            'strength' => '20 mg/ml',
            'info' => 'Ibuprofen. Obvyklé dětské dávkování se řídí hmotností; jednotlivě 5-10 mg/kg, interval obvykle 6-8 h, nepřekračovat denní maximum dle příbalové informace. ' . $safety,
        ],
        [
            'key' => 'paralen_deti_sus_24',
            'name' => 'Paralen pro děti',
            'form' => 'suspenze',
            'strength' => '24 mg/ml',
            'info' => 'Paracetamol. Obvyklé dětské dávkování 10-15 mg/kg v jednotlivé dávce, interval nejméně 6 h, nepřekračovat denní maximum dle příbalové informace. ' . $safety,
        ],
        [
            'key' => 'paralen_125_tablety',
            'name' => 'Paralen pro děti',
            'form' => 'tablety',
            'strength' => '125 mg',
            'info' => 'Paracetamol. Volba síly závisí na věku a hmotnosti dítěte; dávkování ověřte v příbalové informaci. Nekombinujte s dalšími přípravky s paracetamolem. ' . $safety,
        ],
        [
            'key' => 'paralen_500_tablety',
            'name' => 'Paralen',
            'form' => 'tablety',
            'strength' => '500 mg',
            'info' => 'Paracetamol. Síla 500 mg je vhodná jen pro odpovídající věk/hmotnost; u dětí ověřte dávkování v příbalové informaci. Nekombinujte s dalšími přípravky s paracetamolem. ' . $safety,
        ],
        [
            'key' => 'aerius_sirup_05',
            'name' => 'Aerius',
            'form' => 'sirup',
            'strength' => '0,5 mg/ml',
            'info' => 'Desloratadin. Orientačně: 1-5 let 2,5 ml 1x denně, 6-11 let 5 ml 1x denně, 12+ let 10 ml 1x denně. Výdej je na lékařský předpis. ' . $safety,
        ],
        [
            'key' => 'mucosolvan_junior_sirup_15',
            'name' => 'Mucosolvan pro děti',
            'form' => 'sirup',
            'strength' => '15 mg/5 ml',
            'info' => 'Ambroxol. Orientačně: 2-5 let 2,5 ml 3x denně, 6-12 let 5 ml 2-3x denně; do 2 let jen po poradě s lékařem. ' . $safety,
        ],
    ];
}

function ensure_default_medications(int $familyId): void
{
    $select = db()->prepare('SELECT id FROM medications WHERE family_id = ? AND system_key = ? LIMIT 1');
    $insert = db()->prepare(
        'INSERT INTO medications (family_id, system_key, name, dosage_form, strength, dosing_info, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $update = db()->prepare(
        'UPDATE medications SET name = ?, dosage_form = ?, strength = ?, dosing_info = ?, is_active = 1 WHERE family_id = ? AND system_key = ?'
    );

    foreach (default_medication_rows() as $medication) {
        $select->execute([$familyId, $medication['key']]);
        if ($select->fetchColumn()) {
            $update->execute([$medication['name'], $medication['form'], $medication['strength'], $medication['info'], $familyId, $medication['key']]);
        } else {
            $insert->execute([$familyId, $medication['key'], $medication['name'], $medication['form'], $medication['strength'], $medication['info']]);
        }
    }
    db()->prepare('UPDATE medications SET source_url = ? WHERE family_id = ? AND system_key IS NOT NULL')
        ->execute(['https://prehledy.sukl.cz/prehled_leciv.html', $familyId]);
}

function update_system_record_type_names(int $familyId): void
{
    $stmt = db()->prepare('UPDATE record_types SET name = ?, is_quick = 1, sort_order = ? WHERE family_id = ? AND code = ? AND is_system = 1');
    foreach ([
        'TEMPERATURE' => ['Teplota', 10],
        'MEDICATION' => ['Podání léku', 20],
        'SYMPTOMS' => ['Příznaky', 30],
        'CARE' => ['Péče', 40],
    ] as $code => $row) {
        $stmt->execute([$row[0], $row[1], $familyId, $code]);
    }
}

function ensure_special_record_types(?int $familyId = null): void
{
    $families = [];
    if ($familyId !== null) {
        $families[] = ['id' => $familyId];
    } else {
        $families = db()->query('SELECT id FROM families')->fetchAll();
    }

    $insertIgnore = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $stmt = db()->prepare($insertIgnore . ' INTO record_types (family_id, code, name, kind, is_system) VALUES (?, ?, ?, ?, 1)');
    $update = db()->prepare('UPDATE record_types SET is_quick = 1, sort_order = 30 WHERE family_id = ? AND code = ? AND is_system = 1');
    foreach ($families as $family) {
        $stmt->execute([(int)$family['id'], 'SYMPTOMS', 'Příznaky', 'CARE']);
        $update->execute([(int)$family['id'], 'SYMPTOMS']);
    }
}

function create_system_record_types(int $familyId): void
{
    $types = [
        ['TEMPERATURE', 'Teplota', 'TEMPERATURE', 1],
        ['MEDICATION', 'Podání léku', 'MEDICATION', 1],
        ['CARE', 'Péče', 'CARE', 1],
    ];
    $stmt = db()->prepare('INSERT INTO record_types (family_id, code, name, kind, is_system) VALUES (?, ?, ?, ?, ?)');
    foreach ($types as $type) {
        $stmt->execute([$familyId, $type[0], $type[1], $type[2], $type[3]]);
    }
}

function family_members(int $familyId): array
{
    $stmt = db()->prepare(
        'SELECT fm.*, u.email, u.display_name
         FROM family_members fm
         JOIN users u ON u.id = fm.user_id
         WHERE fm.family_id = ?
         ORDER BY fm.role DESC, u.display_name'
    );
    $stmt->execute([$familyId]);
    return $stmt->fetchAll();
}

function set_child_access_users(int $familyId, int $childId, array $userIds): void
{
    $stmt = db()->prepare('SELECT owner_user_id FROM families WHERE id = ? LIMIT 1');
    $stmt->execute([$familyId]);
    $ownerId = (int)$stmt->fetchColumn();
    if ($ownerId <= 0) {
        throw new RuntimeException('Rodina nebyla nalezena.');
    }

    $memberIds = array_map(fn($member) => (int)$member['user_id'], family_members($familyId));
    $allowed = array_values(array_intersect(array_map('intval', $userIds), $memberIds));
    $allowed[] = $ownerId;

    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM child_access WHERE child_id = ?')->execute([$childId]);
        $insert = db()->prepare('INSERT INTO child_access (child_id, user_id, can_view, can_create_record, can_edit_record, can_delete_record) VALUES (?, ?, 1, 1, 1, 1)');
        foreach (array_unique($allowed) as $userId) {
            $insert->execute([$childId, $userId]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function children_for_user(int $userId): array
{
    global $requestChildrenCache;
    if (!is_array($requestChildrenCache ?? null)) {
        $requestChildrenCache = [];
    }
    if (array_key_exists($userId, $requestChildrenCache)) {
        return $requestChildrenCache[$userId];
    }

    $stmt = db()->prepare(
        'SELECT c.*
         FROM children c
         JOIN child_access ca ON ca.child_id = c.id AND ca.user_id = ? AND ca.can_view = 1
         ORDER BY c.first_name, c.last_name'
    );
    $stmt->execute([$userId]);
    $children = $stmt->fetchAll();
    $requestChildrenCache[$userId] = $children;
    return $children;
}

function child_for_user(int $childId, int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT c.*
         FROM children c
         JOIN child_access ca ON ca.child_id = c.id AND ca.user_id = ? AND ca.can_view = 1
         WHERE c.id = ?'
    );
    $stmt->execute([$userId, $childId]);
    return $stmt->fetch() ?: null;
}

function provider_value(array $row, string $key): ?string
{
    $value = trim((string)($row[$key] ?? ''));
    $value = ensure_utf8_text($value);
    return $value === '' ? null : $value;
}

function ensure_utf8_text(string $value): string
{
    if ($value === '' || preg_match('//u', $value)) {
        return $value;
    }
    $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $value);
    if ($converted !== false && $converted !== '') {
        return $converted;
    }
    $converted = @iconv('CP1250', 'UTF-8//IGNORE', $value);
    if ($converted !== false && $converted !== '') {
        return $converted;
    }
    if (function_exists('mb_convert_encoding')) {
        foreach (['CP1250', 'ISO-8859-2'] as $encoding) {
            try {
                $converted = mb_convert_encoding($value, 'UTF-8', $encoding);
                if ($converted !== '') {
                    return $converted;
                }
            } catch (ValueError $e) {
                continue;
            }
        }
    }
    return $value;
}

function uppercase_first_letter(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($value, 1, null, 'UTF-8');
    }
    return strtoupper(substr($value, 0, 1)) . substr($value, 1);
}

function normalize_provider_specialty(string $value): string
{
    return uppercase_first_letter($value);
}

function split_provider_specialties(?string $careField): array
{
    if ($careField === null || trim($careField) === '') {
        return [];
    }
    $items = preg_split('/\s*,\s*/u', $careField) ?: [];
    $items = array_map(fn($item) => normalize_provider_specialty((string)$item), $items);
    $items = array_filter($items, fn($item) => $item !== '');
    return array_values(array_unique($items));
}

function provider_base_source_id(array $row): ?string
{
    return provider_value($row, 'MistoPoskytovaniId') ?? provider_value($row, 'ZdravotnickeZarizeniId');
}

function provider_source_id(array $row): ?string
{
    $base = provider_base_source_id($row);
    if (!$base) {
        return null;
    }
    if (empty($row['__duplicate_source_id'])) {
        return $base;
    }

    $parts = [
        $base,
        provider_value($row, 'ZdravotnickeZarizeniId') ?? '',
        provider_value($row, 'PCZ') ?? '',
        provider_value($row, 'PCDP') ?? '',
        provider_value($row, 'NazevCely') ?? '',
        provider_value($row, 'Obec') ?? '',
        provider_value($row, 'Ulice') ?? '',
        provider_value($row, 'CisloDomovniOrientacni') ?? '',
    ];

    return $base . ':' . substr(hash('sha256', implode('|', $parts)), 0, 16);
}

function delete_base_provider_source_ids(array $sourceIds): void
{
    $sourceIds = array_values(array_unique(array_filter($sourceIds, fn($id) => is_string($id) && $id !== '')));
    foreach (array_chunk($sourceIds, 100) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        db()->prepare('DELETE FROM healthcare_providers WHERE source = ? AND source_id IN (' . $placeholders . ')')
            ->execute(array_merge(['NRPZS'], $chunk));
    }
}

function import_nrpzs_provider_row(array $row): bool
{
    $sourceId = provider_source_id($row);
    $name = provider_value($row, 'NazevCely') ?? provider_value($row, 'PoskytovatelNazev');
    if (!$sourceId || !$name) {
        return false;
    }

    $values = [
        'NRPZS',
        $sourceId,
        $name,
        provider_value($row, 'PoskytovatelNazev'),
        provider_value($row, 'DruhZarizeni'),
        provider_value($row, 'OborPece'),
        provider_value($row, 'FormaPece'),
        provider_value($row, 'DruhPece'),
        provider_search_text_from_parts([
            $name,
            provider_value($row, 'PoskytovatelNazev'),
            provider_value($row, 'DruhZarizeni'),
            provider_value($row, 'OborPece'),
            provider_value($row, 'Obec'),
            provider_value($row, 'Ulice'),
            provider_value($row, 'Okres'),
            provider_value($row, 'OdbornyZastupce'),
        ]),
        provider_value($row, 'Obec'),
        provider_value($row, 'Psc'),
        provider_value($row, 'Ulice'),
        provider_value($row, 'CisloDomovniOrientacni'),
        provider_value($row, 'Kraj'),
        provider_value($row, 'Okres'),
        provider_value($row, 'PoskytovatelTelefon'),
        provider_value($row, 'PoskytovatelEmail'),
        provider_value($row, 'PoskytovatelWeb'),
        provider_value($row, 'OdbornyZastupce'),
        provider_value($row, 'GPS'),
        provider_value($row, 'LastModified'),
        now_sql(),
    ];

    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql = 'INSERT INTO healthcare_providers
                (source, source_id, name, provider_name, facility_type, care_field, care_form, care_type, search_text, city, zip, street, house_number, region, district, phone, email, web, representative, gps, last_modified, imported_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    provider_name = VALUES(provider_name),
                    facility_type = VALUES(facility_type),
                    care_field = VALUES(care_field),
                    care_form = VALUES(care_form),
                    care_type = VALUES(care_type),
                    search_text = VALUES(search_text),
                    city = VALUES(city),
                    zip = VALUES(zip),
                    street = VALUES(street),
                    house_number = VALUES(house_number),
                    region = VALUES(region),
                    district = VALUES(district),
                    phone = VALUES(phone),
                    email = VALUES(email),
                    web = VALUES(web),
                    representative = VALUES(representative),
                    gps = VALUES(gps),
                    last_modified = VALUES(last_modified),
                    imported_at = VALUES(imported_at)';
    } else {
        $sql = 'INSERT INTO healthcare_providers
                (source, source_id, name, provider_name, facility_type, care_field, care_form, care_type, search_text, city, zip, street, house_number, region, district, phone, email, web, representative, gps, last_modified, imported_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(source, source_id) DO UPDATE SET
                    name = excluded.name,
                    provider_name = excluded.provider_name,
                    facility_type = excluded.facility_type,
                    care_field = excluded.care_field,
                    care_form = excluded.care_form,
                    care_type = excluded.care_type,
                    search_text = excluded.search_text,
                    city = excluded.city,
                    zip = excluded.zip,
                    street = excluded.street,
                    house_number = excluded.house_number,
                    region = excluded.region,
                    district = excluded.district,
                    phone = excluded.phone,
                    email = excluded.email,
                    web = excluded.web,
                    representative = excluded.representative,
                    gps = excluded.gps,
                    last_modified = excluded.last_modified,
                    imported_at = excluded.imported_at';
    }

    db()->prepare($sql)->execute($values);
    $stmt = db()->prepare('SELECT id FROM healthcare_providers WHERE source = ? AND source_id = ? LIMIT 1');
    $stmt->execute(['NRPZS', $sourceId]);
    $providerId = (int)$stmt->fetchColumn();
    if ($providerId > 0) {
        db()->prepare('DELETE FROM healthcare_provider_specialties WHERE provider_id = ?')->execute([$providerId]);
        $insertIgnore = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $specialtyStmt = db()->prepare($insertIgnore . ' INTO healthcare_provider_specialties (provider_id, specialty) VALUES (?, ?)');
        foreach (split_provider_specialties(provider_value($row, 'OborPece')) as $specialty) {
            $specialtyStmt->execute([$providerId, $specialty]);
        }
    }
    return true;
}

function healthcare_provider_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM healthcare_providers')->fetchColumn();
}

function healthcare_provider_fields(): array
{
    $stmt = db()->query(
        'SELECT specialty AS care_field, COUNT(*) AS count_items
         FROM healthcare_provider_specialties
         WHERE specialty IS NOT NULL AND specialty <> ""
         GROUP BY care_field
         LIMIT 400'
    );
    $items = $stmt->fetchAll();
    usort($items, fn($a, $b) => compare_czech_text((string)$a['care_field'], (string)$b['care_field']));
    return $items;
}

function compare_czech_text(string $left, string $right): int
{
    if (class_exists('Collator')) {
        static $collator = null;
        if ($collator === null) {
            $collator = new Collator('cs_CZ');
        }
        return $collator->compare($left, $right);
    }
    return strcasecmp(remove_czech_diacritics($left), remove_czech_diacritics($right));
}

function remove_czech_diacritics(string $value): string
{
    $map = [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
        'Á' => 'A', 'Č' => 'C', 'Ď' => 'D', 'É' => 'E', 'Ě' => 'E', 'Í' => 'I', 'Ň' => 'N', 'Ó' => 'O', 'Ř' => 'R', 'Š' => 'S', 'Ť' => 'T', 'Ú' => 'U', 'Ů' => 'U', 'Ý' => 'Y', 'Ž' => 'Z',
    ];
    return strtr($value, $map);
}

function normalize_provider_search_text(string $value): string
{
    $value = text_lower(remove_czech_diacritics($value));
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function provider_search_text_from_parts(array $parts): string
{
    $parts = array_filter(array_map(fn($part) => trim((string)$part), $parts), fn($part) => $part !== '');
    return normalize_provider_search_text(implode(' ', $parts));
}

function rebuild_healthcare_provider_search_texts(): void
{
    $stmt = db()->query('SELECT id, name, provider_name, facility_type, care_field, city, street, district, representative FROM healthcare_providers WHERE search_text IS NULL OR search_text = ""');
    $update = db()->prepare('UPDATE healthcare_providers SET search_text = ? WHERE id = ?');
    foreach ($stmt->fetchAll() as $provider) {
        $update->execute([
            provider_search_text_from_parts([
                $provider['name'] ?? '',
                $provider['provider_name'] ?? '',
                $provider['facility_type'] ?? '',
                $provider['care_field'] ?? '',
                $provider['city'] ?? '',
                $provider['street'] ?? '',
                $provider['district'] ?? '',
                $provider['representative'] ?? '',
            ]),
            $provider['id'],
        ]);
    }
}

function provider_specialties_sql(): string
{
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        return '(SELECT GROUP_CONCAT(hps.specialty ORDER BY hps.specialty SEPARATOR ", ") FROM healthcare_provider_specialties hps WHERE hps.provider_id = hp.id) AS specialties';
    }
    return '(SELECT GROUP_CONCAT(hps.specialty, ", ") FROM healthcare_provider_specialties hps WHERE hps.provider_id = hp.id) AS specialties';
}

function search_healthcare_providers(string $query, string $careField = '', string $city = '', int $limit = 40): array
{
    $where = [];
    $params = [];
    $query = trim($query);
    $careField = trim($careField);
    $city = trim($city);

    if ($query !== '') {
        $tokens = preg_split('/\s+/u', normalize_provider_search_text($query)) ?: [];
        $tokens = array_values(array_filter($tokens, fn($token) => $token !== ''));
        foreach (array_slice($tokens, 0, 5) as $token) {
            $where[] = '(search_text LIKE ? OR name LIKE ? OR provider_name LIKE ? OR representative LIKE ? OR street LIKE ? OR district LIKE ? OR EXISTS (SELECT 1 FROM healthcare_provider_specialties hps_q WHERE hps_q.provider_id = healthcare_providers.id AND hps_q.specialty LIKE ?))';
            $like = '%' . $token . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
    }
    if ($careField !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM healthcare_provider_specialties hps_f WHERE hps_f.provider_id = healthcare_providers.id AND hps_f.specialty = ?)';
        $params[] = $careField;
    }
    if ($city !== '') {
        $where[] = 'city LIKE ?';
        $params[] = '%' . $city . '%';
    }

    if (!$where) {
        return [];
    }

    $specialtiesSql = provider_specialties_sql();
    $sql = 'SELECT healthcare_providers.*, ' . str_replace('hp.id', 'healthcare_providers.id', $specialtiesSql) . ' FROM healthcare_providers WHERE ' . implode(' AND ', $where) . ' ORDER BY city, name LIMIT ' . max(1, min(80, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function healthcare_provider_by_id(int $providerId): ?array
{
    $stmt = db()->prepare('SELECT * FROM healthcare_providers WHERE id = ?');
    $stmt->execute([$providerId]);
    return $stmt->fetch() ?: null;
}

function child_doctors(int $childId): array
{
    $specialtiesSql = provider_specialties_sql();
    $stmt = db()->prepare(
        'SELECT cd.*, hp.name, hp.provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.region, hp.district, hp.phone, hp.email, hp.web, hp.representative
                , ' . $specialtiesSql . '
         FROM child_doctors cd
         JOIN healthcare_providers hp ON hp.id = cd.provider_id
         WHERE cd.child_id = ?
         ORDER BY COALESCE(cd.role_label, hp.care_field), hp.name'
    );
    $stmt->execute([$childId]);
    return $stmt->fetchAll();
}

function add_child_doctor(int $childId, int $providerId, string $roleLabel = '', string $note = ''): void
{
    if (!healthcare_provider_by_id($providerId)) {
        throw new InvalidArgumentException('Vybraný lékař nebyl nalezen.');
    }
    $insertIgnore = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    db()->prepare($insertIgnore . ' INTO child_doctors (child_id, provider_id, role_label, note) VALUES (?, ?, ?, ?)')
        ->execute([$childId, $providerId, trim($roleLabel) ?: null, trim($note) ?: null]);
}

function remove_child_doctor(int $childId, int $childDoctorId): ?array
{
    $stmt = db()->prepare('SELECT * FROM child_doctors WHERE id = ? AND child_id = ?');
    $stmt->execute([$childDoctorId, $childId]);
    $doctor = $stmt->fetch() ?: null;
    if (!$doctor) {
        return null;
    }
    db()->prepare('DELETE FROM child_doctors WHERE id = ? AND child_id = ?')->execute([$childDoctorId, $childId]);
    return $doctor;
}

function child_documents(int $childId): array
{
    $specialtiesSql = provider_specialties_sql();
    $stmt = db()->prepare(
        'SELECT cd.*, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM child_documents cd
         LEFT JOIN healthcare_providers hp ON hp.id = cd.provider_id
         WHERE cd.child_id = ?
         ORDER BY cd.created_at DESC, cd.id DESC'
    );
    $stmt->execute([$childId]);
    return $stmt->fetchAll();
}

function latest_child_document_by_type(int $childId, string $documentType): ?array
{
    $specialtiesSql = provider_specialties_sql();
    $stmt = db()->prepare(
        'SELECT cd.*, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM child_documents cd
         LEFT JOIN healthcare_providers hp ON hp.id = cd.provider_id
         WHERE cd.child_id = ? AND cd.document_type = ?
         ORDER BY cd.created_at DESC, cd.id DESC
         LIMIT 1'
    );
    $stmt->execute([$childId, $documentType]);
    return $stmt->fetch() ?: null;
}

function latest_child_documents_by_type(array $childIds, string $documentType): array
{
    $childIds = array_values(array_unique(array_map('intval', $childIds)));
    if (!$childIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $stmt = db()->prepare(
        'SELECT cd.*
         FROM child_documents cd
         WHERE cd.child_id IN (' . $placeholders . ') AND cd.document_type = ?
         ORDER BY cd.child_id, cd.created_at DESC, cd.id DESC'
    );
    $stmt->execute(array_merge($childIds, [$documentType]));
    $documents = [];
    foreach ($stmt->fetchAll() as $document) {
        $childId = (int)$document['child_id'];
        if (!isset($documents[$childId])) {
            $documents[$childId] = $document;
        }
    }
    return $documents;
}

function child_documents_between(int $childId, string $from, string $to, bool $includeSensitive = false): array
{
    $specialtiesSql = provider_specialties_sql();
    $where = 'cd.child_id = ? AND cd.created_at BETWEEN ? AND ?';
    $params = [$childId, $from, $to];
    if (!$includeSensitive) {
        $where .= ' AND cd.is_sensitive = 0 AND cd.document_type <> ?';
        $params[] = 'ehic';
    }
    $stmt = db()->prepare(
        'SELECT cd.*, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM child_documents cd
         LEFT JOIN healthcare_providers hp ON hp.id = cd.provider_id
         WHERE ' . $where . '
         ORDER BY cd.created_at DESC, cd.id DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function create_child_document(int $childId, int $userId, string $title, string $note, ?int $providerId, string $originalFilename, string $storagePath, ?string $mimeType, int $sizeBytes, string $documentType = 'general', bool $isSensitive = false, string $storageMode = 'plain', ?string $encryptionAlgo = null): int
{
    if ($providerId !== null && !healthcare_provider_by_id($providerId)) {
        throw new InvalidArgumentException('Vybraný lékař nebyl nalezen.');
    }
    $storageMode = in_array($storageMode, ['plain', 'encrypted'], true) ? $storageMode : 'plain';
    db()->prepare(
        'INSERT INTO child_documents (child_id, provider_id, title, note, document_type, is_sensitive, original_filename, storage_path, mime_type, storage_mode, encryption_algo, size_bytes, uploaded_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$childId, $providerId, $title, $note !== '' ? $note : null, $documentType, $isSensitive ? 1 : 0, $originalFilename, $storagePath, $mimeType, $storageMode, $encryptionAlgo, $sizeBytes, $userId]);
    return (int)db()->lastInsertId();
}

function child_document_for_user(int $documentId, int $userId): ?array
{
    $specialtiesSql = provider_specialties_sql();
    $stmt = db()->prepare(
        'SELECT cd.*, c.family_id, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM child_documents cd
         JOIN children c ON c.id = cd.child_id
         JOIN child_access ca ON ca.child_id = c.id AND ca.user_id = ? AND ca.can_view = 1
         LEFT JOIN healthcare_providers hp ON hp.id = cd.provider_id
         WHERE cd.id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId, $documentId]);
    return $stmt->fetch() ?: null;
}

function delete_child_document(int $documentId, int $childId): ?array
{
    $stmt = db()->prepare('SELECT * FROM child_documents WHERE id = ? AND child_id = ? LIMIT 1');
    $stmt->execute([$documentId, $childId]);
    $document = $stmt->fetch() ?: null;
    if (!$document) {
        return null;
    }
    db()->prepare('DELETE FROM child_documents WHERE id = ? AND child_id = ?')->execute([$documentId, $childId]);
    return $document;
}

function child_document_storage_paths_for_family(int $familyId): array
{
    $stmt = db()->prepare(
        'SELECT cd.storage_path
         FROM child_documents cd
         JOIN children c ON c.id = cd.child_id
         WHERE c.family_id = ?'
    );
    $stmt->execute([$familyId]);
    return array_map(fn($row) => (string)$row['storage_path'], $stmt->fetchAll());
}

function child_appointments(int $childId): array
{
    $specialtiesSql = provider_specialties_sql();
    $stmt = db()->prepare(
        'SELECT ca.*, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM child_appointments ca
         LEFT JOIN healthcare_providers hp ON hp.id = ca.provider_id
         WHERE ca.child_id = ?
         ORDER BY ca.scheduled_at DESC, ca.id DESC'
    );
    $stmt->execute([$childId]);
    return $stmt->fetchAll();
}

function child_appointments_between(int $childId, string $from, string $to, bool $includeCancelled = false): array
{
    $specialtiesSql = provider_specialties_sql();
    $where = 'ca.child_id = ? AND ca.scheduled_at BETWEEN ? AND ?';
    $params = [$childId, $from, $to];
    if (!$includeCancelled) {
        $where .= ' AND ca.status <> ?';
        $params[] = 'cancelled';
    }
    $stmt = db()->prepare(
        'SELECT ca.*, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM child_appointments ca
         LEFT JOIN healthcare_providers hp ON hp.id = ca.provider_id
         WHERE ' . $where . '
         ORDER BY ca.scheduled_at DESC, ca.id DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function appointment_document_ids(int $appointmentId): array
{
    $stmt = db()->prepare('SELECT document_id FROM appointment_documents WHERE appointment_id = ?');
    $stmt->execute([$appointmentId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function appointment_documents(int $appointmentId): array
{
    $specialtiesSql = provider_specialties_sql();
    $stmt = db()->prepare(
        'SELECT cd.*, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM appointment_documents ad
         JOIN child_documents cd ON cd.id = ad.document_id
         LEFT JOIN healthcare_providers hp ON hp.id = cd.provider_id
         WHERE ad.appointment_id = ?
         ORDER BY cd.created_at DESC, cd.id DESC'
    );
    $stmt->execute([$appointmentId]);
    return $stmt->fetchAll();
}

function appointment_documents_for_appointments(array $appointmentIds): array
{
    $appointmentIds = array_values(array_unique(array_map('intval', $appointmentIds)));
    if (!$appointmentIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
    $specialtiesSql = provider_specialties_sql();
    $stmt = db()->prepare(
        'SELECT ad.appointment_id, cd.*, hp.name AS provider_name, hp.facility_type, hp.care_field, hp.city, hp.zip, hp.street, hp.house_number,
                hp.phone, hp.email, hp.web, ' . $specialtiesSql . '
         FROM appointment_documents ad
         JOIN child_documents cd ON cd.id = ad.document_id
         LEFT JOIN healthcare_providers hp ON hp.id = cd.provider_id
         WHERE ad.appointment_id IN (' . $placeholders . ')
         ORDER BY ad.appointment_id, cd.created_at DESC, cd.id DESC'
    );
    $stmt->execute($appointmentIds);
    $items = [];
    foreach ($stmt->fetchAll() as $document) {
        $items[(int)$document['appointment_id']][] = $document;
    }
    return $items;
}

function save_child_appointment(int $childId, int $userId, ?int $appointmentId, array $data, array $documentIds): int
{
    $providerId = (int)($data['provider_id'] ?? 0);
    $providerId = $providerId > 0 ? $providerId : null;
    if ($providerId !== null && !healthcare_provider_by_id($providerId)) {
        throw new InvalidArgumentException('Vybraný lékař nebyl nalezen.');
    }

    $title = trim((string)($data['title'] ?? ''));
    $appointmentType = trim((string)($data['appointment_type'] ?? 'Kontrola')) ?: 'Kontrola';
    $scheduledAt = (string)($data['scheduled_at'] ?? '');
    $status = normalize_appointment_status((string)($data['status'] ?? 'planned'));
    if ($title === '') {
        throw new InvalidArgumentException('Vyplňte název kontroly.');
    }
    $scheduledAtDb = db_datetime_any($scheduledAt);
    $preNote = trim((string)($data['pre_note'] ?? ''));
    $resultNote = trim((string)($data['result_note'] ?? ''));
    $recommendation = trim((string)($data['recommendation'] ?? ''));
    $now = now_sql();

    db()->beginTransaction();
    try {
        if ($appointmentId !== null && $appointmentId > 0) {
            $stmt = db()->prepare(
                'UPDATE child_appointments
                 SET provider_id = ?, title = ?, appointment_type = ?, scheduled_at = ?, status = ?, pre_note = ?, result_note = ?, recommendation = ?, updated_at = ?
                 WHERE id = ? AND child_id = ?'
            );
            $stmt->execute([$providerId, $title, $appointmentType, $scheduledAtDb, $status, $preNote ?: null, $resultNote ?: null, $recommendation ?: null, $now, $appointmentId, $childId]);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException('Kontrola nebyla nalezena.');
            }
        } else {
            db()->prepare(
                'INSERT INTO child_appointments (child_id, provider_id, title, appointment_type, scheduled_at, status, pre_note, result_note, recommendation, created_by_user_id, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$childId, $providerId, $title, $appointmentType, $scheduledAtDb, $status, $preNote ?: null, $resultNote ?: null, $recommendation ?: null, $userId, $now]);
            $appointmentId = (int)db()->lastInsertId();
        }
        sync_appointment_documents((int)$appointmentId, $childId, $documentIds);
        db()->commit();
        return (int)$appointmentId;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function normalize_appointment_status(string $status): string
{
    return in_array($status, ['planned', 'completed', 'cancelled'], true) ? $status : 'planned';
}

function sync_appointment_documents(int $appointmentId, int $childId, array $documentIds): void
{
    $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), fn($id) => $id > 0)));
    db()->prepare('DELETE FROM appointment_documents WHERE appointment_id = ?')->execute([$appointmentId]);
    if (!$documentIds) {
        return;
    }
    $validStmt = db()->prepare('SELECT id FROM child_documents WHERE child_id = ? AND id = ?');
    $insertIgnore = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    $insert = db()->prepare($insertIgnore . ' INTO appointment_documents (appointment_id, document_id) VALUES (?, ?)');
    foreach ($documentIds as $documentId) {
        $validStmt->execute([$childId, $documentId]);
        if ($validStmt->fetchColumn()) {
            $insert->execute([$appointmentId, $documentId]);
        }
    }
}

function delete_child_appointment(int $appointmentId, int $childId): ?array
{
    $stmt = db()->prepare('SELECT * FROM child_appointments WHERE id = ? AND child_id = ? LIMIT 1');
    $stmt->execute([$appointmentId, $childId]);
    $appointment = $stmt->fetch() ?: null;
    if (!$appointment) {
        return null;
    }
    db()->prepare('DELETE FROM child_appointments WHERE id = ? AND child_id = ?')->execute([$appointmentId, $childId]);
    return $appointment;
}

function child_access_rows(int $childId): array
{
    $stmt = db()->prepare(
        'SELECT ca.*, u.email, u.display_name
         FROM child_access ca
         JOIN users u ON u.id = ca.user_id
         WHERE ca.child_id = ?
         ORDER BY u.display_name'
    );
    $stmt->execute([$childId]);
    return $stmt->fetchAll();
}

function child_summary(int $childId): array
{
    $since24h = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
    $lastTemp = db()->prepare(
        'SELECT tr.temperature_celsius, hr.event_at
         FROM health_records hr
         JOIN temperature_records tr ON tr.health_record_id = hr.id
         WHERE hr.child_id = ?
         ORDER BY hr.event_at DESC
         LIMIT 1'
    );
    $lastTemp->execute([$childId]);

    $maxTemp = db()->prepare(
        'SELECT MAX(tr.temperature_celsius) AS value
         FROM health_records hr
         JOIN temperature_records tr ON tr.health_record_id = hr.id
         WHERE hr.child_id = ? AND hr.event_at >= ?'
    );
    $maxTemp->execute([$childId, $since24h]);

    $lastMed = db()->prepare(
        'SELECT m.name, m.dosage_form, m.strength, hr.event_at
         FROM health_records hr
         JOIN medication_administrations ma ON ma.health_record_id = hr.id
         JOIN medications m ON m.id = ma.medication_id
         WHERE hr.child_id = ?
         ORDER BY hr.event_at DESC
         LIMIT 1'
    );
    $lastMed->execute([$childId]);

    return [
        'last_temperature' => $lastTemp->fetch() ?: null,
        'max_24h' => $maxTemp->fetch()['value'] ?? null,
        'last_medication' => $lastMed->fetch() ?: null,
    ];
}

function child_summaries(array $childIds): array
{
    $childIds = array_values(array_unique(array_map('intval', $childIds)));
    $summaries = [];
    foreach ($childIds as $childId) {
        $summaries[$childId] = [
            'last_temperature' => null,
            'max_24h' => null,
            'last_medication' => null,
        ];
    }
    if (!$childIds) {
        return $summaries;
    }

    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $since24h = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');

    $lastTemp = db()->prepare(
        'SELECT hr.child_id, tr.temperature_celsius, hr.event_at
         FROM health_records hr
         JOIN temperature_records tr ON tr.health_record_id = hr.id
         WHERE hr.child_id IN (' . $placeholders . ')
         ORDER BY hr.child_id, hr.event_at DESC, hr.id DESC'
    );
    $lastTemp->execute($childIds);
    foreach ($lastTemp->fetchAll() as $row) {
        $childId = (int)$row['child_id'];
        if ($summaries[$childId]['last_temperature'] === null) {
            $summaries[$childId]['last_temperature'] = $row;
        }
    }

    $maxTemp = db()->prepare(
        'SELECT hr.child_id, MAX(tr.temperature_celsius) AS value
         FROM health_records hr
         JOIN temperature_records tr ON tr.health_record_id = hr.id
         WHERE hr.child_id IN (' . $placeholders . ') AND hr.event_at >= ?
         GROUP BY hr.child_id'
    );
    $maxTemp->execute(array_merge($childIds, [$since24h]));
    foreach ($maxTemp->fetchAll() as $row) {
        $summaries[(int)$row['child_id']]['max_24h'] = $row['value'];
    }

    $lastMed = db()->prepare(
        'SELECT hr.child_id, m.name, m.dosage_form, m.strength, hr.event_at
         FROM health_records hr
         JOIN medication_administrations ma ON ma.health_record_id = hr.id
         JOIN medications m ON m.id = ma.medication_id
         WHERE hr.child_id IN (' . $placeholders . ')
         ORDER BY hr.child_id, hr.event_at DESC, hr.id DESC'
    );
    $lastMed->execute($childIds);
    foreach ($lastMed->fetchAll() as $row) {
        $childId = (int)$row['child_id'];
        if ($summaries[$childId]['last_medication'] === null) {
            $summaries[$childId]['last_medication'] = $row;
        }
    }

    return $summaries;
}

function medications(int $familyId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM medications WHERE family_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, system_key IS NULL, name, dosage_form, strength';
    $stmt = db()->prepare($sql);
    $stmt->execute([$familyId]);
    return $stmt->fetchAll();
}

function record_types(int $familyId, ?string $kind = null, bool $activeOnly = true): array
{
    $sql = 'SELECT * FROM record_types WHERE family_id = ?';
    $params = [$familyId];
    if ($kind) {
        $sql .= ' AND kind = ?';
        $params[] = $kind;
    }
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY is_quick DESC, sort_order, is_system DESC, name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function custom_care_types(int $familyId, bool $activeOnly = true): array
{
    return array_values(array_filter(
        record_types($familyId, 'CARE', $activeOnly),
        fn(array $type): bool => empty($type['is_system'])
    ));
}

function record_type_id(int $familyId, string $code): int
{
    $stmt = db()->prepare('SELECT id FROM record_types WHERE family_id = ? AND code = ? LIMIT 1');
    $stmt->execute([$familyId, $code]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        throw new RuntimeException('Chybí systémový typ záznamu: ' . $code);
    }
    return (int)$id;
}

function medication_belongs_to_family(int $familyId, int $medicationId): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM medications WHERE id = ? AND family_id = ?');
    $stmt->execute([$medicationId, $familyId]);
    return (int)$stmt->fetchColumn() > 0;
}

function record_type_belongs_to_family(int $familyId, int $recordTypeId, string $kind): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM record_types WHERE id = ? AND family_id = ? AND kind = ? AND is_active = 1');
    $stmt->execute([$recordTypeId, $familyId, $kind]);
    return (int)$stmt->fetchColumn() > 0;
}

function timeline_data(int $childId, int $hours): array
{
    $from = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');

    $temps = db()->prepare(
        'SELECT hr.id, hr.event_at, tr.temperature_celsius, hr.place, hr.note
         FROM health_records hr
         JOIN temperature_records tr ON tr.health_record_id = hr.id
         WHERE hr.child_id = ? AND hr.event_at >= ?
         ORDER BY hr.event_at'
    );
    $temps->execute([$childId, $from]);

    $meds = db()->prepare(
        'SELECT hr.id, hr.event_at, m.name, m.dosage_form, m.strength
         FROM health_records hr
         JOIN medication_administrations ma ON ma.health_record_id = hr.id
         JOIN medications m ON m.id = ma.medication_id
         WHERE hr.child_id = ? AND hr.event_at >= ?
         ORDER BY hr.event_at'
    );
    $meds->execute([$childId, $from]);

    return ['from' => $from, 'to' => date('Y-m-d H:i:s'), 'temperatures' => $temps->fetchAll(), 'medications' => $meds->fetchAll()];
}

function timeline_data_for_children(array $childIds, int $hours): array
{
    $childIds = array_values(array_unique(array_map('intval', $childIds)));
    $from = (new DateTimeImmutable("-{$hours} hours"))->format('Y-m-d H:i:s');
    $to = date('Y-m-d H:i:s');
    $items = [];
    foreach ($childIds as $childId) {
        $items[$childId] = ['from' => $from, 'to' => $to, 'temperatures' => [], 'medications' => []];
    }
    if (!$childIds) {
        return $items;
    }

    $placeholders = implode(',', array_fill(0, count($childIds), '?'));
    $temps = db()->prepare(
        'SELECT hr.child_id, hr.id, hr.event_at, tr.temperature_celsius, hr.place, hr.note
         FROM health_records hr
         JOIN temperature_records tr ON tr.health_record_id = hr.id
         WHERE hr.child_id IN (' . $placeholders . ') AND hr.event_at >= ?
         ORDER BY hr.child_id, hr.event_at'
    );
    $temps->execute(array_merge($childIds, [$from]));
    foreach ($temps->fetchAll() as $row) {
        $items[(int)$row['child_id']]['temperatures'][] = $row;
    }

    $meds = db()->prepare(
        'SELECT hr.child_id, hr.id, hr.event_at, m.name, m.dosage_form, m.strength
         FROM health_records hr
         JOIN medication_administrations ma ON ma.health_record_id = hr.id
         JOIN medications m ON m.id = ma.medication_id
         WHERE hr.child_id IN (' . $placeholders . ') AND hr.event_at >= ?
         ORDER BY hr.child_id, hr.event_at'
    );
    $meds->execute(array_merge($childIds, [$from]));
    foreach ($meds->fetchAll() as $row) {
        $items[(int)$row['child_id']]['medications'][] = $row;
    }

    return $items;
}

function dashboard_overview(int $userId, int $hours = 72): array
{
    $children = children_for_user($userId);
    if (!$children) {
        return [];
    }

    $childIds = array_map(fn(array $child): int => (int)$child['id'], $children);
    $summaries = child_summaries($childIds);
    $timelines = timeline_data_for_children($childIds, $hours);
    $ehics = latest_child_documents_by_type($childIds, 'ehic');
    $overview = [];
    foreach ($children as $child) {
        $childId = (int)$child['id'];
        $overview[] = [
            'child' => $child,
            'summary' => $summaries[$childId] ?? ['last_temperature' => null, 'max_24h' => null, 'last_medication' => null],
            'timeline' => $timelines[$childId] ?? ['from' => date('Y-m-d H:i:s'), 'to' => date('Y-m-d H:i:s'), 'temperatures' => [], 'medications' => []],
            'ehic' => $ehics[$childId] ?? null,
        ];
    }

    return $overview;
}

function child_records(int $childId, int $limit = 500): array
{
    $stmt = db()->prepare(
        'SELECT hr.*, rt.kind, rt.code, rt.name AS type_name,
                tr.temperature_celsius,
                m.name AS medication_name,
                m.dosage_form AS medication_dosage_form,
                m.strength AS medication_strength,
                m.dosing_info AS medication_dosing_info,
                sr.symptoms,
                sr.severity AS symptom_severity
         FROM health_records hr
         JOIN record_types rt ON rt.id = hr.record_type_id
         LEFT JOIN temperature_records tr ON tr.health_record_id = hr.id
         LEFT JOIN medication_administrations ma ON ma.health_record_id = hr.id
         LEFT JOIN medications m ON m.id = ma.medication_id
         LEFT JOIN symptom_records sr ON sr.health_record_id = hr.id
         WHERE hr.child_id = ?
         ORDER BY hr.event_at DESC
         LIMIT ' . (int)$limit
    );
    $stmt->execute([$childId]);
    return $stmt->fetchAll();
}

function record_for_user(int $recordId, int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT hr.*, c.family_id, rt.kind, rt.code, rt.name AS type_name,
                tr.temperature_celsius,
                ma.medication_id,
                m.name AS medication_name,
                m.dosage_form AS medication_dosage_form,
                m.strength AS medication_strength,
                m.dosing_info AS medication_dosing_info,
                sr.symptoms,
                sr.severity AS symptom_severity
         FROM health_records hr
         JOIN children c ON c.id = hr.child_id
         JOIN child_access ca ON ca.child_id = c.id AND ca.user_id = ? AND ca.can_view = 1
         JOIN record_types rt ON rt.id = hr.record_type_id
         LEFT JOIN temperature_records tr ON tr.health_record_id = hr.id
         LEFT JOIN medication_administrations ma ON ma.health_record_id = hr.id
         LEFT JOIN medications m ON m.id = ma.medication_id
         LEFT JOIN symptom_records sr ON sr.health_record_id = hr.id
         WHERE hr.id = ?'
    );
    $stmt->execute([$userId, $recordId]);
    return $stmt->fetch() ?: null;
}

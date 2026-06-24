<?php

declare(strict_types=1);

function ensure_runtime_schema(): void
{
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
    ensure_users_email_allows_duplicates($driver);
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
        db()->exec('CREATE INDEX IF NOT EXISTS idx_healthcare_providers_name ON healthcare_providers(name)');
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
                KEY idx_healthcare_providers_care_field (care_field),
                KEY idx_healthcare_providers_city (city)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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
                original_filename VARCHAR(255) NOT NULL,
                storage_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(160) NULL,
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
        db()->exec(
            'CREATE TABLE IF NOT EXISTS symptom_records (
                health_record_id INT UNSIGNED PRIMARY KEY,
                symptoms TEXT NOT NULL,
                severity VARCHAR(40) NULL,
                CONSTRAINT fk_symptom_records_health FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        ensure_special_record_types();
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

function create_user(string $email, string $name, ?string $password, ?string $googleSubject = null): int
{
    $hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    $stmt = db()->prepare('INSERT INTO users (email, display_name, password_hash, google_subject_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([text_lower(trim($email)), trim($name), $hash, $googleSubject]);
    return (int)db()->lastInsertId();
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
    }
    return $family;
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
                ) AS registered_display_name
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
    if (!$user) {
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
    $stmt = db()->prepare('UPDATE record_types SET name = ? WHERE family_id = ? AND code = ? AND is_system = 1');
    foreach ([
        'TEMPERATURE' => 'Teplota',
        'MEDICATION' => 'Podání léku',
        'CARE' => 'Péče',
        'SYMPTOMS' => 'Příznaky',
    ] as $code => $name) {
        $stmt->execute([$name, $familyId, $code]);
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
    foreach ($families as $family) {
        $stmt->execute([(int)$family['id'], 'SYMPTOMS', 'Příznaky', 'CARE']);
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
    $stmt = db()->prepare(
        'SELECT c.*
         FROM children c
         JOIN child_access ca ON ca.child_id = c.id AND ca.user_id = ? AND ca.can_view = 1
         ORDER BY c.first_name, c.last_name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
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
                (source, source_id, name, provider_name, facility_type, care_field, care_form, care_type, city, zip, street, house_number, region, district, phone, email, web, representative, gps, last_modified, imported_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    provider_name = VALUES(provider_name),
                    facility_type = VALUES(facility_type),
                    care_field = VALUES(care_field),
                    care_form = VALUES(care_form),
                    care_type = VALUES(care_type),
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
                (source, source_id, name, provider_name, facility_type, care_field, care_form, care_type, city, zip, street, house_number, region, district, phone, email, web, representative, gps, last_modified, imported_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(source, source_id) DO UPDATE SET
                    name = excluded.name,
                    provider_name = excluded.provider_name,
                    facility_type = excluded.facility_type,
                    care_field = excluded.care_field,
                    care_form = excluded.care_form,
                    care_type = excluded.care_type,
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
        $tokens = preg_split('/\s+/u', $query) ?: [];
        $tokens = array_values(array_filter($tokens, fn($token) => $token !== ''));
        foreach (array_slice($tokens, 0, 5) as $token) {
            $where[] = '(name LIKE ? OR provider_name LIKE ? OR representative LIKE ? OR street LIKE ? OR district LIKE ? OR EXISTS (SELECT 1 FROM healthcare_provider_specialties hps_q WHERE hps_q.provider_id = healthcare_providers.id AND hps_q.specialty LIKE ?))';
            $like = '%' . $token . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
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

function create_child_document(int $childId, int $userId, string $title, string $note, ?int $providerId, string $originalFilename, string $storagePath, ?string $mimeType, int $sizeBytes): int
{
    if ($providerId !== null && !healthcare_provider_by_id($providerId)) {
        throw new InvalidArgumentException('Vybraný lékař nebyl nalezen.');
    }
    db()->prepare(
        'INSERT INTO child_documents (child_id, provider_id, title, note, original_filename, storage_path, mime_type, size_bytes, uploaded_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$childId, $providerId, $title, $note !== '' ? $note : null, $originalFilename, $storagePath, $mimeType, $sizeBytes, $userId]);
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

function record_types(int $familyId, ?string $kind = null): array
{
    $sql = 'SELECT * FROM record_types WHERE family_id = ? AND is_active = 1';
    $params = [$familyId];
    if ($kind) {
        $sql .= ' AND kind = ?';
        $params[] = $kind;
    }
    $sql .= ' ORDER BY is_system DESC, name';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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

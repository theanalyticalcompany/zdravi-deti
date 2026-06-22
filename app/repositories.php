<?php

declare(strict_types=1);

function ensure_runtime_schema(): void
{
    $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
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

function find_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([text_lower(trim($email))]);
    return $stmt->fetch() ?: null;
}

function create_user(string $email, string $name, ?string $password, ?string $googleSubject = null): int
{
    $hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    $stmt = db()->prepare('INSERT INTO users (email, display_name, password_hash, google_subject_id) VALUES (?, ?, ?, ?)');
    $stmt->execute([text_lower(trim($email)), trim($name), $hash, $googleSubject]);
    return (int)db()->lastInsertId();
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
         ORDER BY fm.created_at DESC, f.id DESC
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

function add_user_to_family(int $familyId, int $userId): void
{
    $insertIgnore = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
    db()->beginTransaction();
    try {
        db()->prepare($insertIgnore . ' INTO family_members (family_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$familyId, $userId, 'PARENT']);
        $children = db()->prepare('SELECT id FROM children WHERE family_id = ?');
        $children->execute([$familyId]);
        $access = db()->prepare($insertIgnore . ' INTO child_access (child_id, user_id, can_view, can_create_record, can_edit_record, can_delete_record) VALUES (?, ?, 1, 1, 1, 1)');
        foreach ($children->fetchAll() as $child) {
            $access->execute([$child['id'], $userId]);
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

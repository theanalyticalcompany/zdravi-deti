CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    password_hash TEXT NULL,
    google_subject_id TEXT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1
);

CREATE INDEX idx_users_email ON users(email);

CREATE TABLE families (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id)
);

CREATE TABLE family_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'PARENT',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (family_id, user_id),
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_family_members_user ON family_members(user_id);

CREATE TABLE children (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    first_name TEXT NOT NULL,
    last_name TEXT NOT NULL,
    date_of_birth TEXT NOT NULL,
    weight_kg REAL NULL,
    allergies TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
);

CREATE INDEX idx_children_family ON children(family_id);

CREATE TABLE child_access (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    can_view INTEGER NOT NULL DEFAULT 1,
    can_create_record INTEGER NOT NULL DEFAULT 1,
    can_edit_record INTEGER NOT NULL DEFAULT 1,
    can_delete_record INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (child_id, user_id),
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_child_access_user ON child_access(user_id);

CREATE TABLE record_types (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    code TEXT NOT NULL,
    name TEXT NOT NULL,
    kind TEXT NOT NULL,
    is_system INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (family_id, code),
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
);

CREATE TABLE health_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    child_id INTEGER NOT NULL,
    record_type_id INTEGER NOT NULL,
    event_at TEXT NOT NULL,
    created_by_user_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    place TEXT NULL,
    note TEXT NULL,
    FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    FOREIGN KEY (record_type_id) REFERENCES record_types(id),
    FOREIGN KEY (created_by_user_id) REFERENCES users(id)
);

CREATE INDEX idx_health_records_child ON health_records(child_id);
CREATE INDEX idx_health_records_event_at ON health_records(event_at);
CREATE INDEX idx_health_records_child_event ON health_records(child_id, event_at);

CREATE TABLE temperature_records (
    health_record_id INTEGER PRIMARY KEY,
    temperature_celsius REAL NOT NULL,
    FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE
);

CREATE TABLE medications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    family_id INTEGER NOT NULL,
    system_key TEXT NULL,
    name TEXT NOT NULL,
    dosage_form TEXT NULL,
    strength TEXT NULL,
    dosing_info TEXT NULL,
    source_url TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
);

CREATE INDEX idx_medications_family ON medications(family_id);
CREATE UNIQUE INDEX uq_medications_system_key ON medications(family_id, system_key);

CREATE TABLE medication_administrations (
    health_record_id INTEGER PRIMARY KEY,
    medication_id INTEGER NOT NULL,
    FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE,
    FOREIGN KEY (medication_id) REFERENCES medications(id)
);

CREATE TABLE symptom_records (
    health_record_id INTEGER PRIMARY KEY,
    symptoms TEXT NOT NULL,
    severity TEXT NULL,
    FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE
);

CREATE TABLE family_invitations (
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
);

CREATE TABLE password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TEXT NOT NULL,
    used_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_password_resets_user ON password_resets(user_id);
CREATE INDEX idx_password_resets_expires ON password_resets(expires_at);

CREATE TABLE audit_logs (
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
);

CREATE INDEX idx_audit_logs_user ON audit_logs(user_id);
CREATE INDEX idx_audit_logs_family ON audit_logs(family_id);
CREATE INDEX idx_audit_logs_action ON audit_logs(action);

CREATE TABLE rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rate_key TEXT NOT NULL,
    action TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    first_attempt_at TEXT NOT NULL,
    last_attempt_at TEXT NOT NULL,
    blocked_until TEXT NULL,
    UNIQUE (rate_key, action)
);

CREATE INDEX idx_rate_limits_blocked ON rate_limits(blocked_until);

CREATE TABLE user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_id_hash TEXT NOT NULL UNIQUE,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_user_sessions_user ON user_sessions(user_id);
CREATE INDEX idx_user_sessions_revoked ON user_sessions(revoked_at);

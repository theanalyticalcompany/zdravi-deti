CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NULL,
    google_subject_id VARCHAR(190) NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE families (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    owner_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_families_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE family_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('OWNER', 'PARENT') NOT NULL DEFAULT 'PARENT',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_family_user (family_id, user_id),
    KEY idx_family_members_user (user_id),
    CONSTRAINT fk_family_members_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_family_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE children (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    first_name VARCHAR(120) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    date_of_birth DATE NOT NULL,
    weight_kg DECIMAL(5,2) NULL,
    allergies TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_children_family (family_id),
    CONSTRAINT fk_children_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE child_access (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    can_view TINYINT(1) NOT NULL DEFAULT 1,
    can_create_record TINYINT(1) NOT NULL DEFAULT 1,
    can_edit_record TINYINT(1) NOT NULL DEFAULT 1,
    can_delete_record TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_child_user (child_id, user_id),
    KEY idx_child_access_user (user_id),
    CONSTRAINT fk_child_access_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    CONSTRAINT fk_child_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE record_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    kind ENUM('TEMPERATURE', 'MEDICATION', 'CARE') NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_record_type_code (family_id, code),
    CONSTRAINT fk_record_types_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE health_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_id INT UNSIGNED NOT NULL,
    record_type_id INT UNSIGNED NOT NULL,
    event_at DATETIME NOT NULL,
    created_by_user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    place VARCHAR(160) NULL,
    note TEXT NULL,
    KEY idx_health_records_child (child_id),
    KEY idx_health_records_event_at (event_at),
    KEY idx_health_records_child_event (child_id, event_at),
    CONSTRAINT fk_health_records_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
    CONSTRAINT fk_health_records_type FOREIGN KEY (record_type_id) REFERENCES record_types(id),
    CONSTRAINT fk_health_records_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE temperature_records (
    health_record_id INT UNSIGNED PRIMARY KEY,
    temperature_celsius DECIMAL(4,1) NOT NULL,
    CONSTRAINT fk_temperature_records_health FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    system_key VARCHAR(80) NULL,
    name VARCHAR(190) NOT NULL,
    dosage_form VARCHAR(120) NULL,
    strength VARCHAR(120) NULL,
    dosing_info TEXT NULL,
    source_url VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_medications_system_key (family_id, system_key),
    KEY idx_medications_family (family_id),
    CONSTRAINT fk_medications_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medication_administrations (
    health_record_id INT UNSIGNED PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    CONSTRAINT fk_medication_administrations_health FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_medication_administrations_medication FOREIGN KEY (medication_id) REFERENCES medications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE symptom_records (
    health_record_id INT UNSIGNED PRIMARY KEY,
    symptoms TEXT NOT NULL,
    severity VARCHAR(40) NULL,
    CONSTRAINT fk_symptom_records_health FOREIGN KEY (health_record_id) REFERENCES health_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE family_invitations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    KEY idx_password_resets_user (user_id),
    KEY idx_password_resets_expires (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key CHAR(64) NOT NULL,
    action VARCHAR(80) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    first_attempt_at DATETIME NOT NULL,
    last_attempt_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    UNIQUE KEY uq_rate_limits_key_action (rate_key, action),
    KEY idx_rate_limits_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE healthcare_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(40) NOT NULL DEFAULT 'NRPZS',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE healthcare_provider_specialties (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    specialty VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_healthcare_provider_specialty (provider_id, specialty),
    KEY idx_healthcare_provider_specialties_specialty (specialty),
    CONSTRAINT fk_provider_specialties_provider FOREIGN KEY (provider_id) REFERENCES healthcare_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE child_doctors (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE child_documents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

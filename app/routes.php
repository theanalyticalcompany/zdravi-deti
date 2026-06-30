<?php

declare(strict_types=1);

function dispatch(): void
{
    send_security_headers();
    validate_http_request();
    $route = $_GET['r'] ?? 'dashboard';

    if (is_post()) {
        verify_csrf();
    }

    switch ($route) {
        case 'login': page_login(); break;
        case 'register': page_register(); break;
        case 'email_verify': action_email_verify(); break;
        case 'password_forgot': page_password_forgot(); break;
        case 'password_reset': page_password_reset(); break;
        case 'logout': action_logout(); break;
        case 'google_start': action_google_start(); break;
        case 'google_callback': action_google_callback(); break;
        case 'settings': page_settings(); break;
        case 'account_delete': action_account_delete(); break;
        case 'devices': redirect('settings'); break;
        case 'device_revoke': action_device_revoke(); break;
        case 'devices_revoke_others': action_devices_revoke_others(); break;
        case 'dashboard': page_dashboard(); break;
        case 'children': page_children(); break;
        case 'child': page_child(); break;
        case 'child_timeline': page_child_timeline(); break;
        case 'document_upload': action_document_upload(); break;
        case 'document_view': action_document_view(); break;
        case 'document_inline': action_document_inline(); break;
        case 'document_download': action_document_download(); break;
        case 'document_delete': action_document_delete(); break;
        case 'appointment_save': action_appointment_save(); break;
        case 'appointment_delete': action_appointment_delete(); break;
        case 'child_doctors': page_child_doctors(); break;
        case 'child_doctor_add': action_child_doctor_add(); break;
        case 'child_doctor_remove': action_child_doctor_remove(); break;
        case 'child_create': action_child_create(); break;
        case 'child_profile_save': action_child_profile_save(); break;
        case 'child_delete': action_child_delete(); break;
        case 'record_edit': page_record_edit(); break;
        case 'temperature_save': action_temperature_save(); break;
        case 'medication_record_save': action_medication_record_save(); break;
        case 'symptom_record_save': action_symptom_record_save(); break;
        case 'care_record_save': action_care_record_save(); break;
        case 'record_delete': action_record_delete(); break;
        case 'medications': page_medications(); break;
        case 'medication_save': action_medication_save(); break;
        case 'sukl_medication_add': action_sukl_medication_add(); break;
        case 'medication_remove': action_medication_remove(); break;
        case 'medication_toggle': action_medication_toggle(); break;
        case 'care_types': page_care_types(); break;
        case 'care_type_save': action_care_type_save(); break;
        case 'care_type_toggle': action_care_type_toggle(); break;
        case 'care_type_delete': action_care_type_delete(); break;
        case 'family': page_family(); break;
        case 'family_save': action_family_save(); break;
        case 'family_delete': action_family_delete(); break;
        case 'member_add': action_member_add(); break;
        case 'invitation_cancel': action_invitation_cancel(); break;
        case 'invitation_accept_registered': action_invitation_accept_registered(); break;
        case 'member_remove': action_member_remove(); break;
        case 'access_save': action_access_save(); break;
        case 'export': page_export(); break;
        default: page_not_found();
    }
}

function page_login(): void
{
    if (current_user()) {
        redirect('dashboard');
    }

    if (is_post()) {
        $email = text_lower(trim($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $blockedSeconds = rate_limit_blocked_seconds('login', $email);
        if ($blockedSeconds > 0) {
            audit_log('auth.login_rate_limited', null, null, 'user', null, ['email_hash' => hash('sha256', $email)]);
            flash('error', 'Příliš mnoho pokusů o přihlášení. Zkuste to znovu za několik minut.');
            render_layout('Přihlášení', function () {
                ?>
                <section class="auth-card">
                    <h1>Přihlášení</h1>
                    <form method="post" class="stack">
                        <?= csrf_field() ?>
                        <label>E-mail <input required type="email" name="email" autocomplete="email"></label>
                        <label>Heslo <input required type="password" name="password" autocomplete="current-password"></label>
                        <button class="button primary" type="submit">Přihlásit</button>
                    </form>
                    <p class="muted"><a href="<?= e(url('password_forgot')) ?>">Zapomenuté heslo</a></p>
                    <p class="muted">Nemáte účet? <a href="<?= e(url('register')) ?>">Vytvořit účet</a></p>
                </section>
                <?php
            });
            return;
        }
        $user = find_password_user_by_email($email);
        if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            remember_user_session((int)$user['id']);
            db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now_sql(), $user['id']]);
            $family = ensure_family((int)$user['id'], $user['display_name']);
            rate_limit_clear('login', $email);
            audit_log('auth.login_success', (int)$user['id'], (int)$family['id'], 'user', (int)$user['id']);
            redirect('dashboard');
        }
        rate_limit_hit('login', $email, 5, 15 * 60, 15 * 60);
        audit_log('auth.login_failed', $user ? (int)$user['id'] : null, null, 'user', $user ? (int)$user['id'] : null, ['email_hash' => hash('sha256', $email)]);
        flash('error', 'E-mail nebo heslo nesedí.');
    }

    render_layout('Přihlášení', function () {
        ?>
        <section class="auth-card">
            <h1>Přihlášení</h1>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label>E-mail <input required type="email" name="email" autocomplete="email"></label>
                <label>Heslo <input required type="password" name="password" autocomplete="current-password"></label>
                <button class="button primary" type="submit">Přihlásit</button>
            </form>
            <?php if (cfg('google.client_id')): ?>
                <a class="button wide" href="<?= e(url('google_start')) ?>">Přihlásit přes Google</a>
            <?php endif; ?>
            <p class="muted"><a href="<?= e(url('password_forgot')) ?>">Zapomenuté heslo</a></p>
            <p class="muted">Nemáte účet? <a href="<?= e(url('register')) ?>">Vytvořit účet</a></p>
        </section>
        <?php
    });
}

function page_password_forgot(): void
{
    if (current_user()) {
        redirect('dashboard');
    }

    if (is_post()) {
        $email = text_lower(trim($_POST['email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $blockedSeconds = rate_limit_blocked_seconds('password_reset', $email);
            $user = $blockedSeconds > 0 ? null : find_password_user_by_email($email);
            if ($blockedSeconds > 0) {
                audit_log('auth.password_reset_rate_limited', null, null, 'user', null, ['email_hash' => hash('sha256', $email)]);
            } else {
                rate_limit_hit('password_reset', $email, 3, 15 * 60, 30 * 60);
            }
            if ($user && $blockedSeconds === 0) {
                $token = create_password_reset_token((int)$user['id']);
                $resetUrl = app_base_url() . '/?r=password_reset&token=' . urlencode($token);
                send_app_email(
                    $user['email'],
                    'Obnova hesla v aplikaci Zdraví dětí',
                    "Dobrý den,\n\npožádali jste o obnovu hesla v aplikaci Zdraví dětí.\n\nNové heslo nastavíte zde:\n{$resetUrl}\n\nOdkaz je platný 1 hodinu a lze ho použít jen jednou.\n\nPokud jste o obnovu hesla nežádali, tento e-mail ignorujte."
                );
                audit_log('auth.password_reset_requested', (int)$user['id'], null, 'user', (int)$user['id']);
            } elseif ($blockedSeconds === 0) {
                audit_log('auth.password_reset_requested_unknown', null, null, 'user', null, ['email_hash' => hash('sha256', $email)]);
            }
        }
        flash('success', 'Pokud je e-mail registrovaný, poslali jsme na něj odkaz pro obnovu hesla.');
        redirect('login');
    }

    render_layout('Obnova hesla', function () {
        ?>
        <section class="auth-card">
            <h1>Obnova hesla</h1>
            <p class="muted">Zadejte e-mail k účtu. Pokud ho v aplikaci známe, pošleme na něj odkaz pro nastavení nového hesla.</p>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label>E-mail <input required type="email" name="email" autocomplete="email"></label>
                <button class="button primary" type="submit">Poslat odkaz</button>
            </form>
            <p class="muted"><a href="<?= e(url('login')) ?>">Zpět na přihlášení</a></p>
        </section>
        <?php
    });
}

function page_password_reset(): void
{
    if (current_user()) {
        redirect('dashboard');
    }

    $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
    $reset = password_reset_by_token($token);

    if (is_post()) {
        $password = (string)($_POST['password'] ?? '');
        $passwordAgain = (string)($_POST['password_again'] ?? '');
        if (!$reset) {
            flash('error', 'Odkaz pro obnovu hesla je neplatný nebo vypršel.');
            redirect('password_forgot');
        } elseif (text_length($password) < 10) {
            flash('error', 'Heslo musí mít alespoň 10 znaků.');
        } elseif ($password !== $passwordAgain) {
            flash('error', 'Zadaná hesla se neshodují.');
        } elseif (consume_password_reset_token($token, $password)) {
            audit_log('auth.password_reset_completed', (int)$reset['user_id'], null, 'user', (int)$reset['user_id']);
            flash('success', 'Heslo bylo změněno. Teď se můžete přihlásit.');
            redirect('login');
        } else {
            audit_log('auth.password_reset_failed', null, null, 'password_reset', null);
            flash('error', 'Odkaz pro obnovu hesla už byl použit nebo vypršel.');
            redirect('password_forgot');
        }
        $reset = password_reset_by_token($token);
    }

    render_layout('Nastavení nového hesla', function () use ($token, $reset) {
        ?>
        <section class="auth-card">
            <h1>Nové heslo</h1>
            <?php if (!$reset): ?>
                <p class="muted">Odkaz pro obnovu hesla je neplatný nebo vypršel.</p>
                <a class="button primary wide" href="<?= e(url('password_forgot')) ?>">Poslat nový odkaz</a>
            <?php else: ?>
                <p class="muted">Nastavte nové heslo k účtu <?= e($reset['email']) ?>.</p>
                <form method="post" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <label>Nové heslo <input required type="password" name="password" minlength="10" autocomplete="new-password"></label>
                    <label>Nové heslo znovu <input required type="password" name="password_again" minlength="10" autocomplete="new-password"></label>
                    <button class="button primary" type="submit">Uložit nové heslo</button>
                </form>
            <?php endif; ?>
        </section>
        <?php
    });
}

function page_register(): void
{
    if (current_user()) {
        redirect('dashboard');
    }

    if (is_post()) {
        $email = text_lower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['display_name'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $blockedSeconds = rate_limit_blocked_seconds('register', client_ip());
        if ($blockedSeconds > 0) {
            audit_log('auth.register_rate_limited', null, null, 'user', null, ['email_hash' => hash('sha256', $email)]);
            flash('error', 'Příliš mnoho pokusů o registraci. Zkuste to znovu za několik minut.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            rate_limit_hit('register', client_ip(), 5, 15 * 60, 30 * 60);
            flash('error', 'Zadejte platný e-mail.');
        } elseif (text_length($name) < 2) {
            rate_limit_hit('register', client_ip(), 5, 15 * 60, 30 * 60);
            flash('error', 'Zadejte jméno.');
        } elseif (text_length($password) < 10) {
            rate_limit_hit('register', client_ip(), 5, 15 * 60, 30 * 60);
            flash('error', 'Heslo musí mít alespoň 10 znaků.');
        } elseif ($existingPasswordUser = find_password_user_by_email($email)) {
            rate_limit_hit('register', client_ip(), 5, 15 * 60, 30 * 60);
            if (!user_email_is_verified($existingPasswordUser)) {
                send_email_verification_message((int)$existingPasswordUser['id'], (string)$existingPasswordUser['email'], (string)$existingPasswordUser['display_name']);
                flash('success', 'Pokud je e-mail registrovaný a čeká na ověření, poslali jsme nový ověřovací odkaz.');
                redirect('login');
            }
            flash('error', 'Účet s tímto e-mailem už existuje.');
        } else {
            rate_limit_hit('register', client_ip(), 5, 15 * 60, 30 * 60);
            $userId = create_user($email, $name, $password);
            mark_invitations_registered($email);
            send_email_verification_message($userId, $email, $name);
            $family = current_family($userId) ?: ensure_family($userId, $name);
            audit_log('auth.registered', $userId, (int)$family['id'], 'user', $userId, ['email_verified' => false]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            remember_user_session($userId);
            flash('success', 'Účet byl vytvořen. Zkontrolujte e-mail a potvrďte adresu; teprve potom se přijmou případné pozvánky do rodiny.');
            redirect('dashboard');
        }
    }

    $prefillEmail = text_lower(trim($_GET['email'] ?? ''));
    render_layout('Registrace', function () use ($prefillEmail) {
        ?>
        <section class="auth-card">
            <h1>Nový účet</h1>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label>Jméno <input required name="display_name" autocomplete="name"></label>
                <label>E-mail <input required type="email" name="email" autocomplete="email" value="<?= e($prefillEmail) ?>"></label>
                <label>Heslo <input required type="password" name="password" minlength="10" autocomplete="new-password"></label>
                <button class="button primary" type="submit">Vytvořit účet</button>
            </form>
            <p class="muted">Už máte účet? <a href="<?= e(url('login')) ?>">Přihlásit</a></p>
        </section>
        <?php
    });
}

function send_email_verification_message(int $userId, string $email, string $name): void
{
    $token = create_email_verification_token($userId);
    $verifyUrl = app_base_url() . '/?r=email_verify&token=' . urlencode($token);
    send_app_email(
        $email,
        'Ověření e-mailu v aplikaci Zdraví dětí',
        "Dobrý den,\n\npotvrďte prosím e-mail pro účet {$name} v aplikaci Zdraví dětí.\n\nOvěření dokončíte zde:\n{$verifyUrl}\n\nOdkaz je platný 24 hodin. Po ověření se automaticky přijmou aktivní pozvánky odeslané na tuto adresu."
    );
    audit_log('auth.email_verification_requested', $userId, null, 'user', $userId, ['email_hash' => hash('sha256', text_lower($email))]);
}

function action_email_verify(): void
{
    $result = consume_email_verification_token((string)($_GET['token'] ?? ''));
    if (!$result) {
        flash('error', 'Odkaz pro ověření e-mailu je neplatný nebo vypršel.');
        redirect('login');
    }

    $verifiedUser = $result['user'];
    $invitations = $result['invitations'];
    foreach ($invitations as $invitation) {
        send_app_email(
            $invitation['inviter_email'],
            'Pozvaný rodič byl přidán do rodiny',
            "Dobrý den,\n\nuživatel {$verifiedUser['email']} ověřil e-mail a byl přidán do rodiny {$invitation['family_name']}.\n\nPřístupy k dětem můžete upravit ve Správě rodiny:\n\n" . app_base_url() . '/?r=family'
        );
    }
    audit_log('auth.email_verified', (int)$verifiedUser['id'], null, 'user', (int)$verifiedUser['id'], ['invitation_count' => count($invitations)]);
    flash('success', 'E-mail byl ověřen.');
    if (current_user()) {
        redirect('dashboard');
    }
    redirect('login');
}

function page_register_legacy(): void
{
    if (current_user()) {
        redirect('dashboard');
    }

    if (is_post()) {
        $email = text_lower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['display_name'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Zadejte platný e-mail.');
        } elseif (text_length($name) < 2) {
            flash('error', 'Zadejte jméno.');
        } elseif (text_length($password) < 10) {
            flash('error', 'Heslo musí mít alespoň 10 znaků.');
        } elseif (find_password_user_by_email($email)) {
            flash('error', 'Účet s tímto e-mailem už existuje.');
        } else {
            $userId = create_user($email, $name, $password);
            $invitations = accept_pending_invitations_for_user($userId, $email);
            foreach ($invitations as $invitation) {
                send_app_email(
                    $invitation['inviter_email'],
                    'Pozvaný rodič byl přidán do rodiny',
                    "Dobrý den,\n\nuživatel {$email} se zaregistroval do aplikace Zdraví dětí a byl přidán do rodiny {$invitation['family_name']}.\n\nPřístupy k dětem můžete upravit ve Správě rodiny:\n\n" . app_base_url() . '/?r=family'
                );
            }
            $family = current_family($userId) ?: ensure_family($userId, $name);
            audit_log('auth.registered', $userId, (int)$family['id'], 'user', $userId, ['invitation_count' => count($invitations)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            remember_user_session($userId);
            redirect('dashboard');
        }
    }

    $prefillEmail = text_lower(trim($_GET['email'] ?? ''));
    render_layout('Registrace', function () use ($prefillEmail) {
        ?>
        <section class="auth-card">
            <h1>Nový účet</h1>
            <form method="post" class="stack">
                <?= csrf_field() ?>
                <label>Jméno <input required name="display_name" autocomplete="name"></label>
                <label>E-mail <input required type="email" name="email" autocomplete="email" value="<?= e($prefillEmail) ?>"></label>
                <label>Heslo <input required type="password" name="password" minlength="10" autocomplete="new-password"></label>
                <button class="button primary" type="submit">Vytvořit účet</button>
            </form>
            <p class="muted">Už máte účet? <a href="<?= e(url('login')) ?>">Přihlásit</a></p>
        </section>
        <?php
    });
}

function action_logout(): void
{
    if (!is_post()) {
        redirect(current_user() ? 'dashboard' : 'login');
    }
    $user = current_user();
    if ($user) {
        revoke_current_user_session((int)$user['id']);
        audit_log('auth.logout', (int)$user['id'], null, 'user', (int)$user['id']);
    }
    session_destroy();
    redirect('login');
}

function action_google_start(): void
{
    if (!cfg('google.client_id') || !cfg('google.client_secret')) {
        flash('error', 'Google přihlášení není nakonfigurované.');
        redirect('login');
    }
    $_SESSION['google_state'] = bin2hex(random_bytes(16));
    $params = [
        'client_id' => cfg('google.client_id'),
        'redirect_uri' => cfg('google.redirect_uri'),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['google_state'],
        'prompt' => 'select_account',
    ];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit;
}

function action_google_callback(): void
{
    if (!hash_equals($_SESSION['google_state'] ?? '', $_GET['state'] ?? '')) {
        flash('error', 'Google přihlášení se nepodařilo ověřit.');
        redirect('login');
    }
    $code = $_GET['code'] ?? '';
    if (!$code || !function_exists('curl_init')) {
        flash('error', 'Server nepodporuje dokonceni Google prihlaseni.');
        redirect('login');
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => cfg('google.client_id'),
            'client_secret' => cfg('google.client_secret'),
            'redirect_uri' => cfg('google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]),
    ]);
    $token = json_decode((string)curl_exec($ch), true);
    curl_close($ch);

    $idToken = $token['id_token'] ?? '';
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $info = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $googleSubject = (string)($info['sub'] ?? '');
    $emailVerified = filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (($info['aud'] ?? '') !== cfg('google.client_id') || empty($info['email']) || $googleSubject === '' || !$emailVerified) {
        flash('error', 'Google identitu se nepodařilo ověřit.');
        redirect('login');
    }

    $user = find_user_by_google_subject($googleSubject);
    if (!$user) {
        $userId = create_user($info['email'], $info['name'] ?? $info['email'], null, $googleSubject, true);
        $invitations = accept_pending_invitations_for_user($userId, $info['email']);
        foreach ($invitations as $invitation) {
            send_app_email(
                $invitation['inviter_email'],
                'Pozvaný rodič byl přidán do rodiny',
                "Dobrý den,\n\nuživatel {$info['email']} se přihlásil přes Google a byl přidán do rodiny {$invitation['family_name']}.\n\nPřístupy k dětem můžete upravit ve Správě rodiny:\n\n" . app_base_url() . '/?r=family'
            );
        }
        audit_log('auth.google_registered', $userId, null, 'user', $userId, ['invitation_count' => count($invitations)]);
    } else {
        $userId = (int)$user['id'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    remember_user_session($userId);
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([now_sql(), $userId]);
    $family = current_family($userId) ?: ensure_family($userId, $info['name'] ?? 'Rodina');
    audit_log('auth.google_login_success', $userId, (int)$family['id'], 'user', $userId);
    redirect('dashboard');
}

function page_settings(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $isCurrentFamilyOwner = ($family['role'] ?? '') === 'OWNER';
    $hasPassword = !empty($user['password_hash']);
    $sessions = active_user_sessions((int)$user['id']);
    $currentHash = current_session_hash();

    render_layout('Nastavení', function () use ($user, $family, $isCurrentFamilyOwner, $hasPassword, $sessions, $currentHash) {
        ?>
        <div class="page-head">
            <div>
                <h1>Nastavení</h1>
                <p class="muted">Účet, přihlášení a citlivé akce.</p>
            </div>
        </div>

        <section class="panel">
            <h2>Účet</h2>
            <dl class="summary-list">
                <dt>Jméno</dt>
                <dd><?= e($user['display_name']) ?></dd>
                <dt>E-mail</dt>
                <dd><?= e($user['email']) ?></dd>
                <dt>Rodina</dt>
                <dd><?= e($family['name'] ?? 'Bez rodiny') ?></dd>
                <dt>Role</dt>
                <dd><?= e(role_label((string)($family['role'] ?? 'PARENT'))) ?></dd>
                <dt>Přihlášení</dt>
                <dd><?= $hasPassword ? 'E-mail a heslo' : 'Google účet' ?></dd>
            </dl>
        </section>

        <section class="panel">
            <div class="section-head">
                <div>
                    <h2>Aktivní zařízení</h2>
                    <p class="muted">Přehled prohlížečů a zařízení, kde je váš účet přihlášený.</p>
                </div>
                <?php if (count($sessions) > 1): ?>
                    <form method="post" action="<?= e(url('devices_revoke_others')) ?>" data-confirm="Odhlásit všechna ostatní zařízení?">
                        <?= csrf_field() ?>
                        <button class="button danger" type="submit">Odhlásit ostatní</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$sessions): ?>
                <div class="empty">Aktuálně není evidované žádné aktivní zařízení.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($sessions as $session):
                        $isCurrent = hash_equals((string)$session['session_id_hash'], $currentHash);
                        ?>
                        <div class="list-row device-row">
                            <span>
                                <strong><?= e(device_label((string)$session['user_agent'])) ?></strong>
                                <small>
                                    <?= $isCurrent ? 'Toto zařízení · ' : '' ?>
                                    IP <?= e($session['ip_address'] ?: '-') ?>
                                </small>
                            </span>
                            <small>
                                Naposledy <?= e(display_datetime($session['last_seen_at'])) ?><br>
                                Vytvořeno <?= e(display_datetime($session['created_at'])) ?>
                            </small>
                            <?php if ($isCurrent): ?>
                                <span class="badge">Aktuální</span>
                            <?php else: ?>
                                <form method="post" action="<?= e(url('device_revoke')) ?>" data-confirm="Odhlásit toto zařízení?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="session_id" value="<?= e($session['id']) ?>">
                                    <button class="button tiny danger" type="submit">Odhlásit</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel danger-zone">
            <div class="section-head">
                <div>
                    <h2>Smazání účtu</h2>
                    <p class="muted">Smaže osobní údaje účtu, odhlásí všechna zařízení a odebere přístup k rodině a dětem. Pokud jste v rodině jen rodič, rodina i zdravotní záznamy dětí zůstanou zachované.</p>
                </div>
            </div>

            <?php if ($isCurrentFamilyOwner): ?>
                <div class="empty">
                    Tento účet je administrátorem rodiny. Nejdříve zrušte rodinu ve Správě rodiny, nebo správu v budoucnu předejte jinému rodiči.
                </div>
            <?php else: ?>
                <form method="post" action="<?= e(url('account_delete')) ?>" class="stack" data-confirm="Opravdu chcete smazat svůj účet? Tato akce odhlásí všechna zařízení a nejde vrátit zpět.">
                    <?= csrf_field() ?>
                    <?php if ($hasPassword): ?>
                        <label>Aktuální heslo <input required type="password" name="current_password" autocomplete="current-password"></label>
                    <?php endif; ?>
                    <label class="check">
                        <input required type="checkbox" name="confirm_delete" value="1">
                        <span>Rozumím, že účet bude anonymizován a ztratím přístup k rodině a dětem.</span>
                    </label>
                    <button class="button danger" type="submit">Smazat můj účet</button>
                </form>
            <?php endif; ?>
        </section>
        <?php
    }, 'settings');
}

function action_account_delete(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);

    if (($_POST['confirm_delete'] ?? '') !== '1') {
        flash('error', 'Potvrďte prosím smazání účtu.');
        redirect('settings');
    }
    if (($family['role'] ?? '') === 'OWNER') {
        flash('error', 'Účet administrátora rodiny nejde smazat, dokud rodina existuje.');
        redirect('settings');
    }
    if (!empty($user['password_hash'])) {
        $password = (string)($_POST['current_password'] ?? '');
        if (!password_verify($password, (string)$user['password_hash'])) {
            audit_log('auth.account_delete_password_failed', (int)$user['id'], $family ? (int)$family['id'] : null, 'user', (int)$user['id']);
            flash('error', 'Aktuální heslo nesedí.');
            redirect('settings');
        }
    }

    audit_log('auth.account_deleted', (int)$user['id'], $family ? (int)$family['id'] : null, 'user', (int)$user['id']);
    delete_user_account((int)$user['id']);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
        session_start();
    }
    flash('success', 'Účet byl smazán.');
    redirect('login');
}

function page_devices(): void
{
    redirect('settings');
}

function action_device_revoke(): void
{
    $user = require_login();
    $session = revoke_user_session((int)$user['id'], (int)($_POST['session_id'] ?? 0));
    if (!$session) {
        flash('error', 'Zařízení se nepodařilo najít nebo jde o aktuální přihlášení.');
        redirect('settings');
    }
    audit_log('auth.device_revoked', (int)$user['id'], null, 'user_session', (int)$session['id']);
    flash('success', 'Zařízení bylo odhlášeno.');
    redirect('settings');
}

function action_devices_revoke_others(): void
{
    $user = require_login();
    $count = revoke_other_user_sessions((int)$user['id']);
    audit_log('auth.devices_revoked_others', (int)$user['id'], null, 'user_session', null, ['count' => $count]);
    flash('success', 'Ostatní zařízení byla odhlášena.');
    redirect('settings');
}

function page_dashboard(): void
{
    $user = require_login();
    ensure_family((int)$user['id'], $user['display_name']);
    $overview = dashboard_overview((int)$user['id'], 72);

    render_layout('Přehled', function () use ($overview) {
        ?>
        <div class="page-head">
            <div>
                <h1>Přehled</h1>
                <p class="muted">Aktuální zdravotní historie všech dětí, ke kterým máte přístup.</p>
            </div>
            <a class="button" href="<?= e(url('family')) ?>">Správa rodiny</a>
        </div>

        <?php if (!$overview): ?>
            <section class="panel">
                <div class="empty">Zatím tu není žádné dítě. Administrátor rodiny ho může přidat ve správě rodiny.</div>
                <div class="panel-actions">
                    <a class="button primary" href="<?= e(url('family')) ?>">Přejít na správu rodiny</a>
                </div>
            </section>
        <?php else: ?>
            <div class="overview-list">
                <?php foreach ($overview as $item):
                    $child = $item['child'];
                    $summary = $item['summary'];
                    $last = $summary['last_temperature'];
                    $max24 = $summary['max_24h'];
                    $lastMedication = $summary['last_medication'];
                    $ehic = $item['ehic'];
                    ?>
                    <section class="panel child-overview">
                        <div class="child-overview-head">
                            <div>
                                <div class="child-title-row">
                                    <h2><?= e($child['first_name'] . ' ' . $child['last_name']) ?></h2>
                                    <?php if ($ehic): ?>
                                        <?php render_ehic_menu((int)$child['id'], $ehic, 'dashboard'); ?>
                                    <?php endif; ?>
                                </div>
                                <p class="muted">
                                    Narození <?= e(date('d.m.Y', strtotime($child['date_of_birth']))) ?>,
                                    věk <?= e(child_age_label($child['date_of_birth'])) ?>
                                </p>
                            </div>
                            <div class="actions">
                                <a class="button" href="<?= e(url('child', ['id' => $child['id'], 'documents' => 1])) ?>">Dokumenty</a>
                                <a class="button" href="<?= e(url('child', ['id' => $child['id']])) ?>">Detail dítěte</a>
                                <a class="button" href="<?= e(url('export', ['child_id' => $child['id']])) ?>">Export</a>
                            </div>
                        </div>

                        <div class="stat-strip">
                            <div class="<?= e(severity($last ? (float)$last['temperature_celsius'] : null)) ?>">
                                <span>Poslední teplota</span>
                                <strong><?= $last ? e(number_format((float)$last['temperature_celsius'], 1, ',', ' ') . ' °C') : '-' ?></strong>
                                <small><?= $last ? e(display_datetime($last['event_at'])) : 'Zatím bez teploty' ?></small>
                            </div>
                            <div class="<?= e(severity($max24 ? (float)$max24 : null)) ?>">
                                <span>Maximum za 24 h</span>
                                <strong><?= $max24 ? e(number_format((float)$max24, 1, ',', ' ') . ' °C') : '-' ?></strong>
                                <small>Za posledních 24 hodin</small>
                            </div>
                            <div>
                                <span>Poslední lék</span>
                                <strong><?= $lastMedication ? e(medication_label($lastMedication)) : '-' ?></strong>
                                <small><?= isset($lastMedication['event_at']) ? e(display_datetime($lastMedication['event_at'])) : 'Zatím bez léku' ?></small>
                            </div>
                        </div>

                        <form method="post" action="<?= e(url('temperature_save')) ?>" class="quick-temperature-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                            <input type="hidden" name="event_at" value="<?= e(input_datetime()) ?>">
                            <input type="hidden" name="return_to" value="dashboard">
                            <span class="quick-label">Rychlý zápis teploty</span>
                            <input required name="temperature_celsius" inputmode="decimal" enterkeyhint="done" autocomplete="off" placeholder="38,4" pattern="[0-9]+([,.][0-9])?" aria-label="Teplota ve stupních Celsia">
                            <button class="button primary" type="submit">Uložit</button>
                        </form>

                        <div class="chart-heading">
                            <h3>Historie za posledních 72 hodin</h3>
                        </div>
                        <?php render_timeline_chart($item['timeline']); ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
    }, 'dashboard');
}

function render_ehic_menu(int $childId, array $ehic, string $returnTo): void
{
    ?>
    <details class="ehic-menu">
        <summary aria-label="EHIC">
            <span class="ehic-card-icon" aria-hidden="true"><span></span></span>
        </summary>
        <button class="ehic-menu-backdrop" type="button" data-ehic-close aria-label="Zavřít menu EHIC"></button>
        <div class="ehic-menu-panel">
            <div class="ehic-menu-head">
                <strong>EHIC</strong>
                <button class="button tiny subtle" type="button" data-ehic-close>Zavřít</button>
            </div>
            <small><?= e(display_datetime($ehic['created_at'])) ?> · <?= e(file_size_label((int)$ehic['size_bytes'])) ?></small>
            <div class="ehic-menu-actions">
                <a class="button tiny primary" href="<?= e(url('document_view', ['id' => $ehic['id']])) ?>">Zobrazit</a>
                <a class="button tiny" href="<?= e(url('document_download', ['id' => $ehic['id']])) ?>">Stáhnout</a>
                <form method="post" action="<?= e(url('document_delete')) ?>" class="ehic-delete-form" data-confirm="Smazat EHIC?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="document_id" value="<?= e($ehic['id']) ?>">
                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                    <button class="button tiny danger" type="submit">Smazat</button>
                </form>
            </div>
            <form method="post" action="<?= e(url('document_upload')) ?>" enctype="multipart/form-data" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="child_id" value="<?= e($childId) ?>">
                <input type="hidden" name="title" value="EHIC">
                <input type="hidden" name="document_type" value="ehic">
                <input type="hidden" name="is_sensitive" value="1">
                <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                <label>Nahrát nový <input required type="file" name="document_file" accept="image/*,.heic,.heif,.pdf"></label>
                <button class="button tiny" type="submit">Nahrát</button>
            </form>
        </div>
    </details>
    <?php
}

function page_children(): void
{
    redirect('family');
}

function action_child_create(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);

    $first = trim($_POST['first_name'] ?? '');
    $last = trim($_POST['last_name'] ?? '');
    $dob = $_POST['date_of_birth'] ?? '';
    try {
        $weight = normalize_child_weight($_POST['weight_kg'] ?? '');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
        redirect('family');
    }
    $allergies = trim($_POST['allergies'] ?? '');
    if ($first === '' || $last === '' || !$dob || $dob > date('Y-m-d')) {
        flash('error', 'Zkontrolujte jméno, příjmení a datum narození.');
        redirect('family');
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('INSERT INTO children (family_id, first_name, last_name, date_of_birth, weight_kg, allergies) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$family['id'], $first, $last, $dob, $weight, $allergies]);
        $childId = (int)db()->lastInsertId();
        $access = db()->prepare('INSERT INTO child_access (child_id, user_id, can_view, can_create_record, can_edit_record, can_delete_record) VALUES (?, ?, 1, 1, 1, 1)');
        $access->execute([$childId, $family['owner_user_id']]);
        db()->commit();
        audit_log('child.created', (int)$user['id'], (int)$family['id'], 'child', $childId);
        flash('success', 'Dítě bylo přidáno.');
        redirect('child', ['id' => $childId]);
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function normalize_child_weight($value): ?float
{
    $normalized = str_replace(',', '.', trim((string)$value));
    if ($normalized === '') {
        return null;
    }
    $weight = (float)$normalized;
    if ($weight < 0 || $weight > 200) {
        throw new InvalidArgumentException('Váha musí být v rozsahu 0 až 200 kg.');
    }
    return $weight;
}

function symptom_options(): array
{
    return [
        'kašel',
        'rýma',
        'bolest v krku',
        'bolest hlavy',
        'bolest břicha',
        'zvracení',
        'průjem',
        'vyrážka',
        'únava',
        'nechutenství',
        'dušnost',
        'jiné',
    ];
}

function action_child_profile_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);

    try {
        $first = trim($_POST['first_name'] ?? $child['first_name']);
        $last = trim($_POST['last_name'] ?? $child['last_name']);
        $dob = $_POST['date_of_birth'] ?? $child['date_of_birth'];
        if ($first === '' || $last === '' || !$dob || $dob > date('Y-m-d')) {
            throw new InvalidArgumentException('Zkontrolujte jméno, příjmení a datum narození.');
        }
        $weight = normalize_child_weight($_POST['weight_kg'] ?? '');
        $allergies = trim($_POST['allergies'] ?? '');
        db()->prepare('UPDATE children SET first_name = ?, last_name = ?, date_of_birth = ?, weight_kg = ?, allergies = ?, updated_at = ? WHERE id = ? AND family_id = ?')
            ->execute([$first, $last, $dob, $weight, $allergies, now_sql(), $child['id'], $family['id']]);
        audit_log('child.updated', (int)$user['id'], (int)$family['id'], 'child', (int)$child['id']);
        flash('success', 'Údaje dítěte byly uloženy.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }

    if (($_POST['return_to'] ?? '') === 'family') {
        redirect('family');
    }
    redirect('child', ['id' => $child['id']]);
}

function page_child_doctors(): void
{
    $user = require_login();
    $child = require_child_access((int)($_GET['child_id'] ?? $_POST['child_id'] ?? 0), (int)$user['id']);
    $assigned = child_doctors((int)$child['id']);
    $fields = healthcare_provider_fields();
    $query = trim((string)($_GET['q'] ?? ''));
    $careField = trim((string)($_GET['care_field'] ?? ''));
    $city = trim((string)($_GET['city'] ?? ''));
    $results = search_healthcare_providers($query, $careField, $city, 50);
    $providerCount = healthcare_provider_count();

    render_layout('Lékaři dítěte', function () use ($child, $assigned, $fields, $query, $careField, $city, $results, $providerCount) {
        ?>
        <div class="page-head">
            <div>
                <h1>Lékaři dítěte <span class="title-inline-name"><?= e($child['first_name'] . ' ' . $child['last_name']) ?></span></h1>
                <p class="muted">Data z Národního registru Poskytovatelů Zdravotnických služeb. Data nemusí být aktuální.</p>
            </div>
            <div class="actions">
                <a class="button" href="<?= e(url('family')) ?>">Zpět na správu rodiny</a>
                <a class="button" href="<?= e(url('child', ['id' => $child['id']])) ?>">Detail dítěte</a>
            </div>
        </div>

        <section class="panel">
            <h2>Přiřazení lékaři</h2>
            <?php if (!$assigned): ?>
                <div class="empty">Zatím není přiřazený žádný lékař.</div>
            <?php else: ?>
                <div class="provider-list">
                    <?php foreach ($assigned as $doctor): ?>
                        <div class="provider-card assigned">
                            <div>
                                <strong><?= e($doctor['role_label'] ?: (provider_specialty_label($doctor) ?: 'Lékař')) ?></strong>
                                <span><?= e($doctor['name']) ?></span>
                                <small><?= e(provider_specialty_label($doctor)) ?></small>
                                <small><?= e(provider_address_label($doctor)) ?></small>
                                <?php if (!empty($doctor['phone']) || !empty($doctor['email']) || !empty($doctor['web'])): ?>
                                    <small><?= e(provider_contact_label($doctor)) ?></small>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="<?= e(url('child_doctor_remove')) ?>" data-confirm="Odebrat lékaře od dítěte?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                                <input type="hidden" name="child_doctor_id" value="<?= e($doctor['id']) ?>">
                                <button class="button tiny danger" type="submit">Odebrat</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Vyhledat v číselníku</h2>
            <?php if ($providerCount === 0): ?>
                <div class="empty">Číselník poskytovatelů zatím není naimportovaný.</div>
            <?php endif; ?>
            <form method="get" class="provider-search">
                <input type="hidden" name="r" value="child_doctors">
                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                <label>Obor
                    <select name="care_field">
                        <option value="">Všechny obory</option>
                        <?php foreach ($fields as $field): ?>
                            <option value="<?= e($field['care_field']) ?>" <?= $field['care_field'] === $careField ? 'selected' : '' ?>>
                                <?= e($field['care_field'] . ' (' . $field['count_items'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Město <input name="city" value="<?= e($city) ?>" placeholder="např. Praha, Brno"></label>
                <label>Hledat <input name="q" value="<?= e($query) ?>" placeholder="název, lékař, ulice"></label>
                <button class="button primary" type="submit">Hledat</button>
            </form>

            <?php if (($query !== '' || $careField !== '' || $city !== '') && !$results): ?>
                <div class="empty">Nenašel jsem žádného poskytovatele. Zkuste méně přesný dotaz nebo jiný obor.</div>
            <?php endif; ?>

            <?php if ($results): ?>
                <div class="provider-list search-results">
                    <?php foreach ($results as $provider): ?>
                        <div class="provider-card">
                            <div>
                                <strong><?= e($provider['name']) ?></strong>
                                <span><?= e(provider_specialty_label($provider) ?: $provider['facility_type']) ?></span>
                                <small><?= e(provider_address_label($provider)) ?></small>
                                <?php if (!empty($provider['phone']) || !empty($provider['email']) || !empty($provider['web'])): ?>
                                    <small><?= e(provider_contact_label($provider)) ?></small>
                                <?php endif; ?>
                            </div>
                            <form method="post" action="<?= e(url('child_doctor_add')) ?>" class="provider-add-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                                <input type="hidden" name="provider_id" value="<?= e($provider['id']) ?>">
                                <input name="role_label" value="<?= e($careField ?: '') ?>" placeholder="Role u dítěte">
                                <button class="button tiny primary" type="submit">Přidat</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }, 'family');
}

function action_child_doctor_add(): void
{
    $user = require_login();
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $family = current_family((int)$user['id']);
    $providerId = (int)($_POST['provider_id'] ?? 0);
    $roleLabel = trim((string)($_POST['role_label'] ?? ''));
    try {
        add_child_doctor((int)$child['id'], $providerId, $roleLabel);
        audit_log('child.doctor_added', (int)$user['id'], (int)$family['id'], 'child', (int)$child['id'], ['provider_id' => $providerId]);
        flash('success', 'Lékař byl přiřazen k dítěti.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('child_doctors', ['child_id' => $child['id']]);
}

function action_child_doctor_remove(): void
{
    $user = require_login();
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $family = current_family((int)$user['id']);
    $doctor = remove_child_doctor((int)$child['id'], (int)($_POST['child_doctor_id'] ?? 0));
    if ($doctor) {
        audit_log('child.doctor_removed', (int)$user['id'], (int)$family['id'], 'child', (int)$child['id'], ['provider_id' => (int)$doctor['provider_id']]);
        flash('success', 'Lékař byl odebrán.');
    } else {
        flash('error', 'Přiřazený lékař nebyl nalezen.');
    }
    redirect('child_doctors', ['child_id' => $child['id']]);
}

function page_child(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_GET['id'] ?? 0), (int)$user['id']);
    $rangeParam = (string)($_GET['range'] ?? '72');
    $range = in_array($rangeParam, ['12', '24', '72'], true) ? (int)$rangeParam : 72;
    $openDocuments = ($_GET['documents'] ?? '') === '1';
    $openAppointments = ($_GET['appointments'] ?? '') === '1';
    $documentUploaded = in_array($_GET['document_uploaded'] ?? '', ['ehic', 'document'], true) ? (string)$_GET['document_uploaded'] : '';
    $summary = child_summary((int)$child['id']);
    $timeline = timeline_data((int)$child['id'], $range);
    $records = child_records((int)$child['id']);
    $medications = medications((int)$family['id'], true);
    $careTypes = custom_care_types((int)$family['id']);
    encrypt_plain_documents_for_child((int)$child['id']);
    $documents = child_documents((int)$child['id']);
    $appointments = child_appointments((int)$child['id']);
    $appointmentDocuments = appointment_documents_for_appointments(array_map(fn(array $appointment): int => (int)$appointment['id'], $appointments));
    $childDoctors = child_doctors((int)$child['id']);

    render_layout($child['first_name'], function () use ($child, $family, $summary, $timeline, $records, $medications, $careTypes, $range, $documents, $appointments, $appointmentDocuments, $childDoctors, $openDocuments, $openAppointments, $documentUploaded) {
        $last = $summary['last_temperature'];
        ?>
        <div class="page-head">
            <div>
                <h1><?= e($child['first_name'] . ' ' . $child['last_name']) ?></h1>
                <p class="muted">
                    Narození <?= e(date('d.m.Y', strtotime($child['date_of_birth']))) ?>,
                    věk <?= e(child_age_label($child['date_of_birth'])) ?>
                </p>
            </div>
        </div>

        <div class="actions child-actions">
            <a class="button" href="<?= e(url('child_doctors', ['child_id' => $child['id']])) ?>">Lékaři</a>
            <a class="button" href="<?= e(url('child', ['id' => $child['id'], 'documents' => 1])) ?>#documents-dialog">Dokumentace</a>
            <a class="button" href="<?= e(url('child', ['id' => $child['id'], 'appointments' => 1])) ?>#appointments">Kontroly</a>
            <a class="button" href="<?= e(url('export', ['child_id' => $child['id']])) ?>">Export pro lékaře</a>
        </div>

        <?php
        $documentProviderOptions = [];
        foreach (array_merge($childDoctors, $appointments) as $provider) {
            $providerId = (int)($provider['provider_id'] ?? $provider['id'] ?? 0);
            if ($providerId <= 0 || isset($documentProviderOptions[$providerId])) {
                continue;
            }
            $documentProviderOptions[$providerId] = $provider;
        }
        ?>
        <?php if ($openDocuments): ?>
        <section class="panel document-page" id="documents-dialog">
            <div class="modal-head">
                <div>
                    <h2>Dokumentace</h2>
                    <p class="muted"><?= e($child['first_name'] . ' ' . $child['last_name']) ?></p>
                </div>
                <a class="button subtle" href="<?= e(url('child', ['id' => $child['id']])) ?>">Zavřít</a>
            </div>

            <?php if ($documentUploaded): ?>
                <div class="flash success modal-flash"><?= $documentUploaded === 'ehic' ? 'EHIC byl uložen a je dostupný v detailu dítěte.' : 'Dokument byl uložen.' ?></div>
            <?php endif; ?>

            <section class="subsection document-section">
                <h3>Uložené dokumenty</h3>
                <?php if (!$documents): ?>
                    <div class="empty">Zatím není uložený žádný dokument.</div>
                <?php else: ?>
                    <div class="document-list">
                        <?php foreach ($documents as $document): ?>
                            <div class="document-row">
                                <div>
                                    <strong><?= e($document['title']) ?></strong>
                                    <small>
                                        <?= e(document_type_label($document['document_type'] ?? 'general')) ?> ·
                                        <?= e($document['original_filename']) ?> · <?= e(file_size_label((int)$document['size_bytes'])) ?> · <?= e(display_datetime($document['created_at'])) ?>
                                        <?php if (($document['storage_mode'] ?? 'plain') === 'encrypted'): ?><span class="badge">Šifrováno</span><?php endif; ?>
                                        <?php if (!empty($document['is_sensitive'])): ?><span class="badge warning">Citlivé</span><?php endif; ?>
                                    </small>
                                    <?php if (!empty($document['provider_name'])): ?>
                                        <small><?= e($document['provider_name']) ?>, <?= e(provider_specialty_label($document)) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($document['note'])): ?>
                                        <small><?= e($document['note']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="actions">
                                    <a class="button tiny" href="<?= e(url('document_view', ['id' => $document['id']])) ?>">Zobrazit</a>
                                    <a class="button tiny" href="<?= e(url('document_download', ['id' => $document['id']])) ?>">Stáhnout</a>
                                    <form method="post" action="<?= e(url('document_delete')) ?>" data-confirm="Smazat dokument?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="document_id" value="<?= e($document['id']) ?>">
                                        <button class="button tiny danger" type="submit">Smazat</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="subsection document-section">
                <h3>Nahrát EHIC</h3>
                <form method="post" action="<?= e(url('document_upload')) ?>" enctype="multipart/form-data" class="stack" data-upload-form data-upload-label="Nahrávám EHIC...">
                    <?= csrf_field() ?>
                    <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                    <input type="hidden" name="title" value="EHIC">
                    <input type="hidden" name="document_type" value="ehic">
                    <input type="hidden" name="is_sensitive" value="1">
                    <label>Poznámka <textarea name="note" rows="2" placeholder="Volitelné, např. platnost nebo pojišťovna"></textarea></label>
                    <label>Soubor <input required type="file" name="document_file" accept="image/*,.heic,.heif,.pdf"></label>
                    <button class="button primary" type="submit">Uložit EHIC</button>
                </form>
            </section>

            <section class="subsection document-section">
                <h3>Nahrát nový dokument</h3>
                <form method="post" action="<?= e(url('document_upload')) ?>" enctype="multipart/form-data" class="stack" data-upload-form data-upload-label="Nahrávám dokument...">
                    <?= csrf_field() ?>
                    <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                    <label>Název <input required name="title" maxlength="255" placeholder="Např. zpráva z pohotovosti"></label>
                    <label>Typ dokumentu
                        <select name="document_type">
                            <?php foreach (document_type_options() as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Poznámka <textarea name="note" rows="3" placeholder="Krátký kontext, závěr, doporučení"></textarea></label>
                    <label class="check"><input type="checkbox" name="is_sensitive" value="1"> Citlivý dokument</label>
                    <label>Soubor <input required type="file" name="document_file" accept="image/*,.heic,.heif,.pdf,.doc,.docx,.txt"></label>
                    <button class="button primary" type="submit">Uložit dokument</button>
                </form>
            </section>
        </section>
        <?php endif; ?>

        <section class="metrics">
            <?php metric_card('Poslední teplota', $last ? number_format((float)$last['temperature_celsius'], 1, ',', ' ') . ' °C' : '-', $last ? display_datetime($last['event_at']) : '', severity($last ? (float)$last['temperature_celsius'] : null)); ?>
            <?php metric_card('Maximum za 24 h', $summary['max_24h'] ? number_format((float)$summary['max_24h'], 1, ',', ' ') . ' °C' : '-', '', severity($summary['max_24h'] ? (float)$summary['max_24h'] : null)); ?>
            <?php metric_card('Poslední lék', $summary['last_medication'] ? medication_label($summary['last_medication']) : '-', isset($summary['last_medication']['event_at']) ? display_datetime($summary['last_medication']['event_at']) : ''); ?>
        </section>

        <section class="panel">
            <div class="section-head" id="appointments">
                <h2>Kontroly</h2>
                <span class="muted">Plánované i proběhlé návštěvy lékaře</span>
            </div>
            <details class="appointment-card" <?= $openAppointments ? 'open' : '' ?>>
                <summary class="appointment-summary">
                    <strong>Naplánovat nebo zapsat kontrolu</strong>
                    <span class="muted">vazba na lékaře a dokumenty</span>
                </summary>
                <?php render_appointment_form($child, null, $documentProviderOptions, $documents); ?>
            </details>
            <?php if (!$appointments): ?>
                <div class="empty">Zatím tu není žádná kontrola.</div>
            <?php else: ?>
                <div class="appointment-list">
                    <?php foreach ($appointments as $appointment): ?>
                        <details class="appointment-card">
                            <summary class="appointment-summary">
                                <strong><?= e($appointment['title']) ?></strong>
                                <span><?= e(display_datetime($appointment['scheduled_at'])) ?></span>
                                <span class="badge"><?= e(appointment_status_label($appointment['status'] ?? 'planned')) ?></span>
                            </summary>
                            <dl class="summary-list compact">
                                <dt>Typ</dt><dd><?= e($appointment['appointment_type']) ?></dd>
                                <dt>Lékař</dt><dd><?= e(($appointment['provider_name'] ?? '') !== '' ? $appointment['provider_name'] . ', ' . provider_specialty_label($appointment) : '-') ?></dd>
                                <dt>Před kontrolou</dt><dd><?= e(($appointment['pre_note'] ?? '') !== '' ? $appointment['pre_note'] : '-') ?></dd>
                                <dt>Výsledek</dt><dd><?= e(($appointment['result_note'] ?? '') !== '' ? $appointment['result_note'] : '-') ?></dd>
                                <dt>Doporučení</dt><dd><?= e(($appointment['recommendation'] ?? '') !== '' ? $appointment['recommendation'] : '-') ?></dd>
                            </dl>
                            <?php $linkedDocuments = $appointmentDocuments[(int)$appointment['id']] ?? []; ?>
                            <?php if ($linkedDocuments): ?>
                                <div class="mini-list">
                                    <?php foreach ($linkedDocuments as $document): ?>
                                        <a href="<?= e(url('document_view', ['id' => $document['id']])) ?>"><?= e($document['title']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php render_appointment_form($child, $appointment, $documentProviderOptions, $documents); ?>
                            <form method="post" action="<?= e(url('appointment_delete')) ?>" data-confirm="Smazat kontrolu?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                                <input type="hidden" name="appointment_id" value="<?= e($appointment['id']) ?>">
                                <button class="button danger" type="submit">Smazat kontrolu</button>
                            </form>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="forms-3">
            <form method="post" action="<?= e(url('temperature_save')) ?>" class="panel stack">
                <?= csrf_field() ?>
                <h2>Teplota</h2>
                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                <label>Hodnota °C <input required type="number" step="0.1" min="30" max="45" name="temperature_celsius" inputmode="decimal"></label>
                <label>Čas měření <input required type="datetime-local" name="event_at" value="<?= e(input_datetime()) ?>"></label>
                <label>Místo <input name="place"></label>
                <label>Poznámka <textarea name="note" rows="2"></textarea></label>
                <button class="button primary" type="submit">Uložit teplotu</button>
            </form>

            <?php if ($careTypes): ?>
                <form method="post" action="<?= e(url('care_record_save')) ?>" class="panel stack">
                    <?= csrf_field() ?>
                    <h2>Péče</h2>
                    <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                    <label>Typ
                        <select required name="record_type_id">
                            <?php foreach ($careTypes as $type): ?><option value="<?= e($type['id']) ?>"><?= e($type['name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Čas <input required type="datetime-local" name="event_at" value="<?= e(input_datetime()) ?>"></label>
                    <label>Poznámka <textarea name="note" rows="2"></textarea></label>
                    <button class="button primary" type="submit">Uložit péči</button>
                </form>
            <?php else: ?>
                <section class="panel stack">
                    <h2>Péče</h2>
                    <p class="muted">Nejdřív si založte vlastní typ péče.</p>
                    <a class="button" href="<?= e(url('care_types')) ?>">Typy péče</a>
                </section>
            <?php endif; ?>

            <form method="post" action="<?= e(url('medication_record_save')) ?>" class="panel stack">
                <?= csrf_field() ?>
                <h2>Podání léku</h2>
                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                <label>Lék
                    <select required name="medication_id">
                        <option value="">Vyberte lék</option>
                        <?php foreach ($medications as $med): ?>
                            <option value="<?= e($med['id']) ?>" data-info="<?= e($med['dosing_info'] ?? '') ?>" data-source="<?= e($med['source_url'] ?? '') ?>"><?= e(medication_label($med)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="medication-info" data-medication-info hidden></div>
                <label>Čas podání <input required type="datetime-local" name="event_at" value="<?= e(input_datetime()) ?>"></label>
                <label>Poznámka <textarea name="note" rows="2"></textarea></label>
                <button class="button primary" type="submit">Uložit lék</button>
            </form>
        </section>

        <section class="panel">
            <div class="section-head">
                <h2>Časová osa</h2>
                <div class="segmented" data-timeline-controls>
                    <?php foreach ([12, 24, 72] as $hours): ?>
                        <a class="<?= $range === $hours ? 'active' : '' ?>" href="<?= e(url('child', ['id' => $child['id'], 'range' => $hours])) ?>" data-timeline-url="<?= e(url('child_timeline', ['id' => $child['id'], 'range' => $hours])) ?>"><?= $hours ?> h</a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div data-timeline-target>
                <?php render_timeline_chart($timeline); ?>
            </div>
        </section>

        <section class="panel">
            <h2>Poslední záznamy</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Čas</th><th>Typ</th><th>Detail</th><th>Poznámka</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= e(display_datetime($record['event_at'])) ?></td>
                            <td><?= e($record['type_name']) ?></td>
                            <td><?= e(record_detail_label($record)) ?></td>
                            <td><?= e($record['note']) ?></td>
                            <td class="actions">
                                <a class="button tiny" href="<?= e(url('record_edit', ['id' => $record['id']])) ?>">Upravit</a>
                                <form method="post" action="<?= e(url('record_delete')) ?>" data-confirm="Smazat záznam?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="record_id" value="<?= e($record['id']) ?>">
                                    <button class="button tiny danger" type="submit">Smazat</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php
    }, 'child');
}

function page_child_timeline(): void
{
    $user = require_login();
    $child = require_child_access((int)($_GET['id'] ?? 0), (int)$user['id']);
    $range = in_array($_GET['range'] ?? '72', ['12', '24', '72'], true) ? (int)$_GET['range'] : 72;
    render_timeline_chart(timeline_data((int)$child['id'], $range));
}

function render_appointment_form(array $child, ?array $appointment, array $providerOptions, array $documents): void
{
    $selectedDocumentIds = $appointment ? appointment_document_ids((int)$appointment['id']) : [];
    $appointmentTypes = ['Kontrola', 'Pediatr', 'Oční', 'Zubař', 'ORL', 'Alergologie', 'Jiné'];
    $currentType = (string)($appointment['appointment_type'] ?? 'Kontrola');
    ?>
    <form method="post" action="<?= e(url('appointment_save')) ?>" class="stack appointment-form">
        <?= csrf_field() ?>
        <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
        <?php if ($appointment): ?><input type="hidden" name="appointment_id" value="<?= e($appointment['id']) ?>"><?php endif; ?>
        <div class="form-grid">
            <label>Název <input required name="title" maxlength="255" value="<?= e($appointment['title'] ?? '') ?>" placeholder="Např. kontrola po nemoci"></label>
            <label>Termín <input required type="datetime-local" name="scheduled_at" value="<?= e(input_datetime($appointment['scheduled_at'] ?? null)) ?>"></label>
            <label>Typ kontroly
                <select name="appointment_type">
                    <?php foreach ($appointmentTypes as $type): ?>
                        <option value="<?= e($type) ?>" <?= $currentType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Stav
                <select name="status">
                    <?php foreach (['planned', 'completed', 'cancelled'] as $status): ?>
                        <option value="<?= e($status) ?>" <?= ($appointment['status'] ?? 'planned') === $status ? 'selected' : '' ?>><?= e(appointment_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wide-field">Lékař
                <select name="provider_id">
                    <option value="">Bez vazby na lékaře</option>
                    <?php foreach ($providerOptions as $providerId => $provider): ?>
                        <option value="<?= e($providerId) ?>" <?= (int)($appointment['provider_id'] ?? 0) === (int)$providerId ? 'selected' : '' ?>>
                            <?= e(trim(($provider['name'] ?? $provider['provider_name'] ?? 'Lékař') . ' · ' . (provider_specialty_label($provider) ?: 'bez oboru') . ' · ' . provider_address_label($provider), ' ·')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wide-field">Poznámka před kontrolou <textarea name="pre_note" rows="2"><?= e($appointment['pre_note'] ?? '') ?></textarea></label>
            <label class="wide-field">Výsledek kontroly <textarea name="result_note" rows="2"><?= e($appointment['result_note'] ?? '') ?></textarea></label>
            <label class="wide-field">Doporučení <textarea name="recommendation" rows="2"><?= e($appointment['recommendation'] ?? '') ?></textarea></label>
        </div>
        <?php if ($documents): ?>
            <fieldset class="checkbox-panel">
                <legend>Připojené dokumenty</legend>
                <?php foreach ($documents as $document): ?>
                    <label class="check">
                        <input type="checkbox" name="document_ids[]" value="<?= e($document['id']) ?>" <?= in_array((int)$document['id'], $selectedDocumentIds, true) ? 'checked' : '' ?>>
                        <?= e($document['title']) ?> <span class="muted"><?= e(document_type_label($document['document_type'] ?? 'general')) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <button class="button primary" type="submit"><?= $appointment ? 'Uložit změny kontroly' : 'Uložit kontrolu' ?></button>
    </form>
    <?php
}

function record_detail_label(array $record): string
{
    switch ($record['kind']) {
        case 'TEMPERATURE':
            return number_format((float)$record['temperature_celsius'], 1, ',', ' ') . ' °C';
        case 'MEDICATION':
            return medication_label([
                'name' => $record['medication_name'],
                'dosage_form' => $record['medication_dosage_form'] ?? null,
                'strength' => $record['medication_strength'] ?? null,
            ]);
        case 'CARE':
            if (($record['code'] ?? '') === 'SYMPTOMS' || !empty($record['symptoms'])) {
                return symptom_detail_label((string)($record['symptoms'] ?? ''), $record['symptom_severity'] ?? null);
            }
            return (string)$record['type_name'];
        default:
            return (string)$record['type_name'];
    }
}

function action_document_upload(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $title = trim((string)($_POST['title'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $providerId = (int)($_POST['provider_id'] ?? 0);
    $providerId = $providerId > 0 ? $providerId : null;
    $documentType = (string)($_POST['document_type'] ?? 'general');
    if (!array_key_exists($documentType, document_type_options())) {
        $documentType = 'general';
    }
    $isSensitive = $documentType === 'ehic' || (string)($_POST['is_sensitive'] ?? '') === '1';
    $file = $_FILES['document_file'] ?? null;

    try {
        if ($title === '') {
            throw new InvalidArgumentException('Vyplňte název dokumentu.');
        }
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(document_upload_error_message((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $sizeBytes = (int)($file['size'] ?? 0);
        if ($sizeBytes <= 0 || $sizeBytes > 25 * 1024 * 1024) {
            throw new InvalidArgumentException('Soubor musí mít velikost do 25 MB.');
        }

        $originalName = trim((string)($file['name'] ?? 'dokument'));
        $extension = text_lower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, document_allowed_upload_extensions(), true)) {
            throw new InvalidArgumentException('Povolené jsou soubory PDF, obrázky včetně HEIC/HEIF, DOC/DOCX a TXT.');
        }
        document_validate_upload_name($originalName);

        $mimeType = null;
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file((string)$file['tmp_name']) ?: null;
        }
        document_validate_upload_content((string)$file['tmp_name'], $extension, $mimeType);

        $uploadDir = document_upload_root();
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Úložiště dokumentů se nepodařilo připravit.');
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'dokument';
        $safeBase = trim($safeBase, '.-') ?: 'dokument';
        $storedName = date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '-' . substr($safeBase, 0, 80) . '.' . $extension;
        $storagePath = 'documents/' . date('Y/m') . '/' . $storedName;
        $targetDir = dirname(document_storage_path($storagePath));
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Cílovou složku dokumentů se nepodařilo připravit.');
        }
        [$storageMode, $encryptionAlgo] = store_uploaded_document((string)$file['tmp_name'], document_storage_path($storagePath));
        if ($storageMode === '') {
            throw new RuntimeException('Soubor se nepodařilo uložit.');
        }

        $documentId = create_child_document((int)$child['id'], (int)$user['id'], $title, $note, $providerId, $originalName, $storagePath, $mimeType, $sizeBytes, $documentType, $isSensitive, $storageMode, $encryptionAlgo);
        audit_log('child.document_uploaded', (int)$user['id'], (int)$family['id'], 'child_document', $documentId, ['child_id' => (int)$child['id'], 'provider_id' => $providerId, 'document_type' => $documentType]);
        flash('success', 'Dokument byl uložen.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    if (($_POST['return_to'] ?? '') === 'dashboard') {
        redirect('dashboard');
    }
    redirect('child', ['id' => $child['id'], 'documents' => 1, 'document_uploaded' => $documentType === 'ehic' ? 'ehic' : 'document']);
}

function action_document_view(): void
{
    $user = require_login();
    $document = child_document_for_user((int)($_GET['id'] ?? 0), (int)$user['id']);
    if (!$document) {
        page_not_found();
        return;
    }
    $path = document_storage_path((string)$document['storage_path']);
    if (!is_file($path)) {
        http_response_code(404);
        echo 'Soubor nebyl nalezen.';
        return;
    }
    audit_sensitive_document_view($document, (int)$user['id']);
    $mimeType = document_response_mime_type($document);
    $canPreview = document_can_inline_preview($document, $mimeType);
    $isPreviewImage = $canPreview && strpos($mimeType, 'image/') === 0;
    $isPreviewPdf = $canPreview && $mimeType === 'application/pdf';
    $inlineUrl = url('document_inline', ['id' => $document['id']]);
    $downloadUrl = url('document_download', ['id' => $document['id']]);
    render_layout($document['title'] ?: 'Dokument', function () use ($document, $mimeType, $isPreviewImage, $isPreviewPdf, $inlineUrl, $downloadUrl) {
        ?>
        <div class="page-head">
            <div>
                <h1><?= e($document['title'] ?: 'Dokument') ?></h1>
                <p class="muted"><?= e(document_type_label($document['document_type'] ?? 'general')) ?> · <?= e($document['original_filename']) ?></p>
            </div>
            <div class="actions">
                <a class="button" href="<?= e(url('child', ['id' => $document['child_id'], 'documents' => 1])) ?>">Zpět na dokumentaci</a>
                <a class="button" href="<?= e($inlineUrl) ?>" target="_blank" rel="noopener">Otevřít soubor</a>
                <a class="button" href="<?= e($downloadUrl) ?>">Stáhnout</a>
            </div>
        </div>
        <section class="panel document-viewer">
            <?php if ($isPreviewImage): ?>
                <img class="document-preview-image" src="<?= e($inlineUrl) ?>" alt="<?= e($document['title'] ?: 'Dokument') ?>" data-preview-fallback="document-preview-fallback">
                <div class="empty" id="document-preview-fallback" hidden>Náhled se v tomto prohlížeči nepodařilo zobrazit. Použijte otevření souboru nebo stažení.</div>
            <?php elseif ($isPreviewPdf): ?>
                <iframe class="document-preview-frame" src="<?= e($inlineUrl) ?>" title="<?= e($document['title'] ?: 'Dokument') ?>"></iframe>
                <div class="empty">Pokud je náhled PDF prázdný, použijte otevření souboru nebo stažení.</div>
            <?php else: ?>
                <div class="empty">Náhled tohoto formátu nemusí být v PWA podporovaný. Použijte otevření souboru nebo stažení.</div>
            <?php endif; ?>
            <?php if (!empty($document['note'])): ?>
                <p class="muted"><?= e($document['note']) ?></p>
            <?php endif; ?>
            <small class="muted"><?= e($mimeType) ?> · <?= e(file_size_label((int)$document['size_bytes'])) ?></small>
        </section>
        <?php
    });
}

function action_document_inline(): void
{
    $user = require_login();
    $document = child_document_for_user((int)($_GET['id'] ?? 0), (int)$user['id']);
    if (!$document) {
        page_not_found();
        return;
    }
    $path = document_storage_path((string)$document['storage_path']);
    if (!is_file($path)) {
        http_response_code(404);
        echo 'Soubor nebyl nalezen.';
        return;
    }
    audit_sensitive_document_view($document, (int)$user['id']);
    send_document_file($document, $path, 'inline');
}

function action_document_download(): void
{
    $user = require_login();
    $document = child_document_for_user((int)($_GET['id'] ?? 0), (int)$user['id']);
    if (!$document) {
        page_not_found();
        return;
    }
    $path = document_storage_path((string)$document['storage_path']);
    if (!is_file($path)) {
        http_response_code(404);
        echo 'Soubor nebyl nalezen.';
        return;
    }
    encrypt_document_file_at_rest_if_needed($document);

    send_document_file($document, $path, 'attachment');
}

function audit_sensitive_document_view(array $document, int $userId): void
{
    if (!empty($document['is_sensitive'])) {
        audit_log('child.document_viewed_sensitive', $userId, (int)$document['family_id'], 'child_document', (int)$document['id'], ['child_id' => (int)$document['child_id']]);
    }
}

function document_allowed_upload_extensions(): array
{
    return ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'doc', 'docx', 'txt'];
}

function document_allowed_mime_types(string $extension): array
{
    return [
        'pdf' => ['application/pdf', 'application/x-pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'heic' => ['image/heic', 'image/heic-sequence', 'image/heif', 'image/heif-sequence', 'application/octet-stream'],
        'heif' => ['image/heif', 'image/heif-sequence', 'image/heic', 'image/heic-sequence', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'txt' => ['text/plain'],
    ][$extension] ?? [];
}

function document_validate_upload_name(string $originalName): void
{
    if ($originalName === '' || preg_match('/[\x00-\x1F\x7F]/', $originalName)) {
        throw new InvalidArgumentException('Název souboru není platný.');
    }
    if (str_contains($originalName, '/') || str_contains($originalName, '\\') || str_contains($originalName, '..')) {
        throw new InvalidArgumentException('Název souboru nesmí obsahovat cestu.');
    }
    if (preg_match('/(^|\.)(php\d*|phtml|phar|cgi|pl|py|rb|asp|aspx|jsp|jspx|sh|bash|bat|cmd|exe|dll|msi|com|scr|jar|war)(\.|$)/i', $originalName)) {
        throw new InvalidArgumentException('Soubor má rizikovou nebo spustitelnou příponu.');
    }
}

function document_validate_upload_content(string $tmpPath, string $extension, ?string $mimeType): void
{
    $mimeType = strtolower(trim(explode(';', (string)$mimeType)[0]));
    if ($mimeType !== '' && !in_array($mimeType, document_allowed_mime_types($extension), true)) {
        throw new InvalidArgumentException('Typ souboru neodpovídá příponě.');
    }

    $prefix = file_get_contents($tmpPath, false, null, 0, 1024 * 1024);
    if ($prefix === false || $prefix === '') {
        throw new InvalidArgumentException('Soubor se nepodařilo zkontrolovat.');
    }
    $lowerPrefix = strtolower($prefix);
    if (document_contains_blocked_payload($lowerPrefix)) {
        throw new InvalidArgumentException('Soubor obsahuje aktivní nebo rizikový obsah.');
    }

    $isZip = str_starts_with($prefix, "PK\x03\x04") || str_starts_with($prefix, "PK\x05\x06") || str_starts_with($prefix, "PK\x07\x08");
    $checks = [
        'pdf' => fn(): bool => str_starts_with($prefix, '%PDF-') && !str_contains($lowerPrefix, '/javascript') && !preg_match('/\/js\b/', $lowerPrefix),
        'jpg' => fn(): bool => str_starts_with($prefix, "\xFF\xD8\xFF"),
        'jpeg' => fn(): bool => str_starts_with($prefix, "\xFF\xD8\xFF"),
        'png' => fn(): bool => str_starts_with($prefix, "\x89PNG\r\n\x1A\n"),
        'webp' => fn(): bool => str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WEBP',
        'gif' => fn(): bool => str_starts_with($prefix, 'GIF87a') || str_starts_with($prefix, 'GIF89a'),
        'heic' => fn(): bool => document_has_heif_brand($prefix),
        'heif' => fn(): bool => document_has_heif_brand($prefix),
        'doc' => fn(): bool => str_starts_with($prefix, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
        'docx' => fn(): bool => $isZip && document_docx_structure_is_valid($tmpPath),
        'txt' => fn(): bool => !str_contains($prefix, "\x00"),
    ];
    if (isset($checks[$extension]) && !$checks[$extension]()) {
        throw new InvalidArgumentException('Obsah souboru neodpovídá deklarovanému typu.');
    }
}

function document_contains_blocked_payload(string $lowerPrefix): bool
{
    foreach (['<?php', '<?= ', '<script', '<html', '<svg', 'javascript:', 'vbscript:', '<?xml-stylesheet', 'x5o!p%@ap[4\\pzx54(p^)7cc)7}$eicar'] as $needle) {
        if (str_contains($lowerPrefix, $needle)) {
            return true;
        }
    }
    return false;
}

function document_has_heif_brand(string $prefix): bool
{
    if (strlen($prefix) < 12 || substr($prefix, 4, 4) !== 'ftyp') {
        return false;
    }
    foreach (['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'] as $brand) {
        if (str_contains(substr($prefix, 8, 64), $brand)) {
            return true;
        }
    }
    return false;
}

function document_docx_structure_is_valid(string $tmpPath): bool
{
    if (!class_exists('ZipArchive')) {
        return true;
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        return false;
    }
    $valid = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false;
    $zip->close();
    return $valid;
}

function document_response_mime_type(array $document): string
{
    $mimeType = (string)($document['mime_type'] ?? '');
    if ($mimeType !== '') {
        return $mimeType;
    }
    $extension = text_lower((string)pathinfo((string)$document['original_filename'], PATHINFO_EXTENSION));
    return [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'txt' => 'text/plain; charset=utf-8',
    ][$extension] ?? 'application/octet-stream';
}

function document_can_inline_preview(array $document, string $mimeType): bool
{
    $extension = text_lower((string)pathinfo((string)$document['original_filename'], PATHINFO_EXTENSION));
    if ($mimeType === 'application/pdf') {
        return true;
    }
    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
        && in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
}

function send_document_file(array $document, string $path, string $disposition): void
{
    $mimeType = document_response_mime_type($document);
    if ($disposition === 'inline' && !document_can_inline_preview($document, $mimeType)) {
        $disposition = 'attachment';
        $mimeType = 'application/octet-stream';
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Soubor se nepodařilo načíst.');
    }
    $contents = document_decrypt_bytes($contents);
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($contents));
    header('Content-Disposition: ' . $disposition . '; filename="' . document_header_filename((string)$document['original_filename']) . '"');
    echo $contents;
    exit;
}

function document_header_filename(string $filename): string
{
    $filename = preg_replace('/[\r\n\x00-\x1F\x7F"\\\\]+/', '_', basename($filename)) ?? 'document';
    $filename = trim($filename, '._ ');
    return addcslashes($filename !== '' ? $filename : 'document', '"\\');
}

function action_document_delete(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $documentId = (int)($_POST['document_id'] ?? 0);
    $document = child_document_for_user($documentId, (int)$user['id']);
    if (!$document) {
        flash('error', 'Dokument se nepodařilo najít.');
        redirect('dashboard');
    }
    $path = document_storage_path((string)$document['storage_path']);
    if (!delete_document_storage_file($path)) {
        flash('error', 'Soubor dokumentu se nepodařilo smazat. Záznam zůstal zachovaný.');
        redirect('child', ['id' => (int)$document['child_id'], 'documents' => 1]);
    }

    $deleted = delete_child_document((int)$document['id'], (int)$document['child_id']);
    if ($deleted) {
        audit_log('child.document_deleted', (int)$user['id'], $family ? (int)$family['id'] : null, 'child_document', (int)$document['id'], ['child_id' => (int)$document['child_id']]);
        flash('success', 'Dokument byl smazán.');
    }
    if (($_POST['return_to'] ?? '') === 'dashboard') {
        redirect('dashboard');
    }
    redirect('child', ['id' => (int)$document['child_id'], 'documents' => 1]);
}

function delete_document_storage_file(string $path): bool
{
    if (!is_file($path)) {
        return true;
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return true;
        }
        if (@unlink($path)) {
            clearstatcache(true, $path);
            return !is_file($path);
        }
        usleep(50000);
    }
    clearstatcache(true, $path);
    return !is_file($path);
}

function action_appointment_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    $appointmentId = $appointmentId > 0 ? $appointmentId : null;

    try {
        $savedId = save_child_appointment((int)$child['id'], (int)$user['id'], $appointmentId, $_POST, $_POST['document_ids'] ?? []);
        audit_log($appointmentId ? 'child.appointment_updated' : 'child.appointment_created', (int)$user['id'], $family ? (int)$family['id'] : null, 'child_appointment', $savedId, ['child_id' => (int)$child['id']]);
        flash('success', $appointmentId ? 'Kontrola byla upravena.' : 'Kontrola byla uložena.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect('child', ['id' => (int)$child['id'], 'appointments' => 1]);
}

function action_appointment_delete(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $appointment = delete_child_appointment((int)($_POST['appointment_id'] ?? 0), (int)$child['id']);
    if ($appointment) {
        audit_log('child.appointment_deleted', (int)$user['id'], $family ? (int)$family['id'] : null, 'child_appointment', (int)$appointment['id'], ['child_id' => (int)$child['id']]);
        flash('success', 'Kontrola byla smazána.');
    } else {
        flash('error', 'Kontrola nebyla nalezena.');
    }

    redirect('child', ['id' => (int)$child['id'], 'appointments' => 1]);
}

function document_upload_error_message(int $error): string
{
    switch ($error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Soubor je příliš velký.';
        case UPLOAD_ERR_NO_FILE:
            return 'Vyberte soubor k nahrání.';
        default:
            return 'Soubor se nepodařilo nahrát.';
    }
}

function document_upload_root(): string
{
    return dirname(__DIR__) . '/var/uploads';
}

function document_storage_path(string $storagePath): string
{
    $normalized = str_replace(['\\', '..'], ['/', ''], $storagePath);
    return document_upload_root() . '/' . ltrim($normalized, '/');
}

function store_uploaded_document(string $tmpPath, string $targetPath): array
{
    if (document_encrypt_uploads()) {
        $contents = file_get_contents($tmpPath);
        if ($contents === false) {
            return ['', null];
        }
        $encrypted = document_encrypt_bytes($contents);
        if (file_put_contents($targetPath, $encrypted, LOCK_EX) === false) {
            return ['', null];
        }
        return ['encrypted', 'AES-256-GCM'];
    }
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['', null];
    }
    return ['plain', null];
}

function encrypt_plain_documents_for_child(int $childId): void
{
    if (!document_encrypt_uploads()) {
        return;
    }
    $stmt = db()->prepare("SELECT * FROM child_documents WHERE child_id = ? AND (storage_mode IS NULL OR storage_mode <> 'encrypted')");
    $stmt->execute([$childId]);
    foreach ($stmt->fetchAll() as $document) {
        encrypt_document_file_at_rest_if_needed($document);
    }
}

function encrypt_document_file_at_rest_if_needed(array $document): void
{
    if (!document_encrypt_uploads() || ($document['storage_mode'] ?? 'plain') === 'encrypted') {
        return;
    }
    $path = document_storage_path((string)$document['storage_path']);
    if (!is_file($path)) {
        return;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return;
    }
    if (document_bytes_are_encrypted($contents)) {
        db()->prepare("UPDATE child_documents SET storage_mode = 'encrypted', encryption_algo = 'AES-256-GCM' WHERE id = ?")->execute([(int)$document['id']]);
        return;
    }
    $encrypted = document_encrypt_bytes($contents);
    if (file_put_contents($path, $encrypted, LOCK_EX) === false) {
        throw new RuntimeException('Dokument se nepodařilo uložit v šifrované podobě.');
    }
    db()->prepare("UPDATE child_documents SET storage_mode = 'encrypted', encryption_algo = 'AES-256-GCM' WHERE id = ?")->execute([(int)$document['id']]);
}

function symptom_detail_label(string $symptoms, ?string $severity): string
{
    $severityLabels = [
        'mild' => 'lehké',
        'moderate' => 'střední',
        'high' => 'výrazné',
    ];
    $label = $symptoms !== '' ? $symptoms : 'Příznaky';
    if ($severity && isset($severityLabels[$severity])) {
        $label .= ' (' . $severityLabels[$severity] . ')';
    }
    return $label;
}

function redirect_after_child_record_save(int $childId): void
{
    if (($_POST['return_to'] ?? '') === 'dashboard') {
        redirect('dashboard');
    }
    redirect('child', ['id' => $childId]);
}

function action_child_delete(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $documents = child_documents((int)$child['id']);
    db()->prepare('DELETE FROM children WHERE id = ? AND family_id = ?')->execute([$child['id'], $family['id']]);
    foreach ($documents as $document) {
        $path = document_storage_path((string)$document['storage_path']);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    audit_log('child.deleted', (int)$user['id'], (int)$family['id'], 'child', (int)$child['id']);
    flash('success', 'Dítě a jeho záznamy byly smazány.');
    redirect('family');
}

function action_temperature_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $value = (float)str_replace(',', '.', (string)($_POST['temperature_celsius'] ?? ''));
    if ($value < 30 || $value > 45) {
        flash('error', 'Teplota musí být v rozsahu 30,0 až 45,0 °C.');
        redirect_after_child_record_save((int)$child['id']);
    }
    try {
        $eventAt = db_datetime($_POST['event_at'] ?? '');
        db()->beginTransaction();
        $recordId = $_POST['record_id'] ?? null;
        if ($recordId) {
            $record = record_for_user((int)$recordId, (int)$user['id']);
            if (!$record || $record['kind'] !== 'TEMPERATURE') {
                throw new RuntimeException('Záznam nelze upravit.');
            }
            db()->prepare('UPDATE health_records SET event_at = ?, place = ?, note = ?, updated_at = ? WHERE id = ?')->execute([$eventAt, trim($_POST['place'] ?? ''), trim($_POST['note'] ?? ''), now_sql(), $recordId]);
            db()->prepare('UPDATE temperature_records SET temperature_celsius = ? WHERE health_record_id = ?')->execute([$value, $recordId]);
            audit_log('record.updated', (int)$user['id'], (int)$family['id'], 'health_record', (int)$recordId, ['kind' => 'TEMPERATURE']);
        } else {
            $typeId = record_type_id((int)$family['id'], 'TEMPERATURE');
            db()->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, place, note) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$child['id'], $typeId, $eventAt, $user['id'], trim($_POST['place'] ?? ''), trim($_POST['note'] ?? '')]);
            $newRecordId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO temperature_records (health_record_id, temperature_celsius) VALUES (?, ?)')
                ->execute([$newRecordId, $value]);
            audit_log('record.created', (int)$user['id'], (int)$family['id'], 'health_record', $newRecordId, ['kind' => 'TEMPERATURE', 'child_id' => (int)$child['id']]);
        }
        db()->commit();
        flash('success', 'Teplota byla uložena.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $e->getMessage());
    }
    redirect_after_child_record_save((int)$child['id']);
}

function action_medication_record_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $medicationId = (int)($_POST['medication_id'] ?? 0);
    try {
        if (!medication_belongs_to_family((int)$family['id'], $medicationId)) {
            throw new InvalidArgumentException('Vybraný lék nepatří do této rodiny.');
        }
        $eventAt = db_datetime($_POST['event_at'] ?? '');
        db()->beginTransaction();
        $recordId = $_POST['record_id'] ?? null;
        if ($recordId) {
            $record = record_for_user((int)$recordId, (int)$user['id']);
            if (!$record || $record['kind'] !== 'MEDICATION') {
                throw new RuntimeException('Záznam nelze upravit.');
            }
            db()->prepare('UPDATE health_records SET event_at = ?, note = ?, updated_at = ? WHERE id = ?')->execute([$eventAt, trim($_POST['note'] ?? ''), now_sql(), $recordId]);
            db()->prepare('UPDATE medication_administrations SET medication_id = ? WHERE health_record_id = ?')->execute([$medicationId, $recordId]);
            audit_log('record.updated', (int)$user['id'], (int)$family['id'], 'health_record', (int)$recordId, ['kind' => 'MEDICATION', 'medication_id' => $medicationId]);
        } else {
            $typeId = record_type_id((int)$family['id'], 'MEDICATION');
            db()->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, note) VALUES (?, ?, ?, ?, ?)')
                ->execute([$child['id'], $typeId, $eventAt, $user['id'], trim($_POST['note'] ?? '')]);
            $newRecordId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO medication_administrations (health_record_id, medication_id) VALUES (?, ?)')
                ->execute([$newRecordId, $medicationId]);
            audit_log('record.created', (int)$user['id'], (int)$family['id'], 'health_record', $newRecordId, ['kind' => 'MEDICATION', 'child_id' => (int)$child['id'], 'medication_id' => $medicationId]);
        }
        db()->commit();
        flash('success', 'Podání léku bylo uloženo.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $e->getMessage());
    }
    redirect('child', ['id' => $child['id']]);
}

function action_symptom_record_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $allowed = symptom_options();
    $symptoms = array_values(array_intersect($allowed, array_map('trim', $_POST['symptoms'] ?? [])));
    $severity = (string)($_POST['severity'] ?? 'mild');
    if (!in_array($severity, ['mild', 'moderate', 'high'], true)) {
        $severity = 'mild';
    }
    if (!$symptoms) {
        flash('error', 'Vyberte alespoň jeden příznak.');
        redirect('child', ['id' => $child['id']]);
    }

    try {
        $eventAt = db_datetime($_POST['event_at'] ?? '');
        $recordId = $_POST['record_id'] ?? null;
        db()->beginTransaction();
        if ($recordId) {
            $record = record_for_user((int)$recordId, (int)$user['id']);
            if (!$record || ($record['code'] ?? '') !== 'SYMPTOMS') {
                throw new RuntimeException('Záznam nelze upravit.');
            }
            db()->prepare('UPDATE health_records SET event_at = ?, note = ?, updated_at = ? WHERE id = ?')
                ->execute([$eventAt, trim($_POST['note'] ?? ''), now_sql(), $recordId]);
            db()->prepare('UPDATE symptom_records SET symptoms = ?, severity = ? WHERE health_record_id = ?')
                ->execute([implode(', ', $symptoms), $severity, $recordId]);
            audit_log('record.updated', (int)$user['id'], (int)$family['id'], 'health_record', (int)$recordId, ['kind' => 'SYMPTOMS']);
        } else {
            $typeId = record_type_id((int)$family['id'], 'SYMPTOMS');
            db()->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, note) VALUES (?, ?, ?, ?, ?)')
                ->execute([$child['id'], $typeId, $eventAt, $user['id'], trim($_POST['note'] ?? '')]);
            $newRecordId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO symptom_records (health_record_id, symptoms, severity) VALUES (?, ?, ?)')
                ->execute([$newRecordId, implode(', ', $symptoms), $severity]);
            audit_log('record.created', (int)$user['id'], (int)$family['id'], 'health_record', $newRecordId, ['kind' => 'SYMPTOMS', 'child_id' => (int)$child['id']]);
        }
        db()->commit();
        flash('success', 'Příznaky byly uloženy.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        flash('error', $e->getMessage());
    }
    redirect('child', ['id' => $child['id']]);
}

function action_care_record_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    try {
        $recordTypeId = (int)($_POST['record_type_id'] ?? 0);
        if (!record_type_belongs_to_family((int)$family['id'], $recordTypeId, 'CARE')) {
            throw new InvalidArgumentException('Vybraný typ péče nepatří do této rodiny.');
        }
        $eventAt = db_datetime($_POST['event_at'] ?? '');
        $recordId = $_POST['record_id'] ?? null;
        if ($recordId) {
            $record = record_for_user((int)$recordId, (int)$user['id']);
            if (!$record || $record['kind'] !== 'CARE') {
                throw new RuntimeException('Záznam nelze upravit.');
            }
            db()->prepare('UPDATE health_records SET record_type_id = ?, event_at = ?, note = ?, updated_at = ? WHERE id = ?')
                ->execute([$recordTypeId, $eventAt, trim($_POST['note'] ?? ''), now_sql(), $recordId]);
            audit_log('record.updated', (int)$user['id'], (int)$family['id'], 'health_record', (int)$recordId, ['kind' => 'CARE']);
        } else {
            db()->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, note) VALUES (?, ?, ?, ?, ?)')
                ->execute([$child['id'], $recordTypeId, $eventAt, $user['id'], trim($_POST['note'] ?? '')]);
            audit_log('record.created', (int)$user['id'], (int)$family['id'], 'health_record', (int)db()->lastInsertId(), ['kind' => 'CARE', 'child_id' => (int)$child['id']]);
        }
        flash('success', 'Záznam péče byl uložen.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('child', ['id' => $child['id']]);
}

function page_record_edit(): void
{
    $user = require_login();
    $record = record_for_user((int)($_GET['id'] ?? 0), (int)$user['id']);
    if (!$record) {
        page_not_found();
        return;
    }
    $family = current_family((int)$user['id']);
    $meds = medications((int)$family['id'], false);
    $careTypes = custom_care_types((int)$family['id']);
    render_layout('Upravit záznam', function () use ($record, $meds, $careTypes) {
        switch ($record['kind']) {
            case 'TEMPERATURE':
                $action = 'temperature_save';
                break;
            case 'MEDICATION':
                $action = 'medication_record_save';
                break;
            default:
                $action = ($record['code'] ?? '') === 'SYMPTOMS' ? 'symptom_record_save' : 'care_record_save';
        }
        $selectedSymptoms = array_map('trim', explode(',', (string)($record['symptoms'] ?? '')));
        ?>
        <section class="panel narrow">
            <h1>Upravit záznam</h1>
            <form method="post" action="<?= e(url($action)) ?>" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="record_id" value="<?= e($record['id']) ?>">
                <input type="hidden" name="child_id" value="<?= e($record['child_id']) ?>">
                <?php if ($record['kind'] === 'TEMPERATURE'): ?>
                    <label>Hodnota °C <input required type="number" step="0.1" min="30" max="45" name="temperature_celsius" value="<?= e($record['temperature_celsius']) ?>"></label>
                    <label>Místo <input name="place" value="<?= e($record['place']) ?>"></label>
                <?php elseif ($record['kind'] === 'MEDICATION'): ?>
                    <label>Lék <select required name="medication_id"><?php foreach ($meds as $med): ?><option value="<?= e($med['id']) ?>" <?= (int)$record['medication_id'] === (int)$med['id'] ? 'selected' : '' ?>><?= e($med['name']) ?></option><?php endforeach; ?></select></label>
                <?php elseif (($record['code'] ?? '') === 'SYMPTOMS'): ?>
                    <div class="symptom-grid">
                        <?php foreach (symptom_options() as $symptom): ?>
                            <label class="check"><input type="checkbox" name="symptoms[]" value="<?= e($symptom) ?>" <?= in_array($symptom, $selectedSymptoms, true) ? 'checked' : '' ?>> <?= e($symptom) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <label>Závažnost
                        <select name="severity">
                            <?php foreach (['mild' => 'Lehké', 'moderate' => 'Střední', 'high' => 'Výrazné'] as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= ($record['symptom_severity'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <label>Typ <select required name="record_type_id"><?php foreach ($careTypes as $type): ?><option value="<?= e($type['id']) ?>" <?= (int)$record['record_type_id'] === (int)$type['id'] ? 'selected' : '' ?>><?= e($type['name']) ?></option><?php endforeach; ?></select></label>
                <?php endif; ?>
                <label>Čas <input required type="datetime-local" name="event_at" value="<?= e(input_datetime($record['event_at'])) ?>"></label>
                <label>Poznámka <textarea name="note" rows="3"><?= e($record['note']) ?></textarea></label>
                <button class="button primary" type="submit">Uložit změny</button>
            </form>
        </section>
        <?php
    });
}

function action_record_delete(): void
{
    $user = require_login();
    $record = record_for_user((int)($_POST['record_id'] ?? 0), (int)$user['id']);
    if (!$record) {
        page_not_found();
        return;
    }
    db()->prepare('DELETE FROM health_records WHERE id = ?')->execute([$record['id']]);
    audit_log('record.deleted', (int)$user['id'], (int)$record['family_id'], 'health_record', (int)$record['id'], ['kind' => $record['kind']]);
    flash('success', 'Záznam byl smazán.');
    redirect('child', ['id' => $record['child_id']]);
}

function page_medications(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $items = medications((int)$family['id'], true);
    $query = trim((string)($_GET['q'] ?? ''));
    $catalogResults = $query !== '' ? sukl_drug_catalog_search($query, 30) : [];
    render_layout('Léčiva', function () use ($items, $query, $catalogResults) {
        ?>
        <div class="page-head"><h1>Léčiva</h1></div>
        <section class="panel stack">
            <h2>Vyhledat v katalogu SÚKL</h2>
            <form method="get" class="inline-form">
                <input type="hidden" name="r" value="medications">
                <input name="q" value="<?= e($query) ?>" placeholder="Název léku, účinná látka nebo SÚKL kód">
                <button class="button primary" type="submit">Vyhledat</button>
            </form>
            <?php if ($query !== '' && text_length($query) < 2): ?>
                <p class="muted">Zadejte alespoň 2 znaky.</p>
            <?php elseif ($query !== '' && !$catalogResults): ?>
                <div class="empty">V katalogu SÚKL jsem nenašel odpovídající léčivo.</div>
            <?php elseif ($catalogResults): ?>
                <div class="table-wrap">
                    <table class="compact-table">
                        <thead><tr><th>Léčivo</th><th>Účinná látka</th><th>Výdej</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($catalogResults as $drug): ?>
                            <tr>
                                <td>
                                    <strong><?= e(sukl_drug_label($drug)) ?></strong>
                                    <small><?= e($drug['sukl_code']) ?><?= !empty($drug['atc_code']) ? ' · ' . e($drug['atc_code']) : '' ?></small>
                                    <a href="<?= e($drug['sukl_detail_url']) ?>" target="_blank" rel="noopener">Detail na SÚKL</a>
                                </td>
                                <td><?= e($drug['active_substances'] ?? '') ?></td>
                                <td><?= e($drug['dispensing_name'] ?? '') ?></td>
                                <td class="actions">
                                    <form method="post" action="<?= e(url('sukl_medication_add')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="sukl_code" value="<?= e($drug['sukl_code']) ?>">
                                        <input type="hidden" name="q" value="<?= e($query) ?>">
                                        <button class="button tiny primary" type="submit">Přidat do mých léčiv</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel stack">
            <h2>Ručně přidat léčivo</h2>
            <form method="post" action="<?= e(url('medication_save')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input required name="name" placeholder="Název léku">
                <button class="button primary" type="submit">Přidat</button>
            </form>
        </section>

        <section class="panel">
            <h2>Moje léčiva</h2>
            <div class="list">
                <?php foreach ($items as $item): ?>
                    <form method="post" action="<?= e(url('medication_remove')) ?>" class="list-row medication-row" data-confirm="Odstranit lék ze seznamu? Historické záznamy zůstanou zachované.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                        <span>
                            <strong><?= e(medication_label($item)) ?></strong>
                            <?php if (!empty($item['dosing_info'])): ?>
                                <small><?= e($item['dosing_info']) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($item['source_url'])): ?>
                                <a href="<?= e($item['source_url']) ?>" target="_blank" rel="noopener">Příbalová informace na SÚKL</a>
                            <?php endif; ?>
                        </span>
                        <button class="button tiny danger" type="submit">Odstranit</button>
                    </form>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                    <div class="empty">Zatím nemáte v seznamu žádné léčivo.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }, 'medications');
}

function action_medication_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        db()->prepare('INSERT INTO medications (family_id, name) VALUES (?, ?)')->execute([$family['id'], $name]);
        audit_log('medication.created', (int)$user['id'], (int)$family['id'], 'medication', (int)db()->lastInsertId());
        flash('success', 'Lék byl přidán.');
    }
    redirect('medications');
}

function action_sukl_medication_add(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $code = trim((string)($_POST['sukl_code'] ?? ''));
    $query = trim((string)($_POST['q'] ?? ''));
    $redirectParams = $query !== '' ? ['q' => $query] : [];
    if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code)) {
        flash('error', 'Vybrané léčivo není platné.');
        redirect('medications', $redirectParams);
    }
    $medicationId = add_sukl_drug_to_family_medications((int)$family['id'], $code);
    if ($medicationId === null) {
        flash('error', 'Léčivo se v katalogu SÚKL nepodařilo najít.');
    } else {
        audit_log('medication.sukl_added', (int)$user['id'], (int)$family['id'], 'medication', $medicationId, ['sukl_code' => $code]);
        flash('success', 'Léčivo bylo přidáno do vašeho seznamu.');
    }
    redirect('medications', $redirectParams);
}

function action_medication_remove(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $medicationId = (int)($_POST['id'] ?? 0);
    if ($medicationId <= 0) {
        flash('error', 'Lék se nepodařilo najít.');
        redirect('medications');
    }
    $removed = remove_family_medication((int)$family['id'], $medicationId);
    if ($removed) {
        audit_log('medication.removed', (int)$user['id'], (int)$family['id'], 'medication', $medicationId);
        flash('success', 'Lék byl odstraněn ze seznamu.');
    } else {
        flash('error', 'Lék se nepodařilo odstranit.');
    }
    redirect('medications');
}

function action_medication_toggle(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    db()->prepare('UPDATE medications SET is_active = 1 - is_active WHERE id = ? AND family_id = ? AND system_key IS NULL')->execute([(int)$_POST['id'], $family['id']]);
    audit_log('medication.toggled', (int)$user['id'], (int)$family['id'], 'medication', (int)$_POST['id']);
    redirect('medications');
}

function page_care_types(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $items = custom_care_types((int)$family['id'], false);
    render_layout('Typy péče', function () use ($items) {
        ?>
        <div class="page-head">
            <div>
                <h1>Typy péče</h1>
                <p class="muted">Vlastní seznam typů péče, které chcete u dětí zapisovat.</p>
            </div>
        </div>
        <section class="panel">
            <form method="post" action="<?= e(url('care_type_save')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input required name="name" placeholder="Například koupel, inhalace, odpočinek">
                <button class="button primary" type="submit">Přidat typ péče</button>
            </form>
            <?php if (!$items): ?>
                <div class="empty">Zatím tu není žádný typ péče. Přidejte si vlastní položky podle toho, co chcete sledovat.</div>
            <?php else: ?>
                <div class="list">
                    <?php foreach ($items as $item): ?>
                        <div class="list-row">
                            <span><?= e($item['name']) ?></span>
                            <small><?= empty($item['is_active']) ? 'neaktivní' : 'aktivní' ?></small>
                            <div class="actions">
                                <form method="post" action="<?= e(url('care_type_toggle')) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                                    <button class="button tiny" type="submit"><?= !empty($item['is_active']) ? 'Deaktivovat' : 'Aktivovat' ?></button>
                                </form>
                                <form method="post" action="<?= e(url('care_type_delete')) ?>" data-confirm="Smazat typ péče?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                                    <button class="button tiny danger" type="submit">Smazat</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }, 'care_types');
}

function action_care_type_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        $code = 'CARE_' . strtoupper(bin2hex(random_bytes(4)));
        db()->prepare('INSERT INTO record_types (family_id, code, name, kind, is_system, is_quick, sort_order) VALUES (?, ?, ?, ?, 0, 0, 100)')
            ->execute([$family['id'], $code, $name, 'CARE']);
        audit_log('care_type.created', (int)$user['id'], (int)$family['id'], 'record_type', (int)db()->lastInsertId());
    }
    redirect('care_types');
}

function action_care_type_toggle(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    db()->prepare('UPDATE record_types SET is_active = 1 - is_active WHERE id = ? AND family_id = ? AND is_system = 0')->execute([(int)$_POST['id'], $family['id']]);
    audit_log('care_type.toggled', (int)$user['id'], (int)$family['id'], 'record_type', (int)$_POST['id']);
    redirect('care_types');
}

function action_care_type_delete(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $id = (int)($_POST['id'] ?? 0);
    try {
        $stmt = db()->prepare('DELETE FROM record_types WHERE id = ? AND family_id = ? AND is_system = 0');
        $stmt->execute([$id, $family['id']]);
        if ($stmt->rowCount() > 0) {
            audit_log('care_type.deleted', (int)$user['id'], (int)$family['id'], 'record_type', $id);
            flash('success', 'Typ péče byl smazán.');
        } else {
            flash('error', 'Typ péče se nepodařilo najít.');
        }
    } catch (Throwable $e) {
        flash('error', 'Typ péče nejde smazat, protože už je použitý u záznamu. Můžete ho deaktivovat.');
    }
    redirect('care_types');
}

function page_family(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $members = family_members((int)$family['id']);
    $pendingInvitations = pending_family_invitations((int)$family['id']);
    $children = children_for_user((int)$user['id']);
    $isOwner = ($family['role'] ?? '') === 'OWNER';

    render_layout('Správa rodiny', function () use ($family, $members, $pendingInvitations, $children, $isOwner) {
        ?>
        <div class="page-head">
            <div>
                <h1>Správa rodiny</h1>
                <p class="muted">Děti, přístupy rodičů a nastavení rodiny na jednom místě.</p>
            </div>
        </div>

        <section class="panel">
            <h2>Děti</h2>
            <?php if (!$children): ?>
                <div class="empty">Zatím tu není žádné dítě. Administrátor rodiny ho může přidat níže.</div>
            <?php else: ?>
                <div class="admin-list">
                    <?php foreach ($children as $child): ?>
                        <div class="admin-item">
                            <div class="admin-row">
                                <div>
                                    <strong><?= e($child['first_name'] . ' ' . $child['last_name']) ?></strong>
                                    <small>
                                        narození <?= e(date('d.m.Y', strtotime($child['date_of_birth']))) ?>,
                                        věk <?= e(child_age_label($child['date_of_birth'])) ?>
                                    </small>
                                </div>
                                <div>
                                    <span class="muted">Váha</span>
                                    <strong><?= e(child_weight_label($child['weight_kg'] ?? null)) ?></strong>
                                </div>
                                <div>
                                    <span class="muted">Alergie</span>
                                    <strong><?= e(($child['allergies'] ?? '') !== '' ? $child['allergies'] : '-') ?></strong>
                                </div>
                                <div class="actions">
                                    <a class="button tiny" href="<?= e(url('child', ['id' => $child['id']])) ?>">Detail</a>
                                    <?php if ($isOwner): ?><a class="button tiny" href="#child-edit-<?= e($child['id']) ?>">Upravit</a><?php endif; ?>
                                    <a class="button tiny" href="<?= e(url('child', ['id' => $child['id'], 'documents' => 1])) ?>">Dokumenty</a>
                                    <a class="button tiny" href="<?= e(url('child_doctors', ['child_id' => $child['id']])) ?>">Lékaři</a>
                                    <a class="button tiny" href="#family-access">Přístupy</a>
                                    <?php if ($isOwner): ?>
                                        <form method="post" action="<?= e(url('child_delete')) ?>" data-confirm="Smazání dítěte odstraní i všechny zdravotní záznamy a dokumenty.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                                            <button class="button tiny danger" type="submit">Smazat</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($isOwner): ?>
                                <form id="child-edit-<?= e($child['id']) ?>" method="post" action="<?= e(url('child_profile_save')) ?>" class="child-inline-edit form-grid">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                                    <input type="hidden" name="return_to" value="family">
                                    <label>Jméno <input required name="first_name" value="<?= e($child['first_name']) ?>"></label>
                                    <label>Příjmení <input required name="last_name" value="<?= e($child['last_name']) ?>"></label>
                                    <label>Datum narození <input required type="date" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>" value="<?= e($child['date_of_birth']) ?>"></label>
                                    <label>Váha kg <input type="number" step="0.1" min="0" max="200" name="weight_kg" value="<?= e($child['weight_kg'] ?? '') ?>" inputmode="decimal"></label>
                                    <label class="wide-field">Alergie <textarea name="allergies" rows="2"><?= e($child['allergies'] ?? '') ?></textarea></label>
                                    <button class="button primary" type="submit">Uložit změny</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($family && $isOwner): ?>
            <section class="panel">
                <h2>Přidat dítě</h2>
                <form method="post" action="<?= e(url('child_create')) ?>" class="form-grid">
                    <?= csrf_field() ?>
                    <label>Jméno <input required name="first_name"></label>
                    <label>Příjmení <input required name="last_name"></label>
                    <label>Datum narození <input required type="date" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>"></label>
                    <label>Váha kg <input type="number" step="0.1" min="0" max="200" name="weight_kg" inputmode="decimal"></label>
                    <label class="wide-field">Alergie <textarea name="allergies" rows="2" placeholder="Například penicilin, ořechy, pyl"></textarea></label>
                    <button class="button primary" type="submit">Přidat dítě</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Nastavení rodiny</h2>
            <?php if ($family): ?>
                <form method="post" action="<?= e(url('family_save')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input required name="name" value="<?= e($family['name']) ?>">
                    <button class="button primary" type="submit">Uložit</button>
                </form>
            <?php else: ?>
                <p><?= e($family['name']) ?></p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Rodiče</h2>
            <?php if ($isOwner): ?>
                <form method="post" action="<?= e(url('member_add')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input required type="email" name="email" placeholder="E-mail existujícího účtu">
                    <button class="button primary" type="submit">Přidat rodiče</button>
                </form>
            <?php endif; ?>
            <div class="list">
                <?php foreach ($members as $member): ?>
                    <div class="list-row">
                        <span><?= e($member['display_name']) ?> <small><?= e($member['email']) ?></small></span>
                        <small><?= e(role_label($member['role'])) ?></small>
                        <?php if ($isOwner && $member['role'] !== 'OWNER'): ?>
                            <form method="post" action="<?= e(url('member_remove')) ?>" data-confirm="Odebrat rodiče z rodiny?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= e($member['user_id']) ?>">
                                <button class="button tiny danger" type="submit">Odebrat</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($isOwner && $pendingInvitations): ?>
                <div class="subsection">
                    <h3>Odeslané pozvánky</h3>
                    <div class="list">
                        <?php foreach ($pendingInvitations as $invitation): ?>
                            <div class="list-row invitation-row">
                                <span>
                                    <?= e($invitation['invited_email']) ?>
                                    <small>
                                        Odesláno <?= e(display_datetime($invitation['created_at'])) ?>
                                        <?php if (!empty($invitation['registered_at'])): ?>
                                            · uživatel se registroval
                                        <?php elseif (!empty($invitation['registered_user_id'])): ?>
                                            · účet už existuje
                                        <?php endif; ?>
                                    </small>
                                </span>
                                <small>Pozval <?= e($invitation['inviter_name']) ?></small>
                                <div class="actions">
                                    <?php if (!empty($invitation['registered_user_id'])): ?>
                                        <form method="post" action="<?= e(url('invitation_accept_registered')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="invitation_id" value="<?= e($invitation['id']) ?>">
                                            <button class="button tiny primary" type="submit">Přidat do rodiny</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e(url('invitation_cancel')) ?>" data-confirm="Zrušit tuto pozvánku?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="invitation_id" value="<?= e($invitation['id']) ?>">
                                        <button class="button tiny danger" type="submit">Zrušit</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($family && $isOwner): ?>
            <section class="panel" id="family-access">
                <h2>Přístupy k dětem</h2>
                <?php foreach ($children as $child): ?>
                    <h3><?= e($child['first_name'] . ' ' . $child['last_name']) ?></h3>
                    <form method="post" action="<?= e(url('access_save')) ?>" class="access-grid">
                        <?= csrf_field() ?>
                        <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                        <?php $access = array_column(child_access_rows((int)$child['id']), null, 'user_id'); ?>
                        <?php foreach ($members as $member): ?>
                            <label class="check">
                                <input type="checkbox" name="user_ids[]" value="<?= e($member['user_id']) ?>" <?= isset($access[$member['user_id']]) ? 'checked' : '' ?> <?= $member['role'] === 'OWNER' ? 'disabled checked' : '' ?>>
                                <?= e($member['display_name']) ?>
                            </label>
                        <?php endforeach; ?>
                        <button class="button" type="submit">Uložit přístupy</button>
                    </form>
                <?php endforeach; ?>
            </section>
        <?php elseif ($family): ?>
            <section class="panel" id="family-access">
                <h2>Přístupy k dětem</h2>
                <p class="muted">Přístupy k dětem spravuje administrátor rodiny.</p>
            </section>
        <?php endif; ?>
        <?php if ($isOwner): ?>
            <section class="panel danger-zone">
                <h2>Zrušit rodinu</h2>
                <p class="muted">Zrušení rodiny smaže děti, zdravotní záznamy, číselníky a rodičovské role v této rodině. Uživatelské účty zůstanou zachované.</p>
                <form method="post" action="<?= e(url('family_delete')) ?>" data-confirm="Opravdu zrušit celou rodinu včetně všech záznamů?">
                    <?= csrf_field() ?>
                    <button class="button danger" type="submit">Zrušit rodinu</button>
                </form>
            </section>
        <?php endif; ?>
        <?php
    }, 'family');
}

function action_family_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    db()->prepare('UPDATE families SET name = ?, updated_at = ? WHERE id = ?')->execute([trim($_POST['name'] ?? $family['name']), now_sql(), $family['id']]);
    audit_log('family.updated', (int)$user['id'], (int)$family['id'], 'family', (int)$family['id']);
    flash('success', 'Rodina byla uložena.');
    redirect('family');
}

function action_family_delete(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);

    $documentPaths = child_document_storage_paths_for_family((int)$family['id']);
    db()->prepare('DELETE FROM families WHERE id = ? AND owner_user_id = ?')->execute([$family['id'], $user['id']]);
    foreach ($documentPaths as $storagePath) {
        $path = document_storage_path($storagePath);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    audit_log('family.deleted', (int)$user['id'], (int)$family['id'], 'family', (int)$family['id']);
    flash('success', 'Rodina byla zrušena. Uživatelské účty zůstaly zachované.');
    redirect('dashboard');
}

function action_member_add(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);
    $email = text_lower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Zadejte platný e-mail.');
        redirect('family');
    }

    $newUser = find_user_by_email($email);
    if (!$newUser || !user_email_is_verified($newUser)) {
        if (pending_family_invitation_by_email((int)$family['id'], $email)) {
            if ($newUser && !user_email_is_verified($newUser)) {
                send_email_verification_message((int)$newUser['id'], (string)$newUser['email'], (string)$newUser['display_name']);
                flash('success', 'Pozvánka už čeká. Registrovanému uživateli jsme poslali nový ověřovací odkaz.');
            } else {
                flash('error', 'Na tento e-mail už čeká pozvánka. Pokud ji chcete poslat znovu, nejdřív ji zrušte.');
            }
            redirect('family');
        }

        create_family_invitation((int)$family['id'], (int)$user['id'], $email);
        if ($newUser && !user_email_is_verified($newUser)) {
            send_email_verification_message((int)$newUser['id'], (string)$newUser['email'], (string)$newUser['display_name']);
        } else {
            $registerUrl = app_base_url() . '/?r=register&email=' . urlencode($email);
            $loginUrl = app_base_url() . '/?r=login';
            send_app_email(
                $email,
                'Pozvánka do rodiny v aplikaci Zdraví dětí',
                "Dobrý den,\n\n{$user['display_name']} vás zve do rodiny {$family['name']} v aplikaci Zdraví dětí.\n\nPokud ještě nemáte účet, zaregistrujte se zde:\n{$registerUrl}\n\nPokud účet máte nebo chcete použít Google přihlášení, přihlaste se zde:\n{$loginUrl}\n\nPo ověření e-mailu budete přidáni do pozvané rodiny."
            );
        }
        audit_log('family.invitation_created', (int)$user['id'], (int)$family['id'], 'family_invitation', null, ['email_hash' => hash('sha256', $email)]);
        flash('success', 'Pozvánka byla odeslána e-mailem. Lokální kopie je ve var/mail.log.');
        redirect('family');
    }

    add_user_to_family((int)$family['id'], (int)$newUser['id']);
    audit_log('family.member_added', (int)$user['id'], (int)$family['id'], 'user', (int)$newUser['id']);
    send_app_email(
        $newUser['email'],
        'Byli jste přidáni do rodiny',
        "Dobrý den,\n\nbyli jste přidáni do rodiny {$family['name']} v aplikaci Zdraví dětí.\n\nPřihlášení:\n" . app_base_url() . '/?r=login'
    );
    flash('success', 'Rodič byl přidán do rodiny a dostal potvrzení e-mailem.');
    redirect('family');
}

function action_member_add_legacy(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);
    $email = text_lower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Zadejte platný e-mail.');
        redirect('family');
    }
    $newUser = find_user_by_email($email);
    if (!$newUser) {
        if (pending_family_invitation_by_email((int)$family['id'], $email)) {
            flash('error', 'Na tento e-mail už čeká pozvánka. Pokud ji chcete poslat znovu, nejdřív ji zrušte.');
            redirect('family');
        }
        $invitation = create_family_invitation((int)$family['id'], (int)$user['id'], $email);
        $registerUrl = app_base_url() . '/?r=register&email=' . urlencode($email);
        $loginUrl = app_base_url() . '/?r=login';
        send_app_email(
            $email,
            'Pozvánka do rodiny v aplikaci Zdraví dětí',
            "Dobrý den,\n\n{$user['display_name']} vás zve do rodiny {$family['name']} v aplikaci Zdraví dětí.\n\nPokud ještě nemáte účet, zaregistrujte se zde:\n{$registerUrl}\n\nPokud účet máte nebo chcete použít Google přihlášení, přihlaste se zde:\n{$loginUrl}\n\nPo registraci nebo Google přihlášení budete automaticky přidáni do pozvané rodiny."
        );
        audit_log('family.invitation_created', (int)$user['id'], (int)$family['id'], 'family_invitation', null, ['email_hash' => hash('sha256', $email)]);
        flash('success', 'Pozvánka byla odeslána e-mailem. Lokálně ji najdete také ve var/mail.log.');
        redirect('family');
    }

    add_user_to_family((int)$family['id'], (int)$newUser['id']);
    audit_log('family.member_added', (int)$user['id'], (int)$family['id'], 'user', (int)$newUser['id']);
    send_app_email(
        $newUser['email'],
        'Byli jste přidáni do rodiny',
        "Dobrý den,\n\nbyli jste přidáni do rodiny {$family['name']} v aplikaci Zdraví dětí.\n\nPřihlášení:\n" . app_base_url() . '/?r=login'
    );
    flash('success', 'Rodič byl přidán do rodiny a dostal potvrzení e-mailem.');
    redirect('family');
}

function action_invitation_cancel(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);

    $invitation = cancel_family_invitation((int)$family['id'], (int)($_POST['invitation_id'] ?? 0));
    if (!$invitation) {
        flash('error', 'Pozvánku se nepodařilo najít nebo už není aktivní.');
        redirect('family');
    }

    audit_log('family.invitation_cancelled', (int)$user['id'], (int)$family['id'], 'family_invitation', (int)$invitation['id'], [
        'email_hash' => hash('sha256', (string)$invitation['invited_email']),
    ]);
    flash('success', 'Pozvánka byla zrušena. Na stejný e-mail můžete poslat novou.');
    redirect('family');
}

function action_invitation_accept_registered(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);

    $accepted = accept_registered_family_invitation((int)$family['id'], (int)($_POST['invitation_id'] ?? 0));
    if (!$accepted) {
        flash('error', 'Pozvaný uživatel zatím nemá aktivní účet nebo pozvánka už není aktivní.');
        redirect('family');
    }

    audit_log('family.member_added_from_invitation', (int)$user['id'], (int)$family['id'], 'user', (int)$accepted['user']['id']);
    flash('success', 'Rodič byl přidán do rodiny. Přístupy k dětem mu nastavíte níže.');
    redirect('family');
}

function action_member_remove(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);
    $removedUserId = (int)($_POST['user_id'] ?? 0);
    if ($removedUserId === (int)$family['owner_user_id']) {
        flash('error', 'Administrátora rodiny nelze odebrat.');
        redirect('family');
    }
    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM child_access WHERE user_id = ? AND child_id IN (SELECT id FROM children WHERE family_id = ?)')
            ->execute([$removedUserId, $family['id']]);
        db()->prepare('DELETE FROM family_members WHERE family_id = ? AND user_id = ? AND role <> ?')
            ->execute([$family['id'], $removedUserId, 'OWNER']);
        db()->commit();
        audit_log('family.member_removed', (int)$user['id'], (int)$family['id'], 'user', $removedUserId);
        flash('success', 'Rodič byl odebrán.');
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    redirect('family');
}

function action_access_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    require_owner($family);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);

    set_child_access_users((int)$family['id'], (int)$child['id'], $_POST['user_ids'] ?? []);
    audit_log('child.access_updated', (int)$user['id'], (int)$family['id'], 'child', (int)$child['id']);
    flash('success', 'Přístupy byly uloženy.');
    redirect('family');
}

function export_flag(string $key, bool $default, bool $hasOptions): bool
{
    return $hasOptions ? (($_GET[$key] ?? '') === '1') : $default;
}

function page_export(): void
{
    $user = require_login();
    $child = require_child_access((int)($_GET['child_id'] ?? $_POST['child_id'] ?? 0), (int)$user['id']);
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-3 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $fromDb = $from . ' 00:00:00';
    $toDb = $to . ' 23:59:59';
    $hasOptions = isset($_GET['export_options']);
    $includeTemperature = export_flag('include_temperature', true, $hasOptions);
    $includeMedication = export_flag('include_medication', true, $hasOptions);
    $includeSymptoms = export_flag('include_symptoms', true, $hasOptions);
    $includeCare = export_flag('include_care', true, $hasOptions);
    $includeAppointments = export_flag('include_appointments', true, $hasOptions);
    $includeDocuments = export_flag('include_documents', true, $hasOptions);
    $includeEhic = export_flag('include_ehic', false, $hasOptions);
    $includeCancelled = export_flag('include_cancelled', false, $hasOptions);
    $stmt = db()->prepare(
        'SELECT hr.*, rt.kind, rt.code, rt.name AS type_name, tr.temperature_celsius, m.name AS medication_name,
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
         WHERE hr.child_id = ? AND hr.event_at BETWEEN ? AND ?
         ORDER BY hr.event_at'
    );
    $stmt->execute([$child['id'], $fromDb, $toDb]);
    $records = array_values(array_filter($stmt->fetchAll(), function (array $record) use ($includeTemperature, $includeMedication, $includeSymptoms, $includeCare): bool {
        if ($record['kind'] === 'TEMPERATURE') {
            return $includeTemperature;
        }
        if ($record['kind'] === 'MEDICATION') {
            return $includeMedication;
        }
        if (($record['code'] ?? '') === 'SYMPTOMS') {
            return $includeSymptoms;
        }
        if ($record['kind'] === 'CARE') {
            return $includeCare;
        }
        return true;
    }));
    $timeline72 = timeline_data((int)$child['id'], 72);
    $appointments = $includeAppointments ? child_appointments_between((int)$child['id'], $fromDb, $toDb, $includeCancelled) : [];
    $documents = $includeDocuments ? child_documents_between((int)$child['id'], $fromDb, $toDb, $includeEhic) : [];

    render_layout('Export pro lékaře', function () use ($child, $records, $from, $to, $timeline72, $appointments, $documents, $includeTemperature, $includeMedication, $includeSymptoms, $includeCare, $includeAppointments, $includeDocuments, $includeEhic, $includeCancelled) {
        $temps = array_filter($records, fn($r) => $r['kind'] === 'TEMPERATURE');
        $max = $temps ? max(array_map(fn($r) => (float)$r['temperature_celsius'], $temps)) : null;
        ?>
        <section class="export-head">
            <div>
                <h1>Přehled o zdravotním stavu</h1>
                <p class="document-subtitle">
                    <?= e($child['first_name'] . ' ' . $child['last_name']) ?>,
                    narození <?= e(date('d.m.Y', strtotime($child['date_of_birth']))) ?>
                </p>
            </div>
            <button class="button primary no-print" type="button" data-print-page>Uložit nebo tisknout PDF</button>
        </section>
        <section class="panel no-print">
            <form method="get" class="form-grid export-options">
                <input type="hidden" name="r" value="export">
                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                <input type="hidden" name="export_options" value="1">
                <label>Od <input type="date" name="from" value="<?= e($from) ?>"></label>
                <label>Do <input type="date" name="to" value="<?= e($to) ?>"></label>
                <label class="check"><input type="checkbox" name="include_temperature" value="1" <?= $includeTemperature ? 'checked' : '' ?>> Teploty</label>
                <label class="check"><input type="checkbox" name="include_medication" value="1" <?= $includeMedication ? 'checked' : '' ?>> Léky</label>
                <label class="check"><input type="checkbox" name="include_symptoms" value="1" <?= $includeSymptoms ? 'checked' : '' ?>> Příznaky</label>
                <label class="check"><input type="checkbox" name="include_care" value="1" <?= $includeCare ? 'checked' : '' ?>> Péče</label>
                <label class="check"><input type="checkbox" name="include_appointments" value="1" <?= $includeAppointments ? 'checked' : '' ?>> Kontroly</label>
                <label class="check"><input type="checkbox" name="include_documents" value="1" <?= $includeDocuments ? 'checked' : '' ?>> Dokumenty</label>
                <label class="check"><input type="checkbox" name="include_ehic" value="1" <?= $includeEhic ? 'checked' : '' ?>> EHIC a citlivé dokumenty</label>
                <label class="check"><input type="checkbox" name="include_cancelled" value="1" <?= $includeCancelled ? 'checked' : '' ?>> Zrušené kontroly</label>
                <button class="button" type="submit">Aktualizovat export</button>
            </form>
        </section>
        <section class="panel print-flat">
            <dl class="summary-list">
                <dt>Dítě</dt><dd><?= e($child['first_name'] . ' ' . $child['last_name']) ?></dd>
                <dt>Datum narození</dt><dd><?= e(date('d.m.Y', strtotime($child['date_of_birth']))) ?></dd>
                <dt>Věk</dt><dd><?= e(child_age_label($child['date_of_birth'])) ?></dd>
                <dt>Váha</dt><dd><?= e(child_weight_label($child['weight_kg'] ?? null)) ?></dd>
                <dt>Alergie</dt><dd><?= e(($child['allergies'] ?? '') !== '' ? $child['allergies'] : '-') ?></dd>
                <dt>Období</dt><dd><?= e(date('d.m.Y', strtotime($from)) . ' - ' . date('d.m.Y', strtotime($to))) ?></dd>
                <dt>Vytvořeno</dt><dd><?= e(date('d.m.Y H:i')) ?></dd>
                <dt>Počet měření</dt><dd><?= count($temps) ?></dd>
                <dt>Nejvyšší teplota</dt><dd><?= $max ? e(number_format($max, 1, ',', ' ') . ' °C') : '-' ?></dd>
            </dl>
        </section>
        <section class="panel print-flat">
            <h2>Graf za posledních 72 hodin</h2>
            <?php render_timeline_chart($timeline72); ?>
        </section>
        <?php if ($appointments): ?>
            <section class="panel print-flat">
                <h2>Kontroly</h2>
                <table>
                    <thead><tr><th>Termín</th><th>Stav</th><th>Typ</th><th>Lékař</th><th>Výsledek</th><th>Doporučení</th></tr></thead>
                    <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td><?= e(display_datetime($appointment['scheduled_at'])) ?></td>
                            <td><?= e(appointment_status_label($appointment['status'] ?? 'planned')) ?></td>
                            <td><?= e($appointment['appointment_type']) ?></td>
                            <td><?= e(($appointment['provider_name'] ?? '') !== '' ? $appointment['provider_name'] : '-') ?></td>
                            <td><?= e(($appointment['result_note'] ?? '') ?: $appointment['title']) ?></td>
                            <td><?= e(($appointment['recommendation'] ?? '') ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
        <?php if ($documents): ?>
            <section class="panel print-flat">
                <h2>Dokumenty z období</h2>
                <table>
                    <thead><tr><th>Datum</th><th>Typ</th><th>Název</th><th>Lékař</th><th>Poznámka</th></tr></thead>
                    <tbody>
                    <?php foreach ($documents as $document): ?>
                        <tr>
                            <td><?= e(display_datetime($document['created_at'])) ?></td>
                            <td><?= e(document_type_label($document['document_type'] ?? 'general')) ?><?= !empty($document['is_sensitive']) ? ' (citlivé)' : '' ?></td>
                            <td><?= e($document['title']) ?></td>
                            <td><?= e(($document['provider_name'] ?? '') !== '' ? $document['provider_name'] : '-') ?></td>
                            <td><?= e(($document['note'] ?? '') ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
        <section class="panel print-flat">
            <h2>Záznamy</h2>
            <table>
                <thead><tr><th>Čas</th><th>Typ</th><th>Detail</th><th>Poznámka</th></tr></thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= e(display_datetime($record['event_at'])) ?></td>
                        <td><?= e($record['type_name']) ?></td>
                        <td><?= e(record_detail_label($record)) ?></td>
                        <td><?= e($record['note']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        <?php
    });
}

function page_not_found(): void
{
    http_response_code(404);
    render_layout('Nenalezeno', function () {
        echo '<section class="panel"><h1>Stranka nenalezena</h1></section>';
    });
}


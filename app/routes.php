<?php

declare(strict_types=1);

function dispatch(): void
{
    send_security_headers();
    $route = $_GET['r'] ?? 'dashboard';

    if (is_post()) {
        verify_csrf();
    }

    switch ($route) {
        case 'login': page_login(); break;
        case 'register': page_register(); break;
        case 'password_forgot': page_password_forgot(); break;
        case 'password_reset': page_password_reset(); break;
        case 'logout': action_logout(); break;
        case 'google_start': action_google_start(); break;
        case 'google_callback': action_google_callback(); break;
        case 'dashboard': page_dashboard(); break;
        case 'children': page_children(); break;
        case 'child': page_child(); break;
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
        case 'medication_toggle': action_medication_toggle(); break;
        case 'care_types': page_care_types(); break;
        case 'care_type_save': action_care_type_save(); break;
        case 'care_type_toggle': action_care_type_toggle(); break;
        case 'family': page_family(); break;
        case 'family_save': action_family_save(); break;
        case 'family_delete': action_family_delete(); break;
        case 'member_add': action_member_add(); break;
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
        $user = find_user_by_email($email);
        if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
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
            $user = $blockedSeconds > 0 ? null : find_user_by_email($email);
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
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Zadejte platný e-mail.');
        } elseif (text_length($name) < 2) {
            flash('error', 'Zadejte jméno.');
        } elseif (text_length($password) < 10) {
            flash('error', 'Heslo musí mít alespoň 10 znaků.');
        } elseif (find_user_by_email($email)) {
            flash('error', 'Účet s tímto e-mailem už existuje.');
        } else {
            $userId = create_user($email, $name, $password);
            $invitations = mark_invitations_registered($email);
            foreach ($invitations as $invitation) {
                send_app_email(
                    $invitation['inviter_email'],
                    'Pozvaný rodič se zaregistroval',
                    "Dobrý den,\n\nuživatel {$email} se zaregistroval do aplikace Zdraví dětí.\n\nPozvali jste ho do rodiny {$invitation['family_name']}. Přihlaste se do aplikace a přidejte ho do rodiny přes stránku Rodina.\n\n" . app_base_url() . '/?r=family'
                );
            }
            $family = ensure_family($userId, $name);
            audit_log('auth.registered', $userId, (int)$family['id'], 'user', $userId, ['invitation_count' => count($invitations)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
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
    $user = current_user();
    if ($user) {
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
    if (($info['aud'] ?? '') !== cfg('google.client_id') || empty($info['email'])) {
        flash('error', 'Google identitu se nepodařilo ověřit.');
        redirect('login');
    }

    $user = find_user_by_email($info['email']);
    if (!$user) {
        $userId = create_user($info['email'], $info['name'] ?? $info['email'], null, $info['sub'] ?? null);
        $invitations = mark_invitations_registered($info['email']);
        foreach ($invitations as $invitation) {
            send_app_email(
                $invitation['inviter_email'],
                'Pozvaný rodič se zaregistroval',
                "Dobrý den,\n\nuživatel {$info['email']} se zaregistroval do aplikace Zdraví dětí.\n\nPozvali jste ho do rodiny {$invitation['family_name']}. Přihlaste se do aplikace a přidejte ho do rodiny přes stránku Rodina.\n\n" . app_base_url() . '/?r=family'
            );
        }
        audit_log('auth.google_registered', $userId, null, 'user', $userId, ['invitation_count' => count($invitations)]);
    } else {
        $userId = (int)$user['id'];
        db()->prepare('UPDATE users SET google_subject_id = COALESCE(google_subject_id, ?) WHERE id = ?')->execute([$info['sub'] ?? null, $userId]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $family = ensure_family($userId, $info['name'] ?? 'Rodina');
    audit_log('auth.google_login_success', $userId, (int)$family['id'], 'user', $userId);
    redirect('dashboard');
}

function page_dashboard(): void
{
    $user = require_login();
    ensure_family((int)$user['id'], $user['display_name']);
    $children = children_for_user((int)$user['id']);
    $overview = [];
    foreach ($children as $child) {
        $overview[] = [
            'child' => $child,
            'summary' => child_summary((int)$child['id']),
            'timeline' => timeline_data((int)$child['id'], 72),
        ];
    }

    render_layout('Přehled', function () use ($overview) {
        ?>
        <div class="page-head">
            <div>
                <h1>Přehled</h1>
                <p class="muted">Aktuální zdravotní historie všech dětí, ke kterým máte přístup.</p>
            </div>
            <a class="button" href="<?= e(url('children')) ?>">Správa dětí</a>
        </div>

        <?php if (!$overview): ?>
            <section class="panel">
                <div class="empty">Zatím tu není žádné dítě. Vlastník rodiny ho může přidat na stránce Děti.</div>
                <div class="panel-actions">
                    <a class="button primary" href="<?= e(url('children')) ?>">Přejít na děti</a>
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
                    ?>
                    <section class="panel child-overview">
                        <div class="child-overview-head">
                            <div>
                                <h2><?= e($child['first_name'] . ' ' . $child['last_name']) ?></h2>
                                <p class="muted">
                                    Narození <?= e(date('d.m.Y', strtotime($child['date_of_birth']))) ?>,
                                    věk <?= e(child_age_label($child['date_of_birth'])) ?>
                                </p>
                            </div>
                            <div class="actions">
                                <a class="button" href="<?= e(url('child', ['id' => $child['id']])) ?>">Otevřít dítě</a>
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

function page_children(): void
{
    $user = require_login();
    $family = ensure_family((int)$user['id'], $user['display_name']);
    $children = children_for_user((int)$user['id']);

    render_layout('Děti', function () use ($children, $family) {
        ?>
        <div class="page-head">
            <div>
                <h1>Děti</h1>
                <p class="muted">Správa dětí, jejich základních údajů a přístupů.</p>
            </div>
        </div>
        <section class="grid cards">
            <?php foreach ($children as $child):
                $summary = child_summary((int)$child['id']);
                $last = $summary['last_temperature'];
                $tone = severity($last ? (float)$last['temperature_celsius'] : null);
                ?>
                <a class="child-card <?= e($tone) ?>" href="<?= e(url('child', ['id' => $child['id']])) ?>">
                    <span><?= e($child['first_name'] . ' ' . $child['last_name']) ?></span>
                    <strong><?= $last ? e(number_format((float)$last['temperature_celsius'], 1, ',', ' ') . ' °C') : '-' ?></strong>
                    <small><?= $last ? e(display_datetime($last['event_at'])) : 'Zatím bez teploty' ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$children): ?>
                <div class="empty">Zatím tu není žádné dítě. Vlastník rodiny ho může přidat níže.</div>
            <?php endif; ?>
        </section>

        <?php if ($family): ?>
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
        <?php if (false && (($family['role'] ?? '') === 'OWNER')): ?>
            <section class="panel danger-zone">
                <h2>Zrušit rodinu</h2>
                <p class="muted">Zrušení rodiny smaže děti, zdravotní záznamy, číselníky a rodičovské role v této rodině. Uživatelské účty zůstanou zachované.</p>
                <form method="post" action="<?= e(url('family_delete')) ?>" data-confirm="Opravdu zrušit celou rodinu včetně všech záznamů?">
                    <?= csrf_field() ?>
                    <button class="button danger" type="submit">Zrušit rodinu</button>
                </form>
            </section>
        <?php endif; ?>
        <?php if (false && (($family['role'] ?? '') === 'OWNER')): ?>
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
    }, 'children');
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
        redirect('children');
    }
    $allergies = trim($_POST['allergies'] ?? '');
    if ($first === '' || $last === '' || !$dob || $dob > date('Y-m-d')) {
        flash('error', 'Zkontrolujte jméno, příjmení a datum narození.');
        redirect('children');
    }

    db()->beginTransaction();
    try {
        $stmt = db()->prepare('INSERT INTO children (family_id, first_name, last_name, date_of_birth, weight_kg, allergies) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$family['id'], $first, $last, $dob, $weight, $allergies]);
        $childId = (int)db()->lastInsertId();
        $access = db()->prepare('INSERT INTO child_access (child_id, user_id, can_view, can_create_record, can_edit_record, can_delete_record) VALUES (?, ?, 1, 1, 1, 1)');
        foreach (family_members((int)$family['id']) as $member) {
            $access->execute([$childId, $member['user_id']]);
        }
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

    redirect('child', ['id' => $child['id']]);
}

function page_child(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_GET['id'] ?? 0), (int)$user['id']);
    $range = in_array($_GET['range'] ?? '72', ['12', '24', '72'], true) ? (int)$_GET['range'] : 72;
    $summary = child_summary((int)$child['id']);
    $timeline = timeline_data((int)$child['id'], $range);
    $records = child_records((int)$child['id']);
    $medications = medications((int)$family['id'], true);
    $careTypes = record_types((int)$family['id'], 'CARE');

    render_layout($child['first_name'], function () use ($child, $family, $summary, $timeline, $records, $medications, $careTypes, $range) {
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
            <a class="button" href="<?= e(url('export', ['child_id' => $child['id']])) ?>">Export pro lékaře</a>
        </div>

        <div class="actions child-actions">
            <a class="button" href="#child-edit">Upravit dítě</a>
            <?php if ($family): ?>
                <form method="post" action="<?= e(url('child_delete')) ?>" data-confirm="Smazání dítěte odstraní i všechny zdravotní záznamy.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                    <button class="button danger" type="submit">Smazat dítě</button>
                </form>
            <?php endif; ?>
            <a class="button" href="<?= e(url('export', ['child_id' => $child['id']])) ?>">Export pro lékaře</a>
        </div>

        <section class="metrics">
            <?php metric_card('Poslední teplota', $last ? number_format((float)$last['temperature_celsius'], 1, ',', ' ') . ' °C' : '-', $last ? display_datetime($last['event_at']) : '', severity($last ? (float)$last['temperature_celsius'] : null)); ?>
            <?php metric_card('Maximum za 24 h', $summary['max_24h'] ? number_format((float)$summary['max_24h'], 1, ',', ' ') . ' °C' : '-', '', severity($summary['max_24h'] ? (float)$summary['max_24h'] : null)); ?>
            <?php metric_card('Poslední lék', $summary['last_medication'] ? medication_label($summary['last_medication']) : '-', isset($summary['last_medication']['event_at']) ? display_datetime($summary['last_medication']['event_at']) : ''); ?>
        </section>

        <section class="quick-entry">
            <form method="post" action="<?= e(url('medication_record_save')) ?>" class="panel stack">
                <?= csrf_field() ?>
                <div class="section-head compact">
                    <h2>Rychle podat lék</h2>
                </div>
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
                <input type="hidden" name="event_at" value="<?= e(input_datetime()) ?>">
                <label>Poznámka <input name="note" placeholder="Volitelné"></label>
                <button class="button primary" type="submit">Uložit podání</button>
            </form>

            <form method="post" action="<?= e(url('symptom_record_save')) ?>" class="panel stack">
                <?= csrf_field() ?>
                <div class="section-head compact">
                    <h2>Rychle zapsat příznaky</h2>
                </div>
                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                <input type="hidden" name="event_at" value="<?= e(input_datetime()) ?>">
                <div class="symptom-grid">
                    <?php foreach (symptom_options() as $symptom): ?>
                        <label class="check"><input type="checkbox" name="symptoms[]" value="<?= e($symptom) ?>"> <?= e($symptom) ?></label>
                    <?php endforeach; ?>
                </div>
                <label>Závažnost
                    <select name="severity">
                        <option value="mild">Lehké</option>
                        <option value="moderate">Střední</option>
                        <option value="high">Výrazné</option>
                    </select>
                </label>
                <label>Poznámka <input name="note" placeholder="Volitelné"></label>
                <button class="button primary" type="submit">Uložit příznaky</button>
            </form>
        </section>

        <section class="panel" id="child-edit">
            <div class="section-head">
                <h2>Údaje dítěte</h2>
                <span class="muted">Věk: <?= e(child_age_label($child['date_of_birth'])) ?></span>
            </div>
            <?php if ($family): ?>
                <form method="post" action="<?= e(url('child_profile_save')) ?>" class="stack child-profile-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                    <label>Jméno <input required name="first_name" value="<?= e($child['first_name']) ?>"></label>
                    <label>Příjmení <input required name="last_name" value="<?= e($child['last_name']) ?>"></label>
                    <label>Datum narození <input required type="date" name="date_of_birth" max="<?= e(date('Y-m-d')) ?>" value="<?= e($child['date_of_birth']) ?>"></label>
                    <label>Váha kg <input type="number" step="0.1" min="0" max="200" name="weight_kg" value="<?= e($child['weight_kg'] ?? '') ?>" inputmode="decimal"></label>
                    <label class="wide-field">Alergie <textarea name="allergies" rows="2" placeholder="Bez známých alergií, nebo konkrétní alergie"><?= e($child['allergies'] ?? '') ?></textarea></label>
                    <button class="button primary" type="submit">Uložit údaje</button>
                </form>
            <?php else: ?>
                <dl class="summary-list">
                    <dt>Váha</dt><dd><?= e(child_weight_label($child['weight_kg'] ?? null)) ?></dd>
                    <dt>Alergie</dt><dd><?= e(($child['allergies'] ?? '') !== '' ? $child['allergies'] : '-') ?></dd>
                </dl>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="section-head">
                <h2>Časová osa</h2>
                <div class="segmented">
                    <?php foreach ([12, 24, 72] as $hours): ?>
                        <a class="<?= $range === $hours ? 'active' : '' ?>" href="<?= e(url('child', ['id' => $child['id'], 'range' => $hours])) ?>"><?= $hours ?> h</a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php render_timeline_chart($timeline); ?>
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
    }, 'dashboard');
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

function action_child_delete(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    db()->prepare('DELETE FROM children WHERE id = ? AND family_id = ?')->execute([$child['id'], $family['id']]);
    audit_log('child.deleted', (int)$user['id'], (int)$family['id'], 'child', (int)$child['id']);
    flash('success', 'Dítě a jeho záznamy byly smazány.');
    redirect('children');
}

function action_temperature_save(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $value = (float)str_replace(',', '.', (string)($_POST['temperature_celsius'] ?? ''));
    if ($value < 30 || $value > 45) {
        flash('error', 'Teplota musí být v rozsahu 30,0 až 45,0 °C.');
        redirect('child', ['id' => $child['id']]);
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
    redirect('child', ['id' => $child['id']]);
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
    $careTypes = record_types((int)$family['id'], 'CARE');
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
    $items = medications((int)$family['id'], false);
    render_layout('Léčiva', function () use ($items) {
        ?>
        <div class="page-head"><h1>Léčiva</h1></div>
        <section class="panel">
            <form method="post" action="<?= e(url('medication_save')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input required name="name" placeholder="Název léku">
                <button class="button primary" type="submit">Přidat</button>
            </form>
            <div class="list">
                <?php foreach ($items as $item): ?>
                    <form method="post" action="<?= e(url('medication_toggle')) ?>" class="list-row medication-row">
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
                        <small><?= $item['is_active'] ? 'aktivní' : 'neaktivní' ?></small>
                        <button class="button tiny" type="submit"><?= $item['is_active'] ? 'Deaktivovat' : 'Aktivovat' ?></button>
                    </form>
                <?php endforeach; ?>
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
    $items = record_types((int)$family['id'], 'CARE');
    render_layout('Typy péče', function () use ($items) {
        ?>
        <div class="page-head"><h1>Typy péče</h1></div>
        <section class="panel">
            <form method="post" action="<?= e(url('care_type_save')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input required name="name" placeholder="Například koupel, inhalace, odpočinek">
                <button class="button primary" type="submit">Přidat</button>
            </form>
            <div class="list">
                <?php foreach ($items as $item): ?>
                    <form method="post" action="<?= e(url('care_type_toggle')) ?>" class="list-row">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                        <span><?= e($item['name']) ?></span>
                        <small><?= $item['is_system'] ? 'systémový' : 'vlastní' ?></small>
                        <?php if (!$item['is_system']): ?><button class="button tiny" type="submit">Deaktivovat</button><?php endif; ?>
                    </form>
                <?php endforeach; ?>
            </div>
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
        db()->prepare('INSERT INTO record_types (family_id, code, name, kind, is_system) VALUES (?, ?, ?, ?, 0)')
            ->execute([$family['id'], $code, $name, 'CARE']);
        audit_log('care_type.created', (int)$user['id'], (int)$family['id'], 'record_type', (int)db()->lastInsertId());
    }
    redirect('care_types');
}

function action_care_type_toggle(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    db()->prepare('UPDATE record_types SET is_active = 0 WHERE id = ? AND family_id = ? AND is_system = 0')->execute([(int)$_POST['id'], $family['id']]);
    audit_log('care_type.deactivated', (int)$user['id'], (int)$family['id'], 'record_type', (int)$_POST['id']);
    redirect('care_types');
}

function page_family(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $members = family_members((int)$family['id']);
    $children = children_for_user((int)$user['id']);

    render_layout('Rodina', function () use ($family, $members, $children) {
        ?>
        <div class="page-head"><h1>Rodina</h1></div>
        <section class="panel">
            <h2>Název rodiny</h2>
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
            <?php if (($family['role'] ?? '') === 'OWNER'): ?>
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
                        <?php if ($member['role'] !== 'OWNER'): ?>
                            <form method="post" action="<?= e(url('member_remove')) ?>" data-confirm="Odebrat rodiče z rodiny?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= e($member['user_id']) ?>">
                                <button class="button tiny danger" type="submit">Odebrat</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php if ($family): ?>
            <section class="panel">
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
        <?php endif; ?>
        <?php if (($family['role'] ?? '') === 'OWNER'): ?>
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

    db()->prepare('DELETE FROM families WHERE id = ? AND owner_user_id = ?')->execute([$family['id'], $user['id']]);
    audit_log('family.deleted', (int)$user['id'], (int)$family['id'], 'family', (int)$family['id']);
    flash('success', 'Rodina byla zrušena. Uživatelské účty zůstaly zachované.');
    redirect('dashboard');
}

function action_member_add(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $email = text_lower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Zadejte platný e-mail.');
        redirect('family');
    }
    $newUser = find_user_by_email($email);
    if (!$newUser) {
        $invitation = create_family_invitation((int)$family['id'], (int)$user['id'], $email);
        $registerUrl = app_base_url() . '/?r=register&email=' . urlencode($email);
        $loginUrl = app_base_url() . '/?r=login';
        send_app_email(
            $email,
            'Pozvánka do rodiny v aplikaci Zdraví dětí',
            "Dobrý den,\n\n{$user['display_name']} vás zve do rodiny {$family['name']} v aplikaci Zdraví dětí.\n\nPokud ještě nemáte účet, zaregistrujte se zde:\n{$registerUrl}\n\nPokud účet máte, přihlaste se zde:\n{$loginUrl}\n\nPo registraci dostane pozývající rodič informaci, aby vás přidal do rodiny."
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

function action_member_remove(): void
{
    $user = require_login();
    $family = current_family((int)$user['id']);
    $removedUserId = (int)($_POST['user_id'] ?? 0);
    if ($removedUserId === (int)$family['owner_user_id']) {
        flash('error', 'Vlastníka rodiny nelze odebrat.');
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
    $child = require_child_access((int)($_POST['child_id'] ?? 0), (int)$user['id']);
    $allowed = array_map('intval', $_POST['user_ids'] ?? []);
    $memberIds = array_map(fn($member) => (int)$member['user_id'], family_members((int)$family['id']));
    $allowed = array_values(array_intersect($allowed, $memberIds));
    $ownerId = (int)$family['owner_user_id'];
    $allowed[] = $ownerId;

    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM child_access WHERE child_id = ?')->execute([$child['id']]);
        $stmt = db()->prepare('INSERT INTO child_access (child_id, user_id, can_view, can_create_record, can_edit_record, can_delete_record) VALUES (?, ?, 1, 1, 1, 1)');
        foreach (array_unique($allowed) as $userId) {
            $stmt->execute([$child['id'], $userId]);
        }
        db()->commit();
        audit_log('child.access_updated', (int)$user['id'], (int)$family['id'], 'child', (int)$child['id']);
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    flash('success', 'Přístupy byly uloženy.');
    redirect('family');
}

function page_export(): void
{
    $user = require_login();
    $child = require_child_access((int)($_GET['child_id'] ?? $_POST['child_id'] ?? 0), (int)$user['id']);
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-3 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $fromDb = $from . ' 00:00:00';
    $toDb = $to . ' 23:59:59';
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
    $records = $stmt->fetchAll();
    $timeline72 = timeline_data((int)$child['id'], 72);

    render_layout('Export pro lékaře', function () use ($child, $records, $from, $to, $timeline72) {
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
            <button class="button primary no-print" onclick="window.print()">Uložit nebo tisknout PDF</button>
        </section>
        <section class="panel no-print">
            <form method="get" class="form-grid">
                <input type="hidden" name="r" value="export">
                <input type="hidden" name="child_id" value="<?= e($child['id']) ?>">
                <label>Od <input type="date" name="from" value="<?= e($from) ?>"></label>
                <label>Do <input type="date" name="to" value="<?= e($to) ?>"></label>
                <button class="button" type="submit">Zobrazit období</button>
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


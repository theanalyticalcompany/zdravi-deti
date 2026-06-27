<?php

declare(strict_types=1);

function cfg(string $key, $default = null)
{
    global $config;
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function url(string $route, array $params = []): string
{
    return '?' . http_build_query(array_merge(['r' => $route], $params));
}

function redirect(string $route, array $params = []): void
{
    header('Location: ' . url($route, $params));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sessionToken = $_SESSION['csrf'] ?? '';
    $token = $_POST['csrf'] ?? '';
    if (!is_string($sessionToken) || !is_string($token) || $sessionToken === '' || $token === '' || !hash_equals($sessionToken, $token)) {
        http_response_code(419);
        echo 'Platnost formuláře vypršela. Vraťte se prosím zpět a zkuste to znovu.';
        exit;
    }
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
}

function current_user(): ?array
{
    static $cachedSessionUserId = null;
    static $cachedUser = null;

    if (empty($_SESSION['user_id'])) {
        $cachedSessionUserId = null;
        $cachedUser = null;
        return null;
    }
    $sessionUserId = (int)$_SESSION['user_id'];
    if ($cachedSessionUserId === $sessionUserId) {
        return $cachedUser;
    }

    $user = find_user($sessionUserId);
    if (!$user) {
        $cachedSessionUserId = null;
        $cachedUser = null;
        return null;
    }
    if (user_session_revoked((int)$user['id'])) {
        $_SESSION = [];
        $cachedSessionUserId = null;
        $cachedUser = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return null;
    }
    touch_user_session((int)$user['id']);
    $cachedSessionUserId = $sessionUserId;
    $cachedUser = $user;
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login');
    }
    return $user;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input_datetime(?string $value = null): string
{
    $date = $value ? new DateTimeImmutable($value) : new DateTimeImmutable();
    return $date->format('Y-m-d\TH:i');
}

function file_size_label(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', ' ') . ' kB';
    }
    return $bytes . ' B';
}

function document_encryption_magic(): string
{
    return "ZDENC1\n";
}

function document_encrypt_uploads(): bool
{
    return filter_var(cfg('documents.encrypt_uploads', false), FILTER_VALIDATE_BOOLEAN);
}

function document_encryption_key(): ?string
{
    $configured = trim((string)cfg('documents.encryption_key', ''));
    if ($configured === '') {
        return null;
    }
    if (strpos($configured, 'base64:') === 0) {
        $key = base64_decode(substr($configured, 7), true);
    } else {
        $key = $configured;
    }
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('Šifrovací klíč dokumentů musí mít 32 bajtů.');
    }
    return $key;
}

function document_bytes_are_encrypted(string $bytes): bool
{
    return strpos($bytes, document_encryption_magic()) === 0;
}

function document_encrypt_bytes(string $plaintext): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('Na serveru není dostupné OpenSSL pro šifrování dokumentů.');
    }
    $key = document_encryption_key();
    if ($key === null) {
        throw new RuntimeException('Chybí šifrovací klíč dokumentů.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('Dokument se nepodařilo zašifrovat.');
    }
    $header = [
        'alg' => 'AES-256-GCM',
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
    return document_encryption_magic() . base64_encode(json_encode($header, JSON_UNESCAPED_SLASHES)) . "\n" . $ciphertext;
}

function document_decrypt_bytes(string $bytes): string
{
    if (!document_bytes_are_encrypted($bytes)) {
        return $bytes;
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('Na serveru není dostupné OpenSSL pro čtení šifrovaných dokumentů.');
    }
    $key = document_encryption_key();
    if ($key === null) {
        throw new RuntimeException('Chybí šifrovací klíč dokumentů.');
    }
    $payload = substr($bytes, strlen(document_encryption_magic()));
    $newlinePosition = strpos($payload, "\n");
    if ($newlinePosition === false) {
        throw new RuntimeException('Šifrovaný dokument má neplatný formát.');
    }
    $headerJson = base64_decode(substr($payload, 0, $newlinePosition), true);
    $header = is_string($headerJson) ? json_decode($headerJson, true) : null;
    if (!is_array($header) || ($header['alg'] ?? '') !== 'AES-256-GCM') {
        throw new RuntimeException('Šifrovaný dokument má neplatnou hlavičku.');
    }
    $iv = base64_decode((string)($header['iv'] ?? ''), true);
    $tag = base64_decode((string)($header['tag'] ?? ''), true);
    if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16) {
        throw new RuntimeException('Šifrovaný dokument má neplatné parametry.');
    }
    $ciphertext = substr($payload, $newlinePosition + 1);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('Dokument se nepodařilo dešifrovat.');
    }
    return $plaintext;
}

function document_type_options(): array
{
    return [
        'general' => 'Obecný dokument',
        'medical_report' => 'Lékařská zpráva',
        'lab_result' => 'Laboratorní výsledek',
        'ehic' => 'EHIC / evropský průkaz zdravotního pojištění',
        'vaccination' => 'Očkování',
        'other' => 'Jiné',
    ];
}

function document_type_label(?string $type): string
{
    $options = document_type_options();
    return $options[$type ?: 'general'] ?? $options['general'];
}

function appointment_status_label(?string $status): string
{
    return [
        'planned' => 'Plánovaná',
        'completed' => 'Proběhlá',
        'cancelled' => 'Zrušená',
    ][$status ?: 'planned'] ?? 'Plánovaná';
}

function db_datetime(string $localValue): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $localValue);
    if (!$date) {
        throw new InvalidArgumentException('Neplatný datum a čas.');
    }
    if ($date > new DateTimeImmutable('+1 minute')) {
        throw new InvalidArgumentException('Datum a čas nesmí být v budoucnosti.');
    }
    return $date->format('Y-m-d H:i:s');
}

function db_datetime_any(string $localValue): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $localValue);
    if (!$date) {
        throw new InvalidArgumentException('Neplatný datum a čas.');
    }
    return $date->format('Y-m-d H:i:s');
}

function display_datetime(?string $value): string
{
    if (!$value) {
        return '-';
    }
    return (new DateTimeImmutable($value))->format('d.m.Y H:i');
}

function child_age_label(string $dateOfBirth): string
{
    $birth = new DateTimeImmutable($dateOfBirth);
    $today = new DateTimeImmutable('today');
    if ($birth > $today) {
        return '-';
    }
    $diff = $birth->diff($today);
    if ($diff->y > 0) {
        if ($diff->y === 1) {
            $years = '1 rok';
        } elseif ($diff->y >= 2 && $diff->y <= 4) {
            $years = $diff->y . ' roky';
        } else {
            $years = $diff->y . ' let';
        }
        return $years . ($diff->m ? ' a ' . month_label($diff->m) : '');
    }
    if ($diff->m > 0) {
        return month_label($diff->m);
    }
    if ($diff->d === 1) {
        return '1 den';
    }
    if ($diff->d >= 2 && $diff->d <= 4) {
        return $diff->d . ' dny';
    }
    return $diff->d . ' dnů';
}

function month_label(int $months): string
{
    if ($months === 1) {
        return '1 měsíc';
    }
    if ($months >= 2 && $months <= 4) {
        return $months . ' měsíce';
    }
    return $months . ' měsíců';
}

function child_weight_label($weight): string
{
    if ($weight === null || $weight === '') {
        return '-';
    }
    return number_format((float)$weight, 1, ',', ' ') . ' kg';
}

function medication_label(array $medication): string
{
    $parts = [(string)$medication['name']];
    $detail = trim(implode(' ', array_filter([
        $medication['dosage_form'] ?? '',
        $medication['strength'] ?? '',
    ])));
    if ($detail !== '') {
        $parts[] = '(' . $detail . ')';
    }
    return implode(' ', $parts);
}

function provider_address_label(array $provider): string
{
    $street = trim(implode(' ', array_filter([
        $provider['street'] ?? '',
        $provider['house_number'] ?? '',
    ])));
    return trim(implode(', ', array_filter([
        $street,
        trim(implode(' ', array_filter([
            $provider['zip'] ?? '',
            $provider['city'] ?? '',
        ]))),
        $provider['district'] ?? '',
    ]))) ?: '-';
}

function provider_contact_label(array $provider): string
{
    return trim(implode(' · ', array_filter([
        $provider['phone'] ?? '',
        $provider['email'] ?? '',
        $provider['web'] ?? '',
    ]))) ?: '-';
}

function provider_specialty_label(array $provider): string
{
    $specialties = trim((string)($provider['specialties'] ?? ''));
    if ($specialties !== '') {
        return $specialties;
    }
    return trim((string)($provider['care_field'] ?? '')) ?: trim((string)($provider['facility_type'] ?? ''));
}

function now_sql(): string
{
    return (new DateTimeImmutable())->format('Y-m-d H:i:s');
}

function role_label(string $role): string
{
    return $role === 'OWNER' ? 'Administrátor rodiny' : 'Rodič';
}

function app_base_url(): string
{
    return rtrim((string)cfg('app.base_url', 'http://127.0.0.1:8080'), '/');
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 80);
}

function user_agent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255);
}

function current_session_hash(): string
{
    if (session_id() === '') {
        if (empty($_SESSION['cli_session_id'])) {
            $_SESSION['cli_session_id'] = bin2hex(random_bytes(16));
        }
        return hash('sha256', 'cli|' . $_SESSION['cli_session_id']);
    }
    return hash('sha256', session_id());
}

function device_label(string $agent): string
{
    $agent = trim($agent);
    if ($agent === '' || $agent === 'cli') {
        return 'Neznámé zařízení';
    }

    $browser = 'Prohlížeč';
    foreach ([
        'Edg' => 'Microsoft Edge',
        'OPR' => 'Opera',
        'Chrome' => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari' => 'Safari',
    ] as $needle => $label) {
        if (stripos($agent, $needle) !== false) {
            $browser = $label;
            break;
        }
    }

    $platform = 'zařízení';
    foreach ([
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Mac OS' => 'Mac',
        'Linux' => 'Linux',
    ] as $needle => $label) {
        if (stripos($agent, $needle) !== false) {
            $platform = $label;
            break;
        }
    }

    return $browser . ' na ' . $platform;
}

function audit_log(string $action, ?int $userId = null, ?int $familyId = null, ?string $entityType = null, ?int $entityId = null, array $meta = []): void
{
    try {
        db()->prepare(
            'INSERT INTO audit_logs (user_id, family_id, action, entity_type, entity_id, ip_address, user_agent, meta_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId,
            $familyId,
            $action,
            $entityType,
            $entityId,
            client_ip(),
            user_agent(),
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            now_sql(),
        ]);
    } catch (Throwable $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

function rate_limit_key(string $action, string $subject): string
{
    return hash('sha256', $action . '|' . text_lower(trim($subject)) . '|' . client_ip());
}

function rate_limit_blocked_seconds(string $action, string $subject): int
{
    $stmt = db()->prepare('SELECT blocked_until FROM rate_limits WHERE rate_key = ? AND action = ? LIMIT 1');
    $stmt->execute([rate_limit_key($action, $subject), $action]);
    $blockedUntil = $stmt->fetchColumn();
    if (!$blockedUntil) {
        return 0;
    }
    $seconds = strtotime((string)$blockedUntil) - time();
    return max(0, $seconds);
}

function rate_limit_hit(string $action, string $subject, int $maxAttempts, int $windowSeconds, int $blockSeconds): int
{
    $key = rate_limit_key($action, $subject);
    $now = new DateTimeImmutable();
    $nowSql = $now->format('Y-m-d H:i:s');
    $stmt = db()->prepare('SELECT * FROM rate_limits WHERE rate_key = ? AND action = ? LIMIT 1');
    $stmt->execute([$key, $action]);
    $row = $stmt->fetch();

    if ($row && !empty($row['blocked_until']) && strtotime((string)$row['blocked_until']) > time()) {
        return max(1, strtotime((string)$row['blocked_until']) - time());
    }

    $firstAttempt = $row ? new DateTimeImmutable((string)$row['first_attempt_at']) : $now;
    $attempts = $row ? (int)$row['attempts'] : 0;
    if (!$row || ($now->getTimestamp() - $firstAttempt->getTimestamp()) > $windowSeconds) {
        $firstAttempt = $now;
        $attempts = 0;
    }

    $attempts++;
    $blockedUntil = $attempts >= $maxAttempts ? $now->modify('+' . $blockSeconds . ' seconds')->format('Y-m-d H:i:s') : null;
    if ($row) {
        db()->prepare('UPDATE rate_limits SET attempts = ?, first_attempt_at = ?, last_attempt_at = ?, blocked_until = ? WHERE id = ?')
            ->execute([$attempts, $firstAttempt->format('Y-m-d H:i:s'), $nowSql, $blockedUntil, $row['id']]);
    } else {
        db()->prepare('INSERT INTO rate_limits (rate_key, action, attempts, first_attempt_at, last_attempt_at, blocked_until) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$key, $action, $attempts, $firstAttempt->format('Y-m-d H:i:s'), $nowSql, $blockedUntil]);
    }

    return $blockedUntil ? $blockSeconds : 0;
}

function rate_limit_clear(string $action, string $subject): void
{
    db()->prepare('DELETE FROM rate_limits WHERE rate_key = ? AND action = ?')
        ->execute([rate_limit_key($action, $subject), $action]);
}

function send_app_email(string $to, string $subject, string $body): void
{
    $from = (string)cfg('mail.from', 'noreply@localhost');
    $headers = [
        'From: ' . $from,
        'Content-Type: text/plain; charset=UTF-8',
    ];

    $log = '[' . date('Y-m-d H:i:s') . "] To: {$to}\nSubject: {$subject}\n{$body}\n\n";
    $logPath = __DIR__ . '/../var/mail.log';
    if (is_dir(dirname($logPath))) {
        file_put_contents($logPath, $log, FILE_APPEND);
    }

    if (!cfg('mail.enabled', false)) {
        return;
    }

    $transport = (string)cfg('mail.transport', 'mail');
    try {
        if ($transport === 'smtp') {
            smtp_send($to, $subject, $body, $from);
        } elseif ($transport === 'api') {
            api_send_email($to, $subject, $body, $from);
        } elseif ($transport === 'log') {
            return;
        } elseif (function_exists('mail')) {
            @mail($to, $subject, $body, implode("\r\n", $headers));
        }
    } catch (Throwable $e) {
        error_log('Mail delivery failed: ' . $e->getMessage());
        audit_log('email.delivery_failed', current_user()['id'] ?? null, null, 'email', null, [
            'transport' => $transport,
            'to_hash' => hash('sha256', text_lower($to)),
        ]);
    }
}

function smtp_send(string $to, string $subject, string $body, string $from): void
{
    $host = (string)cfg('mail.smtp.host', '');
    $port = (int)cfg('mail.smtp.port', 587);
    if ($host === '') {
        throw new RuntimeException('SMTP host není nastaven.');
    }

    $remote = (($port === 465 || cfg('mail.smtp.encryption') === 'ssl') ? 'ssl://' : '') . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('SMTP spojení selhalo: ' . $errstr);
    }
    stream_set_timeout($socket, 20);

    $read = function () use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    };
    $write = function (string $command, array $okCodes = ['250']) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        $response = $read();
        if (!in_array(substr($response, 0, 3), $okCodes, true)) {
            throw new RuntimeException('SMTP odpověď: ' . trim($response));
        }
        return $response;
    };

    $read();
    $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (cfg('mail.smtp.encryption', 'tls') === 'tls' && $port !== 465) {
        $write('STARTTLS', ['220']);
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    }

    $username = (string)cfg('mail.smtp.username', '');
    $password = (string)cfg('mail.smtp.password', '');
    if ($username !== '') {
        $write('AUTH LOGIN', ['334']);
        $write(base64_encode($username), ['334']);
        $write(base64_encode($password), ['235']);
    }

    $message = build_email_message($to, $subject, $body, $from);
    $write('MAIL FROM:<' . email_address_only($from) . '>');
    $write('RCPT TO:<' . email_address_only($to) . '>', ['250', '251']);
    $write('DATA', ['354']);
    fwrite($socket, str_replace("\n.", "\n..", $message) . "\r\n.\r\n");
    $response = $read();
    if (substr($response, 0, 3) !== '250') {
        throw new RuntimeException('SMTP DATA odpověď: ' . trim($response));
    }
    $write('QUIT', ['221']);
    fclose($socket);
}

function api_send_email(string $to, string $subject, string $body, string $from): void
{
    $url = (string)cfg('mail.api.url', '');
    if ($url === '') {
        throw new RuntimeException('API URL pro e-mail není nastaveno.');
    }
    $payload = json_encode([
        'from' => $from,
        'to' => $to,
        'subject' => $subject,
        'text' => $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json'];
    $token = (string)cfg('mail.api.token', '');
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('E-mail API selhalo: ' . $statusLine . ' ' . substr((string)$response, 0, 160));
    }
}

function build_email_message(string $to, string $subject, string $body, string $from): string
{
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return implode("\r\n", [
        'From: ' . $from,
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        '',
        $body,
    ]);
}

function email_address_only(string $value): string
{
    if (preg_match('/<([^>]+)>/', $value, $m)) {
        return trim($m[1]);
    }
    return trim($value);
}

function severity(?float $temperature): string
{
    if ($temperature === null) {
        return 'neutral';
    }
    if ($temperature > 38.0) {
        return 'danger';
    }
    if ($temperature > 37.0) {
        return 'warning';
    }
    return 'ok';
}

function require_owner(array $family): void
{
    if (($family['role'] ?? '') !== 'OWNER') {
        http_response_code(403);
        echo 'Tato akce je dostupná pouze administrátorovi rodiny.';
        exit;
    }
}

function require_child_access(int $childId, int $userId): array
{
    $child = child_for_user($childId, $userId);
    if (!$child) {
        http_response_code(404);
        echo 'Dítě nebylo nalezeno nebo k němu nemáte přístup.';
        exit;
    }
    return $child;
}


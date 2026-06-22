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
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return find_user((int)$_SESSION['user_id']);
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

function now_sql(): string
{
    return (new DateTimeImmutable())->format('Y-m-d H:i:s');
}

function role_label(string $role): string
{
    return $role === 'OWNER' ? 'Vlastník' : 'Rodič';
}

function app_base_url(): string
{
    return rtrim((string)cfg('app.base_url', 'http://127.0.0.1:8080'), '/');
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

    if (cfg('mail.enabled', false) && function_exists('mail')) {
        @mail($to, $subject, $body, implode("\r\n", $headers));
    }
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
        echo 'Tato akce je dostupná pouze vlastníkovi rodiny.';
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


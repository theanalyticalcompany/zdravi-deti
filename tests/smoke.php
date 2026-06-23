<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$dbPath = tempnam(sys_get_temp_dir(), 'zdravi-deti-') . '.sqlite';

$config = [
    'app' => [
        'name' => 'Zdraví dětí',
        'base_url' => 'http://127.0.0.1',
        'timezone' => 'Europe/Prague',
    ],
    'db' => [
        'dsn' => 'sqlite:' . $dbPath,
        'user' => null,
        'password' => null,
    ],
    'mail' => [
        'enabled' => false,
        'transport' => 'log',
        'from' => 'noreply@example.test',
    ],
    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
    ],
];

date_default_timezone_set($config['app']['timezone']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'smoke-test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];

$pdo = new PDO($config['db']['dsn'], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents($root . '/database/schema.sqlite.sql'));

require $root . '/app/helpers.php';
require $root . '/app/repositories.php';
ensure_runtime_schema();

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$userId = create_user('rodic@example.test', 'Testovací rodič', 'bezpecne-heslo-123');
$family = ensure_family($userId, 'Testovací rodič');
assert_true((int)$family['owner_user_id'] === $userId, 'owner family was created');

$invitation = create_family_invitation((int)$family['id'], $userId, 'druhy.rodic@example.test');
$pendingInvitation = pending_family_invitation_by_email((int)$family['id'], 'druhy.rodic@example.test');
assert_true($pendingInvitation !== null, 'pending family invitation is found by email');
$pendingList = pending_family_invitations((int)$family['id']);
assert_true(count($pendingList) === 1 && $pendingList[0]['invited_email'] === 'druhy.rodic@example.test', 'pending family invitation is listed');
$cancelledInvitation = cancel_family_invitation((int)$family['id'], (int)$pendingInvitation['id']);
assert_true($cancelledInvitation !== null, 'pending family invitation can be cancelled');
assert_true(pending_family_invitation_by_email((int)$family['id'], 'druhy.rodic@example.test') === null, 'cancelled invitation disappears from pending list');
$newInvitation = create_family_invitation((int)$family['id'], $userId, 'druhy.rodic@example.test');
assert_true(($newInvitation['token'] ?? '') !== ($invitation['token'] ?? ''), 'new invitation can be created for cancelled email');

$resetToken = create_password_reset_token($userId);
assert_true(password_reset_by_token($resetToken) !== null, 'password reset token is readable');
$newPassword = str_repeat('a', 10);
assert_true(consume_password_reset_token($resetToken, $newPassword), 'password reset token is consumed');
assert_true(password_reset_by_token($resetToken) === null, 'password reset token cannot be reused');

$blocked = 0;
for ($i = 0; $i < 5; $i++) {
    $blocked = rate_limit_hit('login', 'rodic@example.test', 5, 900, 900);
}
assert_true($blocked > 0, 'login rate limit blocks after repeated hits');
assert_true(rate_limit_blocked_seconds('login', 'rodic@example.test') > 0, 'login rate limit reports active block');
rate_limit_clear('login', 'rodic@example.test');
assert_true(rate_limit_blocked_seconds('login', 'rodic@example.test') === 0, 'login rate limit can be cleared');

audit_log('smoke.event', $userId, (int)$family['id'], 'user', $userId, ['ok' => true]);
$auditCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'smoke.event'")->fetchColumn();
assert_true($auditCount === 1, 'audit log stores important event');

$pdo->prepare('INSERT INTO children (family_id, first_name, last_name, date_of_birth) VALUES (?, ?, ?, ?)')
    ->execute([$family['id'], 'Anna', 'Testová', '2021-01-01']);
$childId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO child_access (child_id, user_id) VALUES (?, ?)')->execute([$childId, $userId]);
$symptomTypeId = record_type_id((int)$family['id'], 'SYMPTOMS');
$pdo->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, note) VALUES (?, ?, ?, ?, ?)')
    ->execute([$childId, $symptomTypeId, now_sql(), $userId, 'Test']);
$recordId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO symptom_records (health_record_id, symptoms, severity) VALUES (?, ?, ?)')
    ->execute([$recordId, 'kašel, rýma', 'mild']);
$record = record_for_user($recordId, $userId);
assert_true($record && $record['code'] === 'SYMPTOMS' && $record['symptoms'] === 'kašel, rýma', 'symptom record is readable');

@unlink($dbPath);
echo "OK\n";

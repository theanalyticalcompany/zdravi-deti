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
$googleUserId = create_user('rodic@example.test', 'Google rodič', null, 'google-subject-1');
assert_true($googleUserId !== $userId, 'google account can coexist with password account for same email');
assert_true((int)find_password_user_by_email('rodic@example.test')['id'] === $userId, 'password login keeps password account');
assert_true((int)find_user_by_google_subject('google-subject-1')['id'] === $googleUserId, 'google login uses google subject account');

$_SESSION['user_id'] = $userId;
remember_user_session($userId);
$sessions = active_user_sessions($userId);
assert_true(count($sessions) === 1, 'active session is tracked');
assert_true(!user_session_revoked($userId), 'current session is active');

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

$invitedUserId = create_user('druhy.rodic@example.test', 'Druhý rodič', 'bezpecne-heslo-456');
$acceptedInvitations = accept_pending_invitations_for_user($invitedUserId, 'druhy.rodic@example.test');
assert_true(count($acceptedInvitations) === 1, 'pending invitation is accepted during registration');
assert_true(pending_family_invitation_by_email((int)$family['id'], 'druhy.rodic@example.test') === null, 'accepted invitation is no longer pending');
$invitedFamily = current_family($invitedUserId);
assert_true($invitedFamily && (int)$invitedFamily['id'] === (int)$family['id'], 'invited user joins inviter family');
assert_true(child_for_user($childId, $invitedUserId) === null, 'invited user waits for explicit child access');
set_child_access_users((int)$family['id'], $childId, [$invitedUserId]);
assert_true(child_for_user($childId, $invitedUserId) !== null, 'owner can grant child access to invited user');
set_child_access_users((int)$family['id'], $childId, []);
assert_true(child_for_user($childId, $invitedUserId) === null, 'owner can revoke child access from invited user');
set_child_access_users((int)$family['id'], $childId, [$invitedUserId]);
assert_true(user_owned_family_count($userId) === 1, 'owner family count is detected');

$symptomTypeId = record_type_id((int)$family['id'], 'SYMPTOMS');
$pdo->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, note) VALUES (?, ?, ?, ?, ?)')
    ->execute([$childId, $symptomTypeId, now_sql(), $userId, 'Test']);
$recordId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO symptom_records (health_record_id, symptoms, severity) VALUES (?, ?, ?)')
    ->execute([$recordId, 'kašel, rýma', 'mild']);
$record = record_for_user($recordId, $userId);
assert_true($record && $record['code'] === 'SYMPTOMS' && $record['symptoms'] === 'kašel, rýma', 'symptom record is readable');

$pdo->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, note) VALUES (?, ?, ?, ?, ?)')
    ->execute([$childId, $symptomTypeId, now_sql(), $invitedUserId, 'Záznam od přizvaného rodiče']);
delete_user_account($invitedUserId);
assert_true(find_user($invitedUserId) === null, 'deleted account is no longer active');
$deletedUser = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$deletedUser->execute([$invitedUserId]);
$deletedUserRow = $deletedUser->fetch();
assert_true($deletedUserRow && (int)$deletedUserRow['is_active'] === 0, 'deleted account is deactivated');
assert_true(strpos((string)$deletedUserRow['email'], 'deleted-user-' . $invitedUserId . '-') === 0, 'deleted account email is anonymized');
assert_true(child_for_user($childId, $invitedUserId) === null, 'deleted account loses child access');
assert_true(current_family($invitedUserId) === null, 'deleted account loses family membership');

$lateInvitation = create_family_invitation((int)$family['id'], $userId, 'treti.rodic@example.test');
$lateUserId = create_user('treti.rodic@example.test', 'Třetí rodič', 'bezpecne-heslo-789');
$pendingAfterRegistration = pending_family_invitations((int)$family['id']);
$registeredInvitation = null;
foreach ($pendingAfterRegistration as $item) {
    if ($item['invited_email'] === 'treti.rodic@example.test') {
        $registeredInvitation = $item;
    }
}
assert_true($registeredInvitation && (int)$registeredInvitation['registered_user_id'] === $lateUserId, 'pending invitation detects already registered user');
$acceptedRegistered = accept_registered_family_invitation((int)$family['id'], (int)$registeredInvitation['id']);
assert_true($acceptedRegistered !== null, 'registered invited user can be added to family');
assert_true(current_family($lateUserId) && (int)current_family($lateUserId)['id'] === (int)$family['id'], 'registered invited user becomes family member');
assert_true(child_for_user($childId, $lateUserId) === null, 'registered invited user has no child access until owner grants it');

$sideOwnerUserId = create_user('google.rodic@example.test', 'Google rodič', null, 'google-parent-side-family');
$sideFamily = ensure_family($sideOwnerUserId, 'Google rodič');
create_family_invitation((int)$family['id'], $userId, 'google.rodic@example.test');
accept_pending_invitations_for_user($sideOwnerUserId, 'google.rodic@example.test');
assert_true(user_owned_family_count($sideOwnerUserId) === 1, 'parent can have a side owned family');
assert_true((int)current_family($sideOwnerUserId)['id'] === (int)$family['id'], 'invited parent sees the shared family as current family');
delete_user_account($sideOwnerUserId);
assert_true(find_user($sideOwnerUserId) === null, 'parent with side owned family can delete account');
$sideFamilyCount = $pdo->prepare('SELECT COUNT(*) FROM families WHERE id = ?');
$sideFamilyCount->execute([$sideFamily['id']]);
assert_true((int)$sideFamilyCount->fetchColumn() === 0, 'side owned family is removed during account deletion');

$providerRow = [
    'MistoPoskytovaniId' => 'test-provider-1',
    'NazevCely' => 'Dětská ordinace Test',
    'PoskytovatelNazev' => 'Dětská ordinace Test s.r.o.',
    'DruhZarizeni' => 'Samostatná ordinace praktického lékaře',
    'OborPece' => 'praktické lékařství pro děti a dorost, alergologie a klinická imunologie',
    'FormaPece' => 'ambulantní péče',
    'DruhPece' => '',
    'Obec' => 'Praha',
    'Psc' => '11000',
    'Ulice' => 'Testovací',
    'CisloDomovniOrientacni' => '1',
    'Kraj' => 'Hlavní město Praha',
    'Okres' => 'Hlavní město Praha',
    'PoskytovatelTelefon' => '+420123456789',
    'PoskytovatelEmail' => 'ordinace@example.test',
    'PoskytovatelWeb' => 'https://example.test',
    'OdbornyZastupce' => 'MUDr. Test',
    'GPS' => '',
    'LastModified' => '2026-06-01 00:00:00',
];
assert_true(import_nrpzs_provider_row($providerRow), 'provider row is imported');
$fields = array_column(healthcare_provider_fields(), 'care_field');
assert_true(in_array('Praktické lékařství pro děti a dorost', $fields, true), 'first provider specialty is indexed with uppercase initial');
assert_true(in_array('Alergologie a klinická imunologie', $fields, true), 'second provider specialty is indexed with uppercase initial');
assert_true(!in_array('Samostatná ordinace praktického lékaře', $fields, true), 'facility type is not indexed as a care field');
$providers = search_healthcare_providers('Dětská', 'Alergologie a klinická imunologie', 'Praha');
assert_true(count($providers) === 1, 'provider search finds imported provider');
assert_true(strpos((string)$providers[0]['specialties'], 'Praktické lékařství pro děti a dorost') !== false, 'provider result includes all specialties');
add_child_doctor($childId, (int)$providers[0]['id'], 'Pediatr');
$doctors = child_doctors($childId);
assert_true(count($doctors) === 1 && $doctors[0]['role_label'] === 'Pediatr', 'doctor can be assigned to child');
$documentId = create_child_document($childId, $userId, 'Zpráva z kontroly', 'Doporučen klidový režim', (int)$providers[0]['id'], 'zprava.pdf', 'documents/test/zprava.pdf', 'application/pdf', 1234);
$documents = child_documents($childId);
assert_true(count($documents) === 1 && $documents[0]['title'] === 'Zpráva z kontroly', 'child document is listed');
assert_true(child_document_for_user($documentId, $userId) !== null, 'child document can be opened by authorized parent');
assert_true(delete_child_document($documentId, $childId) !== null, 'child document can be deleted');
assert_true(remove_child_doctor($childId, (int)$doctors[0]['id']) !== null, 'doctor can be removed from child');

$duplicateProviderA = $providerRow;
$duplicateProviderB = $providerRow;
$duplicateProviderA['MistoPoskytovaniId'] = 'dup-provider-1';
$duplicateProviderB['MistoPoskytovaniId'] = 'dup-provider-1';
$duplicateProviderA['NazevCely'] = 'Duplicitní ordinace A';
$duplicateProviderB['NazevCely'] = 'Duplicitní ordinace B';
$duplicateProviderA['__duplicate_source_id'] = true;
$duplicateProviderB['__duplicate_source_id'] = true;
assert_true(import_nrpzs_provider_row($duplicateProviderA), 'first duplicate provider row is imported');
assert_true(import_nrpzs_provider_row($duplicateProviderB), 'second duplicate provider row is imported');
$duplicateResults = search_healthcare_providers('Duplicitní ordinace', '', 'Praha');
assert_true(count($duplicateResults) === 2, 'duplicate source ids keep both provider rows');

@unlink($dbPath);
echo "OK\n";

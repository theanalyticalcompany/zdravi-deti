<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tmpDir = sys_get_temp_dir() . '/zdravi-deti-negative-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "FAIL: cannot create temp dir\n");
    exit(1);
}

$dbPath = $tmpDir . '/negative.sqlite';
$configPath = $tmpDir . '/config.php';
$port = pick_free_port();
$baseUrl = 'http://127.0.0.1:' . $port;
$uploadPrefix = 'security-negative-' . bin2hex(random_bytes(4));

$config = [
    'app' => [
        'name' => 'Zdravi deti negative test',
        'base_url' => $baseUrl,
        'session_name' => 'zdravi_deti_negative_' . bin2hex(random_bytes(3)),
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
        'redirect_uri' => $baseUrl . '/?r=google_callback',
    ],
    'documents' => [
        'encrypt_uploads' => true,
        'encryption_key' => 'base64:' . base64_encode(str_repeat('n', 32)),
    ],
];

file_put_contents($configPath, "<?php\n\nreturn " . var_export($config, true) . ";\n");

date_default_timezone_set($config['app']['timezone']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'negative-test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string)file_get_contents($root . '/database/schema.sqlite.sql'));

require $root . '/app/helpers.php';
require $root . '/app/repositories.php';
ensure_runtime_schema();

$fixture = seed_negative_fixture($root, $uploadPrefix);
$server = start_server($root, $port, $configPath);
$failed = false;

try {
    wait_for_server($baseUrl);

    $anon = new TestClient($baseUrl);
    $ownerA = new TestClient($baseUrl);
    $parentA = new TestClient($baseUrl);
    $noChild = new TestClient($baseUrl);
    $ownerB = new TestClient($baseUrl);
    $outsider = new TestClient($baseUrl);
    $unverified = new TestClient($baseUrl);

    assert_redirect_to_login($anon->get('/?r=dashboard'), 'anonymous dashboard requires login');

    $badLogin = new TestClient($baseUrl);
    $loginPage = $badLogin->get('/?r=login');
    $badLoginResponse = $badLogin->post('/?r=login', [
        'csrf' => csrf_from($loginPage->body),
        'email' => 'owner.a@example.test',
        'password' => 'wrong-password',
    ]);
    assert_status($badLoginResponse, 200, 'wrong password stays on login page');
    assert_redirect_to_login($badLogin->get('/?r=dashboard'), 'wrong password does not create session');
    assert_true(count_rows("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_failed'") >= 1, 'wrong password is audited');
    $sqliLogin = new TestClient($baseUrl);
    $sqliLoginPage = $sqliLogin->get('/?r=login');
    $sqliLoginResponse = $sqliLogin->post('/?r=login', [
        'csrf' => csrf_from($sqliLoginPage->body),
        'email' => "' OR 1=1 --",
        'password' => "' OR 1=1 --",
    ]);
    assert_status($sqliLoginResponse, 200, 'SQLi login payload is rejected');
    assert_redirect_to_login($sqliLogin->get('/?r=dashboard'), 'SQLi login payload does not create session');

    $rateLimited = new TestClient($baseUrl);
    $ratePage = $rateLimited->get('/?r=login');
    $rateCsrf = csrf_from($ratePage->body);
    for ($i = 0; $i < 5; $i++) {
        $rateLimited->post('/?r=login', [
            'csrf' => $rateCsrf,
            'email' => 'ratelimit@example.test',
            'password' => 'bad-password',
        ]);
    }
    assert_true(rate_limit_blocked_seconds('login', 'ratelimit@example.test') > 0, 'login rate limit blocks repeated failures');

    login_as($ownerA, 'owner.a@example.test', 'owner-a-password-123');
    login_as($parentA, 'parent.a@example.test', 'parent-a-password-123');
    login_as($noChild, 'nochild.a@example.test', 'nochild-a-password-123');
    login_as($ownerB, 'owner.b@example.test', 'owner-b-password-123');
    login_as($outsider, 'outsider@example.test', 'outsider-password-123');
    login_as($unverified, 'unverified@example.test', 'unverified-password-123');

    $invalidVerify = $anon->get('/?r=email_verify&token=' . str_repeat('a', 64));
    assert_redirect_to_login($invalidVerify, 'invalid email verification token redirects safely');
    assert_true(!user_email_is_verified(find_user($fixture['unverified_user_id'])), 'invalid verification token does not verify user');

    $registerNoCsrf = $anon->post('/?r=register', [
        'display_name' => 'No CSRF',
        'email' => 'nocsrf@example.test',
        'password' => 'nocsrf-password-123',
    ]);
    assert_status($registerNoCsrf, 419, 'registration without CSRF is rejected');
    assert_true(find_password_user_by_email('nocsrf@example.test') === null, 'registration without CSRF creates no user');

    $dashboardBeforeLogout = $ownerA->get('/?r=dashboard');
    assert_status($dashboardBeforeLogout, 200, 'owner session is active before logout checks');
    $logoutGet = $ownerA->get('/?r=logout');
    assert_redirect($logoutGet, '?r=dashboard', 'GET logout does not log out active user');
    assert_status($ownerA->get('/?r=dashboard'), 200, 'GET logout leaves session active');
    $logoutNoCsrf = $ownerA->post('/?r=logout', []);
    assert_status($logoutNoCsrf, 419, 'POST logout without CSRF is rejected');
    assert_status($ownerA->get('/?r=dashboard'), 200, 'failed logout leaves session active');

    $loginHeaders = $anon->get('/?r=login');
    assert_header_contains($loginHeaders, 'content-security-policy', "script-src 'self'", 'CSP is present locally');
    assert_header_not_contains($loginHeaders, 'content-security-policy', 'unsafe-inline', 'CSP does not allow unsafe-inline');

    $tempCount = count_rows('SELECT COUNT(*) FROM health_records WHERE child_id = ?', [$fixture['child_a1_id']]);
    $noCsrfTemperature = $ownerA->post('/?r=temperature_save', [
        'child_id' => (string)$fixture['child_a1_id'],
        'event_at' => date('Y-m-d\TH:i'),
        'temperature_celsius' => '38.1',
    ]);
    assert_status($noCsrfTemperature, 419, 'temperature save without CSRF is rejected');
    assert_same($tempCount, count_rows('SELECT COUNT(*) FROM health_records WHERE child_id = ?', [$fixture['child_a1_id']]), 'temperature without CSRF creates no record');

    assert_status($parentA->get('/?r=child&id=' . $fixture['child_a1_id']), 200, 'shared child is visible to granted parent');
    assert_status($parentA->get('/?r=child&id=' . $fixture['child_a2_id']), 404, 'unshared sibling child is hidden from parent');
    assert_status($noChild->get('/?r=child&id=' . $fixture['child_a1_id']), 404, 'family member without child access cannot open child detail');

    foreach ([
        '/?r=child&id=' . $fixture['child_a1_id'],
        '/?r=child_timeline&id=' . $fixture['child_a1_id'] . '&range=72',
        '/?r=export&child_id=' . $fixture['child_a1_id'],
        '/?r=child_doctors&child_id=' . $fixture['child_a1_id'],
        '/?r=record_edit&id=' . $fixture['record_a_id'],
        '/?r=document_view&id=' . $fixture['document_a_id'],
        '/?r=document_inline&id=' . $fixture['document_a_id'],
        '/?r=document_download&id=' . $fixture['document_a_id'],
    ] as $path) {
        assert_status($outsider->get($path), 404, 'outsider cannot access protected route ' . $path);
    }

    foreach ([
        '/?r=document_view&id=' . $fixture['document_a_id'],
        '/?r=document_inline&id=' . $fixture['document_a_id'],
        '/?r=document_download&id=' . $fixture['document_a_id'],
    ] as $path) {
        assert_redirect_to_login($anon->get($path), 'anonymous document route requires login: ' . $path);
    }
    $textInline = $ownerA->get('/?r=document_inline&id=' . $fixture['document_a_id']);
    assert_status($textInline, 200, 'text document inline route returns file');
    assert_header_contains($textInline, 'content-disposition', 'attachment', 'non-preview text document is forced to attachment');
    assert_header_contains($textInline, 'content-type', 'application/octet-stream', 'non-preview text document is served as octet-stream inline fallback');

    $familyCountBefore = count_rows('SELECT COUNT(*) FROM families WHERE id = ?', [$fixture['family_a_id']]);
    $parentFamilyPage = $parentA->get('/?r=family');
    assert_status($parentA->post('/?r=family_delete', ['csrf' => csrf_from($parentFamilyPage->body)]), 403, 'parent cannot delete family');
    assert_same($familyCountBefore, count_rows('SELECT COUNT(*) FROM families WHERE id = ?', [$fixture['family_a_id']]), 'parent family delete changes no data');
    assert_status($parentA->post('/?r=member_add', [
        'csrf' => csrf_from($parentFamilyPage->body),
        'email' => 'new-parent@example.test',
    ]), 403, 'parent cannot invite another parent');
    assert_true(pending_family_invitation_by_email($fixture['family_a_id'], 'new-parent@example.test') === null, 'parent invite creates no invitation');

    $ownerBFamilyPage = $ownerB->get('/?r=family');
    $accessBefore = count_rows('SELECT COUNT(*) FROM child_access WHERE child_id = ?', [$fixture['child_a1_id']]);
    assert_status($ownerB->post('/?r=access_save', [
        'csrf' => csrf_from($ownerBFamilyPage->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'user_ids' => [(string)$fixture['owner_b_id']],
    ]), 404, 'owner of another family cannot change child access');
    assert_same($accessBefore, count_rows('SELECT COUNT(*) FROM child_access WHERE child_id = ?', [$fixture['child_a1_id']]), 'foreign access update changes no access rows');

    assert_status($ownerB->post('/?r=child_profile_save', [
        'csrf' => csrf_from($ownerBFamilyPage->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'first_name' => 'Compromised',
        'last_name' => 'Child',
        'date_of_birth' => '2020-01-01',
    ]), 404, 'owner of another family cannot update child profile');
    assert_same('Anna', child_row($fixture['child_a1_id'])['first_name'], 'foreign child profile update changes no child data');

    assert_status($ownerB->post('/?r=child_delete', [
        'csrf' => csrf_from($ownerBFamilyPage->body),
        'child_id' => (string)$fixture['child_a1_id'],
    ]), 404, 'owner of another family cannot delete child');
    assert_true(child_row($fixture['child_a1_id']) !== null, 'foreign child delete leaves child intact');

    $ownerBDashboard = $ownerB->get('/?r=dashboard');
    assert_status($ownerB->post('/?r=temperature_save', [
        'csrf' => csrf_from($ownerBDashboard->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'event_at' => date('Y-m-d\TH:i'),
        'temperature_celsius' => '38.3',
    ]), 404, 'foreign user cannot create temperature for child');
    assert_same($tempCount, count_rows('SELECT COUNT(*) FROM health_records WHERE child_id = ?', [$fixture['child_a1_id']]), 'foreign temperature creates no record');

    $ownerAChild = $ownerA->get('/?r=child&id=' . $fixture['child_a1_id']);
    $doctorSearchPayload = $ownerA->get('/?r=child_doctors&child_id=' . $fixture['child_a1_id'] . '&q=' . urlencode("' OR 1=1 --"));
    assert_status($doctorSearchPayload, 200, 'SQLi provider search payload returns safe page');
    assert_not_contains($doctorSearchPayload->body, 'Safe Provider Injection Test', 'SQLi provider search does not return all providers');
    $recordCountBefore = count_rows('SELECT COUNT(*) FROM health_records WHERE child_id = ?', [$fixture['child_a1_id']]);
    $invalidTemperature = $ownerA->post('/?r=temperature_save', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'event_at' => date('Y-m-d\TH:i'),
        'temperature_celsius' => '99',
    ]);
    assert_status($invalidTemperature, 302, 'invalid temperature is rejected with safe redirect');
    assert_same($recordCountBefore, count_rows('SELECT COUNT(*) FROM health_records WHERE child_id = ?', [$fixture['child_a1_id']]), 'invalid temperature creates no record');

    $futureTemperature = $ownerA->post('/?r=temperature_save', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'event_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i'),
        'temperature_celsius' => '38.1',
    ]);
    assert_status($futureTemperature, 302, 'future temperature time is rejected with safe redirect');
    assert_same($recordCountBefore, count_rows('SELECT COUNT(*) FROM health_records WHERE child_id = ?', [$fixture['child_a1_id']]), 'future temperature creates no record');

    $medRecordCount = count_rows('SELECT COUNT(*) FROM medication_administrations');
    assert_status($ownerA->post('/?r=medication_record_save', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'event_at' => date('Y-m-d\TH:i'),
        'medication_id' => (string)$fixture['medication_b_id'],
    ]), 302, 'medication from another family is rejected');
    assert_same($medRecordCount, count_rows('SELECT COUNT(*) FROM medication_administrations'), 'foreign medication creates no administration');

    $careRecordCount = count_rows("SELECT COUNT(*) FROM health_records hr JOIN record_types rt ON rt.id = hr.record_type_id WHERE rt.kind = 'CARE'");
    assert_status($ownerA->post('/?r=care_record_save', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'event_at' => date('Y-m-d\TH:i'),
        'record_type_id' => (string)$fixture['care_type_b_id'],
        'note' => 'foreign care type',
    ]), 302, 'care type from another family is rejected');
    assert_same($careRecordCount, count_rows("SELECT COUNT(*) FROM health_records hr JOIN record_types rt ON rt.id = hr.record_type_id WHERE rt.kind = 'CARE'"), 'foreign care type creates no care record');

    assert_status($ownerB->get('/?r=record_edit&id=' . $fixture['record_a_id']), 404, 'foreign user cannot open record edit');
    assert_status($ownerB->post('/?r=record_delete', [
        'csrf' => csrf_from($ownerBDashboard->body),
        'record_id' => (string)$fixture['record_a_id'],
    ]), 404, 'foreign user cannot delete record');
    assert_true(record_exists($fixture['record_a_id']), 'foreign record delete leaves record intact');

    $docAPath = uploaded_document_path_for_test($root, $fixture['document_a_path']);
    assert_true(is_file($docAPath), 'fixture document file exists before delete checks');
    $docCountBefore = count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_a1_id']]);
    assert_status($ownerB->post('/?r=document_delete', [
        'csrf' => csrf_from($ownerBDashboard->body),
        'document_id' => (string)$fixture['document_a_id'],
        'return_to' => 'dashboard',
    ]), 302, 'foreign document delete redirects safely');
    assert_true(document_row($fixture['document_a_id']) !== null, 'foreign document delete leaves DB row intact');
    assert_true(is_file($docAPath), 'foreign document delete leaves file intact');

    $badUpload = $tmpDir . '/shell.php';
    file_put_contents($badUpload, '<?php echo "bad";');
    assert_status($ownerA->multipart('/?r=document_upload', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'title' => 'Bad upload',
        'document_type' => 'general',
    ], ['document_file' => $badUpload]), 302, 'disallowed upload extension redirects safely');
    assert_same($docCountBefore, count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_a1_id']]), 'disallowed upload creates no document');
    $doubleExtensionUpload = $tmpDir . '/shell.php.jpg';
    file_put_contents($doubleExtensionUpload, "\xFF\xD8\xFF\xE0" . str_repeat('A', 32));
    assert_status($ownerA->multipart('/?r=document_upload', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'title' => 'Double extension upload',
        'document_type' => 'general',
    ], ['document_file' => $doubleExtensionUpload]), 302, 'double executable extension upload redirects safely');
    assert_same($docCountBefore, count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_a1_id']]), 'double executable extension creates no document');
    $fakePdfUpload = $tmpDir . '/fake.pdf';
    file_put_contents($fakePdfUpload, "<html><script>alert(1)</script></html>");
    assert_status($ownerA->multipart('/?r=document_upload', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'title' => 'Fake PDF upload',
        'document_type' => 'general',
    ], ['document_file' => $fakePdfUpload]), 302, 'fake PDF upload redirects safely');
    assert_same($docCountBefore, count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_a1_id']]), 'fake PDF creates no document');
    $eicarUpload = $tmpDir . '/eicar.txt';
    file_put_contents($eicarUpload, 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*');
    assert_throws(fn() => document_validate_upload_content($eicarUpload, 'txt', 'text/plain'), 'EICAR-like content is rejected by validator');
    assert_same($docCountBefore, count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_a1_id']]), 'EICAR-like validation creates no document');
    assert_throws(fn() => document_validate_upload_name('../safe.txt'), 'path traversal upload name is rejected by validator');
    assert_throws(fn() => document_validate_upload_name('safe..txt'), 'dot-dot upload name is rejected by validator');
    assert_same($docCountBefore, count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_a1_id']]), 'path traversal validation creates no document');

    $xssTitleUpload = $tmpDir . '/plain.txt';
    file_put_contents($xssTitleUpload, 'plain text');
    assert_status($ownerA->multipart('/?r=document_upload', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'title' => '<script>alert(1)</script>',
        'document_type' => 'general',
    ], ['document_file' => $xssTitleUpload]), 302, 'plain text upload with XSS title redirects safely');
    $xssDocumentId = latest_document_id($fixture['child_a1_id']);
    $xssDocumentView = $ownerA->get('/?r=document_view&id=' . $xssDocumentId);
    assert_status($xssDocumentView, 200, 'document with XSS title can be opened safely');
    assert_not_contains($xssDocumentView->body, '<script>alert(1)</script>', 'document title is escaped and not rendered as script');
    assert_contains($xssDocumentView->body, '&lt;script&gt;alert(1)&lt;/script&gt;', 'document title is rendered escaped');
    $docCountBefore = count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_a1_id']]);

    $txtUpload = $tmpDir . '/note.txt';
    file_put_contents($txtUpload, 'valid text but wrong child');
    $docBCountBefore = count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_b_id']]);
    assert_status($ownerA->multipart('/?r=document_upload', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_b_id'],
        'title' => 'Wrong child upload',
        'document_type' => 'general',
    ], ['document_file' => $txtUpload]), 404, 'authorized user cannot upload to foreign child');
    assert_same($docBCountBefore, count_rows('SELECT COUNT(*) FROM child_documents WHERE child_id = ?', [$fixture['child_b_id']]), 'foreign child upload creates no document');

    $appointmentCountBefore = count_rows('SELECT COUNT(*) FROM child_appointments WHERE child_id = ?', [$fixture['child_a1_id']]);
    assert_status($ownerA->post('/?r=appointment_save', [
        'csrf' => csrf_from($ownerAChild->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'title' => 'Cross document appointment',
        'appointment_type' => 'Kontrola',
        'scheduled_at' => (new DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i'),
        'status' => 'planned',
        'document_ids' => [(string)$fixture['document_b_id']],
    ]), 302, 'appointment with foreign document redirects safely');
    assert_same($appointmentCountBefore + 1, count_rows('SELECT COUNT(*) FROM child_appointments WHERE child_id = ?', [$fixture['child_a1_id']]), 'valid appointment itself is created');
    $newAppointmentId = latest_appointment_id($fixture['child_a1_id']);
    assert_same([], appointment_document_ids($newAppointmentId), 'foreign document is not linked to appointment');

    assert_status($ownerB->post('/?r=appointment_delete', [
        'csrf' => csrf_from($ownerBDashboard->body),
        'child_id' => (string)$fixture['child_a1_id'],
        'appointment_id' => (string)$fixture['appointment_a_id'],
    ]), 404, 'foreign user cannot delete appointment');
    assert_true(appointment_exists($fixture['appointment_a_id']), 'foreign appointment delete leaves appointment intact');

    $pendingBefore = pending_family_invitation_by_id($fixture['family_a_id'], $fixture['invitation_a_id']);
    assert_true($pendingBefore !== null, 'fixture invitation is pending before ownerB cancellation attempt');
    assert_status($ownerB->post('/?r=invitation_cancel', [
        'csrf' => csrf_from($ownerBFamilyPage->body),
        'invitation_id' => (string)$fixture['invitation_a_id'],
    ]), 302, 'foreign owner cannot cancel another family invitation');
    assert_true(pending_family_invitation_by_id($fixture['family_a_id'], $fixture['invitation_a_id']) !== null, 'foreign invitation cancel leaves invitation pending');

    $ownerAFamilyPage = $ownerA->get('/?r=family');
    assert_status($ownerA->post('/?r=invitation_accept_registered', [
        'csrf' => csrf_from($ownerAFamilyPage->body),
        'invitation_id' => (string)$fixture['invitation_unverified_id'],
    ]), 302, 'owner cannot add registered user until email is verified');
    assert_true(!is_family_member($fixture['family_a_id'], $fixture['unverified_user_id']), 'unverified invited user is not family member');

    assert_status($ownerA->post('/?r=invitation_cancel', [
        'csrf' => csrf_from($ownerAFamilyPage->body),
        'invitation_id' => (string)$fixture['invitation_cancel_id'],
    ]), 302, 'owner can cancel invitation for reuse');
    assert_true(pending_family_invitation_by_id($fixture['family_a_id'], $fixture['invitation_cancel_id']) === null, 'cancelled invitation is gone');
    assert_status($ownerA->post('/?r=invitation_accept_registered', [
        'csrf' => csrf_from($ownerAFamilyPage->body),
        'invitation_id' => (string)$fixture['invitation_cancel_id'],
    ]), 302, 'cancelled invitation cannot be accepted later');

    echo "OK security negative flows\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $failed = true;
} finally {
    stop_server($server);
    cleanup_upload_prefix($root, $uploadPrefix);
    recursive_remove($tmpDir);
}

if ($failed) {
    exit(1);
}

function seed_negative_fixture(string $root, string $uploadPrefix): array
{
    $ownerAId = create_user('owner.a@example.test', 'Owner A', 'owner-a-password-123', null, true);
    $parentAId = create_user('parent.a@example.test', 'Parent A', 'parent-a-password-123', null, true);
    $noChildId = create_user('nochild.a@example.test', 'No Child A', 'nochild-a-password-123', null, true);
    $ownerBId = create_user('owner.b@example.test', 'Owner B', 'owner-b-password-123', null, true);
    $outsiderId = create_user('outsider@example.test', 'Outsider', 'outsider-password-123', null, true);
    $unverifiedId = create_user('unverified@example.test', 'Unverified', 'unverified-password-123');

    $familyA = ensure_family($ownerAId, 'Owner A');
    $familyB = ensure_family($ownerBId, 'Owner B');
    ensure_family($outsiderId, 'Outsider');
    add_user_to_family((int)$familyA['id'], $parentAId);
    add_user_to_family((int)$familyA['id'], $noChildId);

    $childA1Id = insert_child((int)$familyA['id'], 'Anna', 'A', '2020-01-01');
    $childA2Id = insert_child((int)$familyA['id'], 'Boris', 'A', '2021-02-02');
    $childBId = insert_child((int)$familyB['id'], 'Cyril', 'B', '2022-03-03');
    set_child_access_users((int)$familyA['id'], $childA1Id, [$parentAId]);
    set_child_access_users((int)$familyA['id'], $childA2Id, []);
    set_child_access_users((int)$familyB['id'], $childBId, []);

    $careTypeAId = insert_custom_care_type((int)$familyA['id'], 'CARE_A', 'Care A');
    $careTypeBId = insert_custom_care_type((int)$familyB['id'], 'CARE_B', 'Care B');
    $medicationAId = first_medication_id_for_family((int)$familyA['id']);
    $medicationBId = first_medication_id_for_family((int)$familyB['id']);

    $recordAId = insert_temperature_record($childA1Id, (int)$familyA['id'], $ownerAId, '37.8');

    $documentAPath = $uploadPrefix . '/doc-a.txt';
    $documentBPath = $uploadPrefix . '/doc-b.txt';
    write_uploaded_test_file($root, $documentAPath, 'document A');
    write_uploaded_test_file($root, $documentBPath, 'document B');
    $documentAId = create_child_document($childA1Id, $ownerAId, 'Document A', '', null, 'doc-a.txt', $documentAPath, 'text/plain', 10);
    $documentBId = create_child_document($childBId, $ownerBId, 'Document B', '', null, 'doc-b.txt', $documentBPath, 'text/plain', 10);
    import_nrpzs_provider_row([
        'MistoPoskytovaniId' => 'negative-provider-1',
        'NazevCely' => 'Safe Provider Injection Test',
        'PoskytovatelNazev' => 'Safe Provider Injection Test s.r.o.',
        'OborPece' => 'praktické lékařství pro děti a dorost',
        'Obec' => 'Praha',
        'Ulice' => 'Bezpecna',
        'CisloDomovniOrientacni' => '1',
        'Okres' => 'Praha',
        'LastModified' => '2026-01-01 00:00:00',
    ]);

    $appointmentAId = save_child_appointment($childA1Id, $ownerAId, null, [
        'title' => 'Existing appointment',
        'appointment_type' => 'Kontrola',
        'scheduled_at' => (new DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i'),
        'status' => 'planned',
    ], [$documentAId]);

    create_family_invitation((int)$familyA['id'], $ownerAId, 'pending@example.test');
    $invitationA = pending_family_invitation_by_email((int)$familyA['id'], 'pending@example.test');
    create_family_invitation((int)$familyA['id'], $ownerAId, 'unverified@example.test');
    mark_invitations_registered('unverified@example.test');
    $invitationUnverified = pending_family_invitation_by_email((int)$familyA['id'], 'unverified@example.test');
    create_family_invitation((int)$familyA['id'], $ownerAId, 'cancel-me@example.test');
    $invitationCancel = pending_family_invitation_by_email((int)$familyA['id'], 'cancel-me@example.test');

    return [
        'owner_a_id' => $ownerAId,
        'parent_a_id' => $parentAId,
        'no_child_id' => $noChildId,
        'owner_b_id' => $ownerBId,
        'outsider_id' => $outsiderId,
        'unverified_user_id' => $unverifiedId,
        'family_a_id' => (int)$familyA['id'],
        'family_b_id' => (int)$familyB['id'],
        'child_a1_id' => $childA1Id,
        'child_a2_id' => $childA2Id,
        'child_b_id' => $childBId,
        'care_type_a_id' => $careTypeAId,
        'care_type_b_id' => $careTypeBId,
        'medication_a_id' => $medicationAId,
        'medication_b_id' => $medicationBId,
        'record_a_id' => $recordAId,
        'document_a_id' => $documentAId,
        'document_b_id' => $documentBId,
        'document_a_path' => $documentAPath,
        'appointment_a_id' => $appointmentAId,
        'invitation_a_id' => (int)$invitationA['id'],
        'invitation_unverified_id' => (int)$invitationUnverified['id'],
        'invitation_cancel_id' => (int)$invitationCancel['id'],
    ];
}

function insert_child(int $familyId, string $firstName, string $lastName, string $dateOfBirth): int
{
    db()->prepare('INSERT INTO children (family_id, first_name, last_name, date_of_birth) VALUES (?, ?, ?, ?)')
        ->execute([$familyId, $firstName, $lastName, $dateOfBirth]);
    return (int)db()->lastInsertId();
}

function insert_custom_care_type(int $familyId, string $code, string $name): int
{
    db()->prepare("INSERT INTO record_types (family_id, code, name, kind, is_system, is_active) VALUES (?, ?, ?, 'CARE', 0, 1)")
        ->execute([$familyId, $code, $name]);
    return (int)db()->lastInsertId();
}

function first_medication_id_for_family(int $familyId): int
{
    $stmt = db()->prepare('SELECT id FROM medications WHERE family_id = ? AND is_active = 1 ORDER BY id LIMIT 1');
    $stmt->execute([$familyId]);
    return (int)$stmt->fetchColumn();
}

function insert_temperature_record(int $childId, int $familyId, int $userId, string $temperature): int
{
    $typeId = record_type_id($familyId, 'TEMPERATURE');
    db()->prepare('INSERT INTO health_records (child_id, record_type_id, event_at, created_by_user_id, place, note) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$childId, $typeId, now_sql(), $userId, 'home', 'fixture']);
    $recordId = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO temperature_records (health_record_id, temperature_celsius) VALUES (?, ?)')
        ->execute([$recordId, $temperature]);
    return $recordId;
}

function write_uploaded_test_file(string $root, string $storagePath, string $contents): void
{
    $path = uploaded_document_path_for_test($root, $storagePath);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Cannot create upload test directory');
    }
    file_put_contents($path, $contents);
}

function uploaded_document_path_for_test(string $root, string $storagePath): string
{
    return $root . '/var/uploads/' . ltrim(str_replace(['\\', '..'], ['/', ''], $storagePath), '/');
}

function cleanup_upload_prefix(string $root, string $uploadPrefix): void
{
    recursive_remove($root . '/var/uploads/' . $uploadPrefix);
}

function login_as(TestClient $client, string $email, string $password): void
{
    $login = $client->get('/?r=login');
    assert_status($login, 200, 'login page opens for ' . $email);
    $response = $client->post('/?r=login', [
        'csrf' => csrf_from($login->body),
        'email' => $email,
        'password' => $password,
    ]);
    assert_redirect($response, '?r=dashboard', 'login redirects to dashboard for ' . $email);
    assert_status($client->followLastRedirect(), 200, 'dashboard opens for ' . $email);
}

function count_rows(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function child_row(int $childId): ?array
{
    $stmt = db()->prepare('SELECT * FROM children WHERE id = ? LIMIT 1');
    $stmt->execute([$childId]);
    return $stmt->fetch() ?: null;
}

function document_row(int $documentId): ?array
{
    $stmt = db()->prepare('SELECT * FROM child_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$documentId]);
    return $stmt->fetch() ?: null;
}

function record_exists(int $recordId): bool
{
    return count_rows('SELECT COUNT(*) FROM health_records WHERE id = ?', [$recordId]) === 1;
}

function appointment_exists(int $appointmentId): bool
{
    return count_rows('SELECT COUNT(*) FROM child_appointments WHERE id = ?', [$appointmentId]) === 1;
}

function latest_appointment_id(int $childId): int
{
    $stmt = db()->prepare('SELECT id FROM child_appointments WHERE child_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$childId]);
    return (int)$stmt->fetchColumn();
}

function latest_document_id(int $childId): int
{
    $stmt = db()->prepare('SELECT id FROM child_documents WHERE child_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$childId]);
    return (int)$stmt->fetchColumn();
}

function is_family_member(int $familyId, int $userId): bool
{
    return count_rows('SELECT COUNT(*) FROM family_members WHERE family_id = ? AND user_id = ?', [$familyId, $userId]) === 1;
}

final class TestResponse
{
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public ?string $location = null,
    ) {
    }
}

final class TestClient
{
    private array $cookies = [];
    private ?string $lastLocation = null;

    public function __construct(private string $baseUrl)
    {
    }

    public function get(string $path): TestResponse
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields): TestResponse
    {
        return $this->request('POST', $path, normalize_fields($fields));
    }

    public function multipart(string $path, array $fields, array $files): TestResponse
    {
        $fields = normalize_fields($fields);
        foreach ($files as $name => $filePath) {
            if (is_array($filePath)) {
                $fields[$name] = new CURLFile((string)$filePath['path'], (string)($filePath['mime'] ?? ''), (string)($filePath['name'] ?? basename((string)$filePath['path'])));
            } else {
                $fields[$name] = new CURLFile($filePath);
            }
        }
        return $this->request('POST', $path, $fields);
    }

    public function followLastRedirect(): TestResponse
    {
        assert_true($this->lastLocation !== null, 'last response has redirect');
        $location = $this->lastLocation;
        if (str_starts_with($location, 'http')) {
            $location = (string)parse_url($location, PHP_URL_PATH) . (parse_url($location, PHP_URL_QUERY) ? '?' . parse_url($location, PHP_URL_QUERY) : '');
        }
        return $this->get($location);
    }

    private function request(string $method, string $path, array $fields = []): TestResponse
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIE => $this->cookieHeader(),
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException(curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = [];
        $location = null;
        foreach (preg_split('/\r\n|\n|\r/', trim($headerText)) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($line, 11));
                $pair = explode('=', explode(';', $cookie, 2)[0], 2);
                if (count($pair) === 2) {
                    $this->cookies[$pair[0]] = $pair[1];
                }
            } elseif (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))][] = trim($value);
                if (strtolower(trim($name)) === 'location') {
                    $location = trim($value);
                }
            }
        }
        $this->lastLocation = $location;
        return new TestResponse($status, $headers, $body, $location);
    }

    private function cookieHeader(): string
    {
        $parts = [];
        foreach ($this->cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }
}

function normalize_fields(array $fields): array
{
    $normalized = [];
    foreach ($fields as $key => $value) {
        if (is_array($value)) {
            foreach (array_values($value) as $index => $item) {
                $normalized[$key . '[' . $index . ']'] = (string)$item;
            }
        } else {
            $normalized[$key] = $value;
        }
    }
    return $normalized;
}

function pick_free_port(): int
{
    for ($port = 8150; $port < 8210; $port++) {
        $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        if ($socket) {
            fclose($socket);
            return $port;
        }
    }
    throw new RuntimeException('No free local test port found');
}

function start_server(string $root, int $port, string $configPath)
{
    $cmd = '"' . PHP_BINARY . '" -d display_errors=1 -S 127.0.0.1:' . $port . ' -t public';
    putenv('ZD_CONFIG_PATH=' . $configPath);
    if (!is_dir($root . '/var')) {
        mkdir($root . '/var', 0775, true);
    }
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $root . '/var/php-negative-test.out.log', 'a'],
        2 => ['file', $root . '/var/php-negative-test.err.log', 'a'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start PHP server');
    }
    fclose($pipes[0]);
    return $process;
}

function stop_server($process): void
{
    if (is_resource($process)) {
        $status = proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>NUL');
        } else {
            proc_terminate($process);
        }
        proc_close($process);
    }
}

function wait_for_server(string $baseUrl): void
{
    $deadline = microtime(true) + 8;
    do {
        $ch = curl_init($baseUrl . '/?r=login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 300]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status === 200 && is_string($body)) {
            return;
        }
        if ($status >= 500 && is_string($body)) {
            throw new RuntimeException('Local server returned ' . $status . ': ' . strip_tags(substr($body, 0, 500)));
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Local server did not start');
}

function csrf_from(string $html): string
{
    if (!preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('CSRF token not found');
    }
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

function assert_same($expected, $actual, string $message): void
{
    assert_true($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function assert_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

function assert_status(TestResponse $response, int $status, string $message): void
{
    assert_true($response->status === $status, $message . ' (status ' . $response->status . ')');
}

function assert_redirect(TestResponse $response, string $location, string $message): void
{
    assert_status($response, 302, $message);
    assert_true($response->location === $location, $message . ' (location ' . ($response->location ?? '') . ')');
}

function assert_redirect_to_login(TestResponse $response, string $message): void
{
    assert_redirect($response, '?r=login', $message);
}

function assert_contains(string $haystack, string $needle, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, $message);
}

function assert_not_contains(string $haystack, string $needle, string $message): void
{
    assert_true(strpos($haystack, $needle) === false, $message);
}

function assert_header_contains(TestResponse $response, string $header, string $needle, string $message): void
{
    $value = implode(' ', $response->headers[strtolower($header)] ?? []);
    assert_true(stripos($value, $needle) !== false, $message . ' (' . $value . ')');
}

function assert_header_not_contains(TestResponse $response, string $header, string $needle, string $message): void
{
    $value = implode(' ', $response->headers[strtolower($header)] ?? []);
    assert_true(stripos($value, $needle) === false, $message . ' (' . $value . ')');
}

function recursive_remove(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        recursive_remove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

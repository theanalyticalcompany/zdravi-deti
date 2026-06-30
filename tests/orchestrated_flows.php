<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tmpDir = sys_get_temp_dir() . '/zdravi-deti-flow-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "FAIL: cannot create temp dir\n");
    exit(1);
}

$dbPath = $tmpDir . '/flow.sqlite';
$configPath = $tmpDir . '/config.php';
$mailLog = $tmpDir . '/mail.log';
$port = pick_free_port();
$baseUrl = 'http://127.0.0.1:' . $port;

$config = [
    'app' => [
        'name' => 'Zdravi deti test',
        'base_url' => $baseUrl,
        'session_name' => 'zdravi_deti_flow_' . bin2hex(random_bytes(3)),
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
        'encryption_key' => 'base64:' . base64_encode(str_repeat('t', 32)),
    ],
];

file_put_contents($configPath, "<?php\n\nreturn " . var_export($config, true) . ";\n");
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec((string)file_get_contents($root . '/database/schema.sqlite.sql'));

$server = start_server($root, $port, $configPath);
$failed = false;
try {
    wait_for_server($baseUrl);

    $owner = new TestClient($baseUrl);
    $parent = new TestClient($baseUrl);

    $register = $owner->get('/?r=register');
    assert_status($register, 200, 'register page opens');
    assert_contains($register->body, 'Vytvořit účet', 'register button is visible');
    $owner->post('/?r=register', [
        'csrf' => csrf_from($register->body),
        'display_name' => 'Prvni rodic',
        'email' => 'owner@example.test',
        'password' => 'bezpecne-heslo-owner',
    ]);
    $dashboard = $owner->followLastRedirect();
    assert_contains($dashboard->body, 'Správa rodiny', 'new owner lands in dashboard');

    $family = $owner->get('/?r=family');
    assert_all_controls($family->body, ['Přidat dítě', 'Přidat rodiče', 'Uložit', 'Zrušit rodinu'], 'family management controls');

    $owner->post('/?r=child_create', [
        'csrf' => csrf_from($family->body),
        'first_name' => 'Anna',
        'last_name' => 'Testova',
        'date_of_birth' => '2021-01-01',
        'weight_kg' => '18.5',
        'allergies' => 'penicilin',
    ]);
    $childOne = child_id_by_name($pdo, 'Anna');
    $childOnePage = $owner->followLastRedirect();
    assert_contains($childOnePage->body, 'Dokumentace', 'child detail has documentation control');

    $family = $owner->get('/?r=family');
    $owner->post('/?r=child_create', [
        'csrf' => csrf_from($family->body),
        'first_name' => 'Boris',
        'last_name' => 'Test',
        'date_of_birth' => '2023-03-15',
        'weight_kg' => '12',
        'allergies' => '',
    ]);
    $childTwo = child_id_by_name($pdo, 'Boris');
    assert_true($childOne > 0 && $childTwo > 0 && $childOne !== $childTwo, 'two children are created');

    $family = $owner->get('/?r=family');
    assert_contains($family->body, 'child-edit-' . $childOne, 'child can be edited from family management');
    $owner->post('/?r=child_profile_save', [
        'csrf' => csrf_from($family->body),
        'child_id' => (string)$childOne,
        'return_to' => 'family',
        'first_name' => 'Anna',
        'last_name' => 'Testova',
        'date_of_birth' => '2021-01-01',
        'weight_kg' => '19',
        'allergies' => 'penicilin',
    ]);
    $family = $owner->followLastRedirect();
    assert_contains($family->body, '19', 'child edit from family management is saved');

    $family = $owner->get('/?r=family');
    $owner->post('/?r=member_add', [
        'csrf' => csrf_from($family->body),
        'email' => 'parent@example.test',
    ]);
    $family = $owner->followLastRedirect();
    assert_contains($family->body, 'parent@example.test', 'pending invitation is listed');
    assert_contains($family->body, 'Zrušit', 'pending invitation can be cancelled');

    $parentRegister = $parent->get('/?r=register&email=parent%40example.test');
    $parent->post('/?r=register', [
        'csrf' => csrf_from($parentRegister->body),
        'display_name' => 'Druhy rodic',
        'email' => 'parent@example.test',
        'password' => 'bezpecne-heslo-parent',
    ]);
    $parentDashboard = $parent->followLastRedirect();
    assert_not_contains($parentDashboard->body, 'Anna Testova', 'invited parent has no child access before owner grants it');

    $parentId = user_id_by_email($pdo, 'parent@example.test');
    $verifyToken = latest_email_verification_token_from_mail($root, 'parent@example.test');
    assert_true($verifyToken !== '', 'parent email verification link is sent');
    $parent->get('/?r=email_verify&token=' . urlencode($verifyToken));
    $parent->followLastRedirect();

    $family = $owner->get('/?r=family');
    assert_contains($family->body, 'Druhy rodic', 'registered invited parent is visible to owner');
    $owner->post('/?r=access_save', [
        'csrf' => csrf_from($family->body),
        'child_id' => (string)$childOne,
        'user_ids' => [(string)$parentId],
    ]);
    $owner->followLastRedirect();
    $parentDashboard = $parent->get('/?r=dashboard');
    assert_contains($parentDashboard->body, 'Anna Testova', 'shared child is visible to invited parent');
    assert_not_contains($parentDashboard->body, 'Boris Test', 'unshared child is not visible to invited parent');

    $careTypesPage = $owner->get('/?r=care_types');
    assert_contains($careTypesPage->body, 'Typy péče', 'care type page is renamed');
    assert_not_contains($careTypesPage->body, 'Teplota', 'system record types are not listed as user care types');
    $owner->post('/?r=care_type_save', [
        'csrf' => csrf_from($careTypesPage->body),
        'name' => 'Inhalace',
    ]);
    $careTypesPage = $owner->followLastRedirect();
    assert_contains($careTypesPage->body, 'Inhalace', 'custom care type is listed');
    $owner->post('/?r=care_type_save', [
        'csrf' => csrf_from($careTypesPage->body),
        'name' => 'Koupel',
    ]);
    $careTypesPage = $owner->followLastRedirect();
    assert_contains($careTypesPage->body, 'Koupel', 'second custom care type is listed');
    $owner->post('/?r=care_type_delete', [
        'csrf' => csrf_from($careTypesPage->body),
        'id' => (string)care_type_id_by_name($pdo, 'Koupel'),
    ]);
    $careTypesPage = $owner->followLastRedirect();
    assert_not_contains($careTypesPage->body, 'Koupel', 'unused custom care type can be deleted');

    $childPage = $owner->get('/?r=child&id=' . $childOne);
    assert_all_controls($childPage->body, ['Uložit teplotu', 'Uložit lék', 'Uložit péči', 'Kontroly', 'Export pro lékaře'], 'child detail controls');
    assert_not_contains($childPage->body, 'Uložit příznaky', 'symptom quick entry is removed from child detail');
    assert_contains($childPage->body, 'data-timeline-url', 'timeline has AJAX refresh links');

    $timeline = $owner->get('/?r=child_timeline&id=' . $childOne . '&range=24');
    assert_status($timeline, 200, 'timeline AJAX route returns HTTP 200');
    assert_contains($timeline->body, 'chart-wrap', 'timeline AJAX route renders chart markup');

    $owner->post('/?r=temperature_save', [
        'csrf' => csrf_from($childPage->body),
        'child_id' => (string)$childOne,
        'event_at' => date('Y-m-d\TH:i'),
        'temperature_celsius' => '38,4',
        'place' => 'doma',
        'note' => 'rychly test',
    ]);
    $childPage = $owner->followLastRedirect();
    assert_contains($childPage->body, '38,4', 'temperature record is visible');

    $medicationId = first_medication_id($pdo);
    $owner->post('/?r=medication_record_save', [
        'csrf' => csrf_from($childPage->body),
        'child_id' => (string)$childOne,
        'event_at' => date('Y-m-d\TH:i'),
        'medication_id' => (string)$medicationId,
        'note' => 'podano',
    ]);
    $childPage = $owner->followLastRedirect();
    assert_contains($childPage->body, 'Podání léku', 'medication record is saved');

    $careTypeId = first_care_type_id($pdo);
    $owner->post('/?r=care_record_save', [
        'csrf' => csrf_from($childPage->body),
        'child_id' => (string)$childOne,
        'event_at' => date('Y-m-d\TH:i'),
        'record_type_id' => (string)$careTypeId,
        'note' => 'test pece',
    ]);
    $childPage = $owner->followLastRedirect();
    assert_contains($childPage->body, 'test pece', 'care record is saved');

    $pngPath = $tmpDir . '/ehic.png';
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lS9Q1wAAAABJRU5ErkJggg=='));
    $documentsPage = $owner->get('/?r=child&id=' . $childOne . '&documents=1');
    assert_all_controls($documentsPage->body, ['Uložit EHIC', 'Uložit dokument', 'Zavřít'], 'document controls');
    assert_contains($documentsPage->body, 'document-page', 'child documentation is rendered as a mobile-safe page section');
    assert_not_contains($documentsPage->body, '<dialog class="modal document-modal"', 'child documentation is not trapped in a mobile dialog');
    assert_contains($documentsPage->body, 'data-upload-form', 'document upload forms provide PWA submit feedback');
    assert_contains($documentsPage->body, '.heic', 'document upload accepts iPhone HEIC files');
    $documentUploadBlock = extract_between($documentsPage->body, '<h3>Nahrát nový dokument</h3>', '</section>');
    assert_not_contains($documentUploadBlock, 'name="provider_id"', 'child documentation no longer contains provider selection');
    $heicPath = $tmpDir . '/mobilni-fotka.heic';
    file_put_contents($heicPath, "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1");
    $owner->multipart('/?r=document_upload', [
        'csrf' => csrf_from($documentsPage->body),
        'child_id' => (string)$childOne,
        'title' => 'Mobilní fotka',
        'document_type' => 'general',
        'note' => 'test HEIC',
    ], [
        'document_file' => $heicPath,
    ]);
    $documentsPage = $owner->followLastRedirect();
    assert_contains($documentsPage->body, 'Mobilní fotka', 'HEIC-like mobile document is accepted');
    $mobileDocument = latest_document_by_title($pdo, $childOne, 'Mobilní fotka');
    assert_true($mobileDocument !== null, 'mobile document is stored in database');
    $mobileDocumentPath = uploaded_document_path((string)$mobileDocument['storage_path']);
    assert_true(uploaded_document_file_exists($mobileDocumentPath), 'mobile document file exists before deleting another document');
    $mobileView = $owner->get('/?r=document_view&id=' . (int)$mobileDocument['id']);
    assert_status($mobileView, 200, 'mobile document view returns HTTP 200');
    assert_contains($mobileView->body, 'Náhled tohoto formátu', 'unsupported mobile image has visible fallback instead of blank preview');
    assert_contains($mobileView->body, 'Otevřít soubor', 'unsupported mobile image can be opened directly');
    assert_not_contains($mobileView->body, 'document-preview-image', 'unsupported mobile image does not render a broken image preview');

    $owner->multipart('/?r=document_upload', [
        'csrf' => csrf_from($documentsPage->body),
        'child_id' => (string)$childOne,
        'title' => 'EHIC',
        'document_type' => 'ehic',
        'is_sensitive' => '1',
        'note' => 'test EHIC',
    ], [
        'document_file' => $pngPath,
    ]);
    $documentsPage = $owner->followLastRedirect();
    assert_contains($documentsPage->body, 'EHIC byl uložen', 'EHIC upload confirmation is shown');

    $ehicId = latest_ehic_id($pdo, $childOne);
    assert_true($ehicId > 0, 'EHIC is stored in database');
    $ehicDocument = document_by_id($pdo, $ehicId);
    assert_true($ehicDocument !== null, 'EHIC document row is available before delete');
    $ehicPath = uploaded_document_path((string)$ehicDocument['storage_path']);
    assert_true(uploaded_document_file_exists($ehicPath), 'EHIC file exists before delete');
    assert_true(document_count_for_child($pdo, $childOne) >= 2, 'child has multiple documents before deleting one');
    $dashboard = $owner->get('/?r=dashboard');
    assert_contains($dashboard->body, '?r=document_view&amp;id=' . $ehicId, 'EHIC view link is present in dashboard menu');

    $view = $owner->get('/?r=document_view&id=' . $ehicId);
    assert_status($view, 200, 'EHIC view returns HTTP 200');
    assert_contains($view->body, 'document-preview-image', 'EHIC view renders image preview');
    assert_contains($view->body, '?r=document_inline&amp;id=' . $ehicId, 'EHIC view uses inline document route');

    $inline = $owner->get('/?r=document_inline&id=' . $ehicId);
    assert_status($inline, 200, 'EHIC inline route returns HTTP 200');
    assert_header_contains($inline, 'content-type', 'image/png', 'EHIC inline route has image content type');
    assert_header_contains($inline, 'content-disposition', 'inline', 'EHIC inline route is inline');
    assert_true(str_starts_with($inline->body, "\x89PNG"), 'EHIC inline route returns decrypted PNG bytes');

    $download = $owner->get('/?r=document_download&id=' . $ehicId);
    assert_status($download, 200, 'EHIC download returns HTTP 200');
    assert_header_contains($download, 'content-disposition', 'attachment', 'EHIC download is attachment');

    $dashboard = $owner->get('/?r=dashboard');
    $owner->post('/?r=document_delete', [
        'csrf' => csrf_from($dashboard->body),
        'document_id' => (string)$ehicId,
        'return_to' => 'dashboard',
    ]);
    $dashboard = $owner->followLastRedirect();
    assert_not_contains($dashboard->body, '?r=document_view&amp;id=' . $ehicId, 'deleted EHIC disappears from dashboard');
    assert_true(document_by_id($pdo, $ehicId) === null, 'deleted EHIC is removed from database');
    assert_true(!uploaded_document_file_exists($ehicPath), 'deleted EHIC file is removed from storage');
    assert_true(document_by_id($pdo, (int)$mobileDocument['id']) !== null, 'deleting EHIC keeps other child document in database');
    assert_true(uploaded_document_file_exists($mobileDocumentPath), 'deleting EHIC keeps other child document file in storage');
    assert_true(document_count_for_child($pdo, $childOne) >= 1, 'deleting one document does not delete all child documents');
    $documentsAfterDelete = $owner->get('/?r=child&id=' . $childOne . '&documents=1');
    assert_contains($documentsAfterDelete->body, 'Mobilní fotka', 'deleting one document keeps another document visible');
    assert_not_contains($documentsAfterDelete->body, '?r=document_view&amp;id=' . $ehicId, 'deleted document is no longer visible in documentation');

    $recordId = first_record_id($pdo, $childOne);
    $edit = $owner->get('/?r=record_edit&id=' . $recordId);
    assert_contains($edit->body, 'Uložit změny', 'record edit button is covered');
    $owner->post('/?r=record_delete', [
        'csrf' => csrf_from($edit->body),
        'record_id' => (string)$recordId,
    ]);
    $owner->followLastRedirect();

    $settings = $parent->get('/?r=settings');
    assert_all_controls($settings->body, ['Smazat můj účet'], 'parent settings destructive controls');

    $export = $owner->get('/?r=export&child_id=' . $childOne);
    assert_all_controls($export->body, ['Uložit nebo tisknout PDF', 'Aktualizovat export'], 'doctor export controls');
    assert_not_contains($export->body, 'Detail exportu', 'doctor export no longer has detail level');
    assert_not_contains($export->body, 'Ošetřující lékaři', 'doctor export does not include assigned doctors');

    echo "OK orchestrated flows\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    $failed = true;
} finally {
    stop_server($server);
    cleanup_uploaded_documents($pdo);
    recursive_remove($tmpDir);
}
if ($failed) {
    exit(1);
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
            $fields[$name] = new CURLFile($filePath);
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
    for ($port = 8095; $port < 8150; $port++) {
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
        1 => ['file', $root . '/var/php-flow-test.out.log', 'a'],
        2 => ['file', $root . '/var/php-flow-test.err.log', 'a'],
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

function assert_status(TestResponse $response, int $status, string $message): void
{
    assert_true($response->status === $status, $message . ' (status ' . $response->status . ')');
}

function assert_contains(string $haystack, string $needle, string $message): void
{
    assert_true(strpos($haystack, $needle) !== false, $message);
}

function assert_not_contains(string $haystack, string $needle, string $message): void
{
    assert_true(strpos($haystack, $needle) === false, $message);
}

function extract_between(string $haystack, string $start, string $end): string
{
    $startPos = strpos($haystack, $start);
    if ($startPos === false) {
        return '';
    }
    $startPos += strlen($start);
    $endPos = strpos($haystack, $end, $startPos);
    if ($endPos === false) {
        return substr($haystack, $startPos);
    }
    return substr($haystack, $startPos, $endPos - $startPos);
}

function assert_header_contains(TestResponse $response, string $header, string $needle, string $message): void
{
    $value = implode(' ', $response->headers[strtolower($header)] ?? []);
    assert_true(stripos($value, $needle) !== false, $message . ' (' . $value . ')');
}

function assert_all_controls(string $html, array $labels, string $message): void
{
    foreach ($labels as $label) {
        assert_contains($html, $label, $message . ': ' . $label);
    }
}

function child_id_by_name(PDO $pdo, string $firstName): int
{
    $stmt = $pdo->prepare('SELECT id FROM children WHERE first_name = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$firstName]);
    return (int)$stmt->fetchColumn();
}

function user_id_by_email(PDO $pdo, string $email): int
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$email]);
    return (int)$stmt->fetchColumn();
}

function latest_email_verification_token_from_mail(string $root, string $email): string
{
    $path = $root . '/var/mail.log';
    if (!is_file($path)) {
        return '';
    }
    $log = (string)file_get_contents($path);
    $token = '';
    foreach (preg_split('/\n\n(?=\\[\\d{4}-\\d{2}-\\d{2})/', $log) as $message) {
        if (strpos($message, 'To: ' . $email) === false || strpos($message, 'r=email_verify&token=') === false) {
            continue;
        }
        if (preg_match('/r=email_verify&token=([a-f0-9]{64})/', $message, $m)) {
            $token = $m[1];
        }
    }
    return $token;
}

function first_medication_id(PDO $pdo): int
{
    return (int)$pdo->query('SELECT id FROM medications WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
}

function first_care_type_id(PDO $pdo): int
{
    return (int)$pdo->query("SELECT id FROM record_types WHERE kind = 'CARE' AND is_system = 0 AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
}

function care_type_id_by_name(PDO $pdo, string $name): int
{
    $stmt = $pdo->prepare("SELECT id FROM record_types WHERE kind = 'CARE' AND is_system = 0 AND name = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn();
}

function latest_ehic_id(PDO $pdo, int $childId): int
{
    $stmt = $pdo->prepare("SELECT id FROM child_documents WHERE child_id = ? AND document_type = 'ehic' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$childId]);
    return (int)$stmt->fetchColumn();
}

function latest_document_by_title(PDO $pdo, int $childId, string $title): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM child_documents WHERE child_id = ? AND title = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$childId, $title]);
    return $stmt->fetch() ?: null;
}

function document_by_id(PDO $pdo, int $documentId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM child_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$documentId]);
    return $stmt->fetch() ?: null;
}

function document_count_for_child(PDO $pdo, int $childId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM child_documents WHERE child_id = ?');
    $stmt->execute([$childId]);
    return (int)$stmt->fetchColumn();
}

function uploaded_document_path(string $storagePath): string
{
    return dirname(__DIR__) . '/var/uploads/' . ltrim(str_replace(['\\', '..'], ['/', ''], $storagePath), '/');
}

function uploaded_document_file_exists(string $path): bool
{
    clearstatcache(true, $path);
    return is_file($path);
}

function first_record_id(PDO $pdo, int $childId): int
{
    $stmt = $pdo->prepare('SELECT id FROM health_records WHERE child_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$childId]);
    return (int)$stmt->fetchColumn();
}

function cleanup_uploaded_documents(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT storage_path FROM child_documents');
    if (!$stmt) {
        return;
    }
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storagePath) {
        $path = dirname(__DIR__) . '/var/uploads/' . ltrim(str_replace(['\\', '..'], ['/', ''], (string)$storagePath), '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function recursive_remove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

<?php
declare(strict_types=1);

/**
 * Relais serveur → ingress formulaire (webhooky.builders).
 * Validation métier minimale + limitation de fréquence + honeypot.
 *
 * CONTACT_WEBHOOK_FORWARD_URL — URL d’ingress (même jeton que src/config/site.ts)
 */

function contact_journal_dir(): ?string
{
    $candidates = [dirname(__DIR__) . '/contact-journal', __DIR__ . '/_contact-journal'];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            if (str_starts_with($dir, __DIR__)) {
                if (!is_file($dir . '/.htaccess')) {
                    @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
                }
                if (!is_file($dir . '/index.html')) {
                    @file_put_contents($dir . '/index.html', '');
                }
            }

            return $dir;
        }
    }

    return null;
}

function contact_journal_write(array $entry): void
{
    try {
        $dir = contact_journal_dir();
        if ($dir === null) {
            error_log('[contact-journal] dossier non inscriptible');

            return;
        }
        $file = $dir . '/demandes-' . gmdate('Y-m') . '.jsonl';
        $ok = @file_put_contents(
            $file,
            json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
        if ($ok === false) {
            error_log('[contact-journal] écriture impossible: ' . $file);
        }
        $limit = gmdate('Y-m', strtotime('first day of -2 months'));
        foreach ((array) glob($dir . '/demandes-*.jsonl') as $old) {
            if (preg_match('/demandes-(\d{4}-\d{2})\.jsonl$/', (string) $old, $m) && $m[1] < $limit) {
                @unlink((string) $old);
            }
        }
    } catch (Throwable $e) {
        error_log('[contact-journal] ' . $e->getMessage());
    }
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty body']);
    exit;
}

if (strlen($raw) > 65536) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Payload too large']);
    exit;
}

$ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_contains($ct, 'application/json')) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'Expected application/json']);
    exit;
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$secFetchSite = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');

$allowedPrefixes = [
    'https://webhooky.fr',
    'https://www.webhooky.fr',
    'http://localhost',
    'http://127.0.0.1',
];

$allowed = false;
foreach ($allowedPrefixes as $p) {
    if (($referer !== '' && str_starts_with($referer, $p)) || ($origin !== '' && str_starts_with($origin, $p))) {
        $allowed = true;
        break;
    }
}

if (!$allowed && $secFetchSite === 'same-origin' && preg_match('/(^|\.)webhooky\.fr(:\d+)?$/i', $host) === 1) {
    $allowed = true;
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

/** @var array<string, mixed>|null */
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

// Honeypot (si renvoyé par le client)
$website = trim((string) ($data['website'] ?? ''));
if ($website !== '') {
    http_response_code(204);
    exit;
}

$name = trim((string) ($data['name'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));
$privacy = $data['privacy_policy_accepted'] ?? false;

if (strlen($name) < 2 || strlen($name) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid name']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid email']);
    exit;
}

if (strlen($message) < 10 || strlen($message) > 8000) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid message']);
    exit;
}

if ($privacy !== true && $privacy !== 1 && $privacy !== '1') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Privacy acceptance required']);
    exit;
}

// Rate limit simple par IP (5 req / 10 min)
$ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$ip = trim(explode(',', $ip)[0]);
$ipHash = hash('sha256', $ip);
$rateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'webhooky-contact-rate';
if (!is_dir($rateDir)) {
    @mkdir($rateDir, 0700, true);
}
$rateFile = $rateDir . DIRECTORY_SEPARATOR . $ipHash . '.json';
$now = time();
$window = 600;
$maxHits = 5;
$hits = [];
if (is_file($rateFile)) {
    $prev = json_decode((string) file_get_contents($rateFile), true);
    if (is_array($prev)) {
        $hits = array_values(array_filter($prev, static fn ($t) => is_int($t) && ($now - $t) < $window));
    }
}
if (count($hits) >= $maxHits) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests']);
    exit;
}
$hits[] = $now;
file_put_contents($rateFile, json_encode($hits), LOCK_EX);

// Nettoyage / limitation des champs avant forward (pas de logs du message)
$forward = [
    'source' => 'webhooky.fr-contact',
    'name' => preg_replace('/[\r\n]+/', ' ', $name) ?? $name,
    'email' => $email,
    'company' => preg_replace('/[\r\n]+/', ' ', trim((string) ($data['company'] ?? ''))) ?? '',
    'objet' => preg_replace('/[\r\n]+/', ' ', trim((string) ($data['objet'] ?? ''))) ?? '',
    'request_type' => preg_replace('/[^a-z_]/', '', (string) ($data['request_type'] ?? 'other')) ?: 'other',
    'message' => $message,
    'submitted_at' => (string) ($data['submitted_at'] ?? gmdate('c')),
    'privacy_policy_accepted' => true,
    'privacy_policy_accepted_at' => (string) ($data['privacy_policy_accepted_at'] ?? gmdate('c')),
];

$forwardRaw = json_encode($forward, JSON_UNESCAPED_UNICODE);
if ($forwardRaw === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Encode failed']);
    exit;
}

$forwardUrl = getenv('CONTACT_WEBHOOK_FORWARD_URL') ?: '';
if (!is_string($forwardUrl) || trim($forwardUrl) === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Contact relay not configured']);
    exit;
}
$forwardUrl = trim($forwardUrl);

$journalId = bin2hex(random_bytes(6));
contact_journal_write([
    'id' => $journalId,
    'etape' => 'recue',
    'ip' => $ip,
    'host' => $host,
    'source' => $forward['source'],
    'name' => $forward['name'],
    'email' => $forward['email'],
    'company' => $forward['company'],
    'objet' => $forward['objet'],
    'request_type' => $forward['request_type'],
    'message' => $forward['message'],
    'submitted_at' => $forward['submitted_at'],
]);

$ch = curl_init($forwardUrl);
if ($ch === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Relay init failed']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $forwardRaw,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$responseBody = curl_exec($ch);
$curlErr = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false || $curlErr !== '') {
    contact_journal_write([
        'id' => $journalId,
        'etape' => 'echec',
        'at' => gmdate('c'),
        'http' => $status,
        'erreur' => $curlErr,
        'reponse' => substr((string) $responseBody, 0, 300),
    ]);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Relay upstream failed']);
    exit;
}

contact_journal_write([
    'id' => $journalId,
    'etape' => 'envoyee',
    'at' => gmdate('c'),
    'http' => $status,
]);

http_response_code($status > 0 ? $status : 502);
echo $responseBody;

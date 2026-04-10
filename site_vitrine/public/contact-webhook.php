<?php
declare(strict_types=1);

/**
 * Relais serveur → ingress formulaire (webhooky.builders).
 * Évite CORS : le navigateur poste en same-origin, PHP forward en cURL.
 *
 * Configuration : variable serveur CONTACT_WEBHOOK_FORWARD_URL (Apache SetEnv, php-fpm pool, etc.)
 * si différente du défaut (même jeton que src/site.ts — à tenir synchronisé ou utiliser SetEnv).
 */
header('Content-Type: application/json; charset=UTF-8');

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

/** @var non-empty-string */
$forwardUrl = getenv('CONTACT_WEBHOOK_FORWARD_URL') ?: 'https://webhooky.builders/webhook/form/64470731-3f7d-48b8-aabf-817f08ce8b42';

$ch = curl_init($forwardUrl);
if ($ch === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Relay init failed']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $raw,
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
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Relay upstream failed']);
    exit;
}

http_response_code($status > 0 ? $status : 502);
echo $responseBody;

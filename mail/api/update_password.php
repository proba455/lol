<?php
header('Content-Type: application/json; charset=utf-8');

function send_json($payload, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function send_error($message, $error, $http = 400) {
    send_json([
        'ok' => false,
        'success' => false,
        'message' => $message,
        'error' => $error
    ], $http);
}

function send_success($message, $extra = []) {
    $base = [
        'ok' => true,
        'success' => true,
        'message' => $message
    ];
    send_json(array_merge($base, $extra), 200);
}

function get_input_data() {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function get_service_account() {
    $json = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    if ($json) return json_decode($json, true);
    $path = getenv('FIREBASE_SERVICE_ACCOUNT_PATH');
    if ($path && file_exists($path)) return json_decode(file_get_contents($path), true);
    return null;
}

function get_access_token($sa) {
    $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now = time();
    $payload = [
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/identitytoolkit https://www.googleapis.com/auth/firebase.database',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ];
    $payloadEnc = base64_encode(json_encode($payload));
    $jwtUnsigned = $header . '.' . $payloadEnc;
    openssl_sign($jwtUnsigned, $signature, $sa['private_key'], 'sha256');
    $jwt = $jwtUnsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

function firebase_lookup_uid_by_email($projectId, $token, $email) {
    $url = "https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:lookup";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['email' => [$email]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (isset($data['users'][0]['localId'])) return $data['users'][0]['localId'];
    return null;
}

function get_db_base_url($dbName) {
    $fromEnv = getenv('FIREBASE_DB_URL');
    if ($fromEnv) return rtrim($fromEnv, '/');
    return "https://{$dbName}.firebaseio.com";
}

function firebase_db_get($dbName, $path, $token) {
    $url = get_db_base_url($dbName) . "/{$path}.json?access_token={$token}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 20
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return ['__error' => 'curl_failed'];
    if ($httpCode < 200 || $httpCode >= 300) return ['__error' => 'http_' . $httpCode];
    $trimmed = trim($resp);
    if ($trimmed === '' || $trimmed === 'null') return null;
    $decoded = json_decode($resp, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) return ['__error' => 'json_parse_failed'];
    return $decoded;
}

function firebase_db_delete($dbName, $path, $token) {
    $url = get_db_base_url($dbName) . "/{$path}.json?access_token={$token}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 20
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}

function firebase_update_password($projectId, $token, $uid, $password) {
    $url = "https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:update";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'localId' => $uid,
            'password' => strval($password)
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Метод не поддерживается. Используйте POST.', 'method_not_allowed', 405);
}

$input = get_input_data();
$rawEmail = isset($input['email']) ? trim($input['email']) : '';
$email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);
$code = isset($input['code']) ? trim((string)$input['code']) : '';
$password = isset($input['password']) ? trim((string)$input['password']) : '';
if (!$email || $code === '' || $password === '') send_error('Некорректные параметры.', 'invalid_params', 400);

$sa = get_service_account();
if (!$sa) send_error('Не найден сервисный аккаунт Firebase.', 'service_account_missing', 500);
$token = get_access_token($sa);
if (!$token) send_error('Не удалось получить access token.', 'access_token_failed', 500);
$projectId = $sa['project_id'] ?? 'nexules-3ba83';
$dbName = $projectId . '-default-rtdb';

$uid = firebase_lookup_uid_by_email($projectId, $token, $email);
if (!$uid) send_error('Пользователь с таким email не найден.', 'user_not_found', 404);

$data = firebase_db_get($dbName, "password_reset/{$uid}", $token);
if (is_array($data) && isset($data['__error'])) send_error('Ошибка чтения кода из базы.', 'db_read_failed', 500);
if (!isset($data['code'])) send_error('Код не найден.', 'code_not_found', 404);
if (intval($data['code']) !== intval($code)) send_error('Неверный код.', 'code_mismatch', 400);
if (isset($data['created_at']) && time() - intval($data['created_at']) > 600) send_error('Срок действия кода истёк.', 'code_expired', 400);

$ok = firebase_update_password($projectId, $token, $uid, $password);
if (!$ok) send_error('Не удалось изменить пароль.', 'update_failed', 500);
firebase_db_delete($dbName, "password_reset/{$uid}", $token);

send_success('Пароль успешно изменён.');
?>

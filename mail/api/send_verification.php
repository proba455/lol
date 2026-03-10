<?php
// send_verification.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

function send_json($payload, $code = 200)
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
}

// Настройки SMTP (ЗАПОЛНИ СВОИМИ ДАННЫМИ)
const SMTP_HOST       = 'smtp.gmail.com';
const SMTP_PORT       = 587;
const SMTP_USERNAME   = 'shubkagames@gmail.com';
const SMTP_PASSWORD   = 'nehk ezib lgse rc';
const SMTP_FROM_EMAIL = 'shubkagames@gmail.com';
const SMTP_FROM_NAME  = 'nexules';

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        send_json(['ok' => false, 'error' => 'server_fatal', 'detail' => $error['message']], 500);
    }
});

require __DIR__ . '/Exception.php';
require __DIR__ . '/PHPMailer.php';
require __DIR__ . '/SMTP.php';

try {

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
    exit;
}

$email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$uid      = isset($_POST['uid']) ? trim($_POST['uid']) : '';
$id_token = isset($_POST['id_token']) ? trim($_POST['id_token']) : '';

if (!$email) {
    send_json(['ok' => false, 'error' => 'bad_email'], 400);
    exit;
}
if ($uid === '' || $id_token === '') {
    send_json(['ok' => false, 'error' => 'missing_uid_or_token'], 400);
    exit;
}

// 6‑значный код
$code = random_int(100000, 999999);

// --- Сохранение кода в Firebase Realtime Database ---
$firebaseDbUrl = 'https://nexules-3ba83-default-rtdb.firebaseio.com/';
$path          = 'email_verification/' . rawurlencode($uid) . '.json?auth=' . urlencode($id_token);
$payload       = json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);

$ch = curl_init($firebaseDbUrl . $path);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'PUT',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$firebaseResponse = curl_exec($ch);
$firebaseCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($firebaseCode !== 200) {
    send_json([
        'ok'    => false,
        'error' => 'firebase_error',
        'code'  => $firebaseCode,
        'body'  => $firebaseResponse,
    ], 500);
    exit;
}

// --- Отправка письма через SMTP (Gmail) ---
mb_internal_encoding('UTF-8');

$subject = 'Подтверждение почты Nexules';
$message = "Ваш код подтверждения: {$code}\n\n"
         . "Если вы не регистрировались в игре Nexules, просто проигнорируйте это письмо.";

$host = getenv('SMTP_HOST') ?: SMTP_HOST;
$portEnv = getenv('SMTP_PORT');
$port = (int) ($portEnv ?: SMTP_PORT);
$username = getenv('SMTP_USERNAME') ?: SMTP_USERNAME;
$password = preg_replace('/\s+/', '', (getenv('SMTP_PASSWORD') ?: SMTP_PASSWORD));
$fromEmail = getenv('SMTP_FROM_EMAIL') ?: SMTP_FROM_EMAIL;
$fromName  = getenv('SMTP_FROM_NAME')  ?: SMTP_FROM_NAME;
$secureEnv = strtolower((string) getenv('SMTP_SECURE'));

$attempts = [];
if ($secureEnv !== '') {
    $attempts[] = ['host' => $host, 'port' => $port, 'secure' => $secureEnv];
} elseif ($portEnv !== false && $portEnv !== '') {
    $attempts[] = ['host' => $host, 'port' => $port, 'secure' => ($port === 465 ? 'ssl' : 'tls')];
} else {
    $attempts[] = ['host' => $host, 'port' => 465, 'secure' => 'ssl'];
    $attempts[] = ['host' => $host, 'port' => 587, 'secure' => 'tls'];
}

$lastError = '';
foreach ($attempts as $attempt) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $attempt['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = $attempt['secure'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $attempt['port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        if ($mail->send()) {
            send_json(['ok' => true, 'code' => $code]);
            exit;
        }
        $lastError = $mail->ErrorInfo;
    } catch (Exception $e) {
        $lastError = $e->getMessage();
    }
}

send_json(['ok' => false, 'error' => 'mail_exception', 'code' => $code, 'detail' => $lastError], 500);
exit;
} catch (Throwable $e) {
    send_json(['ok' => false, 'error' => 'server_exception', 'detail' => $e->getMessage()], 500);
    exit;
}

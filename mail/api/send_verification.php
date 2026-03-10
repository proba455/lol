<?php
// send_verification.php

header('Content-Type: application/json; charset=utf-8');

ob_start();
$__response_sent = false;
function __send_json($payload, $code = 200)
{
    global $__response_sent;
    if ($__response_sent) {
        return;
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload);
    $__response_sent = true;
}
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function ($e) {
    __send_json(['ok' => false, 'error' => 'server_exception', 'detail' => $e->getMessage()], 500);
});
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && !$GLOBALS['__response_sent']) {
        __send_json(['ok' => false, 'error' => 'server_fatal', 'detail' => $error['message']], 500);
    }
    $output = ob_get_clean();
    if ($GLOBALS['__response_sent']) {
        echo $output;
        return;
    }
    $trimmed = trim($output);
    if ($trimmed !== '') {
        __send_json(['ok' => false, 'error' => 'server_output', 'detail' => substr($trimmed, 0, 200)], 500);
    }
});

// Подключаем PHPMailer (нужно загрузить библиотеку в папку PHPMailer рядом с этим файлом)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/Exception.php';
require __DIR__ . '/PHPMailer.php';
require __DIR__ . '/SMTP.php';

// Настройки SMTP (ЗАПОЛНИ СВОИМИ ДАННЫМИ)
const SMTP_HOST       = 'smtp.gmail.com';
const SMTP_PORT       = 587;
const SMTP_USERNAME   = 'shubkagames@gmail.com';
const SMTP_PASSWORD   = 'nehk ezib lgse rcpf';
const SMTP_FROM_EMAIL = shubkagames@gmail.com;
const SMTP_FROM_NAME  = 'nexules';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$uid      = isset($_POST['uid']) ? trim($_POST['uid']) : '';
$id_token = isset($_POST['id_token']) ? trim($_POST['id_token']) : '';

if (!$email) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_email']);
    exit;
}
if ($uid === '' || $id_token === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_uid_or_token']);
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
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'firebase_error',
        'code'  => $firebaseCode,
        'body'  => $firebaseResponse,
    ]);
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
            echo json_encode(['ok' => true, 'code' => $code]);
            exit;
        }
        $lastError = $mail->ErrorInfo;
    } catch (Exception $e) {
        $lastError = $e->getMessage();
    }
}

__send_json(['ok' => false, 'error' => 'mail_exception', 'code' => $code, 'detail' => $lastError], 500);
exit;





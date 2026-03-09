<?php
// send_verification.php

header('Content-Type: application/json; charset=utf-8');

// Подключаем PHPMailer (нужно загрузить библиотеку в папку PHPMailer рядом с этим файлом)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/Exception.php';
require __DIR__ . '/PHPMailer.php';
require __DIR__ . '/SMTP.php';

// Настройки SMTP (ЗАПОЛНИ СВОИМИ ДАННЫМИ)
const SMTP_HOST       = 'smtp.gmail.com';
const SMTP_PORT       = 587;
const SMTP_USERNAME   = 'shubkagames@gmail.com';   // сюда свой Gmail
const SMTP_PASSWORD   = 'Yfipy ygbk lmvt yyoh';              // сюда app‑password от Gmail
const SMTP_FROM_EMAIL = SMTP_USERNAME;
const SMTP_FROM_NAME  = 'Nexules';

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

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = str_replace(' ', '', SMTP_PASSWORD);
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email);

    $mail->Subject = $subject;
    $mail->Body    = $message;

    if (!$mail->send()) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'mail_failed', 'code' => $code, 'detail' => $mail->ErrorInfo]);
        exit;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mail_exception', 'code' => $code, 'detail' => $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => true, 'code' => $code]);

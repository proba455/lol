<?php
// send_verification.php - РАБОЧАЯ ВЕРСИЯ

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

function send_json($payload, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Настройки SMTP (ЗАПОЛНИ СВОИМИ ДАННЫМИ)
const SMTP_HOST       = 'smtp.gmail.com';
const SMTP_PORT       = 587;
const SMTP_USERNAME   = 'shubkagames@gmail.com';
const SMTP_PASSWORD   = 'rugefskcgjxxegmt';
const SMTP_FROM_EMAIL = 'shubkagames@gmail.com';
const SMTP_FROM_NAME  = 'Nexules';

// Подключаем PHPMailer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    require __DIR__ . '/Exception.php';
    require __DIR__ . '/PHPMailer.php';
    require __DIR__ . '/SMTP.php';
}

try {
    // Получаем данные
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
    $id_token = isset($_POST['id_token']) ? trim($_POST['id_token']) : '';

    if (!$email || !$uid || !$id_token) {
        send_json(['ok' => false, 'error' => 'invalid_params'], 400);
    }

    // Генерируем код
    $code = random_int(100000, 999999);
    
    // Firebase (сохраняем код)
    $firebaseData = json_encode(['code' => $code, 'created_at' => time()]);
    $ch = curl_init("https://nexules-3ba83-default-rtdb.firebaseio.com/email_verification/{$uid}.json?auth={$id_token}");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $firebaseData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);
    curl_exec($ch);
    curl_close($ch);

    // Отправляем письмо
    $mail = new PHPMailer(true);
    
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Код подтверждения Nexules';
    $mail->Body = "Ваш код подтверждения: $code";
    $mail->AltBody = "Ваш код подтверждения: $code";

    $mail->send();
    
    send_json(['ok' => true, 'code' => $code]);

} catch (Exception $e) {
    send_json(['ok' => false, 'error' => 'mail_failed'], 500);
}
?>

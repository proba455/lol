<?php
// send_verification.php - СОХРАНЕНИЕ ЛОГА НА ДИСК C:

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

function send_json($payload, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Настройки SMTP (ЗАПОЛНИ СВОИМИ ДАННЫМИ)
const SMTP_HOST       = 'smtp.gmail.com';
const SMTP_PORT       = 587;
const SMTP_USERNAME   = 'shubkagames@gmail.com';
const SMTP_PASSWORD   = 'rugefskcgjxxegmt';
const SMTP_FROM_EMAIL = 'shubkagames@gmail.com';
const SMTP_FROM_NAME  = 'Nexules';

// ========== ЛОГ НА ДИСК C: ==========
define('LOG_FILE', 'C:\smtp_debug_log.txt');

// Очищаем лог-файл при каждом запуске (или добавляем разделитель)
file_put_contents(LOG_FILE, "=== НАЧАЛО ОТПРАВКИ " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

// Функция для записи в лог
function write_log($message) {
    file_put_contents(LOG_FILE, $message . "\n", FILE_APPEND);
}

// Подключаем PHPMailer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    write_log("✓ PHPMailer загружен через Composer");
} elseif (file_exists(__DIR__ . '/PHPMailer.php')) {
    require __DIR__ . '/Exception.php';
    require __DIR__ . '/PHPMailer.php';
    require __DIR__ . '/SMTP.php';
    write_log("✓ PHPMailer загружен из файлов");
} else {
    write_log("✗ ОШИБКА: PHPMailer не найден");
    send_json(['ok' => false, 'error' => 'phpmailer_missing'], 500);
}

try {
    // Получаем и валидируем email
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
    $id_token = isset($_POST['id_token']) ? trim($_POST['id_token']) : '';

    write_log("Получены данные: email=$email, uid=$uid, token=" . substr($id_token, 0, 20) . "...");

    if (!$email || !$uid || !$id_token) {
        write_log("✗ ОШИБКА: Невалидные параметры");
        send_json(['ok' => false, 'error' => 'invalid_params'], 400);
    }

    // Генерируем код
    $code = random_int(100000, 999999);
    write_log("✓ Сгенерирован код: $code");
    
    // Firebase (опционально)
    try {
        write_log("→ Отправка в Firebase...");
        $firebaseData = json_encode(['code' => $code, 'created_at' => time()]);
        $ch = curl_init("https://nexules-3ba83-default-rtdb.firebaseio.com/email_verification/{$uid}.json?auth={$id_token}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $firebaseData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        write_log("✓ Firebase ответил кодом: $httpCode");
    } catch (Exception $e) {
        write_log("✗ Ошибка Firebase: " . $e->getMessage());
    }

    // ========== ОТПРАВКА ПИСЬМА ==========
    write_log("\n=== НАЧАЛО SMTP СОЕДИНЕНИЯ ===");
    
    $mail = new PHPMailer(true);
    
    // Максимальная отладка - всё пишем в файл на C:
    $mail->SMTPDebug = SMTP::DEBUG_CONNECTION;
    $mail->Debugoutput = function($str, $level) {
        // Убираем лишние пробелы и переносы
        $str = trim($str);
        if (!empty($str)) {
            // Записываем в лог на C:
            file_put_contents('C:\smtp_debug_log.txt', "SMTP: " . $str . "\n", FILE_APPEND);
        }
    };

    // Настройки SMTP
    write_log("Настройка SMTP: Host=" . SMTP_HOST . ", Port=" . SMTP_PORT);
    write_log("Username=" . SMTP_USERNAME);
    write_log("Password длина=" . strlen(SMTP_PASSWORD) . " символов");
    
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    $mail->Timeout = 30;
    
    // Опции SSL
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    
    write_log("✓ SMTP настроен");
    
    // Отправитель и получатель
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email);
    write_log("✓ Отправитель: " . SMTP_FROM_EMAIL);
    write_log("✓ Получатель: $email");
    
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Код подтверждения Nexules';
    $mail->Body = "Ваш код подтверждения: $code";
    $mail->AltBody = "Ваш код подтверждения: $code";
    
    write_log("✓ Тема письма: Код подтверждения Nexules");
    write_log("→ Попытка отправки...");

    // Пытаемся отправить
    $result = $mail->send();
    
    if ($result) {
        write_log("✓✓✓ ПИСЬМО УСПЕШНО ОТПРАВЛЕНО!");
        write_log("=== КОНЕЦ =================\n\n");
        send_json(['ok' => true, 'code' => $code]);
    } else {
        throw new Exception('Mailer Error: ' . $mail->ErrorInfo);
    }

} catch (Exception $e) {
    // Записываем ошибку в лог
    write_log("✗✗✗ ОШИБКА: " . $e->getMessage());
    if (isset($mail) && $mail->ErrorInfo) {
        write_log("✗ PHPMailer ErrorInfo: " . $mail->ErrorInfo);
    }
    write_log("=== КОНЕЦ (С ОШИБКОЙ) =========\n\n");
    
    send_json([
        'ok' => false,
        'error' => 'mail_failed',
        'message' => $e->getMessage()
    ], 500);
}
?>

<?php
// send_verification.php - Исправленная версия

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Включение отображения ошибок ТОЛЬКО для отладки (закомментируй на продакшене)
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

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

// Логирование ошибок
function log_error($message, $data = null) {
    $logFile = __DIR__ . '/mail_errors.log';
    $logEntry = date('Y-m-d H:i:s') . ' - ' . $message;
    if ($data) {
        $logEntry .= ' - ' . print_r($data, true);
    }
    $logEntry .= PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_error('Fatal error', $error);
        send_json(['ok' => false, 'error' => 'server_fatal', 'detail' => $error['message']], 500);
    }
});

// Подключение PHPMailer - УБЕДИСЬ, ЧТО ПУТИ ПРАВИЛЬНЫЕ!
$phpmailerPath = __DIR__ . '/vendor/autoload.php'; // Если через Composer
if (file_exists($phpmailerPath)) {
    require $phpmailerPath;
} else {
    // Если файлы лежат рядом со скриптом
    $requiredFiles = ['Exception.php', 'PHPMailer.php', 'SMTP.php'];
    foreach ($requiredFiles as $file) {
        $filePath = __DIR__ . '/' . $file;
        if (!file_exists($filePath)) {
            send_json(['ok' => false, 'error' => 'missing_phpmailer', 'detail' => "Файл $file не найден"], 500);
            exit;
        }
        require $filePath;
    }
}

try {
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    // Валидация входных данных
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
    $id_token = isset($_POST['id_token']) ? trim($_POST['id_token']) : '';

    if (!$email) {
        send_json(['ok' => false, 'error' => 'bad_email'], 400);
    }
    
    if ($uid === '' || $id_token === '') {
        send_json(['ok' => false, 'error' => 'missing_uid_or_token'], 400);
    }

    // Генерация кода подтверждения
    $code = random_int(100000, 999999);
    
    // Сохранение в Firebase
    $firebaseDbUrl = 'https://nexules-3ba83-default-rtdb.firebaseio.com/';
    $path = 'email_verification/' . rawurlencode($uid) . '.json?auth=' . urlencode($id_token);
    $firebaseData = json_encode(['code' => $code, 'created_at' => time()], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($firebaseDbUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => $firebaseData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true, // В продакшене должно быть true
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    
    $firebaseResponse = curl_exec($ch);
    $firebaseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Проверка ответа Firebase
    if ($firebaseCode !== 200) {
        log_error('Firebase error', ['code' => $firebaseCode, 'response' => $firebaseResponse, 'curl_error' => $curlError]);
        send_json([
            'ok' => false,
            'error' => 'firebase_error',
            'detail' => "Код ответа Firebase: $firebaseCode"
        ], 500);
    }

    // --- ОТПРАВКА ПИСЬМА ---
    // Проверка доступности SMTP порта перед отправкой
    $connection = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 5);
    if (!$connection) {
        log_error('SMTP port blocked', ['host' => SMTP_HOST, 'port' => SMTP_PORT, 'error' => "$errstr ($errno)"]);
        send_json([
            'ok' => false,
            'error' => 'smtp_port_blocked',
            'detail' => "Не удаётся подключиться к $SMTP_HOST:$SMTP_PORT. Проверьте настройки хостинга.",
            'smtp_error' => "$errstr ($errno)"
        ], 500);
    }
    fclose($connection);

    // Создание письма
    $mail = new PHPMailer(true);
    
    // ОТЛАДКА - раскомментируй для вывода подробной информации
    // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    // $mail->Debugoutput = function($str, $level) {
    //     file_put_contents(__DIR__ . '/smtp_debug.log', $str, FILE_APPEND);
    // };

    // Настройки SMTP
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS для порта 587
    $mail->Port = SMTP_PORT;
    
    // Дополнительные настройки для стабильности
    $mail->Timeout = 30; // Таймаут соединения
    $mail->SMTPKeepAlive = true; // Держать соединение
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    
    // Отправитель и получатель
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email);
    $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    
    // Содержимое письма
    $mail->isHTML(true); // Отправляем HTML письмо
    $mail->Subject = '=?UTF-8?B?' . base64_encode('Подтверждение почты Nexules') . '?='; // Корректная кодировка темы
    
    // HTML версия письма
    $mail->Body = "
    <html>
    <head>
        <title>Подтверждение почты</title>
        <meta charset='UTF-8'>
    </head>
    <body style='font-family: Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
            <h2 style='color: #333;'>Подтверждение email для Nexules</h2>
            <p>Ваш код подтверждения:</p>
            <div style='background: #f5f5f5; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; border-radius: 5px;'>
                {$code}
            </div>
            <p style='color: #666; font-size: 14px; margin-top: 20px;'>
                Если вы не регистрировались в игре Nexules, просто проигнорируйте это письмо.
            </p>
        </div>
    </body>
    </html>
    ";
    
    // Текстовая версия для старых почтовых клиентов
    $mail->AltBody = "Ваш код подтверждения: {$code}\n\nЕсли вы не регистрировались в игре Nexules, просто проигнорируйте это письмо.";

    // Отправка
    if ($mail->send()) {
        log_error('Email sent successfully', ['to' => $email, 'code' => $code]);
        send_json([
            'ok' => true, 
            'message' => 'Письмо отправлено',
            'code' => $code // В продакшене лучше не возвращать код в ответе
        ]);
    } else {
        throw new Exception('Mailer Error: ' . $mail->ErrorInfo);
    }

} catch (Exception $e) {
    // Обработка ошибок PHPMailer
    log_error('PHPMailer Exception', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    send_json([
        'ok' => false,
        'error' => 'mail_exception',
        'detail' => 'Ошибка при отправке письма. Пожалуйста, попробуйте позже.',
        'debug' => $e->getMessage() // Убери в продакшене!
    ], 500);
    
} catch (Throwable $e) {
    // Обработка всех остальных ошибок
    log_error('General Exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    send_json([
        'ok' => false,
        'error' => 'server_exception',
        'detail' => 'Внутренняя ошибка сервера'
    ], 500);
}
?>






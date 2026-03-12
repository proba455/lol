<?php
// send_verification.php - ДИАГНОСТИЧЕСКАЯ ВЕРСИЯ

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ВКЛЮЧАЕМ ОТОБРАЖЕНИЕ ОШИБОК ДЛЯ ДИАГНОСТИКИ
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// ========== ВРЕМЕННО ОТКЛЮЧАЕМ КАСТОМНЫЕ ОБРАБОТЧИКИ ==========
// set_error_handler(function ($severity, $message, $file, $line) {
//     throw new ErrorException($message, 0, $severity, $file, $line);
// });

// register_shutdown_function(function () {
//     $error = error_get_last();
//     if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
//         send_json(['ok' => false, 'error' => 'server_fatal', 'detail' => $error['message']], 500);
//     }
// });
// ============================================================

// Проверяем наличие PHPMailer
$phpmailerFound = false;

// Вариант 1: через Composer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    $phpmailerFound = true;
} 
// Вариант 2: файлы рядом
elseif (file_exists(__DIR__ . '/PHPMailer.php') && 
        file_exists(__DIR__ . '/SMTP.php') && 
        file_exists(__DIR__ . '/Exception.php')) {
    require __DIR__ . '/Exception.php';
    require __DIR__ . '/PHPMailer.php';
    require __DIR__ . '/SMTP.php';
    $phpmailerFound = true;
}

if (!$phpmailerFound) {
    die(json_encode([
        'ok' => false, 
        'error' => 'phpmailer_missing',
        'detail' => 'PHPMailer не найден. Убедитесь, что файлы PHPMailer есть в папке или установлен Composer.'
    ]));
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
        CURLOPT_SSL_VERIFYPEER => false, // ВРЕМЕННО отключаем проверку SSL для диагностики
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    
    $firebaseResponse = curl_exec($ch);
    $firebaseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Проверка ответа Firebase
    if ($firebaseCode !== 200) {
        send_json([
            'ok' => false,
            'error' => 'firebase_error',
            'detail' => "Код ответа Firebase: $firebaseCode",
            'response' => $firebaseResponse,
            'curl_error' => $curlError
        ], 500);
    }

    // ========== ОТПРАВКА ПИСЬМА ==========
    
    // Проверка доступности порта
    $connection = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 5);
    if (!$connection) {
        send_json([
            'ok' => false,
            'error' => 'smtp_port_blocked',
            'detail' => "Не удаётся подключиться к " . SMTP_HOST . ":" . SMTP_PORT,
            'socket_error' => "$errstr ($errno)"
        ], 500);
    }
    fclose($connection);

    // Создание письма с подробной отладкой
    $mail = new PHPMailer(true);
    
    // ВКЛЮЧАЕМ ПОДРОБНУЮ ОТЛАДКУ
    $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Уровень 2 - вывод всех сообщений
    $mail->Debugoutput = function($str, $level) {
        // Выводим отладку прямо в ответ (для диагностики)
        echo "<!-- SMTP Debug: $str -->\n";
        // Также пишем в файл
        file_put_contents(__DIR__ . '/smtp_debug.log', date('H:i:s') . " - $str\n", FILE_APPEND);
    };

    // Настройки SMTP
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    
    // Дополнительные настройки
    $mail->Timeout = 30;
    $mail->CharSet = 'UTF-8';
    
    // Отправитель и получатель
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email);
    
    // Содержимое письма
    $mail->isHTML(true);
    $mail->Subject = '=?UTF-8?B?' . base64_encode('Подтверждение почты Nexules') . '?=';
    $mail->Body = "
    <html>
    <body>
        <h2>Подтверждение email</h2>
        <p>Ваш код подтверждения: <strong>{$code}</strong></p>
    </body>
    </html>
    ";
    $mail->AltBody = "Ваш код подтверждения: {$code}";

    // Отправка
    if ($mail->send()) {
        send_json([
            'ok' => true, 
            'message' => 'Письмо отправлено',
            'code' => $code
        ]);
    } else {
        throw new Exception('Mailer Error: ' . $mail->ErrorInfo);
    }

} catch (Exception $e) {
    // Подробный вывод ошибки
    $errorDetails = [
        'ok' => false,
        'error' => 'mail_exception',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // Если есть дополнительная информация от PHPMailer
    if (isset($mail) && $mail->ErrorInfo) {
        $errorDetails['phpmailer_error'] = $mail->ErrorInfo;
    }
    
    // Выводим как JSON, но с HTML-комментариями для отладки
    echo "<!-- " . print_r($errorDetails, true) . " -->\n";
    send_json($errorDetails, 500);
    
} catch (Throwable $e) {
    // Любые другие ошибки
    send_json([
        'ok' => false,
        'error' => 'server_exception',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], 500);
}
?>

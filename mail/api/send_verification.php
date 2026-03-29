<?php
// send_verification.php - РАБОЧАЯ ВЕРСИЯ С КРАСИВЫМ ПИСЬМОМ

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

function send_json($payload, $code = 200) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    send_json(['ok' => true], 204);
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'], true)) {
    send_json(['ok' => false, 'error' => 'method_not_allowed'], 405);
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
        $email = filter_input(INPUT_GET, 'email', FILTER_VALIDATE_EMAIL) ?: $email;
        $uid = isset($_GET['uid']) ? trim($_GET['uid']) : $uid;
        $id_token = isset($_GET['id_token']) ? trim($_GET['id_token']) : $id_token;
    }

    if (!$email || !$uid || !$id_token) {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $email = isset($json['email']) ? filter_var($json['email'], FILTER_VALIDATE_EMAIL) : $email;
            $uid = isset($json['uid']) ? trim((string)$json['uid']) : $uid;
            $id_token = isset($json['id_token']) ? trim((string)$json['id_token']) : $id_token;
        }
    }

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

    // Создаём красивое HTML-письмо
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Код подтверждения Nexules</title>
    <style>
        /* Базовые стили для почтовых клиентов */
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #0a0c10;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        .header {
            background: linear-gradient(135deg, #1a1f2b 0%, #0f1219 100%);
            padding: 30px 20px;
            text-align: center;
            border-bottom: 2px solid #ff3366;
        }
        .logo {
            font-size: 32px;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: 2px;
            text-transform: uppercase;
            text-shadow: 0 0 10px rgba(255,51,102,0.5);
        }
        .logo span {
            color: #ff3366;
        }
        .content {
            padding: 40px 30px;
            background-color: #14181f;
            color: #e0e4e9;
        }
        .greeting {
            font-size: 18px;
            margin-bottom: 20px;
        }
        .code-block {
            background: linear-gradient(145deg, #1e232c, #151a22);
            border-radius: 12px;
            padding: 30px;
            margin: 30px 0;
            text-align: center;
            border: 1px solid #2a313c;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.5), 0 5px 15px rgba(255,51,102,0.2);
        }
        .code-label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #8895aa;
            margin-bottom: 10px;
        }
        .code-value {
            font-size: 48px;
            font-weight: 700;
            color: #ff3366;
            text-shadow: 0 0 15px rgba(255,51,102,0.7);
            letter-spacing: 8px;
            font-family: 'Courier New', monospace;
        }
        .hint {
            font-size: 14px;
            color: #6f7c91;
            margin-top: 20px;
            border-top: 1px dashed #2a313c;
            padding-top: 20px;
        }
        .footer {
            background-color: #0a0c10;
            padding: 20px;
            text-align: center;
            color: #4f5a6b;
            font-size: 13px;
            border-top: 1px solid #1f262e;
        }
        .footer a {
            color: #ff3366;
            text-decoration: none;
        }
    </style>
</head>
<body style="margin:0; padding:20px; background-color:#020304; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <div class="container">
        <div class="header">
            <div class="logo">Nex<span>ules</span></div>
        </div>
        <div class="content">
            <div class="greeting">Здравствуйте!</div>
            <p>Вы получили это письмо, потому что запросили код подтверждения для входа в игру <strong>Nexules</strong>.</p>
            
            <div class="code-block">
                <div class="code-label">Ваш код подтверждения</div>
                <div class="code-value">{$code}</div>
            </div>
            
            <div class="hint">
                ⏳ Код действителен в течение 10 минут. Никому не сообщайте его.<br>
                Если вы не запрашивали код, просто проигнорируйте это письмо.
            </div>
        </div>
        <div class="footer">
            © 2025 Nexules. Все права защищены.<br>
            <a href="https://nexules.ru">nexules.ru</a> • <a href="mailto:support@nexules.ru">support@nexules.ru</a>
        </div>
    </div>
</body>
</html>
HTML;

    // Текстовая версия для старых клиентов
    $textBody = "Здравствуйте!\n\n"
              . "Ваш код подтверждения для входа в Nexules: $code\n\n"
              . "Код действителен 10 минут. Никому не сообщайте его.\n"
              . "Если вы не запрашивали код, просто проигнорируйте это письмо.\n\n"
              . "-- \nNexules";

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
    $mail->isHTML(true);
    $mail->Subject = 'Код подтверждения Nexules';
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();
    
    send_json(['ok' => true, 'code' => $code]);

} catch (Exception $e) {
    send_json(['ok' => false, 'error' => 'mail_failed'], 500);
}
?>

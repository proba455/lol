<?php
// send_password_reset.php - отправка кода для сброса пароля

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

// Настройки SMTP (используем твои данные от Gmail)
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

// ===== Helpers for Firebase Admin access (no user login required) =====
function get_service_account() {
    $json = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    if ($json) {
        return json_decode($json, true);
    }
    $path = getenv('FIREBASE_SERVICE_ACCOUNT_PATH');
    if ($path && file_exists($path)) {
        return json_decode(file_get_contents($path), true);
    }
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
    $signature = '';
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

function get_db_base_urls($dbName, $projectId) {
    $fromEnv = getenv('FIREBASE_DB_URL');
    if ($fromEnv) {
        return [rtrim($fromEnv, '/')];
    }
    $instance = getenv('FIREBASE_DB_INSTANCE');
    if ($instance) {
        return [
            "https://{$instance}.firebaseio.com",
            "https://{$instance}.firebasedatabase.app"
        ];
    }
    $urls = [];
    $urls[] = "https://{$dbName}.firebaseio.com";
    $urls[] = "https://{$projectId}-default-rtdb.firebaseio.com";
    return array_values(array_unique($urls));
}

function firebase_db_put($dbName, $projectId, $path, $token, $payload) {
    $last = ['ok' => false, 'http_code' => 0, 'url' => '', 'error' => 'unknown', 'body' => ''];
    $urls = get_db_base_urls($dbName, $projectId);
    foreach ($urls as $baseUrl) {
        $url = $baseUrl . "/{$path}.json?access_token={$token}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false && $httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'http_code' => $httpCode, 'url' => $url, 'error' => '', 'body' => $resp];
        }
        $last = ['ok' => false, 'http_code' => $httpCode, 'url' => $url, 'error' => $err, 'body' => strval($resp)];
    }
    return $last;
}

function firebase_set_temp_password($projectId, $token, $uid, $password) {
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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_error('Метод не поддерживается. Используйте POST.', 'method_not_allowed', 405);
    }

    $input = get_input_data();
    $rawEmail = isset($input['email']) ? trim($input['email']) : '';
    $email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);

    if (!$email) {
        send_error('Некорректный email.', 'invalid_params_email', 400);
    }

    $sa = get_service_account();
    if (!$sa) {
        send_error('Не найден сервисный аккаунт Firebase.', 'service_account_missing', 500);
    }
    $accessToken = get_access_token($sa);
    if (!$accessToken) {
        send_error('Не удалось получить access token.', 'access_token_failed', 500);
    }
    $projectId = $sa['project_id'] ?? 'nexules-3ba83';
    $dbName = $projectId . '-default-rtdb';

    // Находим uid по email с правами сервера
    $uid = firebase_lookup_uid_by_email($projectId, $accessToken, $email);
    if (!$uid) {
        send_error('Пользователь с таким email не найден.', 'user_not_found', 404);
    }

    // Генерируем код
    $code = random_int(100000, 999999);
    
    // Сохраняем код в RTDB с правами сервера
    $saved = firebase_db_put($dbName, $projectId, "password_reset/{$uid}", $accessToken, ['code' => $code, 'created_at' => time()]);
    if (!$saved['ok']) {
        send_json([
            'ok' => false,
            'success' => false,
            'message' => 'Не удалось сохранить код в базе.',
            'error' => 'db_write_failed',
            'http_code' => $saved['http_code'],
            'db_url' => $saved['url'],
            'curl_error' => $saved['error'],
            'body' => $saved['body']
        ], 500);
    }

    // Делаем код временным паролем (админ-права)
    $ok = firebase_set_temp_password($projectId, $accessToken, $uid, $code);
    if (!$ok) {
        send_error('Не удалось установить временный пароль.', 'update_password_failed', 500);
    }

    // Красивое HTML-письмо для сброса пароля
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сброс пароля Nexules</title>
    <style>
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
            <p>Вы получили это письмо, потому что запросили сброс пароля для аккаунта в игре <strong>Nexules</strong>.</p>
            
            <div class="code-block">
                <div class="code-label">Код для сброса пароля</div>
                <div class="code-value">{$code}</div>
            </div>
            
            <div class="hint">
                ⏳ Код действителен в течение 10 минут. Никому не сообщайте его.<br>
                Если вы не запрашивали сброс пароля, просто проигнорируйте это письмо.
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
              . "Вы запросили сброс пароля для аккаунта Nexules.\n"
              . "Ваш код подтверждения: $code\n\n"
              . "Код действителен 10 минут. Никому не сообщайте его.\n"
              . "Если вы не запрашивали сброс, просто проигнорируйте это письмо.\n\n"
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
    $mail->Subject = 'Сброс пароля Nexules';
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();
    
    send_success('Письмо с кодом отправлено.', ['code' => $code]);

} catch (Exception $e) {
    send_error('Ошибка отправки письма.', 'mail_failed', 500);
}
?>

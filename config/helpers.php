<?php

// Load .env file if it exists (useful for local development credentials)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'"); // strip surrounding spaces and quotes
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Load Composer autoloader (PHPMailer, Twilio SDK)
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
}

function load_app_config(): array
{
    $primary = __DIR__ . '/app.php';
    $fallback = __DIR__ . '/app.example.php';

    if (file_exists($primary)) {
        return require $primary;
    }

    if (file_exists($fallback)) {
        return require $fallback;
    }

    throw new RuntimeException('No application config file found. Expected config/app.php or config/app.example.php.');
}

$config = load_app_config();
date_default_timezone_set($config['timezone'] ?? 'UTC');

function config(?string $key = null, mixed $default = null): mixed
{
    static $config;

    if ($config === null) {
        $config = load_app_config();
    }

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}

function db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $settings = require __DIR__ . '/database.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $settings['host'],
        $settings['port'],
        $settings['database'],
        $settings['charset']
    );

    $pdo = new PDO($dsn, $settings['username'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_auth(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.');
        redirect('index.php?page=login');
    }
}

function require_role(array $roles): void
{
    require_auth();

    $role = current_user()['role'] ?? '';

    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flash_messages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function today(): string
{
    return date('Y-m-d');
}

function now_time(): string
{
    return date('H:i:s');
}

function nav_items(): array
{
    $items = [
        'dashboard' => 'Dashboard',
        'students' => 'Students',
        'attendance' => 'Attendance',
        'reports' => 'Reports',
        'notifications' => 'Notifications',
    ];

    if ((current_user()['role'] ?? '') === 'admin') {
        $items['admin'] = 'Admin Access';
    }

    return $items;
}

function face_api_request(string $endpoint, array $payload = [], array $files = []): array
{
    $ch = curl_init(rtrim(config('face_api_url'), '/') . $endpoint);

    $postFields = $payload;

    foreach ($files as $key => $filePath) {
        $postFields[$key] = new CURLFile($filePath);
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_POSTFIELDS => $postFields,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Face API request failed: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Face API returned an invalid JSON response.');
    }

    if ($statusCode >= 400) {
        $message = $decoded['error'] ?? 'Unknown Face API error.';
        throw new RuntimeException($message);
    }

    return $decoded;
}

function render_layout(string $title, string $content, string $activePage = 'dashboard'): void
{
    $user = current_user();
    $flashes = pull_flash_messages();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> | <?= e(config('app_name')) ?></title>
    <link rel="stylesheet" href="public/assets/app.css">
</head>
<body>
<?php if ($user === null): ?>
    <div class="login-shell">
        <div class="login-card">
            <h1><?= e(config('app_name')) ?></h1>
            <?php foreach ($flashes as $flash): ?>
                <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </div>
    </div>
<?php else: ?>
    <div class="shell">
        <!-- Mobile Header Bar -->
        <header class="mobile-header-bar">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle Menu">☰</button>
            <div class="brand" style="margin-bottom:0; font-size:1.15rem;"><?= e(config('app_name')) ?></div>
            <div style="width: 32px;"></div> <!-- For balancing layout -->
        </header>

        <!-- Sidebar Overlay -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="brand" style="margin-bottom:0;"><?= e(config('app_name')) ?></div>
                <button class="sidebar-close" id="sidebarClose" aria-label="Close Menu">✕</button>
            </div>
            <nav class="nav" style="margin-top:20px;">
                <?php foreach (nav_items() as $page => $label): ?>
                    <a class="<?= $activePage === $page ? 'active' : '' ?>" href="index.php?page=<?= e($page) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
                <a href="index.php?page=logout" style="margin-top:20px; border-top:1px solid rgba(255,255,255,0.08); padding-top:15px;">Logout</a>
            </nav>
        </aside>
        
        <main class="content">
            <div class="topbar">
                <div class="topbar-meta">
                    <h1 style="margin:0;"><?= e($title) ?></h1>
                    <div class="muted">Signed in as <?= e($user['email']) ?> (<?= e($user['role']) ?>)</div>
                </div>
                <div class="badge"><?= e(date('D, d M Y H:i')) ?></div>
            </div>
            <?php foreach ($flashes as $flash): ?>
                <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const sidebar = document.getElementById('sidebar');

    if (sidebarToggle && sidebar && sidebarOverlay) {
        const toggleMenu = () => {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
        };

        sidebarToggle.addEventListener('click', toggleMenu);
        sidebarOverlay.addEventListener('click', toggleMenu);

        const sidebarClose = document.getElementById('sidebarClose');
        if (sidebarClose) {
            sidebarClose.addEventListener('click', toggleMenu);
        }
        
        // Close sidebar if user clicks a menu link on mobile
        const navLinks = sidebar.querySelectorAll('.nav a');
        navLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (sidebar.classList.contains('active')) {
                    toggleMenu();
                }
            });
        });
    }
});
</script>
</body>
</html>
<?php
}

function has_registered_users(): bool
{
    $stmt = db()->query('SELECT COUNT(*) FROM users');
    return (int) $stmt->fetchColumn() > 0;
}

function generate_qr_token(): string
{
    do {
        $token = bin2hex(random_bytes(32));
        $exists = db()->prepare('SELECT id FROM students WHERE qr_token = :token LIMIT 1');
        $exists->execute(['token' => $token]);
    } while ($exists->fetch());

    return $token;
}

function send_notification_email(?string $to, string $subject, string $body): bool
{
    static $smtpFailed = false;

    if ($to === null || $to === '') {
        return false;
    }

    // Try sending via Resend API first (if API key is present)
    $resendApiKey = (string) config('resend_api_key', '');
    if ($resendApiKey !== '') {
        try {
            $ch = curl_init('https://api.resend.com/emails');
            $fromName  = (string) config('smtp_from_name', 'FaceTrack Attendance');
            $fromEmail = 'onboarding@resend.dev'; // Free Resend accounts must use onboarding@resend.dev

            $payload = json_encode([
                'from'    => "{$fromName} <{$fromEmail}>",
                'to'      => [$to],
                'subject' => $subject,
                'text'    => $body,
            ]);

            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $resendApiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 5,
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($statusCode >= 200 && $statusCode < 300) {
                return true;
            }

            error_log('[FaceTrack] Resend API failed (status ' . $statusCode . '): ' . $response);
        } catch (Throwable $e) {
            error_log('[FaceTrack] Resend API exception: ' . $e->getMessage());
        }
    }

    // Fallback to PHPMailer SMTP
    if ($smtpFailed || !config('smtp_enabled', false)) {
        return false;
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('[FaceTrack] PHPMailer class not found – run: composer require phpmailer/phpmailer');
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // SMTP configuration
        $mail->isSMTP();
        $mail->Timeout     = 3; // Timeout connection attempt after 3 seconds
        $mail->Host       = (string) config('smtp_host', 'smtp.gmail.com');
        $mail->Port       = (int) config('smtp_port', 587);
        $mail->SMTPAuth   = (bool) config('smtp_auth', true);
        $mail->Username   = (string) config('smtp_username', '');
        $mail->Password   = (string) config('smtp_password', '');
        $mail->SMTPSecure = (string) config('smtp_secure', 'tls');

        // Sender & recipient
        $fromEmail = (string) config('smtp_from_email', config('smtp_username', ''));
        $fromName  = (string) config('smtp_from_name', 'FaceTrack Attendance');
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('[FaceTrack] Email send failed: ' . $e->getMessage());
        // If it's a connection issue, disable SMTP for the rest of this request
        if (str_contains(strtolower($e->getMessage()), 'connect') || str_contains(strtolower($e->getMessage()), 'smtp host')) {
            $smtpFailed = true;
        }
        return false;
    } catch (Throwable $e) {
        error_log('[FaceTrack] Email send error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send a WhatsApp or SMS message to a phone number via Twilio.
 *
 * @param string|null $phone  International format, e.g. +923001234567
 * @param string      $body   Plain-text message body
 * @return string  'whatsapp', 'sms', or '' (empty = not sent)
 */
function send_notification_sms(?string $phone, string $body): string
{
    static $twilioFailed = false;

    if ($twilioFailed) {
        return '';
    }

    if ($phone === null || $phone === '' || !config('twilio_enabled', false)) {
        return '';
    }

    if (!class_exists(\Twilio\Rest\Client::class)) {
        error_log('[FaceTrack] Twilio SDK not found – run: composer require twilio/sdk');
        return '';
    }

    $sid   = (string) config('twilio_account_sid', '');
    $token = (string) config('twilio_auth_token', '');

    if ($sid === '' || $token === '') {
        error_log('[FaceTrack] Twilio credentials not configured.');
        return '';
    }

    // Sanitize phone: strip spaces, dashes, parens, dots — keep only digits and leading +
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (!str_starts_with($phone, '+')) {
        $phone = '+' . ltrim($phone, '0');
    }

    $prefer = config('twilio_prefer', 'whatsapp');

    try {
        $client = new \Twilio\Rest\Client($sid, $token);

        // Try WhatsApp first (if preferred)
        if ($prefer === 'whatsapp') {
            try {
                $client->messages->create(
                    'whatsapp:' . $phone,
                    [
                        'from' => config('twilio_whatsapp_from', 'whatsapp:+14155238886'),
                        'body' => $body,
                    ]
                );
                return 'whatsapp';
            } catch (Throwable $e) {
                error_log('[FaceTrack] WhatsApp failed, falling back to SMS: ' . $e->getMessage());
            }
        }

        // Fallback to SMS
        $smsFrom = config('twilio_sms_from', '');
        if ($smsFrom !== '') {
            $client->messages->create(
                $phone,
                [
                    'from' => $smsFrom,
                    'body' => $body,
                ]
            );
            return 'sms';
        }

        return '';
    } catch (Throwable $e) {
        error_log('[FaceTrack] Twilio send failed: ' . $e->getMessage());
        $twilioFailed = true; // Disable twilio for the rest of this request
        return '';
    }
}

<?php

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
    return [
        'dashboard' => 'Dashboard',
        'students' => 'Students',
        'attendance' => 'Attendance',
        'reports' => 'Reports',
        'notifications' => 'Notifications',
    ];
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
    <style>
        :root { --ink:#1f2937; --muted:#6b7280; --bg:#f4f7fb; --card:#fff; --line:#dbe4f0; --accent:#0f766e; --accent-soft:#d9f3f1; --danger:#b91c1c; --danger-soft:#fee2e2; --warning:#92400e; --warning-soft:#fef3c7; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Segoe UI",Tahoma,sans-serif; background:linear-gradient(180deg,#edf5ff 0%,var(--bg) 30%,#f8fafc 100%); color:var(--ink); }
        .shell { display:grid; grid-template-columns:240px 1fr; min-height:100vh; }
        .sidebar { background:#0f172a; color:#e2e8f0; padding:24px 18px; }
        .brand { font-size:1.25rem; font-weight:700; margin-bottom:24px; }
        .nav a { display:block; color:#cbd5e1; text-decoration:none; padding:10px 12px; border-radius:12px; margin-bottom:8px; }
        .nav a.active, .nav a:hover { background:rgba(148,163,184,.18); color:#fff; }
        .content { padding:28px; }
        .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        .grid { display:grid; gap:16px; }
        .grid.cols-2 { grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); }
        .grid.cols-3 { grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:18px; padding:20px; box-shadow:0 10px 30px rgba(15,23,42,.05); }
        .card h2, .card h3 { margin-top:0; }
        .muted { color:var(--muted); }
        .stat { font-size:2rem; font-weight:700; margin:4px 0 0; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:12px 10px; border-bottom:1px solid var(--line); vertical-align:top; }
        form { display:grid; gap:14px; }
        label { display:grid; gap:6px; font-weight:600; }
        input, select, button { border-radius:12px; border:1px solid #cbd5e1; padding:10px 12px; font:inherit; }
        button { background:var(--accent); color:#fff; border:none; cursor:pointer; font-weight:600; }
        .secondary-btn { background:#e2e8f0; color:#0f172a; }
        .danger-btn { background:#b91c1c; color:#fff; }
        .ghost-btn { background:#fff; color:#0f172a; border:1px solid #cbd5e1; }
        .inline-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .inline-actions form { display:inline; gap:0; }
        .inline-actions a, .inline-actions button { text-decoration:none; padding:8px 12px; border-radius:10px; font-size:.92rem; }
        .flash { padding:12px 14px; border-radius:14px; margin-bottom:12px; border:1px solid transparent; }
        .flash.success { background:var(--accent-soft); border-color:#99f6e4; }
        .flash.error { background:var(--danger-soft); border-color:#fca5a5; color:var(--danger); }
        .flash.warning { background:var(--warning-soft); border-color:#fde68a; color:var(--warning); }
        .badge { display:inline-flex; padding:4px 10px; border-radius:999px; background:#e0f2fe; color:#075985; font-size:.85rem; font-weight:600; }
        .meter { height:10px; border-radius:999px; background:#e5e7eb; overflow:hidden; }
        .meter > span { display:block; height:100%; background:linear-gradient(90deg,#14b8a6,#0ea5e9); }
        .login-shell { min-height:100vh; display:grid; place-items:center; padding:24px; }
        .login-card { width:min(460px,100%); }
        @media (max-width:860px) { .shell { grid-template-columns:1fr; } .sidebar { padding-bottom:8px; } .content { padding:20px; } }
    </style>
</head>
<body>
<?php if ($user === null): ?>
    <div class="login-shell">
        <div class="card login-card">
            <h1><?= e(config('app_name')) ?></h1>
            <?php foreach ($flashes as $flash): ?>
                <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </div>
    </div>
<?php else: ?>
    <div class="shell">
        <aside class="sidebar">
            <div class="brand"><?= e(config('app_name')) ?></div>
            <nav class="nav">
                <?php foreach (nav_items() as $page => $label): ?>
                    <a class="<?= $activePage === $page ? 'active' : '' ?>" href="index.php?page=<?= e($page) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>
                <a href="index.php?page=logout">Logout</a>
            </nav>
        </aside>
        <main class="content">
            <div class="topbar">
                <div>
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
    if ($to === null || $to === '' || !config('smtp_enabled', false)) {
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
        return '';
    }
}

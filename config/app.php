<?php

return [
    'app_name' => 'FaceTrack Attendance',
    'base_url' => 'http://localhost/face_attendance/index.php',
    'face_api_url' => 'https://xainn-facetrack-api.hf.space',
    'face_match_threshold' => 0.55,
    'timezone' => 'Asia/Karachi',

    // Attendance arriving after this time is marked "late" automatically
    'late_threshold' => '09:15:00',

    // Students below this percentage trigger low-attendance alerts
    'min_attendance_pct' => 75,

    // ─── Email Notifications (Gmail SMTP via PHPMailer) ─────────────
    'smtp_enabled'   => true,
    'smtp_host'      => 'smtp.gmail.com',
    'smtp_port'      => 587,
    'smtp_auth'      => true,
    'smtp_secure'    => 'tls',

    // Non-secret values
    'smtp_username'  => 'raozn14112001@gmail.com',
    'smtp_from_name' => 'FaceTrack Attendance',
    'smtp_from_email'=> 'raozn14112001@gmail.com',

    // Secret value from Render Environment Variables
    'smtp_password'  => getenv('SMTP_PASSWORD'),

    // ─── WhatsApp / SMS via Twilio ───────────────────────────────────
    'twilio_enabled'        => true,

    // Secret values from Render Environment Variables
    'twilio_account_sid'    => getenv('TWILIO_ACCOUNT_SID'),
    'twilio_auth_token'     => getenv('TWILIO_AUTH_TOKEN'),

    // Non-secret values
    'twilio_sms_from'       => '',
    'twilio_whatsapp_from'  => 'whatsapp:+14155238886',
    'twilio_prefer'         => 'whatsapp',
];

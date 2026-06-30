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
    // 1. Go to myaccount.google.com → Security → 2-Step Verification → App passwords
    // 2. Generate an "App password" for "Mail" and paste it below
    'smtp_enabled'   => true,          // ← set true once credentials are filled
    'smtp_host'      => 'smtp.gmail.com',
    'smtp_port'      => 587,
    'smtp_auth'      => true,
    'smtp_secure'    => 'tls',          // 'tls' (port 587) or 'ssl' (port 465)
    'smtp_username'  => 'raozn14112001@gmail.com',             // ← your Gmail address (e.g. yourname@gmail.com)
    'smtp_password'  => 'arfu uweg dpbz kosn',             // ← your Gmail App Password (16 chars, no spaces)
    'smtp_from_name' => 'FaceTrack Attendance',
    'smtp_from_email'=> 'raozn14112001@gmail.com',             // ← same as smtp_username usually

    // ─── WhatsApp / SMS via Twilio ───────────────────────────────────
    // 1. Sign up at twilio.com (free trial gives $15 credit)
    // 2. Get your Account SID + Auth Token from the Twilio Console dashboard
    // 3. For SMS: buy a phone number (~$1/mo) in Console → Phone Numbers
    // 4. For WhatsApp: join the sandbox at twilio.com/console/sms/whatsapp/sandbox
    //    (send "join <your-sandbox-keyword>" from your phone to the sandbox number)
    'twilio_enabled'        => true,
    'twilio_account_sid'    => 'ACf59d44f93edf6acd1a115c16e5645720',
    'twilio_auth_token'     => 'a39db5cd6dc25960156c9a9e2f13cfe8',      // ⚠️ PASTE YOUR AUTH TOKEN HERE (from Twilio Console)
    'twilio_sms_from'       => '',      // leave empty if using WhatsApp only
    'twilio_whatsapp_from'  => 'whatsapp:+14155238886',
    'twilio_prefer'         => 'whatsapp',
];

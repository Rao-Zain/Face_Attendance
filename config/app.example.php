<?php

/**
 * ⚠️ DO NOT PUT YOUR REAL KEYS IN THIS FILE.
 * 1. Copy this file to app.php
 * 2. Fill in your real credentials in app.php
 */

return [
    'app_name' => 'FaceTrack Attendance',
    'base_url' => 'http://localhost/face_attendance/index.php',
    'face_api_url' => 'http://127.0.0.1:5000',
    'face_match_threshold' => 0.55,
    'timezone' => 'Asia/Karachi',

    'late_threshold' => '09:15:00',
    'min_attendance_pct' => 75,

    // SMTP Credentials
    'smtp_enabled'   => false,
    'smtp_host'      => 'smtp.gmail.com',
    'smtp_port'      => 587,
    'smtp_auth'      => true,
    'smtp_secure'    => 'tls',
    'smtp_username'  => 'YOUR_EMAIL@gmail.com',
    'smtp_password'  => 'YOUR_APP_PASSWORD',
    'smtp_from_name' => 'FaceTrack Attendance',
    'smtp_from_email'=> 'YOUR_EMAIL@gmail.com',

    // Twilio Credentials
    'twilio_enabled'        => false,
    'twilio_account_sid'    => 'YOUR_ACCOUNT_SID',
    'twilio_auth_token'     => 'YOUR_AUTH_TOKEN',
    'twilio_sms_from'       => 'YOUR_PHONE_NUMBER',
    'twilio_whatsapp_from'  => 'whatsapp:+14155238886',
    'twilio_prefer'         => 'whatsapp',
];

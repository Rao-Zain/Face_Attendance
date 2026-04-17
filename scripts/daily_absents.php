<?php

/**
 * FaceTrack Attendance - Daily Absent Automation Script
 * 
 * This script identifies students who have not marked attendance for today
 * and dispatches notifications to their parents/guardians.
 * 
 * Usage (CLI/Task Scheduler):
 * php scripts/daily_absents.php
 */

// 1. Setup Environment
define('CLI_MODE', php_sapi_name() === 'cli');

// Adjust relative path to helpers
require_once __DIR__ . '/../config/helpers.php';

echo "[FaceTrack] Starting Daily Absent Scan: " . date('Y-m-d H:i:s') . PHP_EOL;

try {
    $today = today();
    $generated = 0;
    $db = db();

    // 2. Find Absent Students
    // Students who exist in the database but have NO attendance record for today
    $absentStmt = $db->prepare('
        SELECT s.id, s.name, s.roll_no, s.class_name, s.parent_email, s.parent_phone
        FROM students s
        WHERE s.id NOT IN (
            SELECT a.student_id FROM attendance a WHERE a.attendance_date = :today
        )
    ');
    $absentStmt->execute(['today' => $today]);
    $absentStudents = $absentStmt->fetchAll();

    if (empty($absentStudents)) {
        echo "[FaceTrack] Clean sweep! No absentees detected today." . PHP_EOL;
        exit(0);
    }

    echo "[FaceTrack] Detected " . count($absentStudents) . " potential absentee(s)." . PHP_EOL;

    // 3. Dispatch Notifications
    foreach ($absentStudents as $student) {
        // Skip if already notified today (to avoid spamming parents)
        $notifiedToday = $db->prepare('
            SELECT id FROM notifications 
            WHERE student_id = :sid AND type = "absent" AND DATE(created_at) = :today 
            LIMIT 1
        ');
        $notifiedToday->execute(['sid' => $student['id'], 'today' => $today]);
        
        if ($notifiedToday->fetch()) {
            echo " - Skipping {$student['name']} (Already notified)" . PHP_EOL;
            continue;
        }

        $message = sprintf(
            "Notice: Your child %s (%s) is ABSENT today (%s). Please contact the institution office if this is an error or to provide a reason.",
            $student['name'], $student['roll_no'], $today
        );

        $subject = "Absence Alert: " . $student['name'];

        // Use the internal dispatch logic (borrowed from notifications module logic)
        $sentVia = [];
        
        // Try Email
        if ($student['parent_email'] && send_notification_email($student['parent_email'], $subject, $message)) {
            $sentVia[] = 'email';
        }

        // Try WhatsApp/SMS
        if ($student['parent_phone']) {
            $smsResult = send_notification_sms($student['parent_phone'], $message);
            if ($smsResult !== '') {
                $sentVia[] = $smsResult;
            }
        }

        $viaString = count($sentVia) > 0 ? implode('+', $sentVia) : 'system';

        // 4. Log to Database
        $log = $db->prepare('
            INSERT INTO notifications (student_id, type, message, recipient_email, sent_via)
            VALUES (:sid, "absent", :msg, :email, :via)
        ');
        $log->execute([
            'sid' => $student['id'],
            'msg' => $message,
            'email' => $student['parent_email'],
            'via' => $viaString
        ]);

        echo " - Notified Parent of {$student['name']} via {$viaString}" . PHP_EOL;
        $generated++;
    }

    echo "[FaceTrack] Operation complete. Total notifications sent: $generated" . PHP_EOL;

} catch (Throwable $e) {
    echo "[FaceTrack] CRITICAL ERROR: " . $e->getMessage() . PHP_EOL;
    error_log("[FaceTrack Cron] " . $e->getMessage());
    exit(1);
}

<?php

require_role(['admin']);

$pageTitle = 'Notifications';

// ─── Local helper: dispatch a notification via all enabled channels ───

/**
 * Send the notification through every enabled channel (email, whatsapp/sms)
 * and insert a record into the notifications table.
 *
 * @return bool  true if the record was inserted (regardless of delivery success)
 */
function _dispatch_notification(
    int    $studentId,
    string $type,
    string $message,
    string $subject,
    ?string $parentEmail,
    ?string $parentPhone
): bool {
    $channels = [];

    // ── Email channel ─────────────────────────────────────────────
    if ($parentEmail && send_notification_email($parentEmail, $subject, $message)) {
        $channels[] = 'email';
    }

    // ── WhatsApp / SMS channel ────────────────────────────────────
    if ($parentPhone) {
        $smsResult = send_notification_sms($parentPhone, $message);
        if ($smsResult !== '') {
            $channels[] = $smsResult; // 'whatsapp' or 'sms'
        }
    }

    // Determine sent_via value
    if (count($channels) === 0) {
        $via = 'system';
    } elseif (count($channels) === 1) {
        $via = $channels[0];
    } else {
        // Combine: e.g. "email+whatsapp"
        sort($channels);
        $via = implode('+', $channels);
    }

    $ins = db()->prepare(
        'INSERT INTO notifications (student_id, type, message, recipient_email, sent_via)
         VALUES (:sid, :type, :msg, :email, :via)'
    );
    $ins->execute([
        'sid'   => $studentId,
        'type'  => $type,
        'msg'   => $message,
        'email' => $parentEmail,
        'via'   => $via,
    ]);

    return true;
}

// ─── POST handlers ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ── Generate alerts for today ──────────────────────
        if ($action === 'generate_alerts') {
            $today = today();
            $generated = 0;

            // ── Find absent students ──────────────────────
            $absent = db()->prepare(
                'SELECT s.id, s.name, s.roll_no, s.class_name, s.parent_email, s.parent_phone
                 FROM students s
                 WHERE s.id NOT IN (
                     SELECT a.student_id FROM attendance a WHERE a.attendance_date = :today
                 )'
            );
            $absent->execute(['today' => $today]);
            $absentStudents = $absent->fetchAll();

            foreach ($absentStudents as $student) {
                // Skip if already notified today
                $alreadyNotified = db()->prepare(
                    'SELECT id FROM notifications WHERE student_id = :sid AND type = :type AND DATE(created_at) = :today LIMIT 1'
                );
                $alreadyNotified->execute(['sid' => $student['id'], 'type' => 'absent', 'today' => $today]);
                if ($alreadyNotified->fetch()) {
                    continue;
                }

                $message = sprintf(
                    'Dear Parent/Guardian, this is to inform you that %s (%s, %s) was absent on %s. Please contact the institution for further details.',
                    $student['name'], $student['roll_no'], $student['class_name'], $today
                );

                _dispatch_notification(
                    $student['id'],
                    'absent',
                    $message,
                    'Absence Alert — ' . $student['name'],
                    $student['parent_email'],
                    $student['parent_phone'] ?? null
                );
                $generated++;
            }

            // ── Find late students ────────────────────────
            $late = db()->prepare(
                'SELECT s.id, s.name, s.roll_no, s.class_name, s.parent_email, s.parent_phone, a.attendance_time
                 FROM attendance a
                 INNER JOIN students s ON s.id = a.student_id
                 WHERE a.attendance_date = :today AND a.status = :status'
            );
            $late->execute(['today' => $today, 'status' => 'late']);
            $lateStudents = $late->fetchAll();

            foreach ($lateStudents as $student) {
                $alreadyNotified = db()->prepare(
                    'SELECT id FROM notifications WHERE student_id = :sid AND type = :type AND DATE(created_at) = :today LIMIT 1'
                );
                $alreadyNotified->execute(['sid' => $student['id'], 'type' => 'late', 'today' => $today]);
                if ($alreadyNotified->fetch()) {
                    continue;
                }

                $message = sprintf(
                    'Dear Parent/Guardian, %s (%s, %s) arrived late at %s on %s. The scheduled time was %s.',
                    $student['name'], $student['roll_no'], $student['class_name'],
                    $student['attendance_time'], $today, config('late_threshold', '09:15:00')
                );

                _dispatch_notification(
                    $student['id'],
                    'late',
                    $message,
                    'Late Arrival Alert — ' . $student['name'],
                    $student['parent_email'],
                    $student['parent_phone'] ?? null
                );
                $generated++;
            }

            // ── Check low attendance (last 30 days) ───────
            $totalWorkingDays = max(1, (int) db()->query(
                'SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
            )->fetchColumn());

            $minPct = (float) config('min_attendance_pct', 75);

            $lowAttendance = db()->query(
                'SELECT s.id, s.name, s.roll_no, s.class_name, s.parent_email, s.parent_phone,
                        COUNT(a.id) AS days_present
                 FROM students s
                 LEFT JOIN attendance a ON a.student_id = s.id
                     AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY s.id, s.name, s.roll_no, s.class_name, s.parent_email, s.parent_phone
                 HAVING days_present > 0'
            )->fetchAll();

            foreach ($lowAttendance as $student) {
                $pct = round(((int) $student['days_present'] / $totalWorkingDays) * 100, 1);
                if ($pct >= $minPct) {
                    continue;
                }

                $alreadyNotified = db()->prepare(
                    'SELECT id FROM notifications WHERE student_id = :sid AND type = :type AND DATE(created_at) = :today LIMIT 1'
                );
                $alreadyNotified->execute(['sid' => $student['id'], 'type' => 'low_attendance', 'today' => $today]);
                if ($alreadyNotified->fetch()) {
                    continue;
                }

                $message = sprintf(
                    'Dear Parent/Guardian, %s (%s, %s) has an attendance rate of %.1f%% over the last 30 days, which is below the required minimum of %.0f%%. Please address this to avoid academic penalties.',
                    $student['name'], $student['roll_no'], $student['class_name'], $pct, $minPct
                );

                _dispatch_notification(
                    $student['id'],
                    'low_attendance',
                    $message,
                    'Low Attendance Warning — ' . $student['name'],
                    $student['parent_email'],
                    $student['parent_phone'] ?? null
                );
                $generated++;
            }

            flash('success', sprintf('Generated %d new notification(s). Duplicates for today were skipped.', $generated));
            redirect('index.php?page=notifications');
        }

        // ── Send a test email + SMS to verify config ──────
        if ($action === 'send_test') {
            $testEmail = trim($_POST['test_email'] ?? '');
            $testPhone = trim($_POST['test_phone'] ?? '');
            $results = [];

            if ($testEmail !== '') {
                $ok = send_notification_email(
                    $testEmail,
                    'FaceTrack Test Email',
                    "This is a test email from FaceTrack Attendance.\nIf you received this, your Gmail SMTP is configured correctly!\n\nSent at: " . date('Y-m-d H:i:s')
                );
                $results[] = $ok ? '✅ Email sent to ' . $testEmail : '❌ Email failed (check PHP error log)';
            }

            if ($testPhone !== '') {
                $via = send_notification_sms(
                    $testPhone,
                    "This is a test message from FaceTrack Attendance.\nIf you received this, your Twilio config is working!\n\nSent at: " . date('Y-m-d H:i:s')
                );
                $results[] = $via !== ''
                    ? '✅ ' . ucfirst($via) . ' sent to ' . $testPhone
                    : '❌ SMS/WhatsApp failed (check PHP error log)';
            }

            if ($results === []) {
                flash('warning', 'Please enter an email or phone number to test.');
            } else {
                flash(str_contains(implode('', $results), '❌') ? 'warning' : 'success', implode(' | ', $results));
            }
            redirect('index.php?page=notifications');
        }

        // ── Clear all notifications ───────────────────────
        if ($action === 'clear_all') {
            db()->exec('DELETE FROM notifications');
            flash('success', 'All notifications have been cleared.');
            redirect('index.php?page=notifications');
        }
    } catch (Throwable $exception) {
        flash('error', 'Notification action failed: ' . $exception->getMessage());
        redirect('index.php?page=notifications');
    }
}

// ─── Stats ────────────────────────────────────────────────────

$totalNotifications = (int) db()->query('SELECT COUNT(*) FROM notifications')->fetchColumn();

$todayStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE DATE(created_at) = :today');
$todayStmt->execute(['today' => today()]);
$todayNotifications = (int) $todayStmt->fetchColumn();

$absentTodayStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE type = "absent" AND DATE(created_at) = :today');
$absentTodayStmt->execute(['today' => today()]);
$todayAbsent = (int) $absentTodayStmt->fetchColumn();

$lateTodayStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE type = "late" AND DATE(created_at) = :today');
$lateTodayStmt->execute(['today' => today()]);
$todayLate = (int) $lateTodayStmt->fetchColumn();

$lowTodayStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE type = "low_attendance" AND DATE(created_at) = :today');
$lowTodayStmt->execute(['today' => today()]);
$todayLow = (int) $lowTodayStmt->fetchColumn();

$emailCount = (int) db()->query('SELECT COUNT(*) FROM notifications WHERE sent_via LIKE "%email%"')->fetchColumn();
$whatsappCount = (int) db()->query('SELECT COUNT(*) FROM notifications WHERE sent_via LIKE "%whatsapp%"')->fetchColumn();
$smsCount = (int) db()->query('SELECT COUNT(*) FROM notifications WHERE sent_via LIKE "%sms%" AND sent_via NOT LIKE "%whatsapp%"')->fetchColumn();

// ─── Channel status flags ─────────────────────────────────────

$smtpReady   = (bool) config('smtp_enabled', false) && config('smtp_username', '') !== '';
$twilioReady = (bool) config('twilio_enabled', false) && config('twilio_account_sid', '') !== '';

// ─── Notification history ─────────────────────────────────────

$notifications = db()->query(
    'SELECT n.id, n.type, n.message, n.recipient_email, n.sent_via, n.created_at, s.name, s.roll_no, s.class_name
     FROM notifications n
     INNER JOIN students s ON s.id = n.student_id
     ORDER BY n.created_at DESC
     LIMIT 100'
)->fetchAll();
?>


<!-- ── Channel Status + Generate Alerts ──────────────────────────── -->

<div class="grid cols-2">
    <section class="card">
        <h2>Generate Alerts</h2>
        <p class="muted">
            Scans today's data and creates notifications for <strong>absent</strong> students,
            <strong>late</strong> arrivals, and students with <strong>low overall attendance</strong>
            (below <?= e((string) config('min_attendance_pct', 75)) ?>%).
        </p>

        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
            <span class="channel-status <?= $smtpReady ? 'channel-on' : 'channel-off' ?>">
                📧 Email: <?= $smtpReady ? 'Enabled' : 'Disabled' ?>
            </span>
            <span class="channel-status <?= $twilioReady ? 'channel-on' : 'channel-off' ?>">
                <?= config('twilio_prefer', 'whatsapp') === 'whatsapp' ? '💬' : '📱' ?>
                <?= ucfirst(config('twilio_prefer', 'whatsapp')) ?>: <?= $twilioReady ? 'Enabled' : 'Disabled' ?>
            </span>
        </div>

        <?php if (!$smtpReady && !$twilioReady): ?>
            <div class="flash warning" style="margin-bottom:14px;">
                ⚠️ No delivery channels configured — notifications will be <strong>logged to system only</strong>.
                Configure SMTP or Twilio in <code>config/app.php</code>.
            </div>
        <?php endif; ?>

        <div class="notif-actions">
            <form method="post">
                <input type="hidden" name="action" value="generate_alerts">
                <button type="submit" style="width:100%;">🔔 Generate Today's Alerts</button>
            </form>
            <form method="post" onsubmit="return confirm('Delete ALL notification records?');">
                <input type="hidden" name="action" value="clear_all">
                <button type="submit" class="danger-btn" style="width:100%;">🗑️ Clear All Notifications</button>
            </form>
        </div>
    </section>

    <section class="card">
        <h2>Notification Summary</h2>
        <div class="notif-stats">
            <div class="notif-stat">
                <div class="stat"><?= e((string) $todayNotifications) ?></div>
                <div class="muted">Today</div>
            </div>
            <div class="notif-stat">
                <div class="stat"><?= e((string) $todayAbsent) ?></div>
                <div class="muted">Absent</div>
            </div>
            <div class="notif-stat">
                <div class="stat"><?= e((string) $todayLate) ?></div>
                <div class="muted">Late</div>
            </div>
            <div class="notif-stat">
                <div class="stat"><?= e((string) $todayLow) ?></div>
                <div class="muted">Low Attend.</div>
            </div>
            <div class="notif-stat">
                <div class="stat"><?= e((string) $emailCount) ?></div>
                <div class="muted">📧 Emails</div>
            </div>
            <div class="notif-stat">
                <div class="stat"><?= e((string) $whatsappCount) ?></div>
                <div class="muted">💬 WhatsApp</div>
            </div>
            <div class="notif-stat">
                <div class="stat"><?= e((string) $smsCount) ?></div>
                <div class="muted">📱 SMS</div>
            </div>
            <div class="notif-stat">
                <div class="stat"><?= e((string) $totalNotifications) ?></div>
                <div class="muted">All-Time</div>
            </div>
        </div>
    </section>
</div>

<!-- ── Test Delivery ─────────────────────────────────────────────── -->

<section class="card" style="margin-top:18px;">
    <h2>🧪 Test Delivery Channels</h2>
    <p class="muted">Send a test message to verify your Email / WhatsApp / SMS configuration before going live.</p>
    <form method="post" class="test-form">
        <input type="hidden" name="action" value="send_test">
        <label>
            Email address
            <input type="email" name="test_email" placeholder="parent@example.com">
        </label>
        <label>
            Phone (intl. format)
            <input type="text" name="test_phone" placeholder="+923001234567">
        </label>
        <button type="submit" class="secondary-btn">📤 Send Test</button>
    </form>
</section>

<!-- ── Notification History ──────────────────────────────────────── -->

<section class="card" style="margin-top: 18px;">
    <h2>Notification History</h2>
    <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>Date / Time</th>
                <th>Type</th>
                <th>Student</th>
                <th>Class</th>
                <th>Message</th>
                <th>Recipient</th>
                <th>Sent Via</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($notifications === []): ?>
                <tr><td colspan="7" class="muted">No notifications have been generated yet. Click "Generate Today's Alerts" above to start.</td></tr>
            <?php else: ?>
                <?php foreach ($notifications as $n): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= e($n['created_at']) ?></td>
                        <td>
                            <span class="badge type-<?= e($n['type']) ?>"><?= e(ucfirst(str_replace('_', ' ', $n['type']))) ?></span>
                        </td>
                        <td>
                            <strong><?= e($n['name']) ?></strong><br>
                            <span class="muted" style="font-size:.8rem;"><?= e($n['roll_no']) ?></span>
                        </td>
                        <td><?= e($n['class_name']) ?></td>
                        <td class="notif-msg"><?= e($n['message']) ?></td>
                        <td style="font-size:.85rem;"><?= $n['recipient_email'] ? e($n['recipient_email']) : '<span class="muted">—</span>' ?></td>
                        <td>
                            <?php
                            $viaParts = explode('+', $n['sent_via']);
                            foreach ($viaParts as $part):
                            ?>
                                <span class="badge via-<?= e(trim($part)) ?>"><?= e(ucfirst(trim($part))) ?></span>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

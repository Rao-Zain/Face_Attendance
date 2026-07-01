<?php

require_role(['admin', 'teacher']);

$pageTitle = 'Attendance Reports';

$editAttendanceId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editingRecord = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_attendance') {
            $attendanceId = (int) ($_POST['attendance_id'] ?? 0);
            $status = trim($_POST['status'] ?? 'present');
            $attendanceDate = trim($_POST['attendance_date'] ?? today());
            $attendanceTime = trim($_POST['attendance_time'] ?? now_time());
            $confidence = (float) ($_POST['confidence_score'] ?? 0);

            $stmt = db()->prepare(
                'UPDATE attendance
                 SET attendance_date = :attendance_date,
                     attendance_time = :attendance_time,
                     status = :status,
                     confidence_score = :confidence_score
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $attendanceId,
                'attendance_date' => $attendanceDate,
                'attendance_time' => $attendanceTime,
                'status' => in_array($status, ['present', 'late'], true) ? $status : 'present',
                'confidence_score' => max(0, min(1, $confidence)),
            ]);

            flash('success', 'Attendance record updated successfully.');
            redirect('index.php?page=reports');
        }

        if ($action === 'delete_attendance') {
            $attendanceId = (int) ($_POST['attendance_id'] ?? 0);
            $stmt = db()->prepare('DELETE FROM attendance WHERE id = :id');
            $stmt->execute(['id' => $attendanceId]);

            flash('success', 'Attendance record deleted successfully.');
            redirect('index.php?page=reports');
        }
    } catch (Throwable $exception) {
        flash('error', 'Attendance action failed: ' . $exception->getMessage());
        if ($action === 'update_attendance') {
            redirect('index.php?page=reports&edit=' . (int) ($_POST['attendance_id'] ?? 0));
        }
        redirect('index.php?page=reports');
    }
}

if ($editAttendanceId > 0) {
    $editStmt = db()->prepare(
        'SELECT a.id, a.attendance_date, a.attendance_time, a.status, a.confidence_score, s.name, s.roll_no
         FROM attendance a
         INNER JOIN students s ON s.id = a.student_id
         WHERE a.id = :id
         LIMIT 1'
    );
    $editStmt->execute(['id' => $editAttendanceId]);
    $editingRecord = $editStmt->fetch() ?: null;

    if ($editingRecord === null) {
        flash('warning', 'The selected attendance record could not be found.');
        redirect('index.php?page=reports');
    }
}

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? today();
$classFilter = trim($_GET['class'] ?? '');

$sql = 'SELECT a.id, a.attendance_date, a.attendance_time, a.status, a.marked_via, a.confidence_score, s.name, s.roll_no, s.class_name
        FROM attendance a
        INNER JOIN students s ON s.id = a.student_id
        WHERE a.attendance_date BETWEEN :date_from AND :date_to';
$params = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

if ($classFilter !== '') {
    $sql .= ' AND s.class_name = :class_name';
    $params['class_name'] = $classFilter;
}

$sql .= ' ORDER BY a.attendance_date DESC, a.attendance_time DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = [
    'total_marks' => count($rows),
    'unique_students' => count(array_unique(array_column($rows, 'roll_no'))),
    'avg_confidence' => $rows === []
        ? 0
        : array_sum(array_map(static fn(array $row): float => (float) $row['confidence_score'], $rows)) / count($rows),
];

$classes = db()->query('SELECT DISTINCT class_name FROM students ORDER BY class_name ASC')->fetchAll();

// Handle Excel/PDF exports
$action = $_GET['action'] ?? '';
if ($action === 'export_excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    // Add UTF-8 BOM for Excel compatibility
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Date', 'Time', 'Student Name', 'Roll No', 'Class', 'Status', 'Marked Via', 'Confidence']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['attendance_date'],
            $row['attendance_time'],
            $row['name'],
            $row['roll_no'],
            $row['class_name'],
            ucfirst($row['status']),
            ucfirst($row['marked_via'] ?? 'face'),
            number_format((float) $row['confidence_score'] * 100, 2) . '%'
        ]);
    }
    fclose($output);
    exit;
}

if ($action === 'print_pdf') {
    $skipLayout = true;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Attendance Report - <?= e(config('app_name')) ?></title>
        <style>
            body {
                font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
                color: #1e293b;
                background: #ffffff;
                margin: 0;
                padding: 40px;
                line-height: 1.5;
            }
            .header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 2px solid #0f766e;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .header h1 {
                margin: 0;
                color: #0f766e;
                font-size: 1.85rem;
                font-weight: 800;
            }
            .header-meta {
                text-align: right;
                font-size: 0.9rem;
                color: #64748b;
            }
            .report-title {
                font-size: 1.4rem;
                font-weight: 700;
                color: #0f766e;
                margin-bottom: 20px;
            }
            .meta-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 30px;
            }
            .meta-card {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 15px;
            }
            .meta-label {
                font-size: 0.75rem;
                font-weight: 700;
                color: #64748b;
                text-transform: uppercase;
                margin-bottom: 4px;
            }
            .meta-value {
                font-size: 1.25rem;
                font-weight: 800;
                color: #0f766e;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th {
                background-color: #f8fafc;
                border-bottom: 2px solid #cbd5e1;
                color: #475569;
                font-weight: 700;
                font-size: 0.8rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                padding: 12px 14px;
                text-align: left;
            }
            td {
                padding: 12px 14px;
                border-bottom: 1px solid #e2e8f0;
                font-size: 0.875rem;
            }
            tr {
                page-break-inside: avoid;
            }
            .badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 9999px;
                font-size: 0.75rem;
                font-weight: 700;
                background-color: #f1f5f9;
                color: #475569;
                border: 1px solid #e2e8f0;
            }
            .badge-success { background-color: #ecfdf5; color: #047857; border-color: #a7f3d0; }
            .badge-warning { background-color: #fffbef; color: #b45309; border-color: #fde68a; }
            .no-print-bar {
                background: #f1f5f9;
                padding: 12px 40px;
                margin: -40px -40px 40px -40px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #cbd5e1;
            }
            @media print {
                .no-print-bar {
                    display: none;
                }
                body {
                    padding: 0;
                }
            }
        </style>
    </head>
    <body>
        <div class="no-print-bar">
            <span>📄 Report Ready for Printing / PDF Export</span>
            <div style="display: flex; gap: 8px;">
                <button onclick="window.print()" style="background:#0f766e; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:600; cursor:pointer;">Print / Save as PDF</button>
                <button onclick="window.close()" style="background:#fff; border:1px solid #cbd5e1; padding:8px 16px; border-radius:6px; font-weight:600; cursor:pointer;">Close</button>
            </div>
        </div>
        <div class="header">
            <div>
                <h1><?= e(config('app_name')) ?></h1>
                <div style="font-size:0.9rem; color:#64748b; margin-top:4px;">Automated Attendance System</div>
            </div>
            <div class="header-meta">
                <div>Generated: <?= date('d M Y, h:i A') ?></div>
                <div>Teacher Report</div>
            </div>
        </div>
        
        <div class="report-title">
            Attendance Report
            <span style="font-size:1rem; font-weight:400; color:#64748b; margin-left:10px;">
                (<?= e($dateFrom) ?> to <?= e($dateTo) ?>)
            </span>
        </div>

        <div class="meta-grid">
            <div class="meta-card">
                <div class="meta-label">Selected Class</div>
                <div class="meta-value"><?= $classFilter !== '' ? e($classFilter) : 'All Classes' ?></div>
            </div>
            <div class="meta-card">
                <div class="meta-label">Total Attendance Marks</div>
                <div class="meta-value"><?= e((string) $summary['total_marks']) ?></div>
            </div>
            <div class="meta-card">
                <div class="meta-label">Unique Students</div>
                <div class="meta-value"><?= e((string) $summary['unique_students']) ?></div>
            </div>
            <div class="meta-card">
                <div class="meta-label">Avg. Confidence</div>
                <div class="meta-value"><?= e(number_format($summary['avg_confidence'] * 100, 2)) ?>%</div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Student Name</th>
                    <th>Roll No</th>
                    <th>Class</th>
                    <th>Status</th>
                    <th>Method</th>
                    <th>Confidence</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" style="text-align:center; color:#64748b;">No records match the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['attendance_date']) ?></td>
                            <td><?= e($row['attendance_time']) ?></td>
                            <td><strong><?= e($row['name']) ?></strong></td>
                            <td><?= e($row['roll_no']) ?></td>
                            <td><?= e($row['class_name']) ?></td>
                            <td>
                                <?php if ($row['status'] === 'present'): ?>
                                    <span class="badge badge-success">Present</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Late</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?= e(ucfirst($row['marked_via'] ?? 'face')) ?></span></td>
                            <td><?= e(number_format((float) $row['confidence_score'] * 100, 2)) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 300);
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}

$classes = db()->query('SELECT DISTINCT class_name FROM students ORDER BY class_name ASC')->fetchAll();
?>
<div class="grid cols-2">
    <section class="card">
        <h2>Filters</h2>
        <form method="get" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
            <input type="hidden" name="page" value="reports">
            <label>
                From
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
            </label>
            <label>
                To
                <input type="date" name="date_to" value="<?= e($dateTo) ?>">
            </label>
            <label>
                Class
                <select name="class">
                    <option value="">All classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= e($class['class_name']) ?>" <?= $classFilter === $class['class_name'] ? 'selected' : '' ?>>
                            <?= e($class['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Apply Filters</button>
        </form>
    </section>

    <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <h2><?= $editingRecord ? 'Edit Attendance Record' : 'Attendance Actions' ?></h2>
            <?php if ($editingRecord): ?>
                <a class="ghost-btn" href="index.php?page=reports">Cancel Edit</a>
            <?php endif; ?>
        </div>
        <?php if ($editingRecord): ?>
            <form method="post">
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="attendance_id" value="<?= e((string) $editingRecord['id']) ?>">
                <label>
                    Student
                    <input type="text" value="<?= e($editingRecord['name'] . ' (' . $editingRecord['roll_no'] . ')') ?>" disabled>
                </label>
                <label>
                    Attendance Date
                    <input type="date" name="attendance_date" value="<?= e($editingRecord['attendance_date']) ?>" required>
                </label>
                <label>
                    Attendance Time
                    <input type="time" step="1" name="attendance_time" value="<?= e($editingRecord['attendance_time']) ?>" required>
                </label>
                <label>
                    Status
                    <select name="status">
                        <option value="present" <?= $editingRecord['status'] === 'present' ? 'selected' : '' ?>>Present</option>
                        <option value="late" <?= $editingRecord['status'] === 'late' ? 'selected' : '' ?>>Late</option>
                    </select>
                </label>
                <label>
                    Confidence Score
                    <input type="number" name="confidence_score" min="0" max="1" step="0.01" value="<?= e((string) $editingRecord['confidence_score']) ?>" required>
                </label>
                <button type="submit">Update Attendance</button>
            </form>
        <?php else: ?>
            <ul>
                <li>Edit attendance date, time, status, and confidence.</li>
                <li>Delete incorrect attendance rows without touching the database manually.</li>
                <li>Use the action buttons in the report table below.</li>
            </ul>
        <?php endif; ?>
    </section>
</div>

<div class="grid cols-3" style="margin-top: 18px;">
    <section class="card">
        <div class="muted">Attendance Marks</div>
        <div class="stat"><?= e((string) $summary['total_marks']) ?></div>
    </section>
    <section class="card">
        <div class="muted">Unique Students</div>
        <div class="stat"><?= e((string) $summary['unique_students']) ?></div>
    </section>
    <section class="card">
        <div class="muted">Average Confidence</div>
        <div class="stat"><?= e(number_format($summary['avg_confidence'] * 100, 2)) ?>%</div>
    </section>
</div>

<section class="card" style="margin-top: 18px;">
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
        <h2 style="margin: 0;">Filtered Attendance Records</h2>
        <div style="display: flex; gap: 8px;">
            <a class="btn secondary-btn" style="text-decoration: none;" href="index.php?page=reports&action=export_excel&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&class=<?= urlencode($classFilter) ?>">
                📊 Export to Excel
            </a>
            <a class="btn secondary-btn" style="text-decoration: none;" href="index.php?page=reports&action=print_pdf&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&class=<?= urlencode($classFilter) ?>" target="_blank">
                📄 Export to PDF
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Time</th>
                <th>Name</th>
                <th>Roll No</th>
                <th>Class</th>
                <th>Status</th>
                <th>Method</th>
                <th>Confidence</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="muted">No records match the selected filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['attendance_date']) ?></td>
                        <td><?= e($row['attendance_time']) ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['roll_no']) ?></td>
                        <td><?= e($row['class_name']) ?></td>
                        <td><?= e(ucfirst($row['status'])) ?></td>
                        <td><span class="badge"><?= e(ucfirst($row['marked_via'] ?? 'face')) ?></span></td>
                        <td><?= e(number_format((float) $row['confidence_score'] * 100, 2)) ?>%</td>
                        <td>
                            <div class="inline-actions">
                                <a class="ghost-btn" href="index.php?page=reports&edit=<?= e((string) $row['id']) ?>">Edit</a>
                                <form method="post" onsubmit="return confirm('Delete this attendance record?');">
                                    <input type="hidden" name="action" value="delete_attendance">
                                    <input type="hidden" name="attendance_id" value="<?= e((string) $row['id']) ?>">
                                    <button type="submit" class="danger-btn">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

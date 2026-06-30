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
    <h2>Filtered Attendance Records</h2>
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

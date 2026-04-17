<?php

require_auth();

$pageTitle = 'Dashboard';

// ─── Basic stats ──────────────────────────────────────────────

$stats = [
    'students' => (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'encodings' => (int) db()->query('SELECT COUNT(*) FROM face_encodings')->fetchColumn(),
];

$stmtToday = db()->prepare('SELECT COUNT(*) FROM attendance WHERE attendance_date = :today');
$stmtToday->execute(['today' => today()]);
$stats['today_attendance'] = (int) $stmtToday->fetchColumn();

$stmtUnique = db()->prepare('SELECT COUNT(DISTINCT student_id) FROM attendance WHERE attendance_date = :today');
$stmtUnique->execute(['today' => today()]);
$stats['unique_today'] = (int) $stmtUnique->fetchColumn();

$stats['rate_today'] = $stats['students'] > 0
    ? round(($stats['unique_today'] / $stats['students']) * 100, 1)
    : 0;

// ─── 30-day trend ─────────────────────────────────────────────

$dailyTrend = db()->query(
    'SELECT attendance_date, COUNT(*) AS total
     FROM attendance
     WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY attendance_date
     ORDER BY attendance_date ASC'
)->fetchAll();

// ─── Day-of-week distribution ─────────────────────────────────

$dowRaw = db()->query(
    'SELECT DAYOFWEEK(attendance_date) AS dow, COUNT(*) AS total
     FROM attendance
     WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DAYOFWEEK(attendance_date)
     ORDER BY dow ASC'
)->fetchAll();

$dowLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$dowData = array_fill(0, 7, 0);
foreach ($dowRaw as $row) {
    $dowData[((int) $row['dow']) - 1] = (int) $row['total'];
}

// ─── Class-wise comparison ────────────────────────────────────

$totalWorkingDays = max(1, (int) db()->query(
    'SELECT COUNT(DISTINCT attendance_date) FROM attendance WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
)->fetchColumn());

$classComparison = db()->query(
    'SELECT s.class_name,
            COUNT(DISTINCT s.id) AS total_students,
            COUNT(a.id) AS total_marks
     FROM students s
     LEFT JOIN attendance a ON a.student_id = s.id
         AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY s.class_name
     ORDER BY s.class_name ASC'
)->fetchAll();

foreach ($classComparison as &$row) {
    $maxPossible = (int) $row['total_students'] * $totalWorkingDays;
    $row['attendance_pct'] = $maxPossible > 0 ? round(((int) $row['total_marks'] / $maxPossible) * 100, 1) : 0;
}
unset($row);

// ─── Per-student attendance (last 30 days) ────────────────────

$perStudent = db()->query(
    'SELECT s.id, s.name, s.roll_no, s.class_name,
            COUNT(a.id) AS days_present
     FROM students s
     LEFT JOIN attendance a ON a.student_id = s.id
         AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY s.id, s.name, s.roll_no, s.class_name
     ORDER BY days_present DESC, s.name ASC'
)->fetchAll();

$minPct = (float) config('min_attendance_pct', 75);

// ─── Recent attendance ────────────────────────────────────────

$recentAttendance = db()->query(
    'SELECT a.attendance_date, a.attendance_time, a.confidence_score, a.status, a.marked_via, s.name, s.roll_no
     FROM attendance a
     INNER JOIN students s ON s.id = a.student_id
     ORDER BY a.id DESC
     LIMIT 10'
)->fetchAll();
?>

<style>
    .stat-card-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 18px; }
    .stat-card { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 20px; box-shadow: 0 10px 30px rgba(15,23,42,.05); }
    .stat-card .muted { margin-bottom: 2px; }
    .stat-card .stat { font-size: 2rem; font-weight: 700; margin: 0; }
    .stat-card .stat-sub { font-size: .85rem; color: var(--muted); }
    .chart-row { display: grid; grid-template-columns: 1.5fr 1fr; gap: 16px; margin-bottom: 18px; }
    .chart-box { background: var(--card); border: 1px solid var(--line); border-radius: 18px; padding: 20px; box-shadow: 0 10px 30px rgba(15,23,42,.05); }
    .chart-box h2 { margin-top: 0; font-size: 1.1rem; }
    .chart-canvas-wrap { position: relative; width: 100%; max-height: 320px; }
    .student-row { display: grid; grid-template-columns: 2fr 1fr 1fr 3fr 80px; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: .92rem; }
    .student-row:last-child { border-bottom: none; }
    .student-row strong { font-size: .95rem; }
    .pct-meter { height: 10px; border-radius: 999px; background: #e5e7eb; overflow: hidden; }
    .pct-meter > span { display: block; height: 100%; border-radius: 999px; transition: width .6s ease; }
    .pct-green { background: linear-gradient(90deg, #14b8a6, #22c55e); }
    .pct-yellow { background: linear-gradient(90deg, #f59e0b, #eab308); }
    .pct-red { background: linear-gradient(90deg, #ef4444, #f97316); }
    .rate-badge { padding: 4px 10px; border-radius: 999px; font-size: .82rem; font-weight: 700; }
    .rate-good { background: #dcfce7; color: #166534; }
    .rate-warn { background: #fef3c7; color: #92400e; }
    .rate-danger { background: #fee2e2; color: #b91c1c; }
    .method-face { background: #dbeafe; color: #1e40af; }
    .method-qr { background: #fae8ff; color: #86198f; }
    @media (max-width: 900px) { .chart-row { grid-template-columns: 1fr; } .student-row { grid-template-columns: 1fr 1fr; } }
</style>

<!-- ─── Stat cards ──────────────────────────────────────────── -->
<div class="stat-card-row">
    <div class="stat-card">
        <div class="muted">Students</div>
        <div class="stat"><?= e((string) $stats['students']) ?></div>
    </div>
    <div class="stat-card">
        <div class="muted">Face Encodings</div>
        <div class="stat"><?= e((string) $stats['encodings']) ?></div>
    </div>
    <div class="stat-card">
        <div class="muted">Present Today</div>
        <div class="stat"><?= e((string) $stats['unique_today']) ?></div>
        <div class="stat-sub">of <?= e((string) $stats['students']) ?> students</div>
    </div>
    <div class="stat-card">
        <div class="muted">Today's Rate</div>
        <div class="stat"><?= e((string) $stats['rate_today']) ?>%</div>
        <div class="stat-sub"><?= $stats['rate_today'] >= $minPct ? '✅ Above threshold' : '⚠️ Below ' . $minPct . '%' ?></div>
    </div>
</div>

<!-- ─── Trend + Day-of-week charts ─────────────────────────── -->
<div class="chart-row">
    <div class="chart-box">
        <h2>30-Day Attendance Trend</h2>
        <?php if ($dailyTrend === []): ?>
            <p class="muted">No attendance data yet.</p>
        <?php else: ?>
            <div class="chart-canvas-wrap"><canvas id="trendChart"></canvas></div>
        <?php endif; ?>
    </div>
    <div class="chart-box">
        <h2>Day-of-Week Distribution</h2>
        <div class="chart-canvas-wrap"><canvas id="dowChart"></canvas></div>
    </div>
</div>

<!-- ─── Class comparison + Quick actions ───────────────────── -->
<div class="chart-row">
    <div class="chart-box">
        <h2>Class-wise Attendance (30 days)</h2>
        <?php if ($classComparison === []): ?>
            <p class="muted">No classes registered.</p>
        <?php else: ?>
            <div class="chart-canvas-wrap"><canvas id="classChart"></canvas></div>
        <?php endif; ?>
    </div>
    <div class="chart-box">
        <h2>Quick Actions</h2>
        <div class="grid">
            <a class="secondary-btn" style="text-decoration:none; text-align:center; padding:12px;" href="index.php?page=students">Register Student</a>
            <a class="secondary-btn" style="text-decoration:none; text-align:center; padding:12px;" href="index.php?page=attendance">Mark Attendance</a>
            <a class="secondary-btn" style="text-decoration:none; text-align:center; padding:12px;" href="index.php?page=reports">View Reports</a>
            <a class="secondary-btn" style="text-decoration:none; text-align:center; padding:12px;" href="index.php?page=notifications">Send Notifications</a>
        </div>
        <p class="muted" style="margin-top:14px;">
            Late threshold: <strong><?= e(config('late_threshold', '09:15:00')) ?></strong> &bull;
            Min attendance: <strong><?= e((string) $minPct) ?>%</strong>
        </p>
    </div>
</div>

<!-- ─── Per-student attendance rankings ────────────────────── -->
<section class="chart-box">
    <h2>Student Attendance Rankings (Last 30 Days)</h2>
    <?php if ($perStudent === []): ?>
        <p class="muted">No students found.</p>
    <?php else: ?>
        <div class="student-row" style="font-weight:700; border-bottom:2px solid var(--line);">
            <span>Student</span>
            <span>Class</span>
            <span>Present</span>
            <span>Attendance</span>
            <span>Rate</span>
        </div>
        <?php foreach ($perStudent as $ps): ?>
            <?php
            $pct = $totalWorkingDays > 0 ? round(((int) $ps['days_present'] / $totalWorkingDays) * 100, 1) : 0;
            $colorClass = $pct >= 90 ? 'pct-green' : ($pct >= $minPct ? 'pct-yellow' : 'pct-red');
            $rateClass = $pct >= 90 ? 'rate-good' : ($pct >= $minPct ? 'rate-warn' : 'rate-danger');
            ?>
            <div class="student-row">
                <div>
                    <strong><?= e($ps['name']) ?></strong>
                    <div class="muted" style="font-size:.8rem;"><?= e($ps['roll_no']) ?></div>
                </div>
                <span><?= e($ps['class_name']) ?></span>
                <span><?= e((string) $ps['days_present']) ?> / <?= e((string) $totalWorkingDays) ?></span>
                <div class="pct-meter"><span class="<?= $colorClass ?>" style="width:<?= $pct ?>%;"></span></div>
                <span class="rate-badge <?= $rateClass ?>"><?= e(number_format($pct, 1)) ?>%</span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<!-- ─── Recent attendance ──────────────────────────────────── -->
<section class="chart-box" style="margin-top:18px;">
    <h2>Recent Attendance</h2>
    <table>
        <thead>
        <tr>
            <th>Student</th>
            <th>Roll No</th>
            <th>Date</th>
            <th>Time</th>
            <th>Status</th>
            <th>Method</th>
            <th>Confidence</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($recentAttendance === []): ?>
            <tr><td colspan="7" class="muted">No attendance records yet.</td></tr>
        <?php else: ?>
            <?php foreach ($recentAttendance as $row): ?>
                <tr>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e($row['roll_no']) ?></td>
                    <td><?= e($row['attendance_date']) ?></td>
                    <td><?= e($row['attendance_time']) ?></td>
                    <td><span class="badge"><?= e(ucfirst($row['status'])) ?></span></td>
                    <td><span class="badge method-<?= e($row['marked_via'] ?? 'face') ?>"><?= e(ucfirst($row['marked_via'] ?? 'face')) ?></span></td>
                    <td><?= e(number_format((float) $row['confidence_score'] * 100, 2)) ?>%</td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<!-- ─── Chart.js ───────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
    const COLORS = {
        teal:    'rgba(20, 184, 166, 1)',
        tealBg:  'rgba(20, 184, 166, .15)',
        blue:    'rgba(14, 165, 233, 1)',
        blueBg:  'rgba(14, 165, 233, .15)',
        purple:  'rgba(139, 92, 246, 1)',
        amber:   'rgba(245, 158, 11, 1)',
        red:     'rgba(239, 68, 68, 1)',
        slate:   'rgba(100, 116, 139, .6)',
    };

    const commonOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
    };

    // ── 30-day trend line chart ───────────────
    const trendEl = document.getElementById('trendChart');
    if (trendEl) {
        const trendData = <?= json_encode($dailyTrend) ?>;
        new Chart(trendEl, {
            type: 'line',
            data: {
                labels: trendData.map(r => r.attendance_date.slice(5)),
                datasets: [{
                    label: 'Attendance',
                    data: trendData.map(r => parseInt(r.total)),
                    borderColor: COLORS.teal,
                    backgroundColor: COLORS.tealBg,
                    fill: true,
                    tension: .35,
                    pointRadius: 3,
                    pointHoverRadius: 6,
                    borderWidth: 2.5,
                }]
            },
            options: {
                ...commonOpts,
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,.05)' } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    // ── Day-of-week doughnut ──────────────────
    const dowEl = document.getElementById('dowChart');
    if (dowEl) {
        const dowLabels = <?= json_encode($dowLabels) ?>;
        const dowValues = <?= json_encode($dowData) ?>;
        const dowColors = [COLORS.red, COLORS.teal, COLORS.blue, COLORS.purple, COLORS.amber, 'rgba(34,197,94,1)', COLORS.slate];
        new Chart(dowEl, {
            type: 'doughnut',
            data: {
                labels: dowLabels,
                datasets: [{ data: dowValues, backgroundColor: dowColors, borderWidth: 2, borderColor: '#fff' }]
            },
            options: {
                ...commonOpts,
                plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 14, padding: 10, font: { size: 12 } } } },
                cutout: '55%',
            },
        });
    }

    // ── Class comparison bar chart ────────────
    const classEl = document.getElementById('classChart');
    if (classEl) {
        const classData = <?= json_encode($classComparison) ?>;
        const classColors = classData.map(c => parseFloat(c.attendance_pct) >= <?= $minPct ?> ? COLORS.teal : COLORS.red);
        new Chart(classEl, {
            type: 'bar',
            data: {
                labels: classData.map(c => c.class_name),
                datasets: [{
                    label: 'Attendance %',
                    data: classData.map(c => parseFloat(c.attendance_pct)),
                    backgroundColor: classColors,
                    borderRadius: 8,
                    barThickness: 36,
                }]
            },
            options: {
                ...commonOpts,
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' }, grid: { color: 'rgba(0,0,0,.05)' } },
                    y: { grid: { display: false } },
                },
            },
        });
    }
})();
</script>

<?php

require_role(['admin', 'teacher']);

$pageTitle = 'Attendance';

function attendance_json_response(array $data): never
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function attendance_resolve_image_path(): array
{
    $tempPath = null;
    $image = $_FILES['image'] ?? null;

    if ($image !== null && $image['error'] === UPLOAD_ERR_OK && is_uploaded_file($image['tmp_name'])) {
        return [$image['tmp_name'], $tempPath];
    }

    $webcamImage = trim($_POST['webcam_image'] ?? '');

    if ($webcamImage === '' || !str_starts_with($webcamImage, 'data:image/')) {
        throw new RuntimeException('Please provide a valid face image from the webcam or file upload.');
    }

    [$meta, $content] = explode(',', $webcamImage, 2) + [null, null];

    if ($content === null) {
        throw new RuntimeException('Webcam image payload is invalid.');
    }

    $binary = base64_decode($content, true);

    if ($binary === false) {
        throw new RuntimeException('Webcam image could not be decoded.');
    }

    $tempDir = dirname(__DIR__) . '/uploads/attendance_tmp';

    if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        throw new RuntimeException('Unable to create a temporary folder for webcam images.');
    }

    $tempPath = tempnam($tempDir, 'attendance_');

    if ($tempPath === false || file_put_contents($tempPath, $binary) === false) {
        throw new RuntimeException('Unable to prepare webcam image for recognition.');
    }

    return [$tempPath, $tempPath];
}

function determine_attendance_status(): string
{
    $lateThreshold = config('late_threshold', '09:15:00');
    return now_time() > $lateThreshold ? 'late' : 'present';
}

// ─── POST handlers ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';
    $tempFileToDelete = null;

    try {
        // ── QR Code scan ──────────────────────────────────────
        if ($mode === 'qr_scan') {
            $qrToken = trim($_POST['qr_token'] ?? '');

            if ($qrToken === '') {
                attendance_json_response(['ok' => false, 'type' => 'error', 'message' => 'No QR code data received.']);
            }

            if (str_starts_with($qrToken, 'FACETRACK:')) {
                $qrToken = substr($qrToken, 10);
            }

            $stmt = db()->prepare('SELECT id, name, roll_no FROM students WHERE qr_token = :token LIMIT 1');
            $stmt->execute(['token' => $qrToken]);
            $student = $stmt->fetch();

            if (!$student) {
                attendance_json_response(['ok' => false, 'type' => 'warning', 'message' => 'Invalid QR code. No student found.']);
            }

            $dup = db()->prepare('SELECT id FROM attendance WHERE student_id = :sid AND attendance_date = :date LIMIT 1');
            $dup->execute(['sid' => $student['id'], 'date' => today()]);

            if ($dup->fetch()) {
                attendance_json_response([
                    'ok' => false,
                    'type' => 'warning',
                    'message' => sprintf('%s (%s) is already marked present today.', $student['name'], $student['roll_no']),
                    'student_name' => $student['name'],
                    'already_marked' => true,
                ]);
            }

            $status = determine_attendance_status();

            $ins = db()->prepare(
                'INSERT INTO attendance (student_id, attendance_date, attendance_time, status, marked_via, confidence_score)
                 VALUES (:sid, :date, :time, :status, :via, :conf)'
            );
            $ins->execute([
                'sid' => $student['id'],
                'date' => today(),
                'time' => now_time(),
                'status' => $status,
                'via' => 'qr',
                'conf' => 1.0000,
            ]);

            attendance_json_response([
                'ok' => true,
                'type' => 'success',
                'message' => sprintf('Attendance marked for %s (%s) via QR code. Status: %s.', $student['name'], $student['roll_no'], ucfirst($status)),
                'results' => [[
                    'student_name' => $student['name'],
                    'roll_no' => $student['roll_no'],
                    'confidence' => 1.0,
                    'status' => $status,
                    'message' => 'Marked via QR',
                    'voice_message' => db()->query('SELECT voice_message FROM students WHERE id = ' . (int)$student['id'])->fetchColumn() ?: null,
                    'gender' => db()->query('SELECT gender FROM students WHERE id = ' . (int)$student['id'])->fetchColumn() ?: 'male',
                ]],
            ]);
        }

        // ── Batch face recognition ────────────────────────────
        if ($mode === 'live_scan') {
            [$imagePath, $tempFileToDelete] = attendance_resolve_image_path();

            $result = face_api_request('/recognize_batch', [], [
                'image' => $imagePath,
            ]);

            $matches = $result['matches'] ?? [];
            $facesDetected = $result['faces_detected'] ?? 0;

            if ($facesDetected === 0) {
                attendance_json_response([
                    'ok' => false,
                    'type' => 'warning',
                    'message' => $result['message'] ?? 'No faces detected in the captured image.',
                    'results' => [],
                ]);
            }

            if (empty($matches)) {
                attendance_json_response([
                    'ok' => false,
                    'type' => 'warning',
                    'message' => sprintf('%d face(s) detected but none matched registered students.', $facesDetected),
                    'results' => [],
                ]);
            }

            $attendanceResults = [];
            $successCount = 0;
            $status = determine_attendance_status();

            foreach ($matches as $match) {
                $studentId = (int) $match['student_id'];
                $confidence = (float) ($match['confidence'] ?? 0);
                $studentName = $match['student_name'] ?? 'Unknown';
                $rollNo = $match['roll_no'] ?? '';

                if ($confidence < (float) config('face_match_threshold', 0.55)) {
                    $attendanceResults[] = [
                        'student_name' => $studentName,
                        'roll_no' => $rollNo,
                        'confidence' => $confidence,
                        'status' => 'low_confidence',
                        'message' => 'Confidence too low',
                    ];
                    continue;
                }

                $dup = db()->prepare('SELECT id FROM attendance WHERE student_id = :sid AND attendance_date = :date LIMIT 1');
                $dup->execute(['sid' => $studentId, 'date' => today()]);

                if ($dup->fetch()) {
                    $attendanceResults[] = [
                        'student_name' => $studentName,
                        'roll_no' => $rollNo,
                        'confidence' => $confidence,
                        'status' => 'already_marked',
                        'message' => 'Already marked today',
                    ];
                    continue;
                }

                $ins = db()->prepare(
                    'INSERT INTO attendance (student_id, attendance_date, attendance_time, status, marked_via, confidence_score)
                     VALUES (:sid, :date, :time, :status, :via, :conf)'
                );
                $ins->execute([
                    'sid' => $studentId,
                    'date' => today(),
                    'time' => now_time(),
                    'status' => $status,
                    'via' => 'face',
                    'conf' => $confidence,
                ]);

                $successCount++;
                $attendanceResults[] = [
                    'student_name' => $studentName,
                    'roll_no' => $rollNo,
                    'confidence' => $confidence,
                    'status' => $status,
                    'message' => 'Attendance marked',
                    'voice_message' => db()->query('SELECT voice_message FROM students WHERE id = ' . (int)$studentId)->fetchColumn() ?: null,
                    'gender' => db()->query('SELECT gender FROM students WHERE id = ' . (int)$studentId)->fetchColumn() ?: 'male',
                ];
            }

            attendance_json_response([
                'ok' => $successCount > 0,
                'type' => $successCount > 0 ? 'success' : 'warning',
                'fail_audio_trigger' => ($successCount === 0 && !empty($matches)),
                'message' => sprintf(
                    '%d of %d recognized student(s) marked. %d face(s) detected in frame.',
                    $successCount, count($matches), $facesDetected
                ),
                'results' => $attendanceResults,
                'faces_detected' => $facesDetected,
                'matched_count' => count($matches),
                'marked_count' => $successCount,
            ]);
        }

        flash('error', 'Unknown attendance mode.');
        redirect('index.php?page=attendance');
    } catch (Throwable $exception) {
        attendance_json_response(['ok' => false, 'type' => 'error', 'message' => 'Attendance failed: ' . $exception->getMessage()]);
    } finally {
        if ($tempFileToDelete !== null && is_file($tempFileToDelete)) {
            unlink($tempFileToDelete);
        }
    }
}

// ─── Today's records ──────────────────────────────────────────

$todayAttendance = db()->prepare(
    'SELECT a.attendance_time, a.confidence_score, a.status, a.marked_via, s.name, s.roll_no, s.class_name
     FROM attendance a
     INNER JOIN students s ON s.id = a.student_id
     WHERE a.attendance_date = :today
     ORDER BY a.attendance_time DESC'
);
$todayAttendance->execute(['today' => today()]);
$records = $todayAttendance->fetchAll();
?>




<div class="scan-tabs">
    <button class="scan-tab-btn active" data-tab="faceTab" id="faceTabBtn">🎥 Face Recognition</button>
    <button class="scan-tab-btn" data-tab="qrTab" id="qrTabBtn">📱 QR Code Scanner</button>
</div>

<!-- ════════════════════════════════════════════════════════
     TAB 1 — FACE RECOGNITION (BATCH)
     ════════════════════════════════════════════════════════ -->
<div class="scan-tab-panel active" id="faceTab">
    <section class="card">
        <h2>Live Webcam — Classroom Scan</h2>
        <div class="camera-grid">
            <div>
                <div class="camera-stage">
                    <video id="attendanceVideo" autoplay playsinline muted></video>
                    <div class="camera-overlay"></div>
                </div>
                <canvas id="attendanceCanvas" style="display:none;"></canvas>
            </div>
            <div class="grid">
                <div class="live-pill">Multi-Face Scanner</div>
                <div id="cameraStatus" class="camera-status">
                    Start the webcam, then use auto-scan or capture manually. Multiple students can be recognized in a single frame.
                </div>
                <div class="camera-actions">
                    <button type="button" id="startCameraBtn">Start Camera</button>
                    <button type="button" id="stopCameraBtn" class="secondary-btn">Stop Camera</button>
                    <button type="button" id="autoScanBtn">Start Auto Scan</button>
                    <button type="button" id="captureBtn" class="secondary-btn">Capture & Mark</button>
                </div>
                <div class="muted">Auto scan captures a frame every 3 seconds. Unlike single mode, <strong>multiple students</strong> are recognized per frame.</div>
            </div>
        </div>

        <div id="batchResults" class="batch-results"></div>

        <form id="webcamAttendanceForm" method="post">
            <input type="hidden" name="mode" value="live_scan">
            <input type="hidden" name="webcam_image" id="webcamImageInput">
        </form>
    </section>
</div>

<!-- ════════════════════════════════════════════════════════
     TAB 2 — QR CODE SCANNER
     ════════════════════════════════════════════════════════ -->
<div class="scan-tab-panel" id="qrTab">
    <section class="card">
        <h2>QR Code Attendance</h2>
        <p class="muted">When face recognition is unavailable (low light, masks, etc.), students can scan their personal QR code to mark attendance.</p>
        <div class="qr-scanner-wrapper">
            <div id="qr-reader"></div>
        </div>
        <div style="margin-top: 14px;">
            <div id="qrStatus" class="camera-status">Click the camera icon above to start scanning QR codes.</div>
        </div>
        <div id="qrBatchResults" class="batch-results"></div>
    </section>
</div>

<!-- ════════════════════════════════════════════════════════
     TODAY'S TABLE
     ════════════════════════════════════════════════════════ -->
<section class="card" style="margin-top: 18px;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Today's Attendance</h2>
        <span class="badge"><?= e((string) count($records)) ?> marked</span>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Roll No</th>
                <th>Class</th>
                <th>Time</th>
                <th>Status</th>
                <th>Method</th>
                <th>Confidence</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($records === []): ?>
                <tr><td colspan="7" class="muted">No attendance has been marked today.</td></tr>
            <?php else: ?>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?= e($record['name']) ?></td>
                        <td><?= e($record['roll_no']) ?></td>
                        <td><?= e($record['class_name']) ?></td>
                        <td><?= e($record['attendance_time']) ?></td>
                        <td><span class="badge"><?= e(ucfirst($record['status'])) ?></span></td>
                        <td><span class="badge method-<?= e($record['marked_via'] ?? 'face') ?>"><?= e(ucfirst($record['marked_via'] ?? 'face')) ?></span></td>
                        <td><?= e(number_format((float) $record['confidence_score'] * 100, 2)) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<!-- Fullscreen Welcome Overlay -->
<div id="welcomeOverlay" class="welcome-overlay">
    <div class="welcome-card">
        <div id="welcomeAvatar" class="welcome-avatar-box">👤</div>
        <h1 id="welcomeName">Student Name</h1>
        <div id="welcomeMeta" class="roll-pill">Roll Number | Class</div>
        
        <div class="success-badge">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
            </svg>
            <span>ATTENDANCE MARKED</span>
        </div>
    </div>
</div>

<!-- QR Scanner Library -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
(() => {
    // ════════════════════════════════════════════
    // TAB SWITCHING
    // ════════════════════════════════════════════
    const tabBtns = document.querySelectorAll('.scan-tab-btn');
    const tabPanels = document.querySelectorAll('.scan-tab-panel');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', async () => {
            const nowTab = btn.dataset.tab;

            // Stop relevant hardware before switching
            if (nowTab === 'qrTab') {
                stopCamera();
            } else {
                await stopQrScanner();
            }

            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanels.forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(nowTab).classList.add('active');

            // Auto-start QR if selected
            if (nowTab === 'qrTab') {
                setTimeout(initQrScanner, 300);
            }
        });
    });

    // ════════════════════════════════════════════
    // FACE RECOGNITION — BATCH SCANNER
    // ════════════════════════════════════════════
    const video = document.getElementById('attendanceVideo');
    const canvas = document.getElementById('attendanceCanvas');
    const statusBox = document.getElementById('cameraStatus');
    const webcamInput = document.getElementById('webcamImageInput');
    const form = document.getElementById('webcamAttendanceForm');
    const batchBox = document.getElementById('batchResults');
    const startBtn = document.getElementById('startCameraBtn');
    const stopBtn = document.getElementById('stopCameraBtn');
    const autoScanBtn = document.getElementById('autoScanBtn');
    const captureBtn = document.getElementById('captureBtn');

    let stream = null;
    let autoScanTimer = null;
    let scanInFlight = false;

    function setStatus(msg, type = '') {
        statusBox.textContent = msg;
        statusBox.className = `camera-status ${type}`.trim();
    }

    // ── Voice Feedback Helper ──
    async function speakAttendance(name, customMsg = null, gender = 'male') {
        const audioPath = gender === 'fail' ? 'public/assets/fail.mp3' : 
                         (gender === 'female' ? 'public/assets/female.mp3' : 'public/assets/male.mp3');
        
        // Priority 1: Play the Global Audio File
        const audio = new Audio(audioPath);
        audio.play().catch(err => {
            if (gender !== 'fail') {
                console.warn('Gender audio not found, falling back to TTS:', err);
                fallbackToTTS(name, customMsg);
            }
        });
    }

    // ── Welcome Overlay Helper ──
    let welcomeTimer = null;
    function showWelcomeOverlay(name, roll, className, gender) {
        if (welcomeTimer) clearTimeout(welcomeTimer);
        
        document.getElementById('welcomeName').textContent = name;
        document.getElementById('welcomeMeta').textContent = `${roll} | ${className}`;
        
        // Set Avatar Icon based on Gender
        const avatar = document.getElementById('welcomeAvatar');
        avatar.textContent = (gender === 'female') ? '👩‍🎓' : '👨‍🎓';
        
        const overlay = document.getElementById('welcomeOverlay');
        overlay.classList.add('visible');
        
        welcomeTimer = setTimeout(() => {
            overlay.classList.remove('visible');
        }, 3500); // Show for 3.5 seconds
    }

    function fallbackToTTS(name, customMsg) {
        if (!('speechSynthesis' in window)) return;
        window.speechSynthesis.cancel();

        const text = customMsg 
            ? customMsg 
            : `Welcome ${name}, your attendance is marked.`;

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.0;
        utterance.pitch = 1.0;
        window.speechSynthesis.speak(utterance);
    }

    async function startCamera() {
        if (stream) return true;
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                audio: false
            });
            video.srcObject = stream;
            setStatus('Camera is live. Position students inside the frame.', 'success');
            return true;
        } catch (err) {
            setStatus(`Camera access failed: ${err.message}`, 'error');
            return false;
        }
    }

    function stopCamera() {
        if (autoScanTimer) { clearInterval(autoScanTimer); autoScanTimer = null; autoScanBtn.textContent = 'Start Auto Scan'; }
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; video.srcObject = null; }
        setStatus('Camera stopped.');
    }

    function captureFrame() {
        if (!stream || video.videoWidth === 0) throw new Error('Camera is not ready yet.');

        const maxWidth = 720;
        const maxHeight = 540;
        const scale = Math.min(1, Math.min(maxWidth / video.videoWidth, maxHeight / video.videoHeight));

        canvas.width = Math.max(1, Math.floor(video.videoWidth * scale));
        canvas.height = Math.max(1, Math.floor(video.videoHeight * scale));
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/jpeg', 0.72);
    }

    function renderBatchResults(results) {
        if (!results || results.length === 0) { batchBox.innerHTML = ''; return; }
        batchBox.innerHTML = '<h3 style="margin:0 0 8px;">Scan Results</h3>' +
            results.map(r => {
                const cls = r.status === 'already_marked' ? 'already_marked' : r.status === 'low_confidence' ? 'low_confidence' : '';
                const conf = r.confidence != null ? (r.confidence * 100).toFixed(1) + '%' : '—';
                return `<div class="batch-card ${cls}">
                    <span class="bc-name">${r.student_name || 'Unknown'}</span>
                    <span class="bc-roll">${r.roll_no || ''}</span>
                    <span class="bc-conf">${conf}</span>
                    <span class="bc-badge">${r.message || r.status}</span>
                </div>`;
            }).join('');
    }

    async function submitBatchFrame() {
        if (scanInFlight) return;
        scanInFlight = true;
        try {
            webcamInput.value = captureFrame();
            const res = await fetch(form.action || window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            });
            const data = await res.json();
            setStatus(data.message, data.type || (data.ok ? 'success' : 'warning'));
            renderBatchResults(data.results || []);

            if (data.ok && data.marked_count > 0) {
                // Success
                if (data.results && data.results.length > 0) {
                    const firstSuccess = data.results.find(r => r.status !== 'already_marked' && r.status !== 'low_confidence');
                    if (firstSuccess) {
                        speakAttendance(firstSuccess.student_name, firstSuccess.voice_message, firstSuccess.gender);
                        showWelcomeOverlay(firstSuccess.student_name, firstSuccess.roll_no, firstSuccess.class_name || 'N/A', firstSuccess.gender);
                    }
                }
            } else if (!data.ok || (data.results && data.results.some(r => r.status === 'fail_audio_trigger'))) {
                // Failure
                speakAttendance(null, null, 'fail');
            }
        } catch (err) {
            setStatus(`Scan failed: ${err.message}`, 'error');
        } finally {
            scanInFlight = false;
        }
    }

    startBtn.addEventListener('click', () => startCamera());
    stopBtn.addEventListener('click', () => stopCamera());
    captureBtn.addEventListener('click', async () => { if (await startCamera()) await submitBatchFrame(); });

    autoScanBtn.addEventListener('click', async () => {
        if (autoScanTimer) {
            clearInterval(autoScanTimer); autoScanTimer = null;
            autoScanBtn.textContent = 'Start Auto Scan';
            setStatus('Auto scan stopped.');
            return;
        }
        if (!(await startCamera())) return;
        autoScanBtn.textContent = 'Stop Auto Scan';
        setStatus('Auto scan running — capturing every 1.5 seconds.', 'success');
        await submitBatchFrame();
        autoScanTimer = setInterval(submitBatchFrame, 1500);
    });

    // ════════════════════════════════════════════
    // QR CODE SCANNER
    // ════════════════════════════════════════════
    const qrStatus = document.getElementById('qrStatus');
    const qrResults = document.getElementById('qrBatchResults');
    let qrScanner = null;

    function setQrStatus(msg, type = '') {
        qrStatus.textContent = msg;
        qrStatus.className = `camera-status ${type}`.trim();
    }

    async function stopQrScanner() {
        if (!qrScanner) return;
        try {
            if (qrScanner.isScanning) {
                await qrScanner.stop();
            }
            qrScanner = null;
            document.getElementById('qr-reader').innerHTML = ''; // Clear the UI
            setQrStatus('QR scanner stopped.');
        } catch (err) {
            console.warn('Silent fail stopping QR scanner:', err);
        }
    }

    function initQrScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            setQrStatus('QR scanner library failed to load. Check your internet connection.', 'error');
            return;
        }
        if (qrScanner) return;

        qrScanner = new Html5Qrcode('qr-reader');
        qrScanner.start(
            { facingMode: "user" }, // Use "user" as default for laptops, works on phones too
            {
                fps: 16,
                qrbox: (viewWidth, viewHeight) => {
                    const minEdge = Math.min(viewWidth, viewHeight);
                    const size = Math.floor(minEdge * 0.6);
                    return { width: size, height: size };
                }
            },
            onQrDetected,
            () => {}
        ).then(() => {
            setQrStatus('QR scanner active. Hold a student QR code in front of the camera.', 'success');
        }).catch(err => {
            // If "user" failed, try without constraints
            qrScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onQrDetected, () => {})
                .catch(e => setQrStatus('Cannot start QR scanner: ' + e, 'error'));
        });
    }

    let qrCooldown = false;

    async function onQrDetected(decodedText) {
        if (qrCooldown) return;
        qrCooldown = true;

        setQrStatus('Processing QR code…');
        try {
            const fd = new FormData();
            fd.append('mode', 'qr_scan');
            fd.append('qr_token', decodedText);
            const res = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: fd
            });
            const data = await res.json();
            setQrStatus(data.message, data.type || (data.ok ? 'success' : 'warning'));

            if (data.results && data.results.length > 0) {
                const r = data.results[0];
                if (data.ok) {
                    speakAttendance(r.student_name, r.voice_message, r.gender);
                    showWelcomeOverlay(r.student_name, r.roll_no, r.class_name || 'N/A', r.gender);
                } else {
                    speakAttendance(null, null, 'fail');
                }
                qrResults.innerHTML = data.results.map(r => {
                    return `<div class="batch-card">
                        <span class="bc-name">${r.student_name || 'Unknown'}</span>
                        <span class="bc-roll">${r.roll_no || ''}</span>
                        <span class="bc-badge">${r.message || 'Marked'}</span>
                    </div>`;
                }).join('');
            }
        } catch (err) {
            setQrStatus('QR scan failed: ' + err.message, 'error');
        }
        setTimeout(() => { qrCooldown = false; }, 2500);
    }

})();
</script>

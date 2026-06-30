<?php

require_role(['admin']);

$pageTitle = 'Student Management';

function collect_student_image_files(array $uploadedFiles): array
{
    $files = [];

    foreach ($uploadedFiles['tmp_name'] ?? [] as $index => $tmpPath) {
        if (($uploadedFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmpPath)) {
            continue;
        }

        $files["images[$index]"] = $tmpPath;
    }

    return $files;
}

function replace_student_encodings(int $studentId, string $studentName, array $files): int
{
    if (count($files) < 2) {
        throw new RuntimeException('At least two valid face images are required when replacing encodings.');
    }

    $deleteEncodings = db()->prepare('DELETE FROM face_encodings WHERE student_id = :student_id');
    $deleteEncodings->execute(['student_id' => $studentId]);

    $apiResponse = face_api_request('/register', [
        'student_id' => $studentId,
        'student_name' => $studentName,
    ], $files);

    return (int) ($apiResponse['encodings_saved'] ?? 0);
}

$editStudentId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editStudent = null;

if ($editStudentId > 0) {
    $stmt = db()->prepare('SELECT id, name, roll_no, class_name, parent_email, parent_phone, qr_token, voice_message, gender FROM students WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $editStudentId]);
    $editStudent = $stmt->fetch() ?: null;

    if ($editStudent === null) {
        flash('warning', 'The selected student could not be found.');
        redirect('index.php?page=students');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $redirectTarget = 'index.php?page=students';

    try {
        if ($action === 'delete') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $stmt = db()->prepare('DELETE FROM students WHERE id = :id');
            $stmt->execute(['id' => $studentId]);

            flash('success', 'Student deleted successfully.');
            redirect($redirectTarget);
        }

        $name = trim($_POST['name'] ?? '');
        $rollNo = trim($_POST['roll_no'] ?? '');
        $class = trim($_POST['class'] ?? '');
        $parentEmail = trim($_POST['parent_email'] ?? '') ?: null;
        $parentPhone = trim($_POST['parent_phone'] ?? '') ?: null;
        $uploadedFiles = $_FILES['images'] ?? null;
        $files = $uploadedFiles ? collect_student_image_files($uploadedFiles) : [];

        if ($name === '' || $rollNo === '' || $class === '') {
            throw new RuntimeException('All student fields are required.');
        }

        if ($parentEmail !== null && !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid parent email address.');
        }

        if ($action === 'create') {
            if (count($files) < 2) {
                throw new RuntimeException('Please capture at least two images for reliable face matching.');
            }

            $qrToken = generate_qr_token();

            $stmt = db()->prepare(
                'INSERT INTO students (name, roll_no, class_name, parent_email, parent_phone, qr_token, voice_message, gender)
                 VALUES (:name, :roll_no, :class_name, :parent_email, :parent_phone, :qr_token, :voice_message, :gender)'
            );
            $stmt->execute([
                'name' => $name,
                'roll_no' => $rollNo,
                'class_name' => $class,
                'parent_email' => $parentEmail,
                'parent_phone' => $parentPhone,
                'qr_token' => $qrToken,
                'voice_message' => trim($_POST['voice_message'] ?? '') ?: null,
                'gender' => $_POST['gender'] ?? 'male',
            ]);

            $studentId = (int) db()->lastInsertId();

            try {
                $saved = replace_student_encodings($studentId, $name, $files);
                flash('success', sprintf('Student registered. %d encoding(s) stored through Python API.', $saved));
            } catch (Throwable $exception) {
                $cleanup = db()->prepare('DELETE FROM students WHERE id = :id');
                $cleanup->execute(['id' => $studentId]);
                throw $exception;
            }

            redirect($redirectTarget);
        }

        if ($action === 'import_csv') {
            $file = $_FILES['csv_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please upload a valid CSV file.');
            }

            $handle = fopen($file['tmp_name'], 'r');
            // Check if file is empty or invalid
            if (!$handle) throw new RuntimeException('Could not read the uploaded CSV file.');

            // Read header row
            $header = fgetcsv($handle);

            $successCount = 0;
            $db = db();
            $db->beginTransaction();

            try {
                while (($row = fgetcsv($handle)) !== false) {
                    if (count($row) < 3) continue; // Skip rows missing mandatory data

                    $name      = trim($row[0]);
                    $rollNo    = trim($row[1]);
                    $className = trim($row[2]);
                    $pEmail    = trim($row[3] ?? '');
                    $pPhone    = trim($row[4] ?? '');

                    if ($name === '' || $rollNo === '') continue;

                    $qrToken = generate_qr_token();

                    $stmt = $db->prepare(
                        'INSERT INTO students (name, roll_no, class_name, parent_email, parent_phone, qr_token)
                         VALUES (:name, :roll_no, :class_name, :parent_email, :parent_phone, :qr_token)'
                    );
                    $stmt->execute([
                        'name'         => $name,
                        'roll_no'      => $rollNo,
                        'class_name'   => $className,
                        'parent_email' => $pEmail ?: null,
                        'parent_phone' => $pPhone ?: null,
                        'qr_token'     => $qrToken,
                    ]);
                    $successCount++;
                }
                $db->commit();
                flash('success', "Successfully imported $successCount students. Note: Face registration must be done manually or via ZIP import.");
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            } finally {
                fclose($handle);
            }
            redirect($redirectTarget);
        }

        if ($action === 'import_faces') {
            $file = $_FILES['zip_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please upload a valid ZIP file.');
            }

            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== TRUE) {
                throw new RuntimeException('Could not open the ZIP file.');
            }

            $successCount = 0;
            $failCount = 0;
            $db = db();
            $apiUrl = config('face_api_url', 'http://127.0.0.1:5000') . '/register';

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (str_starts_with($filename, '__MACOSX')) continue; // skip mac junk
                
                $info = pathinfo($filename);
                if (!isset($info['extension']) || !in_array(strtolower($info['extension']), ['jpg', 'jpeg', 'png'])) continue;

                // Match roll number (filename minus extension)
                $rollNo = $info['filename'];
                $stmt = $db->prepare('SELECT id FROM students WHERE roll_no = :roll LIMIT 1');
                $stmt->execute(['roll' => $rollNo]);
                $student = $stmt->fetch();

                if (!$student) {
                    $failCount++;
                    continue;
                }

                $fileData = $zip->getFromIndex($i);
                
                // Call Face API
                $boundary = uniqid();
                $delimiter = '-------------' . $boundary;
                $postData = "--" . $delimiter . "\r\n"
                          . "Content-Disposition: form-data; name=\"student_id\"\r\n\r\n"
                          . $student['id'] . "\r\n"
                          . "--" . $delimiter . "\r\n"
                          . "Content-Disposition: form-data; name=\"image\"; filename=\"{$filename}\"\r\n"
                          . "Content-Type: image/jpeg\r\n\r\n"
                          . $fileData . "\r\n"
                          . "--" . $delimiter . "--\r\n";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $apiUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: multipart/form-data; boundary=" . $delimiter]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            $zip->close();
            flash($successCount > 0 ? 'success' : 'warning', "Face import complete: $successCount recognized, $failCount skipped/failed.");
            redirect($redirectTarget);
        }

        if ($action === 'update') {
            $studentId = (int) ($_POST['student_id'] ?? 0);

            $stmt = db()->prepare(
                'UPDATE students
                 SET name = :name, roll_no = :roll_no, class_name = :class_name,
                     parent_email = :parent_email, parent_phone = :parent_phone,
                     voice_message = :voice_message, gender = :gender
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $studentId,
                'name' => $name,
                'roll_no' => $rollNo,
                'class_name' => $class,
                'parent_email' => $parentEmail,
                'parent_phone' => $parentPhone,
                'voice_message' => trim($_POST['voice_message'] ?? '') ?: null,
                'gender' => $_POST['gender'] ?? 'male',
            ]);

            $message = 'Student details updated successfully.';

            if ($files !== []) {
                $saved = replace_student_encodings($studentId, $name, $files);
                $message .= sprintf(' Face encodings replaced with %d fresh sample(s).', $saved);
            }

            flash('success', $message);
            redirect($redirectTarget);
        }

        throw new RuntimeException('Unknown student action.');
    } catch (Throwable $exception) {
        flash('error', 'Student action failed: ' . $exception->getMessage());
        if ($action === 'update') {
            redirect('index.php?page=students&edit=' . (int) ($_POST['student_id'] ?? 0));
        }
        redirect($redirectTarget);
    }
}

$students = db()->query(
    'SELECT s.id, s.name, s.roll_no, s.class_name, s.parent_email, s.qr_token, s.gender, COUNT(fe.id) AS encoding_count
     FROM students s
     LEFT JOIN face_encodings fe ON fe.student_id = s.id
     GROUP BY s.id, s.name, s.roll_no, s.class_name, s.parent_email, s.qr_token, s.gender
     ORDER BY s.id DESC'
)->fetchAll();

$formMode = $editStudent ? 'update' : 'create';
?>



<div class="grid cols-2">
    <section class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <h2><?= $editStudent ? 'Edit Student' : 'Register New Student' ?></h2>
            <?php if ($editStudent): ?>
                <a class="ghost-btn" href="index.php?page=students">Cancel Edit</a>
            <?php endif; ?>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= e($formMode) ?>">
            <?php if ($editStudent): ?>
                <input type="hidden" name="student_id" value="<?= e((string) $editStudent['id']) ?>">
            <?php endif; ?>
            
            <div class="form-row cols-2">
                <label>
                    Student Name
                    <input type="text" name="name" value="<?= e($editStudent['name'] ?? '') ?>" required>
                </label>
                <label>
                    Roll Number
                    <input type="text" name="roll_no" value="<?= e($editStudent['roll_no'] ?? '') ?>" required>
                </label>
            </div>
            
            <div class="form-row cols-2">
                <label>
                    Class
                    <input type="text" name="class" value="<?= e($editStudent['class_name'] ?? '') ?>" placeholder="BSCS-8A" required>
                </label>
                <label>
                    Gender
                    <select name="gender" required>
                        <option value="male" <?= ($editStudent['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= ($editStudent['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                    </select>
                </label>
            </div>
            
            <div class="form-row cols-2">
                <label>
                    Parent / Guardian Email <span class="muted">(optional)</span>
                    <input type="email" name="parent_email" value="<?= e($editStudent['parent_email'] ?? '') ?>" placeholder="parent@example.com">
                </label>
                <label>
                    Parent / Guardian Phone <span class="muted">(optional)</span>
                    <input type="tel" name="parent_phone" value="<?= e($editStudent['parent_phone'] ?? '') ?>" placeholder="+92 300 1234567">
                </label>
            </div>
            
            <label>
                Personalized Message <span class="muted">(Text-to-Speech fallback)</span>
                <input type="text" name="voice_message" value="<?= e($editStudent['voice_message'] ?? '') ?>" placeholder="e.g. Happy Birthday!">
            </label>
            
            <label>
                <?= $editStudent ? 'Replace Face Images (optional)' : 'Face Images' ?>
                <input type="file" name="images[]" accept="image/*" multiple <?= $editStudent ? '' : 'required' ?>>
            </label>
            <p class="muted">
                <?= $editStudent
                    ? 'Leave images empty to keep current encodings, or upload at least two new images to refresh them.'
                    : 'Capture multiple images under different lighting and slight angles. The system stores encodings, not raw images.' ?>
            </p>
            <button type="submit"><?= $editStudent ? 'Update Student' : 'Register Student' ?></button>
        </form>

        <?php if ($editStudent && !empty($editStudent['qr_token'])): ?>
            <hr style="border:none; border-top:1px solid var(--line); margin:20px 0;">
            <h3>Student QR Code</h3>
            <div class="edit-qr-card">
                <div class="qr-brand"><?= e(config('app_name')) ?></div>
                <div class="qr-name"><?= e($editStudent['name']) ?></div>
                <div class="qr-roll"><?= e($editStudent['roll_no']) ?></div>
                <div class="qr-class"><?= e($editStudent['class_name'] ?? '') ?></div>
                <div class="qr-code-box"><div id="editQrCode"></div></div>
                <div class="qr-hint">Scan on the Attendance page → QR Code Scanner tab</div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px;">
                <button type="button" class="secondary-btn" onclick="downloadQR('editQrCode', '<?= e($editStudent['roll_no']) ?>')">⬇ Download PNG</button>
                <button type="button" class="secondary-btn" onclick="printSingleCard('editQrCode')">🖨 Print Card</button>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Bulk Operations</h2>
        <div style="margin-bottom: 24px;">
            <h3 style="font-size:1rem;">📤 Import Students (CSV)</h3>
            <p class="muted">Upload a CSV file to add students in bulk. Face registration is required later.</p>
            <form method="post" enctype="multipart/form-data" class="upload-box">
                <input type="hidden" name="action" value="import_csv">
                <label>
                    Select CSV File
                    <input type="file" name="csv_file" accept=".csv" required>
                </label>
                <div class="muted" style="font-size:.75rem;">
                    Format: <code>Name, RollNo, Class, ParentEmail (opt), ParentPhone (opt)</code>
                </div>
                <button type="submit" class="secondary-btn">Import Students</button>
            </form>
        </div>

        <div style="margin-bottom: 24px;">
            <h3 style="font-size:1rem;">🖼️ Bulk Face Upload (ZIP)</h3>
            <p class="muted">Upload a ZIP of photos named as <code>RollNumber.jpg</code> (e.g. 101.jpg) to link faces instantly.</p>
            <form method="post" enctype="multipart/form-data" class="upload-box">
                <input type="hidden" name="action" value="import_faces">
                <label>
                    Select ZIP File
                    <input type="file" name="zip_file" accept=".zip" required>
                </label>
                <button type="submit" class="secondary-btn">Link Faces in Bulk</button>
            </form>
        </div>

        <hr style="border:none; border-top:1px solid var(--line); margin:20px 0;">

        <h2>QR Code Workflow</h2>
        <p class="muted">QR codes let students mark attendance when face recognition fails (masks, poor lighting, glasses, etc.).</p>
        <h3 style="font-size:.95rem;">How to use:</h3>
        <ol class="workflow-list">
            <li><strong>Register</strong> a student → a unique QR code is generated automatically.</li>
            <li>Click the <strong>QR</strong> button next to any student → view, download, or print.</li>
            <li><strong>Print</strong> the QR card and paste it on the student's ID card, notebook, or give it as a separate slip.</li>
            <li>On attendance day, if face scan fails → switch to <strong>QR Code Scanner</strong> tab.</li>
            <li>Student holds their QR card to the webcam → attendance is marked instantly.</li>
        </ol>
        <h3 style="font-size:.95rem; margin-top: 15px;">Bulk printing:</h3>
        <p class="muted">Click <strong>"🖨 Print All QR Cards"</strong> below the student list to generate a printable sheet with QR cards for every registered student. Cut and distribute to students.</p>
    </section>
</div>

<section class="card" style="margin-top: 18px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
        <h2>Registered Students</h2>
        <div class="print-cards-bar">
            <?php if ($students !== []): ?>
                <button type="button" class="secondary-btn" onclick="zipDownloadAllQRs()" id="zipBtn">📦 Download All QRs (ZIP)</button>
                <button type="button" class="secondary-btn" id="printAllBtn" onclick="generateAllCards()">🖨 Print All QR Cards</button>
            <?php endif; ?>
            <a class="ghost-btn" href="index.php?page=students">Add Student</a>
        </div>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Roll No</th>
                <th>Class</th>
                <th>Parent Email</th>
                <th>Face Status</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($students === []): ?>
                <tr><td colspan="7" class="muted">No students registered yet.</td></tr>
            <?php else: ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= e((string) $student['id']) ?></td>
                        <td><?= e($student['name']) ?></td>
                        <td><?= e($student['roll_no']) ?></td>
                        <td><?= e($student['class_name']) ?></td>
                        <td class="parent-info"><?= $student['parent_email'] ? e($student['parent_email']) : '<span class="muted">—</span>' ?></td>
                        <td>
                            <?php if ($student['encoding_count'] > 0): ?>
                                <span class="badge success" style="font-size:.75rem;">✅ Face Registered</span>
                            <?php else: ?>
                                <span class="badge warning" style="font-size:.75rem;">❌ No Face Data</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="inline-actions">
                                <a class="ghost-btn" href="index.php?page=students&edit=<?= e((string) $student['id']) ?>">Edit</a>
                                <?php if (!empty($student['qr_token'])): ?>
                                    <button type="button" class="secondary-btn"
                                            onclick="showQR('<?= e($student['name']) ?>', '<?= e($student['roll_no']) ?>', '<?= e($student['class_name']) ?>', '<?= e($student['qr_token']) ?>')">
                                        QR
                                    </button>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Delete this student and all linked records?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="student_id" value="<?= e((string) $student['id']) ?>">
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

<!-- ══════════════════════════════════════════════════
     QR CODE MODAL — Single Student
     ══════════════════════════════════════════════════ -->
<div class="qr-modal-overlay" id="qrOverlay" onclick="if(event.target===this)closeQR()">
    <div class="qr-modal">
        <div class="qr-card">
            <div class="qr-brand"><?= e(config('app_name')) ?></div>
            <div class="qr-name" id="qrStudentName"></div>
            <div class="qr-roll" id="qrRollNo"></div>
            <div class="qr-class" id="qrClassName"></div>
            <div class="qr-code-box"><div id="modalQrCode"></div></div>
            <div class="qr-hint">Scan on the Attendance page → QR Code Scanner tab</div>
        </div>
        <div class="qr-modal-actions">
            <button type="button" onclick="downloadQR('modalQrCode', currentQrRoll)">⬇ Download</button>
            <button type="button" onclick="printSingleCard('modalQrCode')">🖨 Print</button>
            <button type="button" class="secondary-btn" onclick="closeQR()">✕ Close</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════
     PRINT ALL QR CARDS — Bulk Section
     ══════════════════════════════════════════════════ -->
<section class="card" style="margin-top:18px; display:none;" id="printCardsSection">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px;">
        <h2>🖨 Printable QR Cards</h2>
        <div class="print-cards-bar">
            <button type="button" onclick="window.print()">🖨 Send to Printer</button>
            <button type="button" class="secondary-btn" onclick="document.getElementById('printCardsSection').style.display='none'">✕ Hide</button>
        </div>
    </div>
    <p class="muted">Below are all student QR cards. Click "Send to Printer" or use Ctrl+P. Cut along card borders and distribute to students.</p>
    <div class="qr-cards-grid" id="allCardsGrid">
        <!-- Generated by JS -->
    </div>
</section>

<!-- QR Code JS Library -->
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<!-- ZIP Generation Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>

<div id="hiddenQrFactory" style="display:none; position:fixed; left:-9999px;"></div>

<script>
(() => {
    const APP_NAME = <?= json_encode(config('app_name')) ?>;
    const QR_IMAGE_URL = 'public/assets/logo.jpeg';

    // Preload image for canvas operations
    let bgImg = null;
    function preloadImage() {
        if (bgImg) return Promise.resolve(bgImg);
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => { bgImg = img; resolve(img); };
            img.onerror = () => { bgImg = null; resolve(null); };
            img.src = QR_IMAGE_URL;
        });
    }

    async function applyBranding(canvas) {
        const image = await preloadImage();
        if (!image) return;

        const ctx = canvas.getContext('2d');
        const size = canvas.width;

        // 1. Create a temporary canvas for the QR code
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = size;
        tempCanvas.height = size;
        const tempCtx = tempCanvas.getContext('2d');
        tempCtx.drawImage(canvas, 0, 0);

        // 2. Clear main canvas to draw background
        ctx.clearRect(0, 0, size, size);

        // 3. Draw Background Image (Cover/Fill)
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        const scale = Math.max(size / image.width, size / image.height);
        const x = (size / 2) - (image.width / 2) * scale;
        const y = (size / 2) - (image.height / 2) * scale;
        ctx.drawImage(image, x, y, image.width * scale, image.height * scale);

        // 4. Draw a "High-Contrast White Wash"
        // 60% opacity provides the perfect balance for scanning
        ctx.fillStyle = "rgba(255, 255, 255, 0.6)";
        ctx.fillRect(0, 0, size, size);

        // 5. Draw the QR code on top (Normal blend for sharpest modules)
        ctx.drawImage(tempCanvas, 0, 0);
    }

    // All student data for bulk card generation
    const allStudents = <?= json_encode(array_map(static fn($s) => [
        'name' => $s['name'],
        'roll_no' => $s['roll_no'],
        'class_name' => $s['class_name'],
        'qr_token' => $s['qr_token'],
    ], $students)) ?>;

    // ── Helper: create a QR code inside a container ──
    async function makeQR(containerId, token, size = 600) {
        const el = document.getElementById(containerId);
        if (!el) return;
        el.innerHTML = '';
        new QRCode(el, {
            text: 'FACETRACK:' + token,
            width: size,
            height: size,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H,
        });

        // Wait for render, then apply background
        setTimeout(async () => {
            const canvas = el.querySelector('canvas');
            if (canvas) await applyBranding(canvas);
        }, 80);
    }

    // ── Generate edit-page QR on load ────────────────
    <?php if ($editStudent && !empty($editStudent['qr_token'])): ?>
    makeQR('editQrCode', <?= json_encode($editStudent['qr_token']) ?>, 180);
    <?php endif; ?>

    // ── Single student QR modal ──────────────────────
    window.currentQrRoll = '';

    window.showQR = function(name, rollNo, className, token) {
        document.getElementById('qrStudentName').textContent = name;
        document.getElementById('qrRollNo').textContent = rollNo;
        document.getElementById('qrClassName').textContent = className;
        window.currentQrRoll = rollNo;
        makeQR('modalQrCode', token, 200);
        document.getElementById('qrOverlay').classList.add('visible');
    };

    window.closeQR = function() {
        document.getElementById('qrOverlay').classList.remove('visible');
    };

    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeQR(); });

    // ── Download QR as PNG ───────────────────────────
    window.downloadQR = function(containerId, rollNo) {
        const container = document.getElementById(containerId);
        const canvas = container.querySelector('canvas');
        if (!canvas) { alert('QR code not generated yet.'); return; }

        const link = document.createElement('a');
        link.download = 'QR_' + (rollNo || 'student') + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    };

    // ── Print single QR card ─────────────────────────
    window.printSingleCard = function(containerId) {
        const container = document.getElementById(containerId);
        const canvas = container.querySelector('canvas');
        if (!canvas) { alert('QR code not generated yet.'); return; }

        const imgData = canvas.toDataURL('image/png');
        const name = document.getElementById('qrStudentName')?.textContent ||
                     <?= json_encode($editStudent['name'] ?? '') ?>;
        const roll = document.getElementById('qrRollNo')?.textContent ||
                     <?= json_encode($editStudent['roll_no'] ?? '') ?>;

        const win = window.open('', '_blank', 'width=450,height=600');
        win.document.write(`<!DOCTYPE html><html><head><title>QR Card — ${roll}</title>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family: "Segoe UI", Tahoma, sans-serif; display:grid; place-items:center; min-height:100vh; background:#f4f7fb; }
            .card { background:linear-gradient(135deg, #0f766e, #115e59); color:#fff;
                    border-radius:18px; padding:32px 28px; text-align:center;
                    width:320px; box-shadow:0 12px 40px rgba(0,0,0,.15); }
            .brand { font-size:.7rem; text-transform:uppercase; letter-spacing:2px; opacity:.6; margin-bottom:16px; }
            .name { font-size:1.2rem; font-weight:700; }
            .roll { font-size:.9rem; opacity:.85; margin:4px 0; }
            .cls { font-size:.8rem; opacity:.65; }
            .qr { background:#fff; border-radius:14px; padding:14px; display:inline-block; margin:18px 0 10px; }
            .qr img { display:block; }
            .hint { font-size:.65rem; opacity:.5; }
            @media print {
                body { background:#fff; }
                .card { box-shadow:none; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            }
        </style></head><body>
        <div class="card">
            <div class="brand">${APP_NAME}</div>
            <div class="name">${name}</div>
            <div class="roll">${roll}</div>
            <div class="cls">${document.getElementById('qrClassName')?.textContent || ''}</div>
            <div class="qr"><img src="${imgData}" width="180" height="180"></div>
            <div class="hint">Scan on Attendance page → QR Code Scanner</div>
        </div>
        <script>window.onload = () => { window.print(); };<\/script>
        </body></html>`);
        win.document.close();
    };

    // ── Generate ALL QR cards for bulk printing ──────
    window.generateAllCards = async function() {
        const section = document.getElementById('printCardsSection');
        const grid = document.getElementById('allCardsGrid');
        
        // Preload image first to avoid "blank" cards
        const img = await preloadImage();
        
        section.style.display = 'block';
        grid.innerHTML = '';

        if (allStudents.length === 0) {
            grid.innerHTML = '<p class="muted">No students registered.</p>';
            return;
        }

        allStudents.forEach((student, index) => {
            const card = document.createElement('div');
            card.className = 'qr-card-item';
            const qrId = 'bulkQr_' + index;
            card.innerHTML = `
                <div class="card-bg" style="background-image: url('${QR_IMAGE_URL}')"></div>
                <div class="qr-brand">${APP_NAME}</div>
                <div class="qr-name">${student.name}</div>
                <div class="qr-roll">${student.roll_no}</div>
                <div class="qr-class">${student.class_name}</div>
                <div class="qr-code-box"><div id="${qrId}"></div></div>
                <div class="qr-hint">Scan on Attendance page → QR Scanner</div>
            `;
            grid.appendChild(card);

            // Slightly longer delay for bulk processing to ensure DOM is ready
            setTimeout(() => {
                makeQR(qrId, student.qr_token, 180);
            }, 100 + (index * 40));
        });

        // Scroll to the section
        setTimeout(() => section.scrollIntoView({ behavior: 'smooth' }), 300);
    };

    // ── Download All QR Codes as a ZIP Archive ──────
    window.zipDownloadAllQRs = async function() {
        const btn = document.getElementById('zipBtn');
        const factory = document.getElementById('hiddenQrFactory');
        const originalText = btn.textContent;

        if (allStudents.length === 0) return;

        try {
            btn.disabled = true;
            btn.textContent = 'Processing...';

            const zip = new JSZip();
            const qrFolder = zip.folder("student_qrs");

            for (let i = 0; i < allStudents.length; i++) {
                const s = allStudents[i];
                btn.textContent = `Generating ${i+1}/${allStudents.length}...`;

                const tempId = `temp_qr_${i}`;
                const div = document.createElement('div');
                div.id = tempId;
                factory.appendChild(div);

                // Generate QR at balanced resolution (600px)
                new QRCode(div, {
                    text: 'FACETRACK:' + s.qr_token,
                    width: 600,
                    height: 600,
                    correctLevel: QRCode.CorrectLevel.H
                });

                // Wait a tiny bit for the library to render the canvas
                await new Promise(r => setTimeout(r, 100));

                const canvas = div.querySelector('canvas');
                if (canvas) {
                    await applyBranding(canvas); // Add background before zipping
                    const dataUrl = canvas.toDataURL('image/png', 1.0);
                    const base64Data = dataUrl.split(',')[1];
                    const fileName = `${s.roll_no.replace(/[^a-z0-9]/gi, '_')}_${s.name.replace(/[^a-z0-9]/gi, '_')}.png`;
                    qrFolder.file(fileName, base64Data, {base64: true});
                }
                factory.removeChild(div);
            }

            btn.textContent = 'Zipping...';
            const content = await zip.generateAsync({type: "blob"});
            saveAs(content, `FaceTrack_QR_Codes_${new Date().toISOString().split('T')[0]}.zip`);

            btn.textContent = 'Done!';
            setTimeout(() => {
                btn.disabled = false;
                btn.textContent = originalText;
            }, 2000);

        } catch (err) {
            console.error(err);
            alert('Failed to generate ZIP: ' + err.message);
            btn.disabled = false;
            btn.textContent = originalText;
        }
    };
})();
</script>

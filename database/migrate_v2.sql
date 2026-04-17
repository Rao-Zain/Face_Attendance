-- ============================================================
-- Migration for existing face_attendance databases to v2
-- Run this if you already have the original schema set up.
-- ============================================================

USE face_attendance;

-- 1. Add parent contact and QR token columns to students
ALTER TABLE students ADD COLUMN parent_email VARCHAR(190) DEFAULT NULL;
ALTER TABLE students ADD COLUMN parent_phone VARCHAR(30) DEFAULT NULL;
ALTER TABLE students ADD COLUMN qr_token VARCHAR(64) NOT NULL DEFAULT '';

-- 2. Generate unique tokens for every existing student
UPDATE students SET qr_token = MD5(CONCAT(id, RAND(), NOW(), UUID())) WHERE qr_token = '';

-- 3. Add unique constraint on qr_token
ALTER TABLE students ADD UNIQUE KEY uq_qr_token (qr_token);

-- 4. Track how attendance was marked (face, qr, or manual)
ALTER TABLE attendance ADD COLUMN marked_via ENUM('face', 'qr', 'manual') NOT NULL DEFAULT 'face' AFTER status;

-- 5. Create notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    type ENUM('absent', 'late', 'low_attendance') NOT NULL,
    message TEXT NOT NULL,
    recipient_email VARCHAR(190) DEFAULT NULL,
    sent_via ENUM('email', 'system') NOT NULL DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
);

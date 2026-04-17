CREATE DATABASE IF NOT EXISTS face_attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE face_attendance;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'teacher') NOT NULL DEFAULT 'teacher',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    roll_no VARCHAR(80) NOT NULL UNIQUE,
    class_name VARCHAR(100) NOT NULL,
    parent_email VARCHAR(190) DEFAULT NULL,
    parent_phone VARCHAR(30) DEFAULT NULL,
    qr_token VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qr_token (qr_token)
);

CREATE TABLE IF NOT EXISTS face_encodings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    encoding LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_face_encodings_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_time TIME NOT NULL,
    status ENUM('present', 'late') NOT NULL DEFAULT 'present',
    marked_via ENUM('face', 'qr', 'manual') NOT NULL DEFAULT 'face',
    confidence_score DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE,
    CONSTRAINT uq_attendance_once_per_day UNIQUE (student_id, attendance_date)
);

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    type ENUM('absent', 'late', 'low_attendance') NOT NULL,
    message TEXT NOT NULL,
    recipient_email VARCHAR(190) DEFAULT NULL,
    sent_via ENUM('email', 'sms', 'whatsapp', 'email+whatsapp', 'email+sms', 'system') NOT NULL DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
);

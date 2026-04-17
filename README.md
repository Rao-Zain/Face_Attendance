# 🛡️ FaceTrack AI: Automated Student Attendance System

A professional-grade, automated student management and attendance system utilizing **AI Face Recognition**, **Branded QR Codes**, and **Personalized Voice Feedback**. Perfect for schools, colleges, and secure entry points.

## 🚀 Key Features

-   **🤖 Dual-Recognition Engine**: High-accuracy Face Recognition (Python/OpenCV) with a fast QR Code fallback.
-   **🎤 Smart Voice Feedback**: Automated personalized greetings ("Welcome [Name]!") and gender-aware audio notes.
-   **📱 Branded ID Cards**: High-resolution, fully branded QR code ID cards generated instantly for all students.
-   **⚡ Bulk Operations**: Import hundreds of students via CSV and link their faces instantly with a ZIP photo archive.
-   **🔔 Automated Alerts**: Daily absent reports and parent notifications via Email/WhatsApp (Integration ready).
-   **💎 Premium UI**: A modern, glassmorphic dashboard with a fullscreen "VIP Welcome" overlay for student recognition.

## 🛠️ Tech Stack

-   **Frontend**: HTML5, Vanilla CSS3 (Custom Design System), JavaScript.
-   **Backend**: PHP (XAMPP/MySQL) for dashboard and data management.
-   **AI Core**: Python (Flask) with `face_recognition` and `OpenCV`.
-   **Feedback**: Web Speech API & Custom MP3 Audio engine.

## 📂 Project Structure

-   `/modules`: Main PHP modules (Attendance, Students, Notifications).
-   `/face_api`: Python Flask API for face encoding and matching.
-   `/public/assets`: Custom logos and gender-based audio files.
-   `/scripts`: Automation tasks (e.g., Daily Absent Scanner).

## 🛠️ Setup Instructions

### 1. Database
-   Import the `database.sql` file into your MySQL (PHPMyAdmin).
-   Configure your credentials in `config/database.php`.

### 2. Python API
-   Navigate to `/face_api`.
-   Install dependencies: `pip install -r requirements.txt`.
-   Run the API: `python app.py`.

### 3. Audio Assets
Place your custom audio in `public/assets/`:
-   `male.mp3`
-   `female.mp3`
-   `fail.mp3`

## 🌟 Acknowledgments
This system was built to provide a seamless, futuristic attendance experience using modern AI technologies.

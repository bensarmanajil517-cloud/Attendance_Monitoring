# Student Attendance Monitoring System (PHP + MySQL + QR Code Web Scanner)

A modern, responsive, and web-camera-based Student Attendance Monitoring web application built using **PHP, MySQL, JavaScript (HTML5 Web Camera & QRCode.js), and CSS**.

---

## 🚀 Key Features

1. **Student Portal**:
   - Automatic unique QR Code generation upon registration / login.
   - Download & Save QR Code image button (for screenshotting or printing ID).
   - Real-time personal attendance log history & status breakdown (Present / Late).

2. **Professor Portal**:
   - **Direct Web Camera QR Scanner** (Options for Laptop Webcam, Front / Rear Mobile Cameras).
   - **Instant Sound Effects**: Synthesized audio beep feedback on scan (Success, Warning, Error) via HTML5 Web Audio API.
   - **Smart Automatic Attendance Logging**: Automatically tags student as **Present** or **Late** based on class schedule start time & grace period.
   - **Duplicate Scan Prevention**: Prevents scanning the same student twice in the same subject on the same day.
   - **Live Attendance Feed**: Real-time table updates immediately when a student's QR is scanned without refreshing the page.
   - **Manual Fallback**: Quick Student ID input for students with damaged phone screens or missing IDs.
   - **Subject & Schedule Management**: Set days of week, start times, end times, and late grace periods.
   - **Reports & Export**: Filter attendance logs by Subject, Date Range, and Status. One-click **Export to CSV / Excel** and Print-ready view.

---

## 📁 Project Structure

```text
attendance-monitoring/
├── api/
│   └── log_attendance.php     # REST API endpoint for processing QR scans
├── assets/
│   └── css/
│       └── style.css          # Modern, responsive UI stylesheet
├── config/
│   ├── database.php           # PDO Database connection & settings
│   └── auth_check.php         # Authentication & Session verification
├── database/
│   └── schema.sql             # MySQL schema & sample seed data
├── professor/
│   ├── dashboard.php          # Professor summary & recent attendance
│   ├── scan.php               # Live Web Camera QR Scanner
│   ├── subjects.php           # Manage Subjects & Schedules
│   └── reports.php            # Attendance Logs & CSV Export
├── student/
│   └── dashboard.php          # Student QR code generator & personal logs
├── index.php                  # Root entry & role redirector
├── login.php                  # Unified login for Students & Professors
├── register.php               # Student account registration
├── logout.php                 # Session logout
└── README.md
```

---

## 🛠️ Setup & Installation Instructions

### Step 1: Database Setup (XAMPP / phpMyAdmin / MySQL)
1. Open **phpMyAdmin** (usually `http://localhost/phpmyadmin`) or your MySQL client.
2. Create a database named `attendance_db` or simply import the `database/schema.sql` file.
3. The SQL file automatically creates all tables and inserts sample accounts for testing.

### Step 2: Database Configuration
Open [config/database.php](file:///c:/Users/bensa/OneDrive/Videos/attendance%20monitoring/config/database.php) and verify your MySQL credentials:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'attendance_db');
define('DB_PORT', 3306);
```

### Step 3: Run the Application
You can run this project using **XAMPP / WampServer / Laragon** (place in `htdocs` or `www`) or PHP's built-in server:
```bash
php -S localhost:8000
```
Then navigate in your browser to:
```
http://localhost:8000
```

---

## 🔑 Default Demo Accounts

| Role | Email / ID | Password | Notes |
| :--- | :--- | :--- | :--- |
| **Professor** | `prof.juan@school.edu.ph` | `password123` | Can scan QR codes, manage subjects, view reports |
| **Student 1** | `STUD-2026-101` | `password123` | Maria Clara Santos (BSIT 3-A) |
| **Student 2** | `STUD-2026-102` | `password123` | Crisostomo Ibarra (BSIT 3-A) |
| **Student 3** | `STUD-2026-103` | `password123` | Basilio Gomez (BSIT 3-A) |

---

## 📱 How to Test the Flow

1. **Student Side**:
   - Log in as Student (`STUD-2026-101` / `password123`) or Register a new student.
   - The unique QR Code will appear on the dashboard.
   - Click **Save / Download QR** or view it on your mobile phone screen.

2. **Professor Side**:
   - Open another browser tab or your laptop/phone browser and log in as Professor (`prof.juan@school.edu.ph` / `password123`).
   - Go to **Live QR Scanner** (`professor/scan.php`).
   - Allow camera permissions.
   - Point the camera at the student's QR code.
   - The system will play a success beep, tag the student as **Present** (or **Late** if past schedule), and dynamically add their record to the live table!

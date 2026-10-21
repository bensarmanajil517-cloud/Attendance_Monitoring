<?php
// api/log_attendance.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

// Ensure user is authenticated as professor or admin
if (!is_logged_in() || !in_array($_SESSION['user_role'] ?? '', ['professor', 'admin'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. Please log in as Professor.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// Read JSON input or POST form data
$inputData = json_decode(file_get_contents('php://input'), true);
$qr_token   = trim($inputData['qr_token'] ?? $_POST['qr_token'] ?? '');
$subject_id = intval($inputData['subject_id'] ?? $_POST['subject_id'] ?? 0);
$prof_id    = $_SESSION['user_id'];

if (empty($qr_token)) {
    echo json_encode(['status' => 'error', 'message' => 'QR Code data is empty.']);
    exit;
}

if ($subject_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Please select an active Subject / Class first.']);
    exit;
}

try {
    $pdo = get_db_connection();

    // 1. Verify Subject belongs to professor
    $subjectStmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
    $subjectStmt->execute([$subject_id]);
    $subject = $subjectStmt->fetch();

    if (!$subject) {
        echo json_encode(['status' => 'error', 'message' => 'Subject not found.']);
        exit;
    }

    // 2. Find Student by QR Token
    $studentStmt = $pdo->prepare("SELECT * FROM users WHERE qr_token = ? AND role = 'student' LIMIT 1");
    $studentStmt->execute([$qr_token]);
    $student = $studentStmt->fetch();

    if (!$student) {
        // Also check if scanned data was direct Student ID instead of token
        $studentStmt = $pdo->prepare("SELECT * FROM users WHERE identifier = ? AND role = 'student' LIMIT 1");
        $studentStmt->execute([$qr_token]);
        $student = $studentStmt->fetch();
        
        if (!$student) {
            echo json_encode(['status' => 'error', 'message' => 'Unrecognized QR Code. Student not found in database.']);
            exit;
        }
    }

    // 3. Optional: Check if student is enrolled in this subject (auto-enroll if not yet in table)
    $enrollStmt = $pdo->prepare("SELECT id FROM student_subjects WHERE student_id = ? AND subject_id = ?");
    $enrollStmt->execute([$student['id'], $subject_id]);
    if (!$enrollStmt->fetch()) {
        // Auto-enroll student into subject
        $autoEnroll = $pdo->prepare("INSERT INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
        $autoEnroll->execute([$student['id'], $subject_id]);
    }

    // 4. Check if student already scanned for today
    $todayDate = date('Y-m-d');
    $checkStmt = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? AND subject_id = ? AND date = ?");
    $checkStmt->execute([$student['id'], $subject_id, $todayDate]);
    $existing = $checkStmt->fetch();

    if ($existing) {
        echo json_encode([
            'status' => 'already_recorded',
            'message' => 'Attendance already recorded earlier today!',
            'student' => [
                'name'           => $student['name'],
                'identifier'     => $student['identifier'],
                'course_section' => $student['course_section'] ?? 'N/A'
            ],
            'attendance' => [
                'date'    => $existing['date'],
                'time_in' => date('h:i A', strtotime($existing['time_in'])),
                'status'  => $existing['status']
            ]
        ]);
        exit;
    }

    // 5. Determine Present or Late based on Subject Schedule
    $currentDay = date('l'); // e.g. "Monday"
    $currentTime = date('H:i:s');
    $status = 'Present';

    // Check schedule for today
    $schedStmt = $pdo->prepare("SELECT * FROM schedules WHERE subject_id = ? AND day_of_week = ? LIMIT 1");
    $schedStmt->execute([$subject_id, $currentDay]);
    $sched = $schedStmt->fetch();

    if ($sched) {
        $startTime = strtotime($sched['start_time']);
        $lateThresholdMins = (int)($sched['late_threshold_mins'] ?? 15);
        $lateCutoffTime = $startTime + ($lateThresholdMins * 60);

        if (time() > $lateCutoffTime) {
            $status = 'Late';
        }
    }

    // 6. Record Attendance
    $insertStmt = $pdo->prepare("
        INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by) 
        VALUES (:student_id, :subject_id, :date, :time_in, :status, :scanned_by)
    ");
    $insertStmt->execute([
        ':student_id' => $student['id'],
        ':subject_id' => $subject_id,
        ':date'       => $todayDate,
        ':time_in'    => $currentTime,
        ':status'     => $status,
        ':scanned_by' => $prof_id
    ]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Attendance logged successfully!',
        'student' => [
            'name'           => $student['name'],
            'identifier'     => $student['identifier'],
            'course_section' => $student['course_section'] ?? 'N/A'
        ],
        'attendance' => [
            'date'    => $todayDate,
            'time_in' => date('h:i A', strtotime($currentTime)),
            'status'  => $status
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}

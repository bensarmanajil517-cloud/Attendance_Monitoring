<?php
// admin/teachers.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

$today = date('Y-m-d');
$error = '';
$success = '';

// =========================================================================
// AJAX / JSON API HANDLERS FOR ROW ACTION BUTTONS (VIEW, EDIT, DELETE)
// =========================================================================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_GET['ajax_action'];

    try {
        // 1. Fetch Teacher Details + Attendance History for View Modal
        if ($ajaxAction === 'get_teacher') {
            $teacherId = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ? LIMIT 1");
            $stmt->execute([$teacherId]);
            $teacher = $stmt->fetch();

            if (!$teacher) {
                echo json_encode(['status' => 'error', 'message' => 'Teacher record not found.']);
                exit;
            }

            $logsStmt = $pdo->prepare("
                SELECT date, time_in, time_out, status, subject_name, grade_level_name
                FROM teacher_attendance
                WHERE teacher_id = ?
                ORDER BY date DESC
                LIMIT 20
            ");
            $logsStmt->execute([$teacherId]);
            $logs = $logsStmt->fetchAll();

            echo json_encode([
                'status'  => 'success',
                'teacher' => [
                    'id'               => (int)$teacher['id'],
                    'teacher_id'       => $teacher['teacher_id'],
                    'full_name'        => $teacher['full_name'],
                    'grade_level_name' => $teacher['grade_level_name'],
                    'subject_name'     => $teacher['subject_name'],
                    'email'            => $teacher['email'],
                    'contact_number'   => $teacher['contact_number'],
                    'status'           => $teacher['status']
                ],
                'history' => $logs
            ]);
            exit;
        }

        // 2. Update Teacher Record from Edit Modal
        if ($ajaxAction === 'update_teacher' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id               = intval($input['id'] ?? 0);
            $teacher_id       = trim($input['teacher_id'] ?? '');
            $full_name        = trim($input['full_name'] ?? '');
            $grade_level_name = trim($input['grade_level_name'] ?? 'Grade 7');
            $subject_name     = trim($input['subject_name'] ?? 'English');
            $email            = trim($input['email'] ?? '');
            $contact_number   = trim($input['contact_number'] ?? '');

            if ($id <= 0 || $teacher_id === '' || $full_name === '' || $email === '') {
                echo json_encode(['status' => 'error', 'message' => 'Teacher ID, Full Name, and Email are required.']);
                exit;
            }

            // Preserve existing status if not sent from the modal
            $curStStmt = $pdo->prepare("SELECT status FROM teachers WHERE id = ? LIMIT 1");
            $curStStmt->execute([$id]);
            $currentStatus = $curStStmt->fetchColumn() ?: 'Active';
            $status = isset($input['status']) && trim($input['status']) !== '' ? trim($input['status']) : $currentStatus;

            $gStmt = $pdo->prepare("SELECT id FROM grade_levels WHERE grade_name = ? LIMIT 1");
            $gStmt->execute([$grade_level_name]);
            $gId = $gStmt->fetchColumn() ?: 1;

            $sStmt = $pdo->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND subject_name = ? LIMIT 1");
            $sStmt->execute([$gId, $subject_name]);
            $sId = $sStmt->fetchColumn() ?: null;

            $upd = $pdo->prepare("
                UPDATE teachers 
                SET teacher_id = ?, full_name = ?, grade_level_id = ?, subject_id = ?, grade_level_name = ?, subject_name = ?, email = ?, contact_number = ?, status = ?
                WHERE id = ?
            ");
            $upd->execute([$teacher_id, $full_name, $gId, $sId, $grade_level_name, $subject_name, $email, $contact_number, $status, $id]);

            $pdo->prepare("UPDATE teacher_attendance SET subject_name = ?, grade_level_name = ? WHERE teacher_id = ? AND date = ?")
                ->execute([$subject_name, $grade_level_name, $id, $today]);

            echo json_encode([
                'status'  => 'success',
                'message' => "Teacher '$full_name' updated successfully!",
                'teacher' => [
                    'id'               => $id,
                    'teacher_id'       => $teacher_id,
                    'full_name'        => $full_name,
                    'grade_level_name' => $grade_level_name,
                    'subject_name'     => $subject_name,
                    'email'            => $email,
                    'contact_number'   => $contact_number,
                    'status'           => $status
                ]
            ]);
            exit;
        }

        // 3. Delete Teacher Record from More Options (...) Dropdown
        if ($ajaxAction === 'delete_teacher' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
            $id = intval($input['id'] ?? 0);

            if ($id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid teacher ID.']);
                exit;
            }

            $delStmt = $pdo->prepare("DELETE FROM teachers WHERE id = ?");
            $delStmt->execute([$id]);

            echo json_encode([
                'status'  => 'success',
                'message' => 'Teacher record deleted successfully.'
            ]);
            exit;
        }

        echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Fetch Grade Levels & Core Subjects for Dropdowns
$gradeLevels = $pdo->query("SELECT * FROM grade_levels ORDER BY grade_number ASC")->fetchAll();
$coreSubjectsList = [
    'English',
    'Mathematics',
    'Science',
    'Filipino',
    'Araling Panlipunan',
    'MAPEH',
    'TLE',
    'Computer'
];

// 1. Handle Add Teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_teacher') {
    $teacher_id       = trim($_POST['teacher_id'] ?? '');
    $full_name        = trim($_POST['full_name'] ?? '');
    $grade_level_name = trim($_POST['grade_level_name'] ?? 'Grade 7');
    $subject_name     = trim($_POST['subject_name'] ?? 'English');
    $email            = trim($_POST['email'] ?? '');
    $contact_number   = trim($_POST['contact_number'] ?? '');
    $status           = trim($_POST['status'] ?? 'Active');
    $password         = trim($_POST['password'] ?? 'password123');

    if ($teacher_id === '' || $full_name === '' || $email === '') {
        $error = 'Teacher ID, Full Name, and Email are required.';
    } else {
        try {
            $dup = $pdo->prepare("SELECT id FROM teachers WHERE teacher_id = ? OR email = ? LIMIT 1");
            $dup->execute([$teacher_id, $email]);
            if ($dup->fetch()) {
                $error = 'Teacher ID or Email is already registered.';
            } else {
                $gStmt = $pdo->prepare("SELECT id FROM grade_levels WHERE grade_name = ? LIMIT 1");
                $gStmt->execute([$grade_level_name]);
                $gId = $gStmt->fetchColumn() ?: 1;

                $hashedPass = password_hash($password ?: 'password123', PASSWORD_BCRYPT);
                $qrToken = 'PROF_QR_' . md5($teacher_id . microtime(true));
                $uIns = $pdo->prepare("INSERT IGNORE INTO users (identifier, name, email, password, role, qr_token) VALUES (?, ?, ?, ?, 'professor', ?)");
                $uIns->execute([$teacher_id, $full_name, $email, $hashedPass, $qrToken]);

                $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $uStmt->execute([$email]);
                $uId = $uStmt->fetchColumn() ?: null;

                $sStmt = $pdo->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND subject_name = ? LIMIT 1");
                $sStmt->execute([$gId, $subject_name]);
                $sId = $sStmt->fetchColumn() ?: null;

                $ins = $pdo->prepare("
                    INSERT INTO teachers (user_id, teacher_id, full_name, grade_level_id, subject_id, grade_level_name, subject_name, email, contact_number, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$uId, $teacher_id, $full_name, $gId, $sId, $grade_level_name, $subject_name, $email, $contact_number, $status]);
                $newTId = (int)$pdo->lastInsertId();

                $pdo->prepare("INSERT INTO teacher_assignments (teacher_id, grade_level_id, subject_id, subject_name, section_name) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$newTId, $gId, $sId, $subject_name, $grade_level_name . ' - Section A']);

                $pdo->prepare("INSERT IGNORE INTO teacher_attendance (teacher_id, subject_name, grade_level_name, date, time_in, time_out, status) VALUES (?, ?, ?, ?, '07:50:00', '16:30:00', 'Present')")
                    ->execute([$newTId, $subject_name, $grade_level_name, $today]);

                $success = "Teacher '$full_name' added and assigned to $grade_level_name ($subject_name) successfully!";
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// 2. Handle Edit Teacher & Assign Grade Level / Subject (Fallback POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_teacher') {
    $id               = intval($_POST['id'] ?? 0);
    $teacher_id       = trim($_POST['teacher_id'] ?? '');
    $full_name        = trim($_POST['full_name'] ?? '');
    $grade_level_name = trim($_POST['grade_level_name'] ?? 'Grade 7');
    $subject_name     = trim($_POST['subject_name'] ?? 'English');
    $email            = trim($_POST['email'] ?? '');
    $contact_number   = trim($_POST['contact_number'] ?? '');
    $status           = trim($_POST['status'] ?? 'Active');

    if ($id <= 0 || $teacher_id === '' || $full_name === '' || $email === '') {
        $error = 'Please fill in all required teacher fields.';
    } else {
        try {
            $gStmt = $pdo->prepare("SELECT id FROM grade_levels WHERE grade_name = ? LIMIT 1");
            $gStmt->execute([$grade_level_name]);
            $gId = $gStmt->fetchColumn() ?: 1;

            $sStmt = $pdo->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND subject_name = ? LIMIT 1");
            $sStmt->execute([$gId, $subject_name]);
            $sId = $sStmt->fetchColumn() ?: null;

            $upd = $pdo->prepare("
                UPDATE teachers 
                SET teacher_id = ?, full_name = ?, grade_level_id = ?, subject_id = ?, grade_level_name = ?, subject_name = ?, email = ?, contact_number = ?, status = ?
                WHERE id = ?
            ");
            $upd->execute([$teacher_id, $full_name, $gId, $sId, $grade_level_name, $subject_name, $email, $contact_number, $status, $id]);

            $pdo->prepare("UPDATE teacher_attendance SET subject_name = ?, grade_level_name = ? WHERE teacher_id = ? AND date = ?")
                ->execute([$subject_name, $grade_level_name, $id, $today]);

            $success = "Teacher '$full_name' updated successfully!";
        } catch (PDOException $e) {
            $error = 'Error updating teacher: ' . $e->getMessage();
        }
    }
}

// 3. Handle Record / Update Teacher Attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_teacher_attendance') {
    $tId       = intval($_POST['teacher_id'] ?? 0);
    $attDate   = trim($_POST['att_date'] ?? $today);
    $timeIn    = !empty($_POST['time_in']) ? $_POST['time_in'] : null;
    $timeOut   = !empty($_POST['time_out']) ? $_POST['time_out'] : null;
    $attStatus = trim($_POST['att_status'] ?? 'Present');

    if ($tId > 0) {
        $tRowStmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
        $tRowStmt->execute([$tId]);
        $tRow = $tRowStmt->fetch();

        if ($tRow) {
            if ($attStatus === 'Absent') {
                $timeIn = null;
                $timeOut = null;
            } elseif (!$timeIn) {
                $timeIn = ($attStatus === 'Late') ? '08:20:00' : '07:50:00';
            }

            $upAtt = $pdo->prepare("
                INSERT INTO teacher_attendance (teacher_id, subject_name, grade_level_name, date, time_in, time_out, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    time_in = VALUES(time_in),
                    time_out = VALUES(time_out),
                    status = VALUES(status),
                    subject_name = VALUES(subject_name),
                    grade_level_name = VALUES(grade_level_name)
            ");
            $upAtt->execute([$tId, $tRow['subject_name'], $tRow['grade_level_name'], $attDate, $timeIn, $timeOut, $attStatus]);
            $success = "Attendance for {$tRow['full_name']} on $attDate marked as $attStatus.";
        }
    }
}

// 4. Handle Delete Teacher
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    if ($delId > 0) {
        $pdo->prepare("DELETE FROM teachers WHERE id = ?")->execute([$delId]);
        $success = 'Teacher record deleted.';
    }
}

// Filters for Teacher Directory
$searchQuery   = trim($_GET['search'] ?? '');
$gradeFilter   = trim($_GET['grade_level'] ?? '');
$subjectFilter = trim($_GET['subject'] ?? '');
$attDateFilter = trim($_GET['att_date'] ?? $today);

$sql = "
    SELECT t.*,
           ta.time_in AS today_time_in,
           ta.time_out AS today_time_out,
           ta.status AS today_att_status
    FROM teachers t
    LEFT JOIN teacher_attendance ta ON ta.teacher_id = t.id AND ta.date = :att_date
    WHERE 1=1
";
$params = [':att_date' => $attDateFilter];

if ($searchQuery !== '') {
    $sql .= " AND (t.teacher_id LIKE :q1 OR t.full_name LIKE :q2 OR t.email LIKE :q3 OR t.contact_number LIKE :q4)";
    $params[':q1'] = "%$searchQuery%";
    $params[':q2'] = "%$searchQuery%";
    $params[':q3'] = "%$searchQuery%";
    $params[':q4'] = "%$searchQuery%";
}
if ($gradeFilter !== '') {
    $sql .= " AND t.grade_level_name = :grade";
    $params[':grade'] = $gradeFilter;
}
if ($subjectFilter !== '') {
    $sql .= " AND t.subject_name = :subject";
    $params[':subject'] = $subjectFilter;
}

$sql .= " ORDER BY t.teacher_id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teachers = $stmt->fetchAll();

// Edit Teacher Request
$editingTeacher = null;
if (isset($_GET['edit'])) {
    $eStmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
    $eStmt->execute([intval($_GET['edit'])]);
    $editingTeacher = $eStmt->fetch();
}

// View Teacher Details & Attendance History Request
$historyTeacher = null;
$teacherHistoryRecords = [];
$historyFilterPeriod = trim($_GET['period'] ?? 'month');
$historyCustomStart  = trim($_GET['h_start'] ?? date('Y-m-01'));
$historyCustomEnd    = trim($_GET['h_end'] ?? $today);

if (isset($_GET['view_history'])) {
    $hId = intval($_GET['view_history']);
    $hTStmt = $pdo->prepare("SELECT * FROM teachers WHERE id = ?");
    $hTStmt->execute([$hId]);
    $historyTeacher = $hTStmt->fetch();

    if ($historyTeacher) {
        if ($historyFilterPeriod === 'today') {
            $hStart = $today;
            $hEnd   = $today;
        } elseif ($historyFilterPeriod === 'week') {
            $hStart = date('Y-m-d', strtotime('monday this week'));
            $hEnd   = $today;
        } elseif ($historyFilterPeriod === 'custom') {
            $hStart = $historyCustomStart ?: date('Y-m-01');
            $hEnd   = $historyCustomEnd ?: $today;
        } else {
            $hStart = date('Y-m-01');
            $hEnd   = date('Y-m-t');
        }

        $hLogsStmt = $pdo->prepare("
            SELECT * FROM teacher_attendance
            WHERE teacher_id = ? AND date BETWEEN ? AND ?
            ORDER BY date DESC
        ");
        $hLogsStmt->execute([$hId, $hStart, $hEnd]);
        $teacherHistoryRecords = $hLogsStmt->fetchAll();
    }
}

// Summary Statistics
$totalTeachersCount = (int)$pdo->query("SELECT COUNT(*) FROM teachers")->fetchColumn();
$presentCount = 0;
$lateCount    = 0;
$absentCount  = 0;
foreach ($teachers as $t) {
    $st = $t['today_att_status'] ?? 'Present';
    if ($st === 'Present') $presentCount++;
    elseif ($st === 'Late') $lateCount++;
    elseif ($st === 'Absent') $absentCount++;
}

$nextTeacherCode = 'TCH-' . date('Y') . '-' . str_pad($totalTeachersCount + 1, 3, '0', STR_PAD_LEFT);
$showAddForm = isset($_GET['action']) && $_GET['action'] === 'add';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teachers &amp; Attendance Monitoring - Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 38, 32, 0.45);
            backdrop-filter: blur(3px);
            display: none !important;
            align-items: center;
            justify-content: center;
            z-index: 1200;
            padding: 20px;
        }
        .modal-backdrop.open {
            display: flex !important;
        }
        .modal-dialog {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--line);
            box-shadow: 0 24px 56px rgba(10, 42, 34, 0.22);
            width: 100%;
            max-width: 580px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            margin-bottom: 18px;
            border-bottom: 1px solid var(--line);
        }
        .modal-title {
            font-size: 1.12rem;
            font-weight: 800;
            color: var(--ink);
            display: flex;
            align-items: center;
        }
        .modal-close {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #f6faf8;
            color: var(--ink-muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .modal-close:hover {
            background: #fde8e8;
            color: #dc3545;
            border-color: #f5c2c7;
        }
        .action-dropdown-wrapper {
            position: relative;
            display: inline-block;
        }
        .action-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            min-width: 175px;
            background: #ffffff;
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(15, 45, 38, 0.15);
            padding: 6px;
            z-index: 950;
            display: none !important;
        }
        .action-dropdown-menu.show {
            display: block !important;
        }
        .action-dropdown-item {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border: none;
            background: transparent;
            color: var(--ink);
            font-size: 0.84rem;
            font-weight: 600;
            text-align: left;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.12s ease;
        }
        .action-dropdown-item:hover {
            background: #f0f6f4;
        }
        .action-dropdown-item.danger {
            color: #dc3545;
        }
        .action-dropdown-item.danger:hover {
            background: #fde8e8;
        }
        .action-dropdown-divider {
            height: 1px;
            background: var(--line);
            margin: 5px 0;
        }
    </style>
</head>
<body>

<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo"><i class="fa-solid fa-graduation-cap"></i></div>
            <div class="sidebar-brand-text">
                <span>Global Reciprocal Colleges</span>
                <small>Attendance System</small>
            </div>
        </div>

        <div class="sidebar-section-label">Workspace</div>
        <nav class="sidebar-nav">
            <a class="nav-item" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
            <a class="nav-item" href="students.php"><i class="fa-solid fa-user-group"></i> Students</a>
            <a class="nav-item active" href="teachers.php"><i class="fa-solid fa-chalkboard-user"></i> Teachers</a>
            <a class="nav-item" href="attendance.php"><i class="fa-solid fa-clipboard-check"></i> Attendance</a>
            <a class="nav-item" href="reports.php"><i class="fa-solid fa-chart-line"></i> Reports</a>
        </nav>

        <div class="sidebar-footer">
            <div class="academic-box">
                <span class="label">Academic year</span>
                <strong>2026-2027</strong>
                <div style="margin-top:10px;color:rgba(255,255,255,0.72);font-size:0.82rem;">Term 1 • Week 7</div>
            </div>
            <div class="sidebar-help"><i class="fa-solid fa-circle-question"></i> Help &amp; support</div>
        </div>
    </aside>

    <main class="main-panel">
        <header class="topbar">
            <div class="topbar-title">Teachers Management &amp; Attendance Monitoring</div>
            <div class="topbar-tools">
                <button class="icon-btn" aria-label="Notifications"><i class="fa-regular fa-bell"></i></button>
                <a class="logout-btn" href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                <div class="user-mini">
                    <div class="avatar">EM</div>
                    <div class="meta">
                        <span><?= htmlspecialchars($user['name']) ?></span>
                        <small>Administrator</small>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrap">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Teachers</h1>
                    <div class="page-subtitle">Manage faculty profiles, grade &amp; subject assignments, and daily teacher attendance monitoring.</div>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <button class="primary-btn" type="button" onclick="toggleAddTeacherPanel()">
                        <i class="fa-solid fa-user-plus"></i> Add Teacher
                    </button>
                </div>
            </div>

            <!-- Dynamic AJAX Feedback Alert -->
            <div id="ajaxAlertBox" class="alert" style="display:none;"></div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation\"></i><span><?= htmlspecialchars($error) ?></span></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><span><?= htmlspecialchars($success) ?></span></div>
            <?php endif; ?>

            <!-- Teacher Summary Metric Cards -->
            <div class="metrics-grid">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                    <div>
                        <div class="metric-number" id="totalTeachersMetric"><?= count($teachers) ?></div>
                        <div class="metric-meta">Total Teachers</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-green"><i class="fa-solid fa-user-check"></i></div>
                    <div>
                        <div class="metric-number" id="presentTeachersMetric"><?= $presentCount ?></div>
                        <div class="metric-meta">Present Today</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-amber"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <div class="metric-number" id="lateTeachersMetric"><?= $lateCount ?></div>
                        <div class="metric-meta">Late Today</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-red"><i class="fa-solid fa-user-xmark"></i></div>
                    <div>
                        <div class="metric-number" id="absentTeachersMetric"><?= $absentCount ?></div>
                        <div class="metric-meta">Absent Today</div>
                    </div>
                </div>
            </div>

            <!-- Add / Edit Teacher Form Panel -->
            <section id="teacherFormPanel" class="table-panel" style="padding:22px;margin-bottom:22px;" <?= ($showAddForm || $editingTeacher) ? '' : 'hidden' ?>>
                <div class="panel-header">
                    <div class="panel-title">
                        <?php if ($editingTeacher): ?>
                            <i class="fa-solid fa-user-pen"></i> Edit Teacher &amp; Grade/Subject Assignment (<?= htmlspecialchars($editingTeacher['full_name']) ?>)
                        <?php else: ?>
                            <i class="fa-solid fa-user-plus"></i> Add New Teacher &amp; Assign Grade/Subject
                        <?php endif; ?>
                    </div>
                    <?php if ($editingTeacher): ?>
                        <a href="teachers.php" class="secondary-btn" style="padding:0.4rem 0.8rem;font-size:0.82rem;">Cancel Edit</a>
                    <?php endif; ?>
                </div>

                <form action="teachers.php" method="POST" class="grid-3">
                    <input type="hidden" name="action" value="<?= $editingTeacher ? 'edit_teacher' : 'add_teacher' ?>">
                    <?php if ($editingTeacher): ?>
                        <input type="hidden" name="id" value="<?= (int)$editingTeacher['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Teacher ID *</label>
                        <input type="text" name="teacher_id" class="form-control" required
                               value="<?= htmlspecialchars($editingTeacher['teacher_id'] ?? $nextTeacherCode) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Mr. John Angac" required
                               value="<?= htmlspecialchars($editingTeacher['full_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Assign Grade Level *</label>
                        <select name="grade_level_name" class="form-control" required>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= htmlspecialchars($gl['grade_name']) ?>"
                                    <?= (($editingTeacher['grade_level_name'] ?? '') === $gl['grade_name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gl['grade_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Assign Subject *</label>
                        <select name="subject_name" class="form-control" required>
                            <?php foreach ($coreSubjectsList as $subItem): ?>
                                <option value="<?= htmlspecialchars($subItem) ?>"
                                    <?= (($editingTeacher['subject_name'] ?? '') === $subItem) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subItem) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address *</label>
                        <input type="email" name="email" class="form-control" placeholder="teacher@school.edu.ph" required
                               value="<?= htmlspecialchars($editingTeacher['email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contact Number *</label>
                        <input type="text" name="contact_number" class="form-control" placeholder="0917-XXX-XXXX" required
                               value="<?= htmlspecialchars($editingTeacher['contact_number'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Employment Status</label>
                        <select name="status" class="form-control">
                            <?php foreach (['Active', 'Inactive', 'On Leave'] as $stOpt): ?>
                                <option value="<?= $stOpt ?>" <?= (($editingTeacher['status'] ?? 'Active') === $stOpt) ? 'selected' : '' ?>>
                                    <?= $stOpt ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!$editingTeacher): ?>
                        <div class="form-group">
                            <label class="form-label">Portal Password</label>
                            <input type="password" name="password" class="form-control" value="password123" placeholder="Default: password123">
                        </div>
                    <?php endif; ?>

                    <div style="display:flex;gap:10px;align-items:flex-end;margin-bottom:16px;">
                        <button type="submit" class="primary-btn">
                            <i class="fa-solid fa-floppy-disk"></i> <?= $editingTeacher ? 'Save Changes' : 'Create Teacher' ?>
                        </button>
                        <?php if (!$editingTeacher): ?>
                            <button type="button" class="secondary-btn" onclick="toggleAddTeacherPanel()">Cancel</button>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <!-- Teacher Details & Attendance History Drawer -->
            <?php if ($historyTeacher): ?>
                <section id="teacher-history-section" class="table-panel" style="padding:22px;margin-bottom:24px;border:2px solid var(--primary);">
                    <div class="panel-header" style="flex-wrap:wrap;">
                        <div>
                            <div class="panel-title" style="font-size:1.25rem;">
                                <i class="fa-solid fa-id-card-clip" style="color:var(--primary);"></i>
                                <?= htmlspecialchars($historyTeacher['full_name']) ?> — Teacher Details &amp; Attendance History
                            </div>
                            <div style="font-size:0.88rem;color:var(--ink-muted);margin-top:4px;">
                                <strong>ID:</strong> <?= htmlspecialchars($historyTeacher['teacher_id']) ?> &nbsp;|&nbsp;
                                <strong>Assigned Grade:</strong> <?= htmlspecialchars($historyTeacher['grade_level_name']) ?> &nbsp;|&nbsp;
                                <strong>Subject:</strong> <?= htmlspecialchars($historyTeacher['subject_name']) ?> &nbsp;|&nbsp;
                                <strong>Email:</strong> <?= htmlspecialchars($historyTeacher['email']) ?> &nbsp;|&nbsp;
                                <strong>Contact:</strong> <?= htmlspecialchars($historyTeacher['contact_number']) ?>
                            </div>
                        </div>
                        <a href="teachers.php" class="secondary-btn" style="padding:0.45rem 0.9rem;font-size:0.84rem;">
                            <i class="fa-solid fa-xmark"></i> Close History
                        </a>
                    </div>

                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin:14px 0 18px;">
                        <div class="report-tabs" style="margin-bottom:0;">
                            <a href="teachers.php?view_history=<?= $historyTeacher['id'] ?>&period=today#teacher-history-section"
                               class="report-tab <?= $historyFilterPeriod === 'today' ? 'active' : '' ?>">Today</a>
                            <a href="teachers.php?view_history=<?= $historyTeacher['id'] ?>&period=week#teacher-history-section"
                               class="report-tab <?= $historyFilterPeriod === 'week' ? 'active' : '' ?>">This Week</a>
                            <a href="teachers.php?view_history=<?= $historyTeacher['id'] ?>&period=month#teacher-history-section"
                               class="report-tab <?= $historyFilterPeriod === 'month' ? 'active' : '' ?>">This Month</a>
                        </div>

                        <form method="GET" action="teachers.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <input type="hidden" name="view_history" value="<?= $historyTeacher['id'] ?>">
                            <input type="hidden" name="period" value="custom">
                            <input type="date" name="h_start" class="form-control" style="width:155px;height:38px;" value="<?= htmlspecialchars($historyCustomStart) ?>">
                            <span style="color:var(--ink-muted);font-size:0.85rem;">to</span>
                            <input type="date" name="h_end" class="form-control" style="width:155px;height:38px;" value="<?= htmlspecialchars($historyCustomEnd) ?>">
                            <button type="submit" class="primary-btn" style="padding:0.5rem 0.9rem;font-size:0.84rem;">
                                <i class="fa-solid fa-calendar-day"></i> Custom Date
                            </button>
                        </form>
                    </div>

                    <div class="table-wrap">
                        <table class="tight-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Grade &amp; Subject</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($teacherHistoryRecords)): ?>
                                    <tr>
                                        <td colspan="6" style="padding:22px;text-align:center;color:var(--ink-muted);">
                                            No attendance records found for this period.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($teacherHistoryRecords as $hRow):
                                        $stClass = strtolower($hRow['status']) === 'present' ? 'present' : (strtolower($hRow['status']) === 'late' ? 'late' : 'absent');
                                    ?>
                                        <tr>
                                            <td><strong><?= date('M d, Y', strtotime($hRow['date'])) ?></strong></td>
                                            <td><?= date('l', strtotime($hRow['date'])) ?></td>
                                            <td><?= htmlspecialchars($hRow['grade_level_name']) ?> • <?= htmlspecialchars($hRow['subject_name']) ?></td>
                                            <td><?= !empty($hRow['time_in']) ? date('h:i A', strtotime($hRow['time_in'])) : '—' ?></td>
                                            <td><?= !empty($hRow['time_out']) ? date('h:i A', strtotime($hRow['time_out'])) : '—' ?></td>
                                            <td><span class="status-pill <?= $stClass ?>"><?= htmlspecialchars($hRow['status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <!-- Search & Filter Bar for Teachers Directory -->
            <div class="table-panel" style="padding:18px;margin-bottom:20px;">
                <form method="GET" action="teachers.php" style="display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr auto;gap:12px;align-items:flex-end;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Search Teacher</label>
                        <input type="text" name="search" class="form-control" placeholder="Search ID, Name, Email, Contact..."
                               value="<?= htmlspecialchars($searchQuery) ?>">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Filter Grade Level</label>
                        <select name="grade_level" class="form-control">
                            <option value="">All Grade Levels</option>
                            <?php foreach ($gradeLevels as $gl): ?>
                                <option value="<?= htmlspecialchars($gl['grade_name']) ?>" <?= $gradeFilter === $gl['grade_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($gl['grade_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Filter Subject</label>
                        <select name="subject" class="form-control">
                            <option value="">All Subjects</option>
                            <?php foreach ($coreSubjectsList as $sItem): ?>
                                <option value="<?= htmlspecialchars($sItem) ?>" <?= $subjectFilter === $sItem ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sItem) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Attendance Date</label>
                        <input type="date" name="att_date" class="form-control" value="<?= htmlspecialchars($attDateFilter) ?>">
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="primary-btn"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
                        <a href="teachers.php" class="secondary-btn">Reset</a>
                    </div>
                </form>
            </div>

            <!-- 3. TEACHERS DIRECTORY TABLE -->
            <div class="table-wrap" style="margin-bottom: 28px;">
                <div style="padding: 16px 18px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center;">
                    <div class="panel-title"><i class="fa-solid fa-chalkboard-user" style="color:var(--primary);"></i> Teachers Directory</div>
                    <span class="badge badge-primary"><?= count($teachers) ?> Teachers</span>
                </div>
                <table class="tight-table">
                    <thead>
                        <tr>
                            <th>Teacher ID</th>
                            <th>Full Name</th>
                            <th>Grade Level</th>
                            <th>Subject</th>
                            <th>Email</th>
                            <th>Contact Number</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)): ?>
                            <tr>
                                <td colspan="8" style="padding:24px;text-align:center;color:var(--ink-muted);">No teachers match your search criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teachers as $t):
                                $tId = (int)$t['id'];
                            ?>
                                <tr id="teacher-row-<?= $tId ?>"
                                    data-id="<?= $tId ?>"
                                    data-teacher-id="<?= htmlspecialchars($t['teacher_id'], ENT_QUOTES) ?>"
                                    data-name="<?= htmlspecialchars($t['full_name'], ENT_QUOTES) ?>"
                                    data-grade="<?= htmlspecialchars($t['grade_level_name'], ENT_QUOTES) ?>"
                                    data-subject="<?= htmlspecialchars($t['subject_name'], ENT_QUOTES) ?>"
                                    data-email="<?= htmlspecialchars($t['email'], ENT_QUOTES) ?>"
                                    data-contact="<?= htmlspecialchars($t['contact_number'], ENT_QUOTES) ?>"
                                    data-status="<?= htmlspecialchars($t['status'], ENT_QUOTES) ?>">
                                    <td class="col-teacher-id"><strong style="font-family:monospace;color:var(--primary-dark);"><?= htmlspecialchars($t['teacher_id']) ?></strong></td>
                                    <td class="col-name">
                                        <a href="teachers.php?view_history=<?= $tId ?>#teacher-history-section" style="font-weight:700;color:var(--ink);">
                                            <?= htmlspecialchars($t['full_name']) ?>
                                        </a>
                                    </td>
                                    <td class="col-grade"><span class="student-pill"><?= htmlspecialchars($t['grade_level_name']) ?></span></td>
                                    <td class="col-subject"><strong><?= htmlspecialchars($t['subject_name']) ?></strong></td>
                                    <td class="col-email"><?= htmlspecialchars($t['email']) ?></td>
                                    <td class="col-contact"><?= htmlspecialchars($t['contact_number']) ?></td>
                                    <td class="col-status">
                                        <?php if ($t['status'] === 'Active'): ?>
                                            <span class="status-pill present">Active</span>
                                        <?php elseif ($t['status'] === 'On Leave'): ?>
                                            <span class="status-pill late">On Leave</span>
                                        <?php else: ?>
                                            <span class="status-pill absent">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:8px;align-items:center;">
                                            <button type="button" class="btn btn-secondary btn-sm" title="Edit Teacher" onclick="openEditTeacherModal(<?= $tId ?>)">
                                                <i class="fa-regular fa-pen-to-square"></i>
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm" title="View Details & History" onclick="openViewTeacherModal(<?= $tId ?>)">
                                                <i class="fa-regular fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" title="Delete Teacher" onclick="deleteTeacherRow(<?= $tId ?>)">
                                                <i class="fa-solid fa-ellipsis"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- =========================================================================
     MODAL 1: EDIT TEACHER MODAL (Opened by Pencil Button)
     ========================================================================= -->
<div id="editTeacherModal" class="modal-backdrop" onclick="if(event.target === this) closeEditTeacherModal()">
    <div class="modal-dialog" style="max-width: 580px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-regular fa-pen-to-square" style="color:var(--primary);margin-right:8px;"></i> Edit Teacher Information</div>
            <button type="button" class="modal-close" onclick="closeEditTeacherModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="editTeacherForm" onsubmit="submitEditTeacherForm(event)">
            <input type="hidden" id="edit_t_id" name="id">
            <div class="grid-2" style="gap:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Teacher ID *</label>
                    <input type="text" id="edit_t_code" name="teacher_id" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Full Name *</label>
                    <input type="text" id="edit_t_name" name="full_name" class="form-control" required>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Grade Level *</label>
                    <select id="edit_t_grade" name="grade_level_name" class="form-control" required>
                        <?php foreach ($gradeLevels as $gl): ?>
                            <option value="<?= htmlspecialchars($gl['grade_name']) ?>"><?= htmlspecialchars($gl['grade_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Subject *</label>
                    <select id="edit_t_subject" name="subject_name" class="form-control" required>
                        <?php foreach ($coreSubjectsList as $sItem): ?>
                            <option value="<?= htmlspecialchars($sItem) ?>"><?= htmlspecialchars($sItem) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid-2" style="gap:14px;margin-top:14px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Email Address *</label>
                    <input type="email" id="edit_t_email" name="email" class="form-control" required>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Contact Number *</label>
                    <input type="text" id="edit_t_contact" name="contact_number" class="form-control" required>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;padding-top:14px;border-top:1px solid var(--line);">
                <button type="button" class="secondary-btn" onclick="closeEditTeacherModal()">Cancel</button>
                <button type="submit" id="editTeacherSubmitBtn" class="primary-btn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: VIEW TEACHER DETAILS & HISTORY MODAL (Opened by Eye Button)
     ========================================================================= -->
<div id="viewTeacherModal" class="modal-backdrop" onclick="if(event.target === this) closeViewTeacherModal()">
    <div class="modal-dialog" style="max-width: 680px;">
        <div class="modal-header">
            <div class="modal-title"><i class="fa-regular fa-eye" style="color:var(--primary);margin-right:8px;"></i> Teacher Profile &amp; Attendance History</div>
            <button type="button" class="modal-close" onclick="closeViewTeacherModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="viewTeacherModalContent">
            <div style="padding:30px;text-align:center;color:var(--ink-muted);">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading teacher details...
            </div>
        </div>
    </div>
</div>

<script>
function toggleAddTeacherPanel() {
    const panel = document.getElementById('teacherFormPanel');
    panel.hidden = !panel.hidden;
    if (!panel.hidden) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function showTeacherAlert(message, type = 'success') {
    const box = document.getElementById('ajaxAlertBox');
    box.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-danger');
    box.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i><span>${message}</span>`;
    box.style.display = 'flex';
    setTimeout(() => { box.style.display = 'none'; }, 4000);
}

// INTERACTIVE ATTENDANCE STATUS BADGE DROPDOWN & AJAX HANDLER (update_attendance.php)
function toggleAttStatusMenu(event, id) {
    event.stopPropagation();
    const menu = document.getElementById('att-status-menu-' + id);
    if (!menu) return;
    const isOpen = menu.classList.contains('show');
    closeAllTeacherDropdowns();
    if (!isOpen) menu.classList.add('show');
}

async function quickUpdateTeacherStatus(teacherId, newStatus) {
    closeAllTeacherDropdowns();
    const timeInInput  = document.getElementById('att-input-in-' + teacherId);
    const timeOutInput = document.getElementById('att-input-out-' + teacherId);

    try {
        const res = await fetch('update_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                teacher_id: teacherId,
                status: newStatus,
                date: '<?= htmlspecialchars($attDateFilter) ?>',
                time_in: timeInInput ? timeInInput.value : '',
                time_out: timeOutInput ? timeOutInput.value : ''
            })
        });
        const data = await res.json();
        if (data.status === 'success') {
            const rec = data.record;
            const sum = data.summary;
            const cls = rec.status === 'Present' ? 'present' : (rec.status === 'Late' ? 'late' : 'absent');
            const icon = cls === 'present' ? 'fa-solid fa-user-check' : (cls === 'late' ? 'fa-solid fa-briefcase' : 'fa-solid fa-user-xmark');

            const badge = document.getElementById('att-badge-' + teacherId);
            const badgeIcon = document.getElementById('att-badge-icon-' + teacherId);
            const badgeText = document.getElementById('att-badge-text-' + teacherId);
            if (badge) badge.className = 'status-pill ' + cls;
            if (badgeIcon) badgeIcon.className = icon;
            if (badgeText) badgeText.textContent = rec.status;

            const sel = document.getElementById('att-select-' + teacherId);
            if (sel) sel.value = rec.status;

            const inCell = document.getElementById('att-time-in-cell-' + teacherId);
            const outCell = document.getElementById('att-time-out-cell-' + teacherId);
            if (inCell) inCell.textContent = rec.formatted_in;
            if (outCell) outCell.textContent = rec.formatted_out;

            // Update top summary metric cards
            if (document.getElementById('totalTeachersMetric')) document.getElementById('totalTeachersMetric').textContent = sum.total_teachers;
            if (document.getElementById('presentTeachersMetric')) document.getElementById('presentTeachersMetric').textContent = sum.present_today;
            if (document.getElementById('lateTeachersMetric')) document.getElementById('lateTeachersMetric').textContent = sum.late_today;
            if (document.getElementById('absentTeachersMetric')) document.getElementById('absentTeachersMetric').textContent = sum.absent_today;

            showTeacherAlert(data.message, 'success');
        } else {
            showTeacherAlert(data.message || 'Could not update status.', 'error');
        }
    } catch (err) {
        showTeacherAlert('Network error while updating attendance status.', 'error');
    }
}

// 1. EDIT MODAL LOGIC
function openEditTeacherModal(id) {
    closeAllTeacherDropdowns();
    const row = document.getElementById('teacher-row-' + id);
    if (!row) return;

    document.getElementById('edit_t_id').value = id;
    document.getElementById('edit_t_code').value = row.dataset.teacherId || '';
    document.getElementById('edit_t_name').value = row.dataset.name || '';
    document.getElementById('edit_t_grade').value = row.dataset.grade || 'Grade 7';
    document.getElementById('edit_t_subject').value = row.dataset.subject || 'English';
    document.getElementById('edit_t_email').value = row.dataset.email || '';
    document.getElementById('edit_t_contact').value = row.dataset.contact || '';

    document.getElementById('editTeacherModal').classList.add('open');
}

function closeEditTeacherModal() {
    document.getElementById('editTeacherModal').classList.remove('open');
}

async function submitEditTeacherForm(e) {
    e.preventDefault();
    const btn = document.getElementById('editTeacherSubmitBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

    const payload = {
        id: document.getElementById('edit_t_id').value,
        teacher_id: document.getElementById('edit_t_code').value.trim(),
        full_name: document.getElementById('edit_t_name').value.trim(),
        grade_level_name: document.getElementById('edit_t_grade').value,
        subject_name: document.getElementById('edit_t_subject').value,
        email: document.getElementById('edit_t_email').value.trim(),
        contact_number: document.getElementById('edit_t_contact').value.trim()
    };

    try {
        const res = await fetch('teachers.php?ajax_action=update_teacher', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.status === 'success') {
            const t = data.teacher;
            const row = document.getElementById('teacher-row-' + t.id);
            if (row) {
                row.dataset.teacherId = t.teacher_id;
                row.dataset.name = t.full_name;
                row.dataset.grade = t.grade_level_name;
                row.dataset.subject = t.subject_name;
                row.dataset.email = t.email;
                row.dataset.contact = t.contact_number;
                row.dataset.status = t.status;

                row.querySelector('.col-teacher-id strong').textContent = t.teacher_id;
                row.querySelector('.col-name a').textContent = t.full_name;
                row.querySelector('.col-grade .student-pill').textContent = t.grade_level_name;
                row.querySelector('.col-subject strong').textContent = t.subject_name;
                row.querySelector('.col-email').textContent = t.email;
                row.querySelector('.col-contact').textContent = t.contact_number;

                const pillClass = t.status === 'Active' ? 'present' : (t.status === 'On Leave' ? 'late' : 'absent');
                row.querySelector('.col-status').innerHTML = `<span class="status-pill ${pillClass}">${t.status}</span>`;
            }
            closeEditTeacherModal();
            showTeacherAlert(data.message, 'success');
        } else {
            showTeacherAlert(data.message || 'Failed to update teacher.', 'error');
        }
    } catch (err) {
        showTeacherAlert('Network error occurred while updating teacher.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

// 2. VIEW MODAL LOGIC
async function openViewTeacherModal(id) {
    closeAllTeacherDropdowns();
    const modal = document.getElementById('viewTeacherModal');
    const content = document.getElementById('viewTeacherModalContent');
    modal.classList.add('open');
    content.innerHTML = '<div style="padding:30px;text-align:center;color:var(--ink-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Loading teacher details...</div>';

    try {
        const res = await fetch('teachers.php?ajax_action=get_teacher&id=' + encodeURIComponent(id));
        const data = await res.json();

        if (data.status === 'success') {
            const t = data.teacher;
            const history = data.history || [];

            let historyRows = '';
            if (history.length === 0) {
                historyRows = '<tr><td colspan="4" style="text-align:center;padding:18px;color:var(--ink-muted);">No attendance logs recorded yet.</td></tr>';
            } else {
                historyRows = history.map(h => {
                    const badge = h.status === 'Present' ? 'present' : (h.status === 'Late' ? 'late' : 'absent');
                    return `
                        <tr>
                            <td><strong>${h.date}</strong></td>
                            <td>${h.grade_level_name} • ${h.subject_name}</td>
                            <td>${h.time_in || '—'} / ${h.time_out || '—'}</td>
                            <td><span class="status-pill ${badge}">${h.status}</span></td>
                        </tr>
                    `;
                }).join('');
            }

            content.innerHTML = `
                <div style="padding:16px;background:var(--surface-alt);border-radius:14px;border:1px solid var(--line);margin-bottom:16px;">
                    <div style="font-size:1.15rem;font-weight:800;color:var(--ink);">${t.full_name}</div>
                    <div style="font-size:0.86rem;color:var(--ink-muted);margin-top:4px;">
                        <strong>ID:</strong> ${t.teacher_id} &nbsp;•&nbsp;
                        <strong>Grade:</strong> ${t.grade_level_name} &nbsp;•&nbsp;
                        <strong>Subject:</strong> ${t.subject_name}
                    </div>
                    <div style="font-size:0.84rem;color:var(--ink-muted);margin-top:4px;">
                        <strong>Email:</strong> ${t.email} &nbsp;•&nbsp;
                        <strong>Contact:</strong> ${t.contact_number} &nbsp;•&nbsp;
                        <strong>Status:</strong> ${t.status}
                    </div>
                </div>
                <div style="font-weight:800;font-size:0.94rem;margin-bottom:10px;color:var(--ink);">
                    <i class="fa-solid fa-clock-rotate-left" style="color:var(--primary);margin-right:6px;"></i> Recent Teacher Attendance History
                </div>
                <div class="table-wrap" style="max-height:260px;overflow-y:auto;">
                    <table class="tight-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Grade & Subject</th>
                                <th>Time In / Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>${historyRows}</tbody>
                    </table>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:18px;padding-top:14px;border-top:1px solid var(--line);">
                    <a href="teachers.php?view_history=${t.id}#teacher-history-section" class="secondary-btn">
                        <i class="fa-solid fa-calendar-days"></i> Open Full History Filter View
                    </a>
                    <div style="display:flex;gap:8px;">
                        <button type="button" class="secondary-btn" onclick="closeViewTeacherModal(); openEditTeacherModal(${t.id});">
                            <i class="fa-regular fa-pen-to-square"></i> Edit Teacher
                        </button>
                        <button type="button" class="primary-btn" onclick="closeViewTeacherModal()">Close</button>
                    </div>
                </div>
            `;
        } else {
            content.innerHTML = `<div class="alert alert-danger">${data.message || 'Could not load teacher.'}</div>`;
        }
    } catch (err) {
        content.innerHTML = '<div class="alert alert-danger">Network error while loading teacher details.</div>';
    }
}

function closeViewTeacherModal() {
    document.getElementById('viewTeacherModal').classList.remove('open');
}

// 3. MORE OPTIONS (...) DROPDOWN LOGIC
function closeAllTeacherDropdowns() {
    document.querySelectorAll('.action-dropdown-menu.show').forEach(menu => menu.classList.remove('show'));
}

function toggleTeacherActionDropdown(event, id) {
    event.stopPropagation();
    const menu = document.getElementById('teacher-dropdown-' + id);
    if (!menu) return;
    const isOpen = menu.classList.contains('show');
    closeAllTeacherDropdowns();
    if (!isOpen) menu.classList.add('show');
}

document.addEventListener('click', () => closeAllTeacherDropdowns());

function printTeacherRow(id) {
    closeAllTeacherDropdowns();
    const row = document.getElementById('teacher-row-' + id);
    if (!row) return;

    const win = window.open('', '_blank', 'width=680,height=520');
    win.document.write(`
        <html>
        <head>
            <title>Teacher Profile - ${row.dataset.name}</title>
            <style>
                body { font-family: 'Segoe UI', sans-serif; padding: 32px; color: #17312c; }
                h2 { color: #0f5a4e; border-bottom: 2px solid #0f5a4e; padding-bottom: 10px; }
                .row { margin: 12px 0; font-size: 15px; }
                .label { font-weight: bold; width: 150px; display: inline-block; color: #5b726c; }
            </style>
        </head>
        <body>
            <h2>Global Reciprocal Colleges — Teacher Record</h2>
            <div class="row"><span class="label">Teacher ID:</span> ${row.dataset.teacherId}</div>
            <div class="row"><span class="label">Full Name:</span> ${row.dataset.name}</div>
            <div class="row"><span class="label">Grade Level:</span> ${row.dataset.grade}</div>
            <div class="row"><span class="label">Subject:</span> ${row.dataset.subject}</div>
            <div class="row"><span class="label">Email:</span> ${row.dataset.email}</div>
            <div class="row"><span class="label">Contact Number:</span> ${row.dataset.contact}</div>
            <div class="row"><span class="label">Status:</span> ${row.dataset.status}</div>
            <script>window.onload = function(){ window.print(); window.close(); };<\/script>
        </body>
        </html>
    `);
    win.document.close();
}

function downloadTeacherRow(id) {
    closeAllTeacherDropdowns();
    const row = document.getElementById('teacher-row-' + id);
    if (!row) return;

    const csv = [
        ['Teacher ID', 'Full Name', 'Grade Level', 'Subject', 'Email', 'Contact Number', 'Status'],
        [row.dataset.teacherId, row.dataset.name, row.dataset.grade, row.dataset.subject, row.dataset.email, row.dataset.contact, row.dataset.status]
    ].map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${row.dataset.teacherId || 'teacher'}_details.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

async function deleteTeacherRow(id) {
    closeAllTeacherDropdowns();
    const row = document.getElementById('teacher-row-' + id);
    const name = row ? row.dataset.name : 'this teacher';

    if (!confirm(`Are you sure you want to delete "${name}" from the database?`)) return;

    try {
        const res = await fetch('teachers.php?ajax_action=delete_teacher', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (data.status === 'success') {
            if (row) row.remove();
            showTeacherAlert(data.message, 'success');
        } else {
            showTeacherAlert(data.message || 'Could not delete teacher.', 'error');
        }
    } catch (err) {
        showTeacherAlert('Network error occurred while deleting teacher.', 'error');
    }
}
</script>

</body>
</html>


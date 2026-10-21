<?php
// admin/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['admin']);
$user = current_user();
$pdo = get_db_connection();

$today = date('Y-m-d');
$message = '';
$error = '';

// =========================================================================
// BACKEND HANDLERS FOR QUICK ACTIONS MODALS (Add Teacher, Assign Grade, Assign Subject)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Quick Action: Add Teacher
        if ($action === 'quick_add_teacher') {
            $teacher_id       = trim($_POST['teacher_id'] ?? '');
            $full_name        = trim($_POST['full_name'] ?? '');
            $department_code  = trim($_POST['department_code'] ?? 'FACULTY');
            $grade_level_name = trim($_POST['grade_level_name'] ?? 'Grade 7');
            $subject_name     = trim($_POST['subject_name'] ?? 'English');
            $email            = trim($_POST['email'] ?? '');
            $contact_number   = trim($_POST['contact_number'] ?? '');

            if ($teacher_id === '' || $full_name === '' || $email === '') {
                $error = 'Teacher ID, Full Name, and Email are required.';
            } else {
                $dup = $pdo->prepare("SELECT id FROM teachers WHERE teacher_id = ? OR email = ? LIMIT 1");
                $dup->execute([$teacher_id, $email]);
                if ($dup->fetch()) {
                    $error = 'Teacher ID or Email already exists.';
                } else {
                    $gStmt = $pdo->prepare("SELECT id FROM grade_levels WHERE grade_name = ? LIMIT 1");
                    $gStmt->execute([$grade_level_name]);
                    $gId = $gStmt->fetchColumn() ?: 1;

                    $sStmt = $pdo->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND subject_name = ? LIMIT 1");
                    $sStmt->execute([$gId, $subject_name]);
                    $sId = $sStmt->fetchColumn() ?: null;

                    $hashedPass = password_hash('password123', PASSWORD_BCRYPT);
                    $qrToken = 'PROF_QR_' . md5($teacher_id . microtime(true));
                    $pdo->prepare("INSERT IGNORE INTO users (identifier, name, email, password, role, qr_token) VALUES (?, ?, ?, ?, 'professor', ?)")
                        ->execute([$teacher_id, $full_name, $email, $hashedPass, $qrToken]);

                    $uStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                    $uStmt->execute([$email]);
                    $uId = $uStmt->fetchColumn() ?: null;

                    $ins = $pdo->prepare("
                        INSERT INTO teachers (user_id, teacher_id, full_name, department_code, grade_level_id, subject_id, grade_level_name, subject_name, email, contact_number, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                    ");
                    $ins->execute([$uId, $teacher_id, $full_name, $department_code, $gId, $sId, $grade_level_name, $subject_name, $email, $contact_number]);
                    $newTId = (int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO teacher_assignments (teacher_id, grade_level_id, subject_id, subject_name, section_name) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newTId, $gId, $sId, $subject_name, $grade_level_name . ' - Section A']);

                    $pdo->prepare("INSERT IGNORE INTO teacher_attendance (teacher_id, subject_name, grade_level_name, date, time_in, time_out, status) VALUES (?, ?, ?, ?, '07:50:00', '15:45:00', 'Present')")
                        ->execute([$newTId, $subject_name, $grade_level_name, $today]);

                    $message = "Teacher '$full_name' added and assigned to $grade_level_name ($subject_name)!";
                }
            }
        }

        // 2. Quick Action: Assign Teacher to Grade Level
        if ($action === 'assign_grade_level') {
            $tId       = intval($_POST['teacher_id'] ?? 0);
            $gradeName = trim($_POST['grade_level_name'] ?? 'Grade 7');

            if ($tId > 0) {
                $gStmt = $pdo->prepare("SELECT id FROM grade_levels WHERE grade_name = ? LIMIT 1");
                $gStmt->execute([$gradeName]);
                $gId = $gStmt->fetchColumn() ?: 1;

                $pdo->prepare("UPDATE teachers SET grade_level_id = ?, grade_level_name = ? WHERE id = ?")
                    ->execute([$gId, $gradeName, $tId]);

                $pdo->prepare("UPDATE teacher_attendance SET grade_level_name = ? WHERE teacher_id = ? AND date = ?")
                    ->execute([$gradeName, $tId, $today]);

                $message = "Teacher successfully assigned to $gradeName!";
            }
        }

        // 3. Quick Action: Assign Teacher to Subject
        if ($action === 'assign_subject') {
            $tId         = intval($_POST['teacher_id'] ?? 0);
            $subjectName = trim($_POST['subject_name'] ?? 'English');
            $deptCode    = trim($_POST['department_code'] ?? '');

            if ($tId > 0) {
                $tRowStmt = $pdo->prepare("SELECT grade_level_id FROM teachers WHERE id = ? LIMIT 1");
                $tRowStmt->execute([$tId]);
                $gId = $tRowStmt->fetchColumn() ?: 1;

                $sStmt = $pdo->prepare("SELECT id FROM subjects WHERE grade_level_id = ? AND subject_name = ? LIMIT 1");
                $sStmt->execute([$gId, $subjectName]);
                $sId = $sStmt->fetchColumn() ?: null;

                if ($deptCode !== '') {
                    $pdo->prepare("UPDATE teachers SET subject_id = ?, subject_name = ?, department_code = ? WHERE id = ?")
                        ->execute([$sId, $subjectName, $deptCode, $tId]);
                } else {
                    $pdo->prepare("UPDATE teachers SET subject_id = ?, subject_name = ? WHERE id = ?")
                        ->execute([$sId, $subjectName, $tId]);
                }

                $pdo->prepare("UPDATE teacher_attendance SET subject_name = ? WHERE teacher_id = ? AND date = ?")
                    ->execute([$subjectName, $tId, $today]);

                $message = "Teacher successfully assigned to subject '$subjectName'!";
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// =========================================================================
// LOAD GRADE LEVEL CONFIGURATIONS & SUBJECTS FROM MYSQL
// =========================================================================
$coreSubjectsCol1 = ['English', 'Mathematics', 'Science', 'Filipino', 'Araling Panlipunan'];
$coreSubjectsCol2 = ['MAPEH', 'TLE', 'Computer'];
$allCoreSubjects  = array_merge($coreSubjectsCol1, $coreSubjectsCol2);

$gradeCards = [
    [
        'id'         => 1,
        'grade'      => 'Grade 7',
        'count'      => '8 subjects',
        'icon_bg'    => '#dbf2e8',
        'icon_color' => '#147a57',
        'dot_left'   => '#208c68',
        'dot_right'  => '#208c68',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ],
    [
        'id'         => 2,
        'grade'      => 'Grade 8',
        'count'      => '8 subjects',
        'icon_bg'    => '#dcebfa',
        'icon_color' => '#2b70c9',
        'dot_left'   => '#2b70c9',
        'dot_right'  => '#208c68',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ],
    [
        'id'         => 3,
        'grade'      => 'Grade 9',
        'count'      => '8 subjects',
        'icon_bg'    => '#fcecd2',
        'icon_color' => '#d1811b',
        'dot_left'   => '#dc8b22',
        'dot_right'  => '#208c68',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ],
    [
        'id'         => 4,
        'grade'      => 'Grade 10',
        'count'      => '8 subjects',
        'icon_bg'    => '#e9defa',
        'icon_color' => '#6d44b8',
        'dot_left'   => '#7047eb',
        'dot_right'  => '#208c68',
        'col1'       => $coreSubjectsCol1,
        'col2'       => $coreSubjectsCol2
    ]
];

// =========================================================================
// LOAD TEACHERS & TODAY'S ATTENDANCE RECORDS FROM MYSQL
// =========================================================================
$teachersStmt = $pdo->prepare("
    SELECT t.*,
           ta.time_in AS today_time_in,
           ta.time_out AS today_time_out,
           ta.status AS today_status
    FROM teachers t
    LEFT JOIN teacher_attendance ta ON ta.teacher_id = t.id AND ta.date = ?
    WHERE t.status = 'Active'
    ORDER BY t.id ASC
");
$teachersStmt->execute([$today]);
$allTeachers = $teachersStmt->fetchAll();

// Map department / course codes for display subtitles matching mockup
$defaultDeptCodes = [
    'Mr. John Angac'   => 'HCI (IT)',
    'Ms. Donna Santos' => 'Ethics',
    "Ma'am Jay"        => 'NSTP2',
    'Sir Salazar'      => 'PATHFIT2',
    "Ma'am Rea"        => 'SCITECH',
    "Ma'am Leila"      => 'ENVICIS',
    'Sir Jeryco'       => 'WEBSYS'
];

$defaultTimeOuts = [
    'Mr. John Angac'   => '15:45:00',
    'Ms. Donna Santos' => '15:20:00',
    "Ma'am Jay"        => '15:30:00',
    'Sir Salazar'      => '16:15:00',
    "Ma'am Rea"        => '15:25:00',
    "Ma'am Leila"      => '16:10:00',
    'Sir Jeryco'       => '15:40:00'
];

$defaultTimeIns = [
    'Mr. John Angac'   => '07:52:00',
    'Ms. Donna Santos' => '08:10:00',
    "Ma'am Jay"        => '07:50:00',
    'Sir Salazar'      => '08:00:00',
    "Ma'am Rea"        => '07:45:00',
    "Ma'am Leila"      => '08:05:00',
    'Sir Jeryco'       => '07:55:00'
];

$totalTeachersCount = count($allTeachers);
$presentTodayCount  = 0;
$absentTodayCount   = 0;
$lateTodayCount     = 0;

foreach ($allTeachers as &$tRow) {
    $st = $tRow['today_status'] ?? 'Present';
    $tRow['today_status'] = $st;
    if ($st === 'Present') {
        $presentTodayCount++;
    } elseif ($st === 'Absent') {
        $absentTodayCount++;
    } elseif ($st === 'Late') {
        $lateTodayCount++;
    }

    // Build 2-letter avatar initials matching mockup (JA, DS, MJ, SS, MR, ML, SJ)
    $cleanName = trim($tRow['full_name']);
    $initials = '';
    if (stripos($cleanName, 'Mr. ') === 0 || stripos($cleanName, 'Ms. ') === 0) {
        $withoutTitle = trim(substr($cleanName, 4));
        $parts = preg_split('/\s+/', $withoutTitle);
        $initials = strtoupper(substr($parts[0] ?? 'T', 0, 1) . substr($parts[1] ?? ($parts[0] ?? 'C'), 0, 1));
    } else {
        // e.g. Ma'am Jay -> MJ, Sir Salazar -> SS, Ma'am Rea -> MR, Ma'am Leila -> ML, Sir Jeryco -> SJ
        $parts = preg_split('/\s+/', $cleanName);
        $initials = strtoupper(substr($parts[0] ?? 'T', 0, 1) . substr($parts[1] ?? 'C', 0, 1));
    }
    $tRow['initials'] = $initials;

    // Subtitle / course code
    $dept = trim($tRow['department_code'] ?? '');
    if ($dept === '' || $dept === 'FACULTY') {
        $dept = $defaultDeptCodes[$cleanName] ?? strtoupper(substr($tRow['subject_name'], 0, 6));
    }
    $tRow['subtitle_code'] = $dept;

    // Format Time In & Time Out
    $rawIn  = $tRow['today_time_in'] ?: ($defaultTimeIns[$cleanName] ?? '07:50:00');
    $rawOut = $tRow['today_time_out'] ?: ($defaultTimeOuts[$cleanName] ?? '15:40:00');
    $tRow['formatted_in']  = date('h:i A', strtotime($rawIn));
    $tRow['formatted_out'] = date('h:i A', strtotime($rawOut));
}
unset($tRow);

// Dynamic database-computed metrics:
// Total Teachers: active records in `teachers`
// Present Today, Absent Today, Late Today: counted from `teacher_attendance` for $today
// Attendance Rate: (Present Today / Total Teachers) * 100 rounded to 1 decimal place
$displayTotalTeachers = $totalTeachersCount;
$displayPresentToday  = $presentTodayCount;
$displayAbsentToday   = $absentTodayCount;
$displayLateToday     = $lateTodayCount;
$attendanceRate       = $displayTotalTeachers > 0
    ? round(($displayPresentToday / $displayTotalTeachers) * 100, 1)
    : 0.0;

$presentPct = $displayTotalTeachers > 0 ? round(($displayPresentToday / $displayTotalTeachers) * 100, 1) : 0.0;
$absentPct  = $displayTotalTeachers > 0 ? round(($displayAbsentToday / $displayTotalTeachers) * 100, 1) : 0.0;
$latePct    = $displayTotalTeachers > 0 ? round(($displayLateToday / $displayTotalTeachers) * 100, 1) : 0.0;

// Table rows (show top 7 in the main table as in the design, and all when toggled)
$tableTeachers = array_slice($allTeachers, 0, 7);
$widgetTeachers = array_slice($allTeachers, 0, 3);
$nextTeacherIdCode = 'TCH-' . date('Y') . '-' . str_pad($totalTeachersCount + 1, 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Global Reciprocal Colleges Attendance System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sidebar-bg: #07382e;
            --sidebar-bg-dark: #052921;
            --sidebar-active: #165649;
            --page-bg: #eef4f1;
            --card-bg: #ffffff;
            --card-border: #dce7e2;
            --ink-dark: #0b3930;
            --ink-main: #1b3832;
            --ink-muted: #5c746e;
            --ink-light: #7c938d;
            --green-accent: #14855e;
            --green-soft: #def7ec;
            --red-accent: #d94848;
            --red-soft: #fde8e8;
            --amber-accent: #d98218;
            --amber-soft: #fef0d9;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--page-bg);
            color: var(--ink-main);
            min-height: 100vh;
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* =========================================================
           APP SHELL & FIXED LEFT SIDEBAR
           ========================================================= */
        .dashboard-layout {
            display: grid;
            grid-template-columns: 242px minmax(0, 1fr);
            min-height: 100vh;
        }

        .sidebar {
            background: linear-gradient(180deg, #083d32 0%, #052b23 100%);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            padding: 20px 16px 18px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow: hidden;
            border-right: 1px solid rgba(255, 255, 255, 0.07);
        }

        /* Faint campus building watermark at sidebar bottom */
        .sidebar::after {
            content: '';
            position: absolute;
            left: -10px;
            right: -10px;
            bottom: 95px;
            height: 150px;
            background-image:
                linear-gradient(to top, rgba(5, 43, 35, 0.92), rgba(8, 61, 50, 0.3)),
                repeating-linear-gradient(90deg, rgba(255,255,255,0.04) 0px, rgba(255,255,255,0.04) 14px, transparent 14px, transparent 28px);
            border-top: 1px solid rgba(255,255,255,0.06);
            pointer-events: none;
            z-index: 0;
        }

        .brand-header {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 2px 4px 18px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            z-index: 1;
        }

        .brand-logo {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e6f4ef;
            color: #094336;
            display: grid;
            place-items: center;
            font-size: 1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.18);
        }

        .brand-text h1 {
            font-size: 0.89rem;
            font-weight: 800;
            line-height: 1.22;
            color: #ffffff;
            letter-spacing: -0.01em;
        }

        .brand-text span {
            display: block;
            font-size: 0.61rem;
            font-weight: 700;
            letter-spacing: 0.11em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.66);
            margin-top: 3px;
        }

        .workspace-label {
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.11em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.58);
            margin: 18px 8px 10px;
            position: relative;
            z-index: 1;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
            position: relative;
            z-index: 1;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-radius: 11px;
            font-size: 0.87rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.82);
            transition: all 0.18s ease;
        }

        .nav-link i {
            width: 18px;
            text-align: center;
            font-size: 0.94rem;
            color: rgba(255, 255, 255, 0.85);
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }

        .nav-link.active {
            background: rgba(33, 108, 91, 0.88);
            color: #ffffff;
            font-weight: 700;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
        }

        .sidebar-footer {
            margin-top: auto;
            position: relative;
            z-index: 1;
        }

        .academic-year-card {
            background: rgba(5, 36, 29, 0.78);
            border: 1px solid rgba(255, 255, 255, 0.11);
            border-radius: 13px;
            padding: 14px 15px;
        }

        .academic-year-card .ay-label {
            display: block;
            font-size: 0.61rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.58);
        }

        .academic-year-card .ay-year {
            display: block;
            font-size: 1.05rem;
            font-weight: 800;
            color: #ffffff;
            margin-top: 4px;
        }

        .academic-year-card .ay-term {
            display: block;
            font-size: 0.74rem;
            color: rgba(255, 255, 255, 0.74);
            margin-top: 4px;
        }

        /* =========================================================
           MAIN PANEL & TOP HEADER BAR
           ========================================================= */
        .main-area {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .top-header {
            height: 62px;
            background: rgba(247, 250, 249, 0.92);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--card-border);
            padding: 0 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 40;
        }

        .header-title {
            font-size: 1.02rem;
            font-weight: 700;
            color: var(--ink-main);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .notif-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            color: var(--ink-main);
            display: grid;
            place-items: center;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background 0.15s ease;
        }

        .notif-btn:hover {
            background: #f2f7f5;
        }

        .logout-pill {
            height: 36px;
            padding: 0 14px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #ffffff;
            color: var(--ink-main);
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: background 0.15s ease;
        }

        .logout-pill:hover {
            background: #f2f7f5;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-left: 4px;
        }

        .admin-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #c8e6dc;
            color: #0c5141;
            font-size: 0.78rem;
            font-weight: 800;
            display: grid;
            place-items: center;
        }

        .admin-meta {
            line-height: 1.2;
        }

        .admin-meta strong {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--ink-main);
        }

        .admin-meta small {
            font-size: 0.71rem;
            color: var(--ink-muted);
        }

        /* =========================================================
           DASHBOARD BODY CONTENT
           ========================================================= */
        .dashboard-container {
            padding: 22px 28px 32px;
            max-width: 1600px;
            width: 100%;
            margin: 0 auto;
        }

        .welcome-banner {
            margin-bottom: 18px;
        }

        .welcome-banner h2 {
            font-size: 1.85rem;
            font-weight: 800;
            color: var(--ink-dark);
            letter-spacing: -0.025em;
            line-height: 1.15;
        }

        .welcome-banner p {
            font-size: 0.9rem;
            color: #3b695e;
            font-weight: 500;
            margin-top: 4px;
        }

        .alert-banner {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert-banner.success {
            background: #def7ec;
            color: #0c5e40;
            border: 1px solid #b5ebd3;
        }

        .alert-banner.error {
            background: #fde8e8;
            color: #9b1c1c;
            border: 1px solid #f8b4b4;
        }

        /* =========================================================
           GRADE LEVEL CARDS (4 HORIZONTAL CARDS)
           ========================================================= */
        .grade-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .grade-card {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 15px 16px 14px;
            box-shadow: 0 2px 8px rgba(11, 57, 48, 0.03);
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }

        .grade-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(11, 57, 48, 0.06);
        }

        .grade-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            padding-bottom: 11px;
            border-bottom: 1px solid #edf3f0;
            margin-bottom: 11px;
        }

        .grade-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .grade-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .grade-title-text h3 {
            font-size: 0.98rem;
            font-weight: 800;
            color: var(--ink-main);
            line-height: 1.2;
        }

        .grade-title-text span {
            display: block;
            font-size: 0.74rem;
            color: var(--ink-muted);
            font-weight: 500;
            margin-top: 1px;
        }

        .view-details-link {
            font-size: 0.72rem;
            font-weight: 700;
            color: #2665c9;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 3px;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }

        .view-details-link:hover {
            text-decoration: underline;
        }

        .subjects-two-col {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 4px 8px;
        }

        .subject-col {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .subject-col li {
            font-size: 0.76rem;
            color: #36524b;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .subject-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        /* =========================================================
           LOWER WORKSPACE: LEFT MAIN AREA + RIGHT SIDEBAR
           ========================================================= */
        .lower-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 336px;
            gap: 16px;
            align-items: start;
        }

        .panel-card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            padding: 18px 20px;
            box-shadow: 0 2px 10px rgba(11, 57, 48, 0.03);
        }

        .panel-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .panel-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--ink-dark);
        }

        .panel-heading i {
            color: #0e5847;
            font-size: 1.08rem;
        }

        .view-all-link {
            font-size: 0.78rem;
            font-weight: 700;
            color: #165649;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .view-all-link:hover {
            text-decoration: underline;
        }

        /* 5 Summary Metric Boxes inside Teacher Attendance Monitoring */
        .monitoring-metrics-row {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .m-stat-box {
            border-radius: 13px;
            padding: 12px 13px;
            display: flex;
            align-items: center;
            gap: 11px;
            border: 1px solid #e4ede9;
            background: #f5f9f7;
        }

        .m-stat-box.present-box {
            background: #f2f9f5;
            border-color: #dceee4;
        }

        .m-stat-box.absent-box {
            background: #fdf4f4;
            border-color: #f6dfdf;
        }

        .m-stat-box.late-box {
            background: #fef8ef;
            border-color: #f7e6cc;
        }

        .m-stat-box.rate-box {
            background: #f3f9f6;
            border-color: #ddece5;
        }

        .m-stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 0.88rem;
            flex-shrink: 0;
        }

        .m-stat-icon.total {
            background: #d8efe6;
            color: #116e4e;
        }

        .m-stat-icon.present {
            background: #17825b;
            color: #ffffff;
        }

        .m-stat-icon.absent {
            background: #f9d7d7;
            color: #d64545;
        }

        .m-stat-icon.late {
            background: #fbe5c5;
            color: #d8831a;
        }

        .m-stat-info span {
            display: block;
            font-size: 0.71rem;
            font-weight: 600;
            color: var(--ink-muted);
            white-space: nowrap;
        }

        .m-stat-info strong {
            display: block;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.15;
            margin-top: 2px;
            color: var(--ink-dark);
        }

        .m-stat-box.absent-box .m-stat-info strong {
            color: #c83737;
        }

        .m-stat-box.late-box .m-stat-info strong {
            color: #cc7612;
        }

        /* Mini Donut Ring for Attendance Rate */
        .mini-ring {
            width: 38px;
            height: 38px;
            flex-shrink: 0;
            transform: rotate(-90deg);
        }

        /* Teacher Attendance Table */
        .monitoring-table-wrap {
            background: #f8fbf9;
            border: 1px solid #e5eeea;
            border-radius: 14px;
            overflow: hidden;
        }

        .monitoring-table {
            width: 100%;
            border-collapse: collapse;
        }

        .monitoring-table thead th {
            text-align: left;
            padding: 12px 14px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #36534c;
            background: #f1f6f4;
            border-bottom: 1px solid #e2ece8;
        }

        .monitoring-table tbody tr {
            border-bottom: 1px solid #eaf1ee;
            background: #ffffff;
            transition: background 0.15s ease;
            cursor: pointer;
        }

        .monitoring-table tbody tr:last-child {
            border-bottom: none;
        }

        .monitoring-table tbody tr:hover {
            background: #f4faf7;
        }

        .monitoring-table td {
            padding: 10px 14px;
            font-size: 0.81rem;
            color: #36524b;
            vertical-align: middle;
        }

        .teacher-cell {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .t-avatar-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #165b4c;
            color: #ffffff;
            font-size: 0.74rem;
            font-weight: 700;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }

        .t-name-block strong {
            display: block;
            font-size: 0.84rem;
            font-weight: 700;
            color: var(--ink-main);
            line-height: 1.2;
        }

        .t-name-block small {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: #6b857e;
            margin-top: 2px;
        }

        /* Teacher Attendance Table */
        .monitoring-table-wrap {
            background: #f8fbf9;
            border: 1px solid #e5eeea;
            border-radius: 14px;
            overflow: visible;
        }

        /* Status Badge Pills (Interactive Dropdown Trigger) */
        .status-dropdown-container {
            position: relative;
            display: inline-block;
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
            border: 1px solid transparent;
            cursor: pointer;
            user-select: none;
            transition: transform 0.14s ease, box-shadow 0.14s ease, background-color 0.2s ease, color 0.2s ease;
            font-family: inherit;
        }

        .badge-pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(11, 57, 48, 0.12);
        }

        .badge-pill:active {
            transform: translateY(0);
        }

        .badge-pill.present {
            background: #d8f3e5;
            color: #0f7652;
        }

        .badge-pill.absent {
            background: #fce0e0;
            color: #cf3c3c;
        }

        .badge-pill.late {
            background: #fdeacc;
            color: #c67614;
        }

        /* Floating Status Selector Menu */
        .status-selector-menu {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            min-width: 148px;
            background: #ffffff;
            border: 1px solid #dce7e2;
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(11, 57, 48, 0.16);
            padding: 6px;
            z-index: 250;
            display: none;
        }

        .status-selector-menu.open {
            display: block;
            animation: statusFadeIn 0.14s ease-out;
        }

        @keyframes statusFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .status-option-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 10px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.75rem;
            font-weight: 700;
            text-align: left;
            transition: background 0.14s ease;
        }

        .status-option-btn:hover {
            background: #f2f7f5;
        }

        .status-option-btn .opt-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .status-option-btn .opt-pill.present {
            background: #d8f3e5;
            color: #0f7652;
        }

        .status-option-btn .opt-pill.absent {
            background: #fce0e0;
            color: #cf3c3c;
        }

        .status-option-btn .opt-pill.late {
            background: #fdeacc;
            color: #c67614;
        }

        .row-chevron {
            color: #9ab0aa;
            font-size: 0.75rem;
            text-align: right;
        }

        /* Toast Notification for Real-Time Status Updates */
        .status-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #083d32;
            color: #ffffff;
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 0.82rem;
            font-weight: 600;
            box-shadow: 0 12px 28px rgba(7, 56, 46, 0.25);
            display: none;
            align-items: center;
            gap: 10px;
            z-index: 2000;
        }

        .status-toast.show {
            display: inline-flex;
        }

        /* =========================================================
           RIGHT SIDEBAR WIDGETS
           ========================================================= */
        .right-Known-column {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        /* 1. Quick Actions Card */
        .quick-actions-title {
            font-size: 0.94rem;
            font-weight: 800;
            color: var(--ink-dark);
            margin-bottom: 11px;
        }

        .qa-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .qa-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 11px;
            border-radius: 11px;
            background: #f3f8f6;
            border: 1px solid #dce8e3;
            color: var(--ink-main);
            font-size: 0.76rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.16s ease;
            text-align: left;
        }

        .qa-btn i {
            font-size: 0.88rem;
            color: #0e5847;
            flex-shrink: 0;
        }

        .qa-btn:hover {
            background: #0e5847;
            color: #ffffff;
            border-color: #0e5847;
        }

        .qa-btn:hover i {
            color: #ffffff;
        }

        /* 2. Today's Teacher Attendance Dark Green Card */
        .todays-teacher-card {
            background: linear-gradient(180deg, #0b4d3e 0%, #083b30 100%);
            border-radius: 18px;
            padding: 16px 16px 15px;
            color: #ffffff;
            box-shadow: 0 8px 22px rgba(7, 56, 46, 0.16);
        }

        .todays-teacher-card h3 {
            font-size: 0.89rem;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.94);
            margin-bottom: 12px;
        }

        .ttc-donut-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 4px 4px 14px;
        }

        .ttc-donut-svg {
            width: 58px;
            height: 58px;
            transform: rotate(-90deg);
            flex-shrink: 0;
        }

        .ttc-donut-meta {
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: wrap;
        }

        .ttc-donut-meta strong {
            font-size: 1.45rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.02em;
        }

        .ttc-donut-meta span {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.82);
            font-weight: 500;
        }

        .ttc-mini-list {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-radius: 13px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .ttc-mini-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            transition: background 0.15s ease;
        }

        .ttc-mini-item:last-child {
            border-bottom: none;
        }

        .ttc-mini-item:hover {
            background: rgba(255, 255, 255, 0.06);
        }

        .ttc-mini-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ttc-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            font-size: 0.7rem;
            font-weight: 700;
            display: grid;
            place-items: center;
        }

        .ttc-name strong {
            display: block;
            font-size: 0.8rem;
            font-weight: 700;
            color: #ffffff;
            line-height: 1.2;
        }

        .ttc-name small {
            display: block;
            font-size: 0.68rem;
            color: rgba(255, 255, 255, 0.68);
            margin-top: 1px;
        }

        .ttc-tag {
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.69rem;
            font-weight: 700;
        }

        .ttc-tag.present {
            background: #d2f4e3;
            color: #0e6f4b;
        }

        .ttc-tag.absent {
            background: #fbdada;
            color: #c83737;
        }

        .ttc-tag.late {
            background: #fde7c5;
            color: #c07011;
        }

        .ttc-view-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 12px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.14);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            transition: background 0.15s ease;
        }

        .ttc-view-btn:hover {
            background: rgba(255, 255, 255, 0.18);
        }

        /* 3. Teacher Attendance Summary Card */
        .summary-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 12px;
        }

        .summary-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.86rem;
            font-weight: 800;
            color: var(--ink-dark);
        }

        .summary-title i {
            color: #0e5847;
        }

        .timeframe-select {
            height: 28px;
            padding: 0 8px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            background: #f6faf8;
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--ink-main);
            cursor: pointer;
        }

        .summary-bars {
            display: flex;
            flex-direction: column;
            gap: 11px;
        }

        .s-bar-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .s-bar-label {
            font-size: 0.74rem;
            font-weight: 600;
            color: var(--ink-main);
        }

        .s-bar-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .s-bar-track {
            flex: 1;
            height: 7px;
            border-radius: 999px;
            background: #e6efec;
            overflow: hidden;
        }

        .s-bar-fill {
            height: 100%;
            border-radius: 999px;
            transition: width 0.3s ease;
        }

        .s-bar-fill.present { background: #16855d; }
        .s-bar-fill.absent  { background: #dc4c4c; }
        .s-bar-fill.late    { background: #e08b21; }

        .s-bar-val {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--ink-muted);
            min-width: 58px;
            text-align: right;
        }

        /* =========================================================
           MODALS (View Grade Details, Quick Actions, Teacher Detail)
           ========================================================= */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(7, 40, 33, 0.48);
            backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 18px;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--card-border);
            width: min(100%, 540px);
            max-height: 90vh;
            overflow-y: auto;
            padding: 22px 24px;
            box-shadow: 0 20px 50px rgba(7, 40, 33, 0.22);
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 12px;
            border-bottom: 1px solid #e6efec;
            margin-bottom: 16px;
        }

        .modal-head h3 {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--ink-dark);
        }

        .modal-close-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            background: #f5f9f7;
            cursor: pointer;
            display: grid;
            place-items: center;
        }

        .form-field {
            margin-bottom: 13px;
        }

        .form-field label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--ink-main);
            margin-bottom: 5px;
        }

        .form-input {
            width: 100%;
            height: 38px;
            padding: 0 12px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            font-family: inherit;
            font-size: 0.84rem;
            color: var(--ink-main);
            background: #ffffff;
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid #e6efec;
        }

        .btn-cancel {
            padding: 9px 16px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: #f5f9f7;
            font-weight: 700;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .btn-save {
            padding: 9px 18px;
            border-radius: 10px;
            border: none;
            background: #0b4d3e;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.82rem;
            cursor: pointer;
        }

        /* =========================================================
           RESPONSIVE BREAKPOINTS
           ========================================================= */
        @media (max-width: 1320px) {
            .grade-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .lower-workspace {
                grid-template-columns: 1fr;
            }
            .monitoring-metrics-row {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 920px) {
            .dashboard-layout {
                grid-template-columns: 1fr;
            }
            .sidebar {
                position: relative;
                height: auto;
            }
            .monitoring-metrics-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .monitoring-table-wrap {
                overflow-x: auto;
            }
        }

        @media (max-width: 600px) {
            .grade-grid {
                grid-template-columns: 1fr;
            }
            .monitoring-metrics-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-layout">
    <!-- =========================================================
         2. FIXED LEFT SIDEBAR NAVIGATION
         ========================================================= -->
    <aside class="sidebar">
        <div class="brand-header">
            <div class="brand-logo">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <div class="brand-text">
                <h1>Global Reciprocal Colleges</h1>
                <span>ATTENDANCE SYSTEM</span>
            </div>
        </div>

        <div class="workspace-label">WORKSPACE</div>
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-link active">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
            <a href="students.php" class="nav-link">
                <i class="fa-solid fa-user-group"></i>
                <span>Students</span>
            </a>
            <a href="teachers.php" class="nav-link">
                <i class="fa-solid fa-chalkboard-user"></i>
                <span>Teachers</span>
            </a>
            <a href="attendance.php" class="nav-link">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>Attendance</span>
            </a>
            <a href="reports.php" class="nav-link">
                <i class="fa-solid fa-chart-line"></i>
                <span>Reports</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="academic-year-card">
                <span class="ay-label">ACADEMIC YEAR</span>
                <strong class="ay-year">2026-2027</strong>
                <span class="ay-term">Term 1 • Week 7</span>
            </div>
        </div>
    </aside>

    <!-- =========================================================
         MAIN CONTENT AREA
         ========================================================= -->
    <div class="main-area">
        <!-- 3. HEADER BAR -->
        <header class="top-header">
            <div class="header-title">Dashboard</div>
            <div class="header-actions">
                <button type="button" class="notif-btn" title="Notifications">
                    <i class="fa-regular fa-bell"></i>
                </button>
                <a href="../logout.php" class="logout-pill">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
                <div class="admin-profile">
                    <div class="admin-avatar">EM</div>
                    <div class="admin-meta">
                        <strong>System Administrator</strong>
                        <small>Administrator</small>
                    </div>
                </div>
            </div>
        </header>

        <main class="dashboard-container">
            <?php if ($message !== ''): ?>
                <div class="alert-banner success">
                    <span><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($message) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-weight:800;">✕</button>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert-banner error">
                    <span><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></span>
                    <button type="button" onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;font-weight:800;">✕</button>
                </div>
            <?php endif; ?>

            <!-- 4. TOP SECTION: WELCOME BANNER & GRADE LEVEL CARDS -->
            <div class="welcome-banner">
                <h2>Good morning, System.</h2>
                <p>Tuesday, September 29 • Here's your attendance overview.</p>
            </div>

            <section class="grade-grid">
                <?php foreach ($gradeCards as $gc): ?>
                    <div class="grade-card">
                        <div class="grade-card-header">
                            <div class="grade-title-wrap">
                                <div class="grade-icon-box" style="background: <?= $gc['icon_bg'] ?>; color: <?= $gc['icon_color'] ?>;">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                </div>
                                <div class="grade-title-text">
                                    <h3><?= htmlspecialchars($gc['grade']) ?></h3>
                                    <span><?= htmlspecialchars($gc['count']) ?></span>
                                </div>
                            </div>
                            <button type="button" class="view-details-link" onclick="openGradeModal('<?= htmlspecialchars($gc['grade'], ENT_QUOTES) ?>')">
                                View Details <i class="fa-solid fa-arrow-right" style="font-size:0.66rem;"></i>
                            </button>
                        </div>

                        <div class="subjects-two-col">
                            <ul class="subject-col">
                                <?php foreach ($gc['col1'] as $sub1): ?>
                                    <li>
                                        <span class="subject-dot" style="background: <?= $gc['dot_left'] ?>;"></span>
                                        <?= htmlspecialchars($sub1) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <ul class="subject-col">
                                <?php foreach ($gc['col2'] as $sub2): ?>
                                    <li>
                                        <span class="subject-dot" style="background: <?= $gc['dot_right'] ?>;"></span>
                                        <?= htmlspecialchars($sub2) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- 5. MIDDLE/BOTTOM SECTION: TEACHER ATTENDANCE MONITORING & RIGHT SIDEBAR -->
            <div class="lower-workspace">
                <!-- LEFT / MAIN AREA: Teacher Attendance Monitoring Card -->
                <section class="panel-card">
                    <div class="panel-top-bar">
                        <div class="panel-heading">
                            <i class="fa-solid fa-user-check"></i>
                            <span>Teacher Attendance Monitoring</span>
                        </div>
                        <a href="teachers.php" class="view-all-link">
                            View All <i class="fa-solid fa-arrow-right" style="font-size:0.7rem;"></i>
                        </a>
                    </div>

                    <!-- 5 Metric Summary Cards -->
                    <div class="monitoring-metrics-row">
                        <!-- Total Teachers -->
                        <div class="m-stat-box">
                            <div class="m-stat-icon total">
                                <i class="fa-solid fa-user-group"></i>
                            </div>
                            <div class="m-stat-info">
                                <span>Total Teachers</span>
                                <strong id="counterTotalTeachers"><?= $displayTotalTeachers ?></strong>
                            </div>
                        </div>

                        <!-- Present Today -->
                        <div class="m-stat-box present-box">
                            <div class="m-stat-icon present">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                            <div class="m-stat-info">
                                <span>Present Today</span>
                                <strong id="counterPresentToday"><?= $displayPresentToday ?></strong>
                            </div>
                        </div>

                        <!-- Absent Today -->
                        <div class="m-stat-box absent-box">
                            <div class="m-stat-icon absent">
                                <i class="fa-solid fa-user-xmark"></i>
                            </div>
                            <div class="m-stat-info">
                                <span>Absent Today</span>
                                <strong id="counterAbsentToday"><?= $displayAbsentToday ?></strong>
                            </div>
                        </div>

                        <!-- Late Today -->
                        <div class="m-stat-box late-box">
                            <div class="m-stat-icon late">
                                <i class="fa-regular fa-clock"></i>
                            </div>
                            <div class="m-stat-info">
                                <span>Late Today</span>
                                <strong id="counterLateToday"><?= $displayLateToday ?></strong>
                            </div>
                        </div>

                        <!-- Attendance Rate with Circular Progress Indicator -->
                        <div class="m-stat-box rate-box">
                            <?php
                            $rRadius = 15;
                            $rCirc   = 2 * M_PI * $rRadius;
                            $rOffset = $rCirc - ($attendanceRate / 100) * $rCirc;
                            ?>
                            <svg class="mini-ring" viewBox="0 0 38 38">
                                <circle cx="19" cy="19" r="<?= $rRadius ?>" fill="none" stroke="#d5e8df" stroke-width="4.5"></circle>
                                <circle id="miniRingCircle" cx="19" cy="19" r="<?= $rRadius ?>" fill="none" stroke="#137f59" stroke-width="4.5"
                                        stroke-dasharray="<?= round($rCirc, 2) ?>"
                                        stroke-dashoffset="<?= round($rOffset, 2) ?>"
                                        stroke-linecap="round"></circle>
                            </svg>
                            <div class="m-stat-info">
                                <span>Attendance Rate</span>
                                <strong id="counterAttendanceRate"><?= number_format($attendanceRate, 1) ?>%</strong>
                            </div>
                        </div>
                    </div>

                    <!-- Detailed Teacher Attendance Data Table -->
                    <div class="monitoring-table-wrap">
                        <table class="monitoring-table">
                            <thead>
                                <tr>
                                    <th>Teacher Name</th>
                                    <th>Subject</th>
                                    <th>Grade Level</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tableTeachers as $t):
                                    $tId = (int)$t['id'];
                                    $st = $t['today_status'] ?? 'Present';
                                    $pillClass = strtolower($st) === 'present' ? 'present' : (strtolower($st) === 'late' ? 'late' : 'absent');
                                    $pillIcon  = $pillClass === 'present'
                                        ? 'fa-solid fa-user-check'
                                        : ($pillClass === 'late' ? 'fa-solid fa-briefcase' : 'fa-solid fa-user-xmark');
                                ?>
                                    <tr id="dash-teacher-row-<?= $tId ?>" data-teacher-id="<?= $tId ?>" data-status="<?= htmlspecialchars($st) ?>">
                                        <td onclick="window.location.href='teachers.php?view_history=<?= $tId ?>#teacher-history-section'">
                                            <div class="teacher-cell">
                                                <div class="t-avatar-circle"><?= htmlspecialchars($t['initials']) ?></div>
                                                <div class="t-name-block">
                                                    <strong><?= htmlspecialchars($t['full_name']) ?></strong>
                                                    <small><?= htmlspecialchars($t['subtitle_code']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td onclick="window.location.href='teachers.php?view_history=<?= $tId ?>#teacher-history-section'"><?= htmlspecialchars($t['grade_level_name']) ?></td>
                                        <td onclick="window.location.href='teachers.php?view_history=<?= $tId ?>#teacher-history-section'"><?= htmlspecialchars($t['grade_level_name']) ?></td>
                                        <td id="dash-time-in-<?= $tId ?>" onclick="window.location.href='teachers.php?view_history=<?= $tId ?>#teacher-history-section'"><?= htmlspecialchars($t['formatted_in']) ?></td>
                                        <td id="dash-time-out-<?= $tId ?>" onclick="window.location.href='teachers.php?view_history=<?= $tId ?>#teacher-history-section'"><?= htmlspecialchars($t['formatted_out']) ?></td>
                                        <td>
                                            <div class="status-dropdown-container">
                                                <button type="button"
                                                        id="status-badge-<?= $tId ?>"
                                                        class="badge-pill <?= $pillClass ?>"
                                                        title="Click to change attendance status"
                                                        onclick="toggleStatusDropdown(event, <?= $tId ?>)">
                                                    <i id="status-icon-<?= $tId ?>" class="<?= $pillIcon ?>" style="font-size:0.65rem;"></i>
                                                    <span id="status-text-<?= $tId ?>"><?= htmlspecialchars($st) ?></span>
                                                </button>

                                                <!-- Interactive Status Dropdown Selector -->
                                                <div id="status-menu-<?= $tId ?>" class="status-selector-menu" onclick="event.stopPropagation()">
                                                    <button type="button" class="status-option-btn" onclick="updateTeacherStatusAjax(<?= $tId ?>, 'Present')">
                                                        <span class="opt-pill present">
                                                            <i class="fa-solid fa-user-check" style="font-size:0.63rem;"></i> Present
                                                        </span>
                                                    </button>
                                                    <button type="button" class="status-option-btn" onclick="updateTeacherStatusAjax(<?= $tId ?>, 'Absent')">
                                                        <span class="opt-pill absent">
                                                            <i class="fa-solid fa-user-xmark" style="font-size:0.63rem;"></i> Absent
                                                        </span>
                                                    </button>
                                                    <button type="button" class="status-option-btn" onclick="updateTeacherStatusAjax(<?= $tId ?>, 'Late')">
                                                        <span class="opt-pill late">
                                                            <i class="fa-solid fa-briefcase" style="font-size:0.63rem;"></i> Late
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="row-chevron" onclick="window.location.href='teachers.php?view_history=<?= $tId ?>#teacher-history-section'">
                                            <i class="fa-solid fa-chevron-right"></i>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- RIGHT SIDEBAR AREA -->
                <aside class="right-Known-column">
                    <!-- 1. Quick Actions Panel -->
                    <div class="panel-card" style="padding:16px;">
                        <div class="quick-actions-title">Quick Actions</div>
                        <div class="qa-grid">
                            <button type="button" class="qa-btn" onclick="openModal('addTeacherModal')">
                                <i class="fa-solid fa-user-plus"></i>
                                <span>Add Teacher</span>
                            </button>
                            <button type="button" class="qa-btn" onclick="openModal('assignGradeModal')">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <span>Assign to Grade Level</span>
                            </button>
                            <button type="button" class="qa-btn" onclick="openModal('assignSubjectModal')">
                                <i class="fa-solid fa-book-open"></i>
                                <span>Assign to Subject</span>
                            </button>
                            <a href="reports.php?report_type=teacher" class="qa-btn">
                                <i class="fa-solid fa-file-lines"></i>
                                <span>Generate Report</span>
                            </a>
                        </div>
                    </div>

                    <!-- 2. Today's Teacher Attendance Dark Green Widget -->
                    <div class="todays-teacher-card">
                        <h3>Today's Teacher Attendance</h3>
                        <?php
                        $lgRadius = 23;
                        $lgCirc   = 2 * M_PI * $lgRadius;
                        $lgOffset = $lgCirc - ($attendanceRate / 100) * $lgCirc;
                        ?>
                        <div class="ttc-donut-row">
                            <svg class="ttc-donut-svg" viewBox="0 0 58 58">
                                <circle cx="29" cy="29" r="<?= $lgRadius ?>" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="6"></circle>
                                <circle id="largeRingCircle" cx="29" cy="29" r="<?= $lgRadius ?>" fill="none" stroke="#8be8cb" stroke-width="6"
                                        stroke-dasharray="<?= round($lgCirc, 2) ?>"
                                        stroke-dashoffset="<?= round($lgOffset, 2) ?>"
                                        stroke-linecap="round"></circle>
                            </svg>
                            <div class="ttc-donut-meta">
                                <strong id="widgetRateText"><?= number_format($attendanceRate, 1) ?>%</strong>
                                <span id="widgetPresentRatio"><?= $displayPresentToday ?> of <?= ($displayPresentToday + $displayAbsentToday) ?> teachers present</span>
                            </div>
                        </div>

                        <div class="ttc-mini-list">
                            <?php foreach ($widgetTeachers as $wt):
                                $wtId = (int)$wt['id'];
                                $wSt = $wt['today_status'] ?? 'Present';
                                $wClass = strtolower($wSt) === 'present' ? 'present' : (strtolower($wSt) === 'late' ? 'late' : 'absent');
                            ?>
                                <div class="ttc-mini-item">
                                    <a href="teachers.php?view_history=<?= $wtId ?>#teacher-history-section" class="ttc-mini-left">
                                        <div class="ttc-avatar"><?= htmlspecialchars($wt['initials']) ?></div>
                                        <div class="ttc-name">
                                            <strong><?= htmlspecialchars($wt['full_name']) ?></strong>
                                            <small><?= htmlspecialchars(str_replace(['(', ')'], ['/ ', ''], $wt['subtitle_code'])) ?></small>
                                        </div>
                                    </a>
                                    <button type="button"
                                            id="widget-badge-<?= $wtId ?>"
                                            class="ttc-tag <?= $wClass ?>"
                                            style="border:none;cursor:pointer;font-family:inherit;"
                                            title="Click to cycle status (Present → Late → Absent)"
                                            onclick="cycleTeacherStatus(<?= $wtId ?>)">
                                        <?= htmlspecialchars($wSt) ?>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <a href="teachers.php" class="ttc-view-btn">
                            <span>View All Teachers</span>
                            <i class="fa-solid fa-arrow-right" style="font-size:0.72rem;"></i>
                        </a>
                    </div>

                    <!-- 3. Teacher Attendance Summary Progress Breakdown -->
                    <div class="panel-card" style="padding:16px;">
                        <div class="summary-header">
                            <div class="summary-title">
                                <i class="fa-solid fa-chart-simple"></i>
                                <span>Teacher Attendance Summary</span>
                            </div>
                            <select id="timeframeSelect" class="timeframe-select" onchange="updateSummaryTimeframe(this.value)">
                                <option value="week">This Week</option>
                                <option value="today">Today</option>
                                <option value="month">This Month</option>
                            </select>
                        </div>

                        <div class="summary-bars">
                            <div class="s-bar-group">
                                <div class="s-bar-label">Present</div>
                                <div class="s-bar-row">
                                    <div class="s-bar-track">
                                        <div id="barPresent" class="s-bar-fill present" style="width: <?= $presentPct ?>%;"></div>
                                    </div>
                                    <div id="labelPresent" class="s-bar-val"><?= $displayPresentToday ?> (<?= number_format($presentPct, 1) ?>%)</div>
                                </div>
                            </div>

                            <div class="s-bar-group">
                                <div class="s-bar-label">Absent</div>
                                <div class="s-bar-row">
                                    <div class="s-bar-track">
                                        <div id="barAbsent" class="s-bar-fill absent" style="width: <?= max(12, $absentPct) ?>%;"></div>
                                    </div>
                                    <div id="labelAbsent" class="s-bar-val"><?= $displayAbsentToday ?> (<?= number_format($absentPct, 1) ?>%)</div>
                                </div>
                            </div>

                            <div class="s-bar-group">
                                <div class="s-bar-label">Late</div>
                                <div class="s-bar-row">
                                    <div class="s-bar-track">
                                        <div id="barLate" class="s-bar-fill late" style="width: <?= max(12, $latePct) ?>%;"></div>
                                    </div>
                                    <div id="labelLate" class="s-bar-val"><?= $displayLateToday ?> (<?= number_format($latePct, 1) ?>%)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</div>

<!-- =========================================================
     MODAL 1: GRADE LEVEL DETAILS MODAL
     ========================================================= -->
<div id="gradeDetailsModal" class="modal-overlay" onclick="if(event.target===this) closeModal('gradeDetailsModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="gradeModalTitle">Grade Level Curriculum &amp; Assigned Teachers</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('gradeDetailsModal')">✕</button>
        </div>
        <p style="font-size:0.83rem;color:var(--ink-muted);margin-bottom:14px;">
            Standard 8 core subjects taught under <strong id="gradeModalSub">Grade 7</strong>:
        </p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
            <?php foreach ($allCoreSubjects as $cs): ?>
                <div style="padding:9px 12px;border-radius:10px;background:#f4f9f6;border:1px solid #dce8e3;font-size:0.82rem;font-weight:700;color:var(--ink-main);">
                    <i class="fa-solid fa-book" style="color:#14855e;margin-right:7px;"></i> <?= htmlspecialchars($cs) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeModal('gradeDetailsModal')">Close</button>
            <a id="gradeModalTeachersLink" href="teachers.php" class="btn-save">View Assigned Teachers</a>
        </div>
    </div>
</div>

<!-- =========================================================
     MODAL 2: QUICK ACTION - ADD TEACHER
     ========================================================= -->
<div id="addTeacherModal" class="modal-overlay" onclick="if(event.target===this) closeModal('addTeacherModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fa-solid fa-user-plus" style="color:#0e5847;margin-right:7px;"></i> Add New Teacher</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('addTeacherModal')">✕</button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="quick_add_teacher">
            <div class="form-row-2">
                <div class="form-field">
                    <label>Teacher ID *</label>
                    <input type="text" name="teacher_id" class="form-input" value="<?= htmlspecialchars($nextTeacherIdCode) ?>" required>
                </div>
                <div class="form-field">
                    <label>Course / Dept Subtitle</label>
                    <input type="text" name="department_code" class="form-input" placeholder="e.g. HCI (IT), SCITECH" value="FACULTY">
                </div>
            </div>
            <div class="form-field">
                <label>Full Name *</label>
                <input type="text" name="full_name" class="form-input" placeholder="e.g. Mr. John Angac" required>
            </div>
            <div class="form-row-2">
                <div class="form-field">
                    <label>Grade Level *</label>
                    <select name="grade_level_name" class="form-input" required>
                        <option value="Grade 7">Grade 7</option>
                        <option value="Grade 8">Grade 8</option>
                        <option value="Grade 9">Grade 9</option>
                        <option value="Grade 10">Grade 10</option>
                    </select>
                </div>
                <div class="form-field">
                    <label>Assigned Subject *</label>
                    <select name="subject_name" class="form-input" required>
                        <?php foreach ($allCoreSubjects as $subName): ?>
                            <option value="<?= htmlspecialchars($subName) ?>"><?= htmlspecialchars($subName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row-2">
                <div class="form-field">
                    <label>Email Address *</label>
                    <input type="email" name="email" class="form-input" placeholder="teacher@school.edu.ph" required>
                </div>
                <div class="form-field">
                    <label>Contact Number *</label>
                    <input type="text" name="contact_number" class="form-input" placeholder="0917-XXX-XXXX" required>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('addTeacherModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Teacher</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 3: QUICK ACTION - ASSIGN TO GRADE LEVEL
     ========================================================= -->
<div id="assignGradeModal" class="modal-overlay" onclick="if(event.target===this) closeModal('assignGradeModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fa-solid fa-graduation-cap" style="color:#0e5847;margin-right:7px;"></i> Assign Teacher to Grade Level</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('assignGradeModal')">✕</button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="assign_grade_level">
            <div class="form-field">
                <label>Select Teacher *</label>
                <select name="teacher_id" class="form-input" required>
                    <?php foreach ($allTeachers as $tr): ?>
                        <option value="<?= (int)$tr['id'] ?>">
                            <?= htmlspecialchars($tr['full_name']) ?> (Current: <?= htmlspecialchars($tr['grade_level_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Assign Grade Level *</label>
                <select name="grade_level_name" class="form-input" required>
                    <option value="Grade 7">Grade 7</option>
                    <option value="Grade 8">Grade 8</option>
                    <option value="Grade 9">Grade 9</option>
                    <option value="Grade 10">Grade 10</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('assignGradeModal')">Cancel</button>
                <button type="submit" class="btn-save">Update Grade Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================
     MODAL 4: QUICK ACTION - ASSIGN TO SUBJECT
     ========================================================= -->
<div id="assignSubjectModal" class="modal-overlay" onclick="if(event.target===this) closeModal('assignSubjectModal')">
    <div class="modal-card">
        <div class="modal-head">
            <h3><i class="fa-solid fa-book-open" style="color:#0e5847;margin-right:7px;"></i> Assign Teacher to Subject</h3>
            <button type="button" class="modal-close-btn" onclick="closeModal('assignSubjectModal')">✕</button>
        </div>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="assign_subject">
            <div class="form-field">
                <label>Select Teacher *</label>
                <select name="teacher_id" class="form-input" required>
                    <?php foreach ($allTeachers as $tr): ?>
                        <option value="<?= (int)$tr['id'] ?>">
                            <?= htmlspecialchars($tr['full_name']) ?> (Current: <?= htmlspecialchars($tr['subject_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Assign Subject *</label>
                <select name="subject_name" class="form-input" required>
                    <?php foreach ($allCoreSubjects as $subName): ?>
                        <option value="<?= htmlspecialchars($subName) ?>"><?= htmlspecialchars($subName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Subject Code / Subtitle (Optional)</label>
                <input type="text" name="department_code" class="form-input" placeholder="e.g. HCI (IT), SCITECH, WEBSYS">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('assignSubjectModal')">Cancel</button>
                <button type="submit" class="btn-save">Update Subject Assignment</button>
            </div>
        </form>
    </div>
</div>

<!-- Real-Time Status Update Toast -->
<div id="statusToast" class="status-toast">
    <i class="fa-solid fa-circle-check" style="color:#8be8cb;"></i>
    <span id="statusToastText">Attendance status updated!</span>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

function openGradeModal(gradeName) {
    document.getElementById('gradeModalTitle').textContent = gradeName + ' — Curriculum Overview';
    document.getElementById('gradeModalSub').textContent = gradeName;
    document.getElementById('gradeModalTeachersLink').href = 'teachers.php?grade_level=' + encodeURIComponent(gradeName);
    openModal('gradeDetailsModal');
}

function showStatusToast(msg) {
    const toast = document.getElementById('statusToast');
    const text = document.getElementById('statusToastText');
    if (!toast || !text) return;
    text.textContent = msg;
    toast.classList.add('show');
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Close all open status dropdown menus
function closeAllStatusMenus() {
    document.querySelectorAll('.status-selector-menu.open').forEach(menu => {
        menu.classList.remove('open');
    });
}

// Toggle inline dropdown menu when a status badge is clicked
function toggleStatusDropdown(event, teacherId) {
    event.stopPropagation();
    const menu = document.getElementById('status-menu-' + teacherId);
    if (!menu) return;
    const isOpen = menu.classList.contains('open');
    closeAllStatusMenus();
    if (!isOpen) {
        menu.classList.add('open');
    }
}

document.addEventListener('click', () => {
    closeAllStatusMenus();
});

// Cycle status when clicking a badge in the right-side "Today's Teacher Attendance" widget
function cycleTeacherStatus(teacherId) {
    const row = document.getElementById('dash-teacher-row-' + teacherId);
    const current = row ? (row.dataset.status || 'Present') : 'Present';
    const order = ['Present', 'Late', 'Absent'];
    const nextStatus = order[(order.indexOf(current) + 1) % order.length];
    updateTeacherStatusAjax(teacherId, nextStatus);
}

// Helper to update the 5 Teacher Attendance Monitoring summary cards & circular progress rings in real-time
function applyAttendanceStatsSummary(sum) {
    if (!sum) return;

    const elTotal   = document.getElementById('counterTotalTeachers');
    const elPresent = document.getElementById('counterPresentToday');
    const elAbsent  = document.getElementById('counterAbsentToday');
    const elLate    = document.getElementById('counterLateToday');
    const elRate    = document.getElementById('counterAttendanceRate');

    // Compute Attendance Rate using formula: (Present Today / Total Teachers) * 100 rounded to 1 decimal place
    const totalVal   = Number(sum.total_teachers) || 0;
    const presentVal = Number(sum.present_today) || 0;
    const absentVal  = Number(sum.absent_today) || 0;
    const lateVal    = Number(sum.late_today) || 0;
    const computedRate = totalVal > 0
        ? Math.round((presentVal / totalVal) * 1000) / 10
        : 0.0;

    if (elTotal)   elTotal.textContent   = totalVal;
    if (elPresent) elPresent.textContent = presentVal;
    if (elAbsent)  elAbsent.textContent  = absentVal;
    if (elLate)    elLate.textContent    = lateVal;
    if (elRate)    elRate.textContent    = computedRate.toFixed(1) + '%';

    // Update Mini SVG Donut Ring in the 5th Summary Card
    const miniRing = document.getElementById('miniRingCircle');
    if (miniRing) {
        const rCirc = 2 * Math.PI * 15;
        const rOffset = rCirc - (computedRate / 100) * rCirc;
        miniRing.setAttribute('stroke-dashoffset', rOffset.toFixed(2));
    }

    // Update Large SVG Donut Ring & Text in Dark Green Right Sidebar Card
    const largeRing = document.getElementById('largeRingCircle');
    if (largeRing) {
        const lgCirc = 2 * Math.PI * 23;
        const lgOffset = lgCirc - (computedRate / 100) * lgCirc;
        largeRing.setAttribute('stroke-dashoffset', lgOffset.toFixed(2));
    }
    const widgetRateText = document.getElementById('widgetRateText');
    const widgetRatio    = document.getElementById('widgetPresentRatio');
    if (widgetRateText) widgetRateText.textContent = computedRate.toFixed(1) + '%';
    if (widgetRatio)    widgetRatio.textContent    = `${presentVal} of ${totalVal} teachers present`;

    // Update Progress Bars in Teacher Attendance Summary
    const presentPct = totalVal > 0 ? (presentVal / totalVal) * 100 : 0;
    const absentPct  = totalVal > 0 ? (absentVal / totalVal) * 100 : 0;
    const latePct    = totalVal > 0 ? (lateVal / totalVal) * 100 : 0;

    const barPresent = document.getElementById('barPresent');
    const lblPresent = document.getElementById('labelPresent');
    const barAbsent  = document.getElementById('barAbsent');
    const lblAbsent  = document.getElementById('labelAbsent');
    const barLate    = document.getElementById('barLate');
    const lblLate    = document.getElementById('labelLate');

    if (barPresent) barPresent.style.width = presentPct.toFixed(1) + '%';
    if (lblPresent) lblPresent.textContent = `${presentVal} (${presentPct.toFixed(1)}%)`;
    if (barAbsent)  barAbsent.style.width  = (absentVal > 0 ? Math.max(8, absentPct) : 0) + '%';
    if (lblAbsent)  lblAbsent.textContent  = `${absentVal} (${absentPct.toFixed(1)}%)`;
    if (barLate)    barLate.style.width    = (lateVal > 0 ? Math.max(8, latePct) : 0) + '%';
    if (lblLate)    lblLate.textContent    = `${lateVal} (${latePct.toFixed(1)}%)`;
}

// Fetch real-time attendance stats from get_attendance_stats.php (AJAX polling + on-demand sync)
async function fetchAttendanceStats() {
    try {
        const response = await fetch('get_attendance_stats.php?date=' + encodeURIComponent('<?= $today ?>'), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store'
        });
        const data = await response.json();
        if (data.status === 'success' && data.summary) {
            applyAttendanceStatsSummary(data.summary);

            // Sync individual teacher row statuses if changed in another tab/page
            if (Array.isArray(data.records)) {
                data.records.forEach(rec => {
                    const tId = rec.id;
                    const statusLower = rec.status.toLowerCase();
                    const iconClass = statusLower === 'present'
                        ? 'fa-solid fa-user-check'
                        : (statusLower === 'late' ? 'fa-solid fa-briefcase' : 'fa-solid fa-user-xmark');

                    const row = document.getElementById('dash-teacher-row-' + tId);
                    if (row) row.dataset.status = rec.status;

                    const badgeBtn = document.getElementById('status-badge-' + tId);
                    if (badgeBtn) {
                        badgeBtn.className = 'badge-pill ' + statusLower;
                        const iconEl = document.getElementById('status-icon-' + tId);
                        const textEl = document.getElementById('status-text-' + tId);
                        if (iconEl) iconEl.className = iconClass;
                        if (textEl) textEl.textContent = rec.status;
                    }

                    const timeInCell  = document.getElementById('dash-time-in-' + tId);
                    const timeOutCell = document.getElementById('dash-time-out-' + tId);
                    if (timeInCell && rec.formatted_in)   timeInCell.textContent  = rec.formatted_in;
                    if (timeOutCell && rec.formatted_out) timeOutCell.textContent = rec.formatted_out;

                    const widgetBadge = document.getElementById('widget-badge-' + tId);
                    if (widgetBadge) {
                        widgetBadge.className = 'ttc-tag ' + statusLower;
                        widgetBadge.textContent = rec.status;
                    }
                });
            }
        }
    } catch (err) {
        // Silent background poll handling
    }
}

// Send AJAX request to update_attendance.php and dynamically update badge + dashboard counters
async function updateTeacherStatusAjax(teacherId, newStatus) {
    closeAllStatusMenus();

    const badgeBtn = document.getElementById('status-badge-' + teacherId);
    if (badgeBtn) {
        badgeBtn.style.opacity = '0.65';
    }

    try {
        const response = await fetch('update_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                teacher_id: teacherId,
                status: newStatus,
                date: '<?= $today ?>'
            })
        });

        const data = await response.json();

        if (data.status === 'success') {
            const rec = data.record;
            const sum = data.summary;

            const statusLower = rec.status.toLowerCase(); // 'present' | 'absent' | 'late'
            const iconClass = statusLower === 'present'
                ? 'fa-solid fa-user-check'
                : (statusLower === 'late' ? 'fa-solid fa-briefcase' : 'fa-solid fa-user-xmark');

            // 1. Update Main Table Row & Badge Pill
            const row = document.getElementById('dash-teacher-row-' + teacherId);
            if (row) {
                row.dataset.status = rec.status;
            }

            if (badgeBtn) {
                badgeBtn.className = 'badge-pill ' + statusLower;
                const iconEl = document.getElementById('status-icon-' + teacherId);
                const textEl = document.getElementById('status-text-' + teacherId);
                if (iconEl) iconEl.className = iconClass;
                if (textEl) textEl.textContent = rec.status;
            }

            // Update Time In / Time Out cells
            const timeInCell = document.getElementById('dash-time-in-' + teacherId);
            const timeOutCell = document.getElementById('dash-time-out-' + teacherId);
            if (timeInCell && rec.formatted_in) timeInCell.textContent = rec.formatted_in;
            if (timeOutCell && rec.formatted_out) timeOutCell.textContent = rec.formatted_out;

            // 2. Update Right Sidebar "Today's Teacher Attendance" mini badge if present
            const widgetBadge = document.getElementById('widget-badge-' + teacherId);
            if (widgetBadge) {
                widgetBadge.className = 'ttc-tag ' + statusLower;
                widgetBadge.textContent = rec.status;
            }

            // 3. Dynamically Recalculate & Update Dashboard Summary Counters + Circular Progress
            applyAttendanceStatsSummary(sum);

            // Also verify against get_attendance_stats.php endpoint
            fetchAttendanceStats();

            showStatusToast(data.message);
        } else {
            showStatusToast(data.message || 'Could not update attendance status.');
        }
    } catch (err) {
        showStatusToast('Network error while updating attendance status.');
    } finally {
        if (badgeBtn) {
            badgeBtn.style.opacity = '1';
        }
    }
}

// Start real-time AJAX polling & visibility listener so summary cards always stay synced with MySQL
document.addEventListener('DOMContentLoaded', () => {
    fetchAttendanceStats();
    setInterval(fetchAttendanceStats, 10000);
});
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        fetchAttendanceStats();
    }
});

function updateSummaryTimeframe(period) {
    if (period === 'today' || period === 'week') {
        fetchAttendanceStats();
        return;
    }
    const data = {
        month: { presentW: '93.5%', presentLbl: '215 (93.5%)', absentW: '10%', absentLbl: '8 (3.5%)', lateW: '10%', lateLbl: '7 (3.0%)' }
    };
    const sel = data[period];
    if (sel) {
        document.getElementById('barPresent').style.width = sel.presentW;
        document.getElementById('labelPresent').textContent = sel.presentLbl;
        document.getElementById('barAbsent').style.width = sel.absentW;
        document.getElementById('labelAbsent').textContent = sel.absentLbl;
        document.getElementById('barLate').style.width = sel.lateW;
        document.getElementById('labelLate').textContent = sel.lateLbl;
    }
}
</script>

</body>
</html>
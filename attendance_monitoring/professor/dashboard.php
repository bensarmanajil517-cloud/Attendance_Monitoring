<?php
// professor/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();

$prof_id = $user['id'];
$today = date('Y-m-d');

// 1. Fetch Professor Subjects
$subjectsStmt = $pdo->prepare("SELECT * FROM subjects WHERE professor_id = ? ORDER BY subject_code ASC");
$subjectsStmt->execute([$prof_id]);
$subjects = $subjectsStmt->fetchAll();

// 2. Statistics
$statsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT a.id) as today_scans,
        COUNT(DISTINCT s.id) as total_subjects,
        SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_today,
        SUM(CASE WHEN a.status = 'Late' THEN 1 ELSE 0 END) as late_today
    FROM subjects s
    LEFT JOIN attendance a ON s.id = a.subject_id AND a.date = ?
    WHERE s.professor_id = ?
");
$statsStmt->execute([$today, $prof_id]);
$stats = $statsStmt->fetch();

// 3. Today's Scanned Students
$todayLogsStmt = $pdo->prepare("
    SELECT a.*, u.name as student_name, u.identifier as student_id_number, u.course_section, s.subject_code
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.scanned_by = ? AND a.date = ?
    ORDER BY a.time_in DESC
");
$todayLogsStmt->execute([$prof_id, $today]);
$todayLogs = $todayLogsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professor Portal - Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
        <span>Professor Portal</span>
    </div>
    <ul class="nav-links">
        <li><a href="dashboard.php" class="active"><i class="fa-solid fa-house"></i> Dashboard</a></li>
        <li><a href="scan.php" style="background: var(--primary); color: white;"><i class="fa-solid fa-camera"></i> Open QR Scanner</a></li>
        <li><a href="manual_attendance.php"><i class="fa-solid fa-clipboard-check"></i> Manual Attendance</a></li>
        <li><a href="subjects.php"><i class="fa-solid fa-book-open"></i> Subjects & Classes</a></li>
        <li><a href="reports.php"><i class="fa-solid fa-chart-line"></i> Attendance Reports</a></li>
        <li>
            <div class="user-badge">
                <span class="role-pill professor">Prof</span>
                <span><?= htmlspecialchars($user['name']) ?></span>
            </div>
        </li>
        <li><a href="../logout.php" style="color: var(--danger);"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
    </ul>
</nav>

<div class="container">
    <!-- Header Banner -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h1 style="font-size: 1.6rem; font-weight: 800; color: var(--dark);">Welcome, <?= htmlspecialchars($user['name']) ?>!</h1>
            <p style="color: var(--gray-500); font-size: 0.95rem;">Date today: <strong><?= date('l, F d, Y') ?></strong></p>
        </div>
        <a href="scan.php" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-size: 1rem; box-shadow: var(--shadow);">
            <i class="fa-solid fa-qrcode"></i> Start Scanning Attendance
        </a>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="manual_attendance.php" class="btn btn-secondary" style="padding: 0.75rem 1.25rem; font-size: 0.95rem;">
                <i class="fa-solid fa-clipboard-user"></i> Manual Checking
            </a>
            <a href="scan.php" class="btn btn-primary" style="padding: 0.75rem 1.5rem; font-size: 1rem; box-shadow: var(--shadow);">
                <i class="fa-solid fa-qrcode"></i> Start QR Scanning
            </a>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="grid-4" style="margin-bottom: 2rem;">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fa-solid fa-users"></i></div>
            <div class="stat-content">
                <div class="stat-val"><?= (int)($stats['today_scans'] ?? 0) ?></div>
                <div class="stat-label">Scanned Today</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-content">
                <div class="stat-val"><?= (int)($stats['present_today'] ?? 0) ?></div>
                <div class="stat-label">Present (On-Time)</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fa-solid fa-clock"></i></div>
            <div class="stat-content">
                <div class="stat-val"><?= (int)($stats['late_today'] ?? 0) ?></div>
                <div class="stat-label">Late Students</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fa-solid fa-book"></i></div>
            <div class="stat-content">
                <div class="stat-val"><?= count($subjects) ?></div>
                <div class="stat-label">My Subjects</div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="grid-2">
        <!-- Today's Attendance Real-time Log -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Today's Scanned Attendance</div>
                <a href="reports.php" class="btn btn-secondary btn-sm">Full Report</a>
            </div>

            <?php if (empty($todayLogs)): ?>
                <div style="text-align: center; padding: 2.5rem 1rem; color: var(--gray-500);">
                    <i class="fa-solid fa-camera-retro" style="font-size: 2.5rem; margin-bottom: 0.75rem; color: var(--gray-300);"></i>
                    <p>No scans recorded for today yet.</p>
                    <a href="scan.php" class="btn btn-primary btn-sm" style="margin-top: 0.5rem;">
                        <i class="fa-solid fa-camera"></i> Launch Camera Scanner
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Subject</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayLogs as $log): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($log['student_name']) ?></strong><br>
                                        <small style="color: var(--gray-500);"><?= htmlspecialchars($log['student_id_number']) ?> (<?= htmlspecialchars($log['course_section'] ?? '') ?>)</small>
                                    </td>
                                    <td><span class="badge badge-primary"><?= htmlspecialchars($log['subject_code']) ?></span></td>
                                    <td><?= date('h:i A', strtotime($log['time_in'])) ?></td>
                                    <td>
                                        <?php if ($log['status'] === 'Present'): ?>
                                            <span class="badge badge-success">Present</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Late</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Subjects List -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fa-solid fa-book" style="color: var(--secondary);"></i> Assigned Subjects & Classes</div>
                <a href="subjects.php" class="btn btn-secondary btn-sm">+ Add Subject</a>
            </div>

            <?php if (empty($subjects)): ?>
                <div style="text-align: center; padding: 2rem; color: var(--gray-500);">
                    <p>No subjects added yet.</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <?php foreach ($subjects as $s): ?>
                        <div style="padding: 1rem; border: 1px solid var(--border); border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <strong style="color: var(--primary); font-size: 1.05rem;"><?= htmlspecialchars($s['subject_code']) ?></strong>
                                <div style="font-weight: 600; color: var(--dark);"><?= htmlspecialchars($s['subject_name']) ?></div>
                            </div>
                            <a href="scan.php?subject_id=<?= $s['id'] ?>" class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-camera"></i> Scan
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>

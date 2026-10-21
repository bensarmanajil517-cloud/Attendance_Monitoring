<?php
// student/dashboard.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['student']);
$user = current_user();
$pdo = get_db_connection();
$activeView = $_GET['view'] ?? 'dashboard';
if (!in_array($activeView, ['dashboard', 'subjects', 'attendance', 'reports'], true)) {
    $activeView = 'dashboard';
}
$viewTitles = [
    'dashboard' => 'Dashboard',
    'subjects' => 'My subjects',
    'attendance' => 'Attendance history',
    'reports' => 'Attendance summary'
];

// Refresh user info from DB
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$student = $stmt->fetch();

$subjectsStmt = $pdo->prepare("
    SELECT s.subject_code, s.subject_name, p.name AS professor_name,
           sc.day_of_week, sc.start_time, sc.end_time
    FROM student_subjects ss
    JOIN subjects s ON s.id = ss.subject_id
    JOIN users p ON p.id = s.professor_id
    LEFT JOIN schedules sc ON sc.subject_id = s.id
    WHERE ss.student_id = ?
    ORDER BY s.subject_code, sc.day_of_week
");
$subjectsStmt->execute([$user['id']]);
$enrolledSubjects = $subjectsStmt->fetchAll();

// Attendance statistics
$statStmt = $pdo->prepare("SELECT 
    COUNT(*) as total_attendance,
    SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_count
    FROM attendance WHERE student_id = ?");
$statStmt->execute([$user['id']]);
$stats = $statStmt->fetch();

// Recent Attendance Logs
$logsStmt = $pdo->prepare("
    SELECT a.*, s.subject_code, s.subject_name, p.name as professor_name
    FROM attendance a
    JOIN subjects s ON a.subject_id = s.id
    JOIN users p ON a.scanned_by = p.id
    WHERE a.student_id = ?
    ORDER BY a.date DESC, a.time_in DESC
");
$logsStmt->execute([$user['id']]);
$recentLogs = $logsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - My Attendance QR</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        .student-view-subjects #attendance-summary,
        .student-view-subjects .dashboard-grid,
        .student-view-subjects #attendance-records,
        .student-view-attendance #attendance-summary,
        .student-view-attendance .dashboard-grid,
        .student-view-attendance #my-subjects,
        .student-view-reports .dashboard-grid,
        .student-view-reports .lower-grid {
            display: none;
        }

        .student-view-subjects .lower-grid,
        .student-view-attendance .lower-grid {
            display: block;
        }
    </style>
</head>
<body class="student-view-<?= htmlspecialchars($activeView) ?>">

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
            <a class="nav-item <?= $activeView === 'dashboard' ? 'active' : '' ?>" href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
            <a class="nav-item <?= $activeView === 'subjects' ? 'active' : '' ?>" href="dashboard.php?view=subjects#my-subjects"><i class="fa-solid fa-book-open"></i> My subjects</a>
            <a class="nav-item <?= $activeView === 'attendance' ? 'active' : '' ?>" href="dashboard.php?view=attendance#attendance-records"><i class="fa-solid fa-clipboard-check"></i> Attendance</a>
            <a class="nav-item <?= $activeView === 'reports' ? 'active' : '' ?>" href="dashboard.php?view=reports#attendance-summary"><i class="fa-solid fa-chart-simple"></i> Reports</a>
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
            <div class="topbar-title"><?= htmlspecialchars($viewTitles[$activeView]) ?></div>
            <div class="topbar-tools">
                <a class="icon-btn" href="#attendance-records" aria-label="Attendance history" title="Attendance history"><i class="fa-regular fa-bell"></i></a>
                <a class="logout-btn" href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
                <div class="user-mini">
                    <div class="avatar">EM</div>
                    <div class="meta">
                        <span><?= htmlspecialchars($student['name']) ?></span>
                        <small>Student</small>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrap">
            <div class="page-header">
                <div>
                    <h1 class="page-title"><?= $activeView === 'dashboard' ? 'Good morning, ' . htmlspecialchars(explode(' ', $student['name'])[0]) . '.' : htmlspecialchars($viewTitles[$activeView]) ?></h1>
                    <div class="page-subtitle"><?= $activeView === 'dashboard' ? date('l, F j') . ' · Here’s how attendance is looking.' : 'Your personal attendance information and enrolled classes.' ?></div>
                </div>
                <?php if ($activeView === 'dashboard'): ?>
                    <a class="primary-btn" href="#my-attendance-qr"><i class="fa-solid fa-qrcode"></i> Show attendance QR</a>
                <?php endif; ?>
            </div>

            <div class="metrics-grid" id="attendance-summary">
                <div class="metric-card">
                    <div class="metric-icon"><i class="fa-solid fa-user-graduate"></i></div>
                    <div>
                        <div class="metric-number"><?= (int)($stats['total_attendance'] ?? 0) ?></div>
                        <div class="metric-meta">Total classes</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-green"><i class="fa-solid fa-check"></i></div>
                    <div>
                        <div class="metric-number"><?= (int)($stats['present_count'] ?? 0) ?></div>
                        <div class="metric-meta">Present</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-red"><i class="fa-solid fa-xmark"></i></div>
                    <div>
                        <div class="metric-number"><?= (int)($stats['late_count'] ?? 0) ?></div>
                        <div class="metric-meta">Late</div>
                    </div>
                </div>
                <div class="metric-card">
                    <div class="metric-icon soft-amber"><i class="fa-solid fa-circle-percent"></i></div>
                    <div>
                        <div class="metric-number"><?= !empty($stats['total_attendance']) ? number_format(((int)$stats['present_count'] / (int)$stats['total_attendance']) * 100, 1) : '0.0' ?>%</div>
                        <div class="metric-meta">Present rate</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Attendance this week</div>
                        <div class="legend">
                            <span><span class="dot green"></span>Present</span>
                            <span><span class="dot red"></span>Absent</span>
                            <span><span class="dot gold"></span>Late</span>
                        </div>
                    </div>

                    <div class="bar-chart">
                        <div class="bar-group">
                            <div class="bar-stack">
                                <span class="bar present" style="height: 70%;"></span>
                                <span class="bar absent" style="height: 20%;"></span>
                                <span class="bar late" style="height: 10%;"></span>
                            </div>
                            <div class="bar-label">Mon</div>
                        </div>
                        <div class="bar-group">
                            <div class="bar-stack">
                                <span class="bar present" style="height: 84%;"></span>
                                <span class="bar absent" style="height: 12%;"></span>
                                <span class="bar late" style="height: 8%;"></span>
                            </div>
                            <div class="bar-label">Tue</div>
                        </div>
                        <div class="bar-group">
                            <div class="bar-stack">
                                <span class="bar present" style="height: 76%;"></span>
                                <span class="bar absent" style="height: 18%;"></span>
                                <span class="bar late" style="height: 10%;"></span>
                            </div>
                            <div class="bar-label">Wed</div>
                        </div>
                        <div class="bar-group">
                            <div class="bar-stack">
                                <span class="bar present" style="height: 85%;"></span>
                                <span class="bar absent" style="height: 12%;"></span>
                                <span class="bar late" style="height: 8%;"></span>
                            </div>
                            <div class="bar-label">Thu</div>
                        </div>
                        <div class="bar-group">
                            <div class="bar-stack">
                                <span class="bar present" style="height: 66%;"></span>
                                <span class="bar absent" style="height: 20%;"></span>
                                <span class="bar late" style="height: 16%;"></span>
                            </div>
                            <div class="bar-label">Fri</div>
                        </div>
                    </div>
                </div>

                <div class="side-panel">
                    <div class="panel-header" id="my-attendance-qr">
                        <div class="panel-title">My attendance QR</div>
                    </div>
                    <div class="qr-box" style="margin: 0.75rem auto;background:#fff;">
                        <div id="studentQrCode" aria-label="Your attendance QR code"></div>
                    </div>
                    <div class="progress-note">Show this code to your professor when checking in.</div>
                    <a class="secondary-btn" href="#my-subjects" style="display:flex;justify-content:center;margin-top:16px;background:rgba(255,255,255,0.08);color:#fff;border-color:rgba(255,255,255,0.14);">Review my subjects</a>
                </div>
            </div>

            <div class="lower-grid">
                <div class="table-panel" id="attendance-records">
                    <div class="panel-header" style="margin-bottom: 0;">
                        <div class="panel-title">Attendance history</div>
                        <?php if (count($recentLogs) > 5): ?>
                            <a href="#attendance-records" class="inline-link" id="attendanceToggle" onclick="toggleAttendanceHistory(event)">View all</a>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($recentLogs)): ?>
                        <div style="padding:1.2rem 0;color:var(--ink-muted);">No attendance records yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentLogs as $index => $log): ?>
                            <div class="table-row" <?= $index >= 5 ? 'data-extra-log style="display:none;"' : '' ?>>
                                <div class="activity-user">
                                    <div class="activity-avatar"><?= htmlspecialchars(substr($log['subject_code'], 0, 2)) ?></div>
                                    <div>
                                        <strong><?= htmlspecialchars($log['subject_name']) ?></strong>
                                        <small><?= htmlspecialchars($log['subject_code']) ?></small>
                                    </div>
                                </div>
                                <div><?= date('M d, Y', strtotime($log['date'])) ?></div>
                                <div><?= date('h:i A', strtotime($log['time_in'])) ?></div>
                                <div class="status-pill <?= strtolower($log['status']) === 'late' ? 'late' : (strtolower($log['status']) === 'absent' ? 'absent' : 'present') ?>"><?= htmlspecialchars($log['status']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="table-panel" id="my-subjects">
                    <div class="panel-header" style="margin-bottom: 0;">
                        <div class="panel-title">My subjects</div>
                        <div class="emergency-badge"><?= count($enrolledSubjects) ?> subjects</div>
                    </div>
                    <?php if (empty($enrolledSubjects)): ?>
                        <div style="padding:1.2rem 0;color:var(--ink-muted);">You are not enrolled in any subjects yet.</div>
                    <?php else: ?>
                        <?php foreach ($enrolledSubjects as $subject): ?>
                            <div class="table-row">
                                <div><strong><?= htmlspecialchars($subject['subject_code']) ?></strong><small><?= htmlspecialchars($subject['subject_name']) ?></small></div>
                                <div><?= htmlspecialchars($subject['day_of_week'] ?? 'Schedule TBA') ?></div>
                                <div><?= $subject['start_time'] ? date('h:i A', strtotime($subject['start_time'])) . ' - ' . date('h:i A', strtotime($subject['end_time'])) : '' ?></div>
                                <div class="status-pill present"><?= htmlspecialchars($subject['professor_name']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    const qrToken = <?= json_encode($student['qr_token'] ?? '') ?>;
    const qrTarget = document.getElementById('studentQrCode');
    if (qrToken && window.QRCode && qrTarget) {
        new QRCode(qrTarget, { text: qrToken, width: 180, height: 180 });
    } else if (qrTarget) {
        qrTarget.textContent = qrToken || 'QR code unavailable';
    }

    function toggleAttendanceHistory(event) {
        event.preventDefault();
        const toggle = document.getElementById('attendanceToggle');
        const rows = document.querySelectorAll('[data-extra-log]');
        const showAll = toggle.textContent.trim() === 'View all';
        rows.forEach(row => {
            row.style.display = showAll ? '' : 'none';
        });
        toggle.textContent = showAll ? 'Show less' : 'View all';
    }
</script>

</body>
</html>

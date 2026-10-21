<?php
// professor/reports.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();
$prof_id = $user['id'];

// Get all subjects
$subjectsStmt = $pdo->prepare("SELECT * FROM subjects WHERE professor_id = ? ORDER BY subject_code ASC");
$subjectsStmt->execute([$prof_id]);
$subjects = $subjectsStmt->fetchAll();

// Filters
$subject_filter = intval($_GET['subject_id'] ?? 0);
$start_date     = trim($_GET['start_date'] ?? date('Y-m-01')); // Start of current month
$end_date       = trim($_GET['end_date'] ?? date('Y-m-d'));
$status_filter  = trim($_GET['status'] ?? 'all');

// Build Query
$query = "
    SELECT a.*, u.name as student_name, u.identifier as student_id_number, u.course_section,
           s.subject_code, s.subject_name
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.scanned_by = :prof_id
      AND a.date BETWEEN :start_date AND :end_date
";
$params = [
    ':prof_id'    => $prof_id,
    ':start_date' => $start_date,
    ':end_date'   => $end_date
];

if ($subject_filter > 0) {
    $query .= " AND a.subject_id = :subject_id";
    $params[':subject_id'] = $subject_filter;
}

if (in_array($status_filter, ['Present', 'Late', 'Absent', 'Excused'])) {
    $query .= " AND a.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY a.date DESC, a.time_in DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$attendanceRecords = $stmt->fetchAll();

// Export to CSV feature
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_report_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['#', 'Student ID', 'Student Name', 'Course & Section', 'Subject Code', 'Subject Name', 'Date', 'Time In', 'Status']);
    
    $i = 1;
    foreach ($attendanceRecords as $row) {
        fputcsv($output, [
            $i++,
            $row['student_id_number'],
            $row['student_name'],
            $row['course_section'] ?? 'N/A',
            $row['subject_code'],
            $row['subject_name'],
            $row['date'],
            date('h:i A', strtotime($row['time_in'])),
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports - Professor Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .navbar, .filter-box, .btn-no-print {
                display: none !important;
            }
            body {
                background: white;
            }
            .card {
                border: none;
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-chart-line"></i></div>
        <span>Attendance Reports</span>
    </div>
    <ul class="nav-links">
        <li><a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a></li>
        <li><a href="scan.php"><i class="fa-solid fa-camera"></i> Scanner</a></li>
        <li><a href="scan.php"><i class="fa-solid fa-camera"></i> QR Scanner</a></li>
        <li><a href="manual_attendance.php"><i class="fa-solid fa-clipboard-check"></i> Manual Attendance</a></li>
        <li><a href="subjects.php"><i class="fa-solid fa-book-open"></i> Subjects</a></li>
        <li><a href="reports.php" class="active"><i class="fa-solid fa-chart-line"></i> Reports</a></li>
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
    <!-- Filter Card -->
    <div class="card filter-box">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-filter" style="color: var(--primary);"></i> Filter Attendance Logs</div>
        </div>

        <form action="reports.php" method="GET">
            <div class="grid-4">
                <div class="form-group">
                    <label class="form-label" for="subject_id">Subject</label>
                    <select id="subject_id" name="subject_id" class="form-control">
                        <option value="0">All Subjects</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $subject_filter ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['subject_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="start_date">From Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="end_date">To Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="Present" <?= $status_filter === 'Present' ? 'selected' : '' ?>>Present Only</option>
                        <option value="Late" <?= $status_filter === 'Late' ? 'selected' : '' ?>>Late Only</option>
                        <option value="Absent" <?= $status_filter === 'Absent' ? 'selected' : '' ?>>Absent Only</option>
                        <option value="Excused" <?= $status_filter === 'Excused' ? 'selected' : '' ?>>Excused Only</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-magnifying-glass"></i> Apply Filters
                </button>
                <a href="reports.php" class="btn btn-secondary btn-sm">Reset</a>
                
                <?php
                    $csvParams = $_GET;
                    $csvParams['export'] = 'csv';
                    $csvUrl = 'reports.php?' . http_build_query($csvParams);
                ?>
                <a href="<?= $csvUrl ?>" class="btn btn-success btn-sm btn-no-print">
                    <i class="fa-solid fa-file-csv"></i> Export to CSV / Excel
                </a>
                <button type="button" onclick="window.print()" class="btn btn-secondary btn-sm btn-no-print">
                    <i class="fa-solid fa-print"></i> Print Report
                </button>
            </div>
        </form>
    </div>

    <!-- Attendance Table Card -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">
                <i class="fa-solid fa-table-list" style="color: var(--secondary);"></i> Attendance Log Records
            </div>
            <span class="badge badge-primary"><?= count($attendanceRecords) ?> Records Found</span>
        </div>

        <?php if (empty($attendanceRecords)): ?>
            <div style="text-align: center; padding: 2.5rem; color: var(--gray-500);">
                <i class="fa-solid fa-magnifying-glass" style="font-size: 2.5rem; margin-bottom: 0.75rem; color: var(--gray-300);"></i>
                <p>No attendance records match your filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Course/Section</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Time In</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $num = 1; foreach ($attendanceRecords as $row): ?>
                            <tr>
                                <td><?= $num++ ?></td>
                                <td><span style="font-family: monospace; font-weight: 700;"><?= htmlspecialchars($row['student_id_number']) ?></span></td>
                                <td><strong><?= htmlspecialchars($row['student_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['course_section'] ?? 'N/A') ?></td>
                                <td><span class="badge badge-primary"><?= htmlspecialchars($row['subject_code']) ?></span></td>
                                <td><?= date('M d, Y', strtotime($row['date'])) ?></td>
                                <td><?= date('h:i A', strtotime($row['time_in'])) ?></td>
                                <td>
                                    <?php if ($row['status'] === 'Present'): ?>
                                        <span class="badge badge-success"><i class="fa-solid fa-check"></i> Present</span>
                                    <?php elseif ($row['status'] === 'Late'): ?>
                                        <span class="badge badge-warning"><i class="fa-solid fa-clock"></i> Late</span>
                                    <?php elseif ($row['status'] === 'Absent'): ?>
                                        <span class="badge badge-danger"><i class="fa-solid fa-xmark"></i> Absent</span>
                                    <?php elseif ($row['status'] === 'Excused'): ?>
                                        <span class="badge badge-info"><i class="fa-solid fa-star"></i> Excused</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><i class="fa-solid fa-clock"></i> Late</span>
                                        <span class="badge badge-secondary"><?= htmlspecialchars($row['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>

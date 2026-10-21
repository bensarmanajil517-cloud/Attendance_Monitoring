<?php
// professor/manual_attendance.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();
$prof_id = $user['id'];

// Get all subjects for this professor
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE professor_id = ? ORDER BY subject_code ASC");
$stmt->execute([$prof_id]);
$subjects = $stmt->fetchAll();

$selected_subject_id = intval($_GET['subject_id'] ?? ($subjects[0]['id'] ?? 0));
$selected_date       = trim($_GET['date'] ?? date('Y-m-d'));

$error = '';
$success = '';

// Handle Direct POST Fallback (Non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = trim($_POST['action'] ?? '');
    $student_id = intval($_POST['student_id'] ?? 0);
    $status     = trim($_POST['status'] ?? 'Present');
    $time_in    = date('H:i:s');

    if ($action === 'enroll_and_mark') {
        $student_identifier = trim($_POST['student_identifier'] ?? '');
        $findStmt = $pdo->prepare("SELECT id FROM users WHERE (identifier = :identifier OR email = :email) AND role = 'student' LIMIT 1");
        $findStmt->execute([':identifier' => $student_identifier, ':email' => $student_identifier]);
        $foundStu = $findStmt->fetch();

        if ($foundStu) {
            $student_id = $foundStu['id'];
            $pdo->prepare("INSERT IGNORE INTO student_subjects (student_id, subject_id) VALUES (?, ?)")->execute([$student_id, $selected_subject_id]);
            $pdo->prepare("
                INSERT INTO attendance (student_id, subject_id, date, time_in, status, scanned_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = VALUES(time_in)
            ")->execute([$student_id, $selected_subject_id, $selected_date, $time_in, $status, $prof_id]);
            $success = "Student enrolled and marked as $status!";
        } else {
            $error = "Student ID/Email not found.";
        }
    }
}

// Fetch active subject details and schedule
$subjectInfo = null;
if ($selected_subject_id > 0) {
    $sInfoStmt = $pdo->prepare("
        SELECT s.*, sc.day_of_week, sc.start_time, sc.end_time, sc.late_threshold_mins 
        FROM subjects s 
        LEFT JOIN schedules sc ON s.id = sc.subject_id 
        WHERE s.id = ?
    ");
    $sInfoStmt->execute([$selected_subject_id]);
    $subjectInfo = $sInfoStmt->fetch();
}

// Fetch enrolled students for this subject along with their attendance for $selected_date
$students = [];
if ($selected_subject_id > 0) {
    $rosterStmt = $pdo->prepare("
        SELECT u.id as student_id, u.identifier, u.name, u.course_section, u.email,
               a.id as attendance_id, a.time_in, a.status as attendance_status, a.date as attendance_date
        FROM student_subjects ss
        JOIN users u ON ss.student_id = u.id
        LEFT JOIN attendance a ON a.student_id = u.id AND a.subject_id = ? AND a.date = ?
        WHERE ss.subject_id = ?
        ORDER BY u.name ASC
    ");
    $rosterStmt->execute([$selected_subject_id, $selected_date, $selected_subject_id]);
    $students = $rosterStmt->fetchAll();
}

// Fetch all other students not yet enrolled in this subject (for quick enrollment dropdown)
$otherStudents = [];
if ($selected_subject_id > 0) {
    $otherStmt = $pdo->prepare("
        SELECT id, identifier, name, course_section FROM users 
        WHERE role = 'student' 
          AND id NOT IN (SELECT student_id FROM student_subjects WHERE subject_id = ?)
        ORDER BY name ASC
    ");
    $otherStmt->execute([$selected_subject_id]);
    $otherStudents = $otherStmt->fetchAll();
}

// Quick Attendance Counts for Selected Date
$presentCount = 0;
$lateCount    = 0;
$absentCount  = 0;
$excusedCount = 0;
$unmarkedCount = 0;

foreach ($students as $st) {
    if ($st['attendance_status'] === 'Present') $presentCount++;
    elseif ($st['attendance_status'] === 'Late') $lateCount++;
    elseif ($st['attendance_status'] === 'Absent') $absentCount++;
    elseif ($st['attendance_status'] === 'Excused') $excusedCount++;
    else $unmarkedCount++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Attendance Sheet - Professor Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .manual-header-card {
            background: white;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        .summary-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .quick-filter-input {
            max-width: 300px;
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-clipboard-check"></i></div>
        <span>Professor Portal</span>
    </div>
    <ul class="nav-links">
        <li><a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a></li>
        <li><a href="scan.php"><i class="fa-solid fa-camera"></i> QR Scanner</a></li>
        <li><a href="manual_attendance.php" class="active"><i class="fa-solid fa-clipboard-check"></i> Manual Attendance</a></li>
        <li><a href="subjects.php"><i class="fa-solid fa-book-open"></i> Subjects</a></li>
        <li><a href="reports.php"><i class="fa-solid fa-chart-line"></i> Reports</a></li>
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
    <!-- Notifications -->
    <div id="toast-message" style="display: none; margin-bottom: 1rem;"></div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <span><?= htmlspecialchars($success) ?></span></div>
    <?php endif; ?>

    <!-- Header / Control Box -->
    <div class="manual-header-card">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
            <div>
                <h1 style="font-size: 1.5rem; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-clipboard-user" style="color: var(--primary);"></i> Manual Attendance Checking
                </h1>
                <p style="color: var(--gray-500); font-size: 0.9rem;">
                    Use this sheet if camera QR scanning is unavailable or for manual student attendance checking.
                </p>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <button type="button" onclick="markAllEnrolledPresent()" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-check-double"></i> Mark All Present Today
                </button>
                <a href="scan.php?subject_id=<?= $selected_subject_id ?>" class="btn btn-primary btn-sm">
                    <i class="fa-solid fa-camera"></i> Open QR Scanner
                </a>
            </div>
        </div>

        <!-- Filter Selectors Form -->
        <form action="manual_attendance.php" method="GET" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
            <div style="flex: 1; min-width: 240px;">
                <label class="form-label" for="subject_id"><i class="fa-solid fa-book"></i> Subject / Class:</label>
                <select id="subject_id" name="subject_id" class="form-control" onchange="this.form.submit()">
                    <?php if (empty($subjects)): ?>
                        <option value="0">No subjects available</option>
                    <?php else: ?>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $selected_subject_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['subject_code']) ?> - <?= htmlspecialchars($s['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div style="width: 180px;">
                <label class="form-label" for="date"><i class="fa-solid fa-calendar-day"></i> Attendance Date:</label>
                <input type="date" id="date" name="date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()">
            </div>

            <div>
                <button type="submit" class="btn btn-secondary" style="height: 42px;">
                    <i class="fa-solid fa-arrows-rotate"></i> Load Sheet
                </button>
            </div>
        </form>

        <!-- Summary Status Row -->
        <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border); display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
            <span style="font-size: 0.85rem; font-weight: 700; color: var(--gray-700);">Attendance Status Summary:</span>
            <span class="summary-pill badge-success" id="badge-present-count"><i class="fa-solid fa-check"></i> Present: <?= $presentCount ?></span>
            <span class="summary-pill badge-warning" id="badge-late-count"><i class="fa-solid fa-clock"></i> Late: <?= $lateCount ?></span>
            <span class="summary-pill badge-danger" id="badge-absent-count"><i class="fa-solid fa-xmark"></i> Absent: <?= $absentCount ?></span>
            <span class="summary-pill badge-info" id="badge-excused-count"><i class="fa-solid fa-star"></i> Excused: <?= $excusedCount ?></span>
            <span class="summary-pill badge-secondary" id="badge-unmarked-count"><i class="fa-solid fa-circle-question"></i> Unmarked: <?= $unmarkedCount ?></span>
        </div>
    </div>

    <!-- Attendance Roster Card -->
    <div class="card">
        <div class="card-header" style="flex-wrap: wrap; gap: 0.75rem;">
            <div class="card-title">
                <i class="fa-solid fa-list-ol" style="color: var(--secondary);"></i> 
                Class Roster (<?= count($students) ?> Enrolled Students)
            </div>
            
            <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <!-- Quick Enroll Student Modal Trigger / Dropdown -->
                <?php if (!empty($otherStudents)): ?>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleAddStudentBox()">
                        <i class="fa-solid fa-user-plus"></i> + Enroll Student
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Student Drawer -->
        <?php if (!empty($otherStudents)): ?>
            <div id="addStudentBox" style="display: none; padding: 1rem; background: var(--gray-100); border-radius: 8px; margin-bottom: 1.25rem;">
                <form action="manual_attendance.php?subject_id=<?= $selected_subject_id ?>&date=<?= $selected_date ?>" method="POST" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end;">
                    <input type="hidden" name="action" value="enroll_and_mark">
                    <div style="flex: 1; min-width: 250px;">
                        <label class="form-label">Select Registered Student to Add to this Class:</label>
                        <select name="student_identifier" class="form-control" required>
                            <option value="">-- Choose Student --</option>
                            <?php foreach ($otherStudents as $os): ?>
                                <option value="<?= htmlspecialchars($os['identifier']) ?>">
                                    <?= htmlspecialchars($os['name']) ?> (<?= htmlspecialchars($os['identifier']) ?> - <?= htmlspecialchars($os['course_section'] ?? 'N/A') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Initial Status:</label>
                        <select name="status" class="form-control">
                            <option value="Present">Present</option>
                            <option value="Late">Late</option>
                            <option value="Absent">Absent</option>
                            <option value="Excused">Excused</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add & Mark</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (empty($students)): ?>
            <div style="text-align: center; padding: 3rem 1rem; color: var(--gray-500);">
                <i class="fa-solid fa-users-slash" style="font-size: 2.5rem; margin-bottom: 0.75rem; color: var(--gray-300);"></i>
                <p>No students enrolled in this subject yet.</p>
                <small>Students can register or you can add them using the "+ Enroll Student" button above.</small>
            </div>
        <?php else: ?>
            <div class="manual-shell" id="rosterTable">
                <div class="manual-search-wrap">
                    <input type="text" id="rosterSearch" class="manual-search" placeholder="Search student name or ID..." onkeyup="filterRosterTable()">
                </div>

                <div class="manual-actions-section">
                    <div class="manual-actions-title">Manual Mark Actions</div>

                    <?php foreach ($students as $stu): ?>
                        <div class="manual-attendance-row" id="row-student-<?= $stu['student_id'] ?>" data-search="<?= strtolower(htmlspecialchars($stu['name'] . ' ' . $stu['identifier'] . ' ' . ($stu['course_section'] ?? ''))) ?>">
                            <div class="manual-row-meta">
                                <strong><?= htmlspecialchars($stu['name']) ?></strong>
                                <span><?= htmlspecialchars($stu['identifier']) ?> · <?= htmlspecialchars($stu['course_section'] ?? 'N/A') ?></span>
                            </div>

                            <div class="manual-status-actions">
                                <button type="button" class="btn-status btn-present <?= $stu['attendance_status'] === 'Present' ? 'active-present' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Present')" title="Mark Present">
                                    <i class="fa-solid fa-check"></i> Present
                                </button>
                                <button type="button" class="btn-status btn-late <?= $stu['attendance_status'] === 'Late' ? 'active-late' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Late')" title="Mark Late">
                                    <i class="fa-solid fa-clock"></i> Late
                                </button>
                                <button type="button" class="btn-status btn-absent <?= $stu['attendance_status'] === 'Absent' ? 'active-absent' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Absent')" title="Mark Absent">
                                    <i class="fa-solid fa-xmark"></i> Absent
                                </button>
                                <button type="button" class="btn-status btn-excused <?= $stu['attendance_status'] === 'Excused' ? 'active-excused' : '' ?>"
                                        onclick="updateStudentAttendance(<?= $stu['student_id'] ?>, 'Excused')" title="Mark Excused">
                                    <i class="fa-solid fa-star"></i> Excused
                                </button>
                                <button type="button" class="btn-status btn-clear"
                                        onclick="clearStudentAttendance(<?= $stu['student_id'] ?>)" title="Clear / Reset Attendance">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const currentSubjectId = <?= (int)$selected_subject_id ?>;
    const currentDate = "<?= addslashes($selected_date) ?>";

    // Audio effects
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    function playBeep(freq = 880, duration = 0.12) {
        try {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch(e) {}
    }

    function showToast(msg, type = 'success') {
        const toast = document.getElementById("toast-message");
        toast.style.display = "block";
        toast.className = `alert alert-${type}`;
        toast.innerHTML = `<i class="fa-solid fa-circle-info"></i> <span>${msg}</span>`;
        setTimeout(() => {
            toast.style.display = "none";
        }, 3500);
    }

    function toggleAddStudentBox() {
        const box = document.getElementById("addStudentBox");
        box.style.display = box.style.display === "none" ? "block" : "none";
    }

    function filterRosterTable() {
        const q = document.getElementById("rosterSearch").value.toLowerCase();
        const rows = document.querySelectorAll("#rosterTable .manual-attendance-row");
        rows.forEach(r => {
            const text = r.getAttribute("data-search") || "";
            r.classList.toggle("hidden", q && !text.includes(q));
            if (!q) r.classList.remove("hidden");
        });
    }

    function updateStudentAttendance(studentId, status) {
        fetch('../api/manual_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'set_status',
                subject_id: currentSubjectId,
                student_id: studentId,
                date: currentDate,
                status: status
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                playBeep(880, 0.1);
                showToast(data.message, 'success');

                // Update Status Cell
                // Update button active states in row
                const row = document.getElementById(`row-student-${studentId}`);
                row.querySelectorAll(".btn-status").forEach(b => {
                    b.classList.remove('active-present', 'active-late', 'active-absent', 'active-excused');
                });
                if (status === 'Present') row.querySelector('.btn-present').classList.add('active-present');
                if (status === 'Late') row.querySelector('.btn-late').classList.add('active-late');
                if (status === 'Absent') row.querySelector('.btn-absent').classList.add('active-absent');
                if (status === 'Excused') row.querySelector('.btn-excused').classList.add('active-excused');

                recalculateCounts();
            } else {
                showToast(data.message || 'Error updating attendance', 'danger');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Server error while saving attendance.', 'danger');
        });
    }

    function clearStudentAttendance(studentId) {
        if (!confirm("Clear attendance record for this student on this date?")) return;

        fetch('../api/manual_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'clear_record',
                subject_id: currentSubjectId,
                student_id: studentId,
                date: currentDate
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message, 'info');
                const row = document.getElementById(`row-student-${studentId}`);
                row.querySelectorAll(".btn-status").forEach(b => {
                    b.classList.remove('active-present', 'active-late', 'active-absent', 'active-excused');
                });
                recalculateCounts();
            }
        });
    }

    function markAllEnrolledPresent() {
        if (!confirm(`Mark all enrolled students as Present for date ${currentDate}?`)) return;

        fetch('../api/manual_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'mark_all_present',
                subject_id: currentSubjectId,
                date: currentDate
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                playBeep(980, 0.18);
                showToast(data.message, 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 800);
            }
        });
    }

    function recalculateCounts() {
        const rows = document.querySelectorAll("#rosterTable .manual-attendance-row");
        let present = 0, late = 0, absent = 0, excused = 0, unmarked = 0;
        rows.forEach(r => {
            const activeButton = r.querySelector('.active-present, .active-late, .active-absent, .active-excused');
            if (!activeButton) {
                unmarked++;
                return;
            }
            if (activeButton.classList.contains('active-present')) present++;
            else if (activeButton.classList.contains('active-late')) late++;
            else if (activeButton.classList.contains('active-absent')) absent++;
            else if (activeButton.classList.contains('active-excused')) excused++;
        });

        document.getElementById("badge-present-count").innerHTML = `<i class="fa-solid fa-check"></i> Present: ${present}`;
        document.getElementById("badge-late-count").innerHTML = `<i class="fa-solid fa-clock"></i> Late: ${late}`;
        document.getElementById("badge-absent-count").innerHTML = `<i class="fa-solid fa-xmark"></i> Absent: ${absent}`;
        document.getElementById("badge-excused-count").innerHTML = `<i class="fa-solid fa-star"></i> Excused: ${excused}`;
        document.getElementById("badge-unmarked-count").innerHTML = `<i class="fa-solid fa-circle-question"></i> Unmarked: ${unmarked}`;
    }
</script>

</body>
</html>

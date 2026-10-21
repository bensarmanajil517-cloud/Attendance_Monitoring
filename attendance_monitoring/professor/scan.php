<?php
// professor/scan.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_check.php';

require_auth(['professor', 'admin']);
$user = current_user();
$pdo = get_db_connection();
$prof_id = $user['id'];

// Get all subjects of professor
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE professor_id = ? ORDER BY subject_code ASC");
$stmt->execute([$prof_id]);
$subjects = $stmt->fetchAll();

$selected_subject_id = intval($_GET['subject_id'] ?? ($subjects[0]['id'] ?? 0));

// Get today's attendance for this subject
$today = date('Y-m-d');
$logsStmt = $pdo->prepare("
    SELECT a.*, u.name as student_name, u.identifier as student_id_number, u.course_section
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    WHERE a.subject_id = ? AND a.date = ?
    ORDER BY a.time_in DESC
");
$logsStmt->execute([$selected_subject_id, $today]);
$recentScans = $logsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Web Camera Scanner - Professor Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- HTML5 QR Code Scanner Library -->
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        .scanner-container {
            background: #ffffff;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }
        #reader {
            width: 100% !important;
            max-width: 480px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--primary);
            background: #000;
        }
        #reader video {
            border-radius: 10px;
            object-fit: cover;
        }
        .scan-status-box {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin: 1.25rem 0;
            display: none;
            font-size: 1rem;
            font-weight: 600;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
    <div class="brand">
        <div class="brand-icon"><i class="fa-solid fa-camera"></i></div>
        <span>Live Attendance Scanner</span>
    </div>
    <ul class="nav-links">
        <li><a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a></li>
        <li><a href="scan.php" class="active"><i class="fa-solid fa-camera"></i> Scanner</a></li>
        <li><a href="scan.php" class="active"><i class="fa-solid fa-camera"></i> QR Scanner</a></li>
        <li><a href="manual_attendance.php?subject_id=<?= $selected_subject_id ?>"><i class="fa-solid fa-clipboard-check"></i> Manual Attendance</a></li>
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
    <div class="grid-2">
        <!-- Left: QR Scanner & Camera Controls -->
        <div class="scanner-container">
            <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--dark); margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fa-solid fa-video" style="color: var(--primary);"></i> Web Camera QR Scanner
            </h2>
            <p style="color: var(--gray-500); font-size: 0.9rem; margin-bottom: 1.25rem;">
                Point the student's unique QR code to the camera. Attendance will log automatically.
            </p>

            <!-- Subject Selector -->
            <div class="form-group">
                <label class="form-label" for="subject_select">
                    <i class="fa-solid fa-book-bookmark"></i> Select Active Subject / Class:
                </label>
                <select id="subject_select" class="form-control" onchange="changeSubject(this.value)" style="font-weight: 600;">
                    <?php if (empty($subjects)): ?>
                        <option value="0">No Subjects Available (Please add one first)</option>
                    <?php else: ?>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $selected_subject_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['subject_code']) ?> - <?= htmlspecialchars($s['subject_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Camera Viewport -->
            <div id="reader"></div>

            <!-- Camera Toggle Controls -->
            <div style="display: flex; gap: 0.75rem; justify-content: center; margin-top: 1rem;">
                <button id="btn-start" class="btn btn-primary btn-sm" onclick="startScanner()">
                    <i class="fa-solid fa-play"></i> Start Camera
                </button>
                <button id="btn-stop" class="btn btn-secondary btn-sm" onclick="stopScanner()" style="display: none;">
                    <i class="fa-solid fa-stop"></i> Stop Camera
                </button>
                <button class="btn btn-secondary btn-sm" onclick="switchCamera()">
                    <i class="fa-solid fa-camera-rotate"></i> Flip Camera
                </button>
            </div>

            <!-- Instant Status / Feedback Box -->
            <div id="scan-status" class="scan-status-box"></div>

            <!-- Manual Student ID Fallback -->
            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px dashed var(--border);">
                <span style="font-size: 0.85rem; font-weight: 600; color: var(--gray-500);">Manual Entry Fallback (Student ID):</span>
            <!-- QR Issue / Manual Attendance Banner -->
            <div style="margin-top: 1.5rem; padding: 1rem; background: var(--primary-light); border: 1px solid var(--border); border-radius: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <strong style="color: var(--primary); font-size: 0.95rem;">
                            <i class="fa-solid fa-triangle-exclamation"></i> Having QR or Camera Issues?
                        </strong>
                        <div style="font-size: 0.85rem; color: var(--gray-700);">Switch to full class roster manual attendance sheet.</div>
                    </div>
                    <a href="manual_attendance.php?subject_id=<?= $selected_subject_id ?>" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-clipboard-user"></i> Open Manual Sheet
                    </a>
                </div>
            </div>

            <!-- Manual Student ID Quick Input -->
            <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed var(--border);">
                <span style="font-size: 0.85rem; font-weight: 600; color: var(--gray-500);">Quick Student ID Input:</span>
                <div style="display: flex; gap: 0.5rem; margin-top: 0.4rem;">
                    <input type="text" id="manual_student_id" class="form-control" placeholder="e.g. STUD-2026-101">
                    <button class="btn btn-secondary" onclick="submitManualID()">Submit</button>
                </div>
            </div>
        </div>

        <!-- Right: Real-time Live Scanned List for this Session -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fa-solid fa-clipboard-user" style="color: var(--success);"></i> Scanned Students (Today)
                </div>
                <span id="scan-count-badge" class="badge badge-primary"><?= count($recentScans) ?> Scanned</span>
            </div>

            <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                <table class="table" id="scans-table">
                    <thead>
                        <tr>
                            <th>Student ID & Name</th>
                            <th>Time In</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="scans-tbody">
                        <?php if (empty($recentScans)): ?>
                            <tr id="no-scans-row">
                                <td colspan="3" style="text-align: center; color: var(--gray-500); padding: 2rem;">
                                    Waiting for students to scan their QR code...
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentScans as $scan): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($scan['student_name']) ?></strong><br>
                                        <small style="color: var(--gray-500);"><?= htmlspecialchars($scan['student_id_number']) ?> (<?= htmlspecialchars($scan['course_section'] ?? '') ?>)</small>
                                    </td>
                                    <td><?= date('h:i A', strtotime($scan['time_in'])) ?></td>
                                    <td>
                                        <?php if ($scan['status'] === 'Present'): ?>
                                            <span class="badge badge-success"><i class="fa-solid fa-check"></i> Present</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning"><i class="fa-solid fa-clock"></i> Late</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    let html5QrCode = null;
    let isScanning = false;
    let currentFacingMode = "environment"; // default to rear camera on mobile, webcam on PC
    let lastScannedCode = "";
    let lastScanTime = 0;
    const SCAN_THROTTLE_MS = 3000; // Ignore same code within 3 seconds

    // Web Audio Synthesized Sound Effects (No external files needed)
    const audioCtx = new (window.AudioContext || window.webkitAudioContext)();

    function playBeep(freq = 880, duration = 0.15, type = 'sine') {
        try {
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = type;
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.25, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + duration);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch(e) {
            console.warn("Audio error:", e);
        }
    }

    function playSuccessSound() {
        playBeep(880, 0.12, 'sine'); // High ping
        setTimeout(() => playBeep(1174, 0.18, 'sine'), 100);
    }

    function playWarningSound() {
        playBeep(440, 0.25, 'triangle'); // Double beep
        setTimeout(() => playBeep(440, 0.25, 'triangle'), 280);
    }

    function playErrorSound() {
        playBeep(220, 0.35, 'sawtooth'); // Low buzz
    }

    function changeSubject(subjectId) {
        window.location.href = `scan.php?subject_id=${subjectId}`;
    }

    function showStatus(message, type = 'success') {
        const box = document.getElementById("scan-status");
        box.style.display = "block";
        box.className = `scan-status-box alert alert-${type}`;
        
        let icon = '<i class="fa-solid fa-circle-check"></i>';
        if (type === 'danger') icon = '<i class="fa-solid fa-triangle-exclamation"></i>';
        if (type === 'warning') icon = '<i class="fa-solid fa-clock"></i>';
        if (type === 'info') icon = '<i class="fa-solid fa-circle-info"></i>';

        box.innerHTML = `${icon} <div>${message}</div>`;

        // Hide after 4 seconds
        setTimeout(() => {
            box.style.display = "none";
        }, 4000);
    }

    function startScanner() {
        if (isScanning) return;

        html5QrCode = new Html5Qrcode("reader");
        const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0
        };

        html5QrCode.start(
            { facingMode: currentFacingMode },
            config,
            onScanSuccess,
            (errorMessage) => { /* ignore frame scan misses */ }
        ).then(() => {
            isScanning = true;
            document.getElementById("btn-start").style.display = "none";
            document.getElementById("btn-stop").style.display = "inline-flex";
        }).catch((err) => {
            console.error("Camera Error:", err);
            showStatus("Camera access denied or no camera device found. Please allow camera permissions.", "danger");
        });
    }

    function stopScanner() {
        if (!isScanning || !html5QrCode) return;
        html5QrCode.stop().then(() => {
            isScanning = false;
            document.getElementById("btn-start").style.display = "inline-flex";
            document.getElementById("btn-stop").style.display = "none";
        }).catch(err => console.error(err));
    }

    function switchCamera() {
        if (!isScanning) return;
        stopScanner();
        currentFacingMode = currentFacingMode === "environment" ? "user" : "environment";
        setTimeout(startScanner, 400);
    }

    // Called when a QR code is detected
    function onScanSuccess(decodedText, decodedResult) {
        const now = Date.now();
        if (decodedText === lastScannedCode && (now - lastScanTime) < SCAN_THROTTLE_MS) {
            return; // Throttled duplicate frame
        }

        lastScannedCode = decodedText;
        lastScanTime = now;

        processAttendanceScan(decodedText);
    }

    function submitManualID() {
        const input = document.getElementById("manual_student_id");
        const val = input.value.trim();
        if (!val) {
            showStatus("Please enter a Student ID.", "warning");
            return;
        }
        processAttendanceScan(val);
        input.value = "";
    }

    function processAttendanceScan(qrTokenOrId) {
        const subjectId = document.getElementById("subject_select").value;

        if (!subjectId || subjectId == "0") {
            showStatus("Please select an active subject first.", "warning");
            playWarningSound();
            return;
        }

        fetch('../api/log_attendance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                qr_token: qrTokenOrId,
                subject_id: subjectId
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                playSuccessSound();
                showStatus(`<strong>${data.student.name}</strong> (${data.student.identifier}) logged as <strong>${data.attendance.status}</strong> at ${data.attendance.time_in}!`, data.attendance.status === 'Present' ? 'success' : 'warning');
                addScanToTable(data.student, data.attendance);
            } else if (data.status === 'already_recorded') {
                playWarningSound();
                showStatus(`<strong>${data.student.name}</strong>: ${data.message} (${data.attendance.time_in})`, 'info');
            } else {
                playErrorSound();
                showStatus(data.message || 'Error processing QR Code', 'danger');
            }
        })
        .catch(err => {
            console.error(err);
            playErrorSound();
            showStatus('Network/Server error while recording attendance.', 'danger');
        });
    }

    function addScanToTable(student, attendance) {
        const tbody = document.getElementById("scans-tbody");
        const noScansRow = document.getElementById("no-scans-row");
        if (noScansRow) {
            noScansRow.remove();
        }

        const badgeClass = attendance.status === 'Present' ? 'badge-success' : 'badge-warning';
        const badgeIcon = attendance.status === 'Present' ? 'fa-check' : 'fa-clock';

        const row = document.createElement("tr");
        row.style.animation = "fadeIn 0.5s ease";
        row.innerHTML = `
            <td>
                <strong>${escapeHtml(student.name)}</strong><br>
                <small style="color: var(--gray-500);">${escapeHtml(student.identifier)} (${escapeHtml(student.course_section || '')})</small>
            </td>
            <td>${escapeHtml(attendance.time_in)}</td>
            <td>
                <span class="badge ${badgeClass}"><i class="fa-solid ${badgeIcon}"></i> ${escapeHtml(attendance.status)}</span>
            </td>
        `;

        tbody.insertBefore(row, tbody.firstChild);

        // Update badge count
        const badge = document.getElementById("scan-count-badge");
        if (badge) {
            const currentCount = tbody.querySelectorAll("tr").length;
            badge.innerText = `${currentCount} Scanned`;
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }

    // Auto-start camera when page loads
    window.addEventListener("DOMContentLoaded", () => {
        startScanner();
    });
</script>

</body>
</html>

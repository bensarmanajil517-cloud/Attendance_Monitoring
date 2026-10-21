-- Student Attendance Monitoring System Database Schema
CREATE DATABASE IF NOT EXISTS `attendance_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `attendance_db`;

-- 1. Users Table (Students, Professors, Admins)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Student ID or Employee ID',
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('student', 'professor', 'admin') NOT NULL DEFAULT 'student',
    `course_section` VARCHAR(50) NULL COMMENT 'e.g. BSIT 3-A',
    `qr_token` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Unique hash used for the Student QR code',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Subjects Table
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_code` VARCHAR(20) NOT NULL,
    `subject_name` VARCHAR(100) NOT NULL,
    `professor_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`professor_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Schedules Table
CREATE TABLE IF NOT EXISTS `schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_id` INT NOT NULL,
    `day_of_week` ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `late_threshold_mins` INT DEFAULT 15 COMMENT 'Minutes after start time considered late',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Student Subject Enrollment Table
CREATE TABLE IF NOT EXISTS `student_subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `subject_id` INT NOT NULL,
    `enrolled_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_student_subject` (`student_id`, `subject_id`),
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Attendance Records Table
CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `subject_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `time_in` TIME NOT NULL,
    `status` ENUM('Present', 'Late', 'Absent', 'Excused') NOT NULL DEFAULT 'Present',
    `scanned_by` INT NOT NULL COMMENT 'Professor ID who scanned',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_daily_attendance` (`student_id`, `subject_id`, `date`),
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`subject_id`) REFERENCES `subjects`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`scanned_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================
-- Sample Initial Data (Default password for all sample accounts is: password123)
-- Password hash generated with password_hash('password123', PASSWORD_BCRYPT)
-- ==========================================================
INSERT INTO `users` (`identifier`, `name`, `email`, `password`, `role`, `course_section`, `qr_token`) VALUES
('ADMIN-2026-001', 'System Administrator', 'admin@school.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'admin', NULL, 'ADMIN_TOKEN_001'),
('PROF-2026-001', 'Prof. Juan Dela Cruz', 'prof.juan@school.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'professor', NULL, 'PROF_TOKEN_JUAN_001'),
('STUD-2026-101', 'Maria Clara Santos', 'maria.santos@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'BSIT 3-A', 'STU_QR_7f8d9a2b1c3e4f506172'),
('STUD-2026-102', 'Crisostomo Ibarra', 'crisostomo.ibarra@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'BSIT 3-A', 'STU_QR_9a8b7c6d5e4f3a2b1098'),
('STUD-2026-103', 'Basilio Gomez', 'basilio.gomez@student.edu.ph', '$2y$10$hWvMSDk54XvI2SLszIYFV.L8Yr4nhlnoX8uV5XSHb7TDtjKn35PuW', 'student', 'BSIT 3-A', 'STU_QR_3c4d5e6f7a8b9c0d1234')
ON DUPLICATE KEY UPDATE `identifier` = VALUES(`identifier`);

-- Sample Subjects for Prof Juan Dela Cruz (Assumes ID 1)
INSERT INTO `subjects` (`id`, `subject_code`, `subject_name`, `professor_id`) VALUES
(1, 'IT301', 'Web Systems and Technologies', 1),
(2, 'IT302', 'Database Administration', 1)
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- Sample Schedules
INSERT INTO `schedules` (`subject_id`, `day_of_week`, `start_time`, `end_time`, `late_threshold_mins`) VALUES
(1, 'Monday', '08:00:00', '11:00:00', 15),
(1, 'Wednesday', '08:00:00', '11:00:00', 15),
(2, 'Tuesday', '13:00:00', '16:00:00', 15)
ON DUPLICATE KEY UPDATE `subject_id` = VALUES(`subject_id`);
-- Sample Enrolled Students
INSERT INTO `student_subjects` (`student_id`, `subject_id`) VALUES
(2, 1),
(3, 1),
(4, 1),
(2, 2),
(3, 2)
ON DUPLICATE KEY UPDATE `student_id` = VALUES(`student_id`);

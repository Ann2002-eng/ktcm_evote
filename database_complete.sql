-- ============================================
-- KTCM E-VOTE — PRODUCTION DATABASE
-- Optimized for 3,000-5,000 students
-- ============================================

CREATE DATABASE IF NOT EXISTS eschool_voting;
USE eschool_voting;

DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS votes;
DROP TABLE IF EXISTS candidates;
DROP TABLE IF EXISTS positions;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS election_settings;
DROP TABLE IF EXISTS admins;
DROP TABLE IF EXISTS login_attempts;

-- STUDENTS TABLE
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    class VARCHAR(50) NOT NULL,
    pin VARCHAR(255) NOT NULL,
    has_voted TINYINT(1) DEFAULT 0,
    device_fingerprint VARCHAR(255) DEFAULT NULL,
    voted_at DATETIME DEFAULT NULL,
    receipt_code VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- INDEXES for fast lookups on 5000+ students
    INDEX idx_student_id (student_id),
    INDEX idx_has_voted (has_voted),
    INDEX idx_receipt (receipt_code)
);

-- POSITIONS TABLE
CREATE TABLE positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT,
    display_order INT DEFAULT 0,
    INDEX idx_order (display_order)
);

-- CANDIDATES TABLE
CREATE TABLE candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    position_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    class VARCHAR(50) NOT NULL,
    manifesto TEXT,
    photo LONGTEXT DEFAULT NULL,
    vote_count INT DEFAULT 0,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    INDEX idx_position (position_id),
    INDEX idx_votes (vote_count DESC)
);

-- VOTES TABLE
CREATE TABLE votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_code VARCHAR(20) NOT NULL,
    position_id INT NOT NULL,
    candidate_id INT NOT NULL,
    voted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (position_id) REFERENCES positions(id),
    FOREIGN KEY (candidate_id) REFERENCES candidates(id),
    INDEX idx_receipt (receipt_code),
    INDEX idx_position (position_id),
    INDEX idx_candidate (candidate_id)
);

-- ELECTION SETTINGS TABLE
CREATE TABLE election_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_name VARCHAR(200) NOT NULL,
    school_name VARCHAR(200) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    status ENUM('pending','active','closed') DEFAULT 'pending',
    winner_announced TINYINT(1) DEFAULT 0
);

-- ADMINS TABLE
CREATE TABLE admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    INDEX idx_username (username)
);

-- AUDIT LOG TABLE
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    performed_by VARCHAR(100) DEFAULT 'System',
    performed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(50) DEFAULT NULL,
    INDEX idx_action (action),
    INDEX idx_time (performed_at)
);

-- LOGIN ATTEMPTS TABLE (for rate limiting)
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(50) NOT NULL,
    student_id VARCHAR(50) DEFAULT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address),
    INDEX idx_time (attempted_at)
);

-- ============================================
-- SAMPLE DATA
-- ============================================

INSERT INTO admins (username, password, full_name) VALUES
('admin', 'admin123', 'System Administrator');

INSERT INTO election_settings (election_name, school_name, start_time, end_time, status) VALUES
('2026 Student Leadership Elections', 'Kiharu Technical College Murang''a', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 'active');

INSERT INTO positions (title, description, display_order) VALUES
('School President', 'Overall student body leader', 1),
('Vice President', 'Assistant to the school president', 2),
('Secretary General', 'Handles communication and records', 3),
('Treasurer', 'Manages student council finances', 4);

INSERT INTO candidates (position_id, full_name, class, manifesto) VALUES
(1, 'Alice Wanjiku', 'ICT Diploma 2', 'I will improve school Wi-Fi, introduce student mentorship programs, and ensure every student voice is heard. Together we rise!'),
(1, 'Brian Kamau', 'Business Diploma 3', 'My agenda: Better canteen food, more study spaces, and transparent student council operations. Vote for real change!'),
(1, 'Carol Muthoni', 'Engineering Diploma 2', 'Stronger industry attachments, improved labs, and a student welfare fund. I bring leadership with experience!'),
(2, 'Daniel Otieno', 'ICT Diploma 3', 'Supporting the president with innovation and discipline. I will bridge the gap between students and management.'),
(2, 'Faith Njeri', 'Nursing Diploma 2', 'I believe in unity and service. My focus is on mental health awareness and student counseling support.'),
(3, 'George Mwangi', 'Business Diploma 2', 'Transparent communication, proper meeting minutes, and an active student notice board. Accountability starts here!'),
(3, 'Hannah Achieng', 'ICT Diploma 1', 'Digital-first secretary. I will create an online student portal and keep everyone informed in real time.'),
(4, 'Isaac Njoroge', 'Accounting Diploma 3', 'Certified financial management skills. I will account for every shilling and publish monthly council reports.'),
(4, 'Jane Wambui', 'Business Diploma 1', 'Financial discipline and student empowerment. My goal: a fully-funded student welfare kitty by June.');

INSERT INTO students (student_id, full_name, class, pin) VALUES
('KTCM/001/2026', 'John Maina', 'ICT Diploma 2', '1234'),
('KTCM/002/2026', 'Mary Wangui', 'Business Diploma 3', '1234'),
('KTCM/003/2026', 'Peter Kariuki', 'Engineering Diploma 2', '1234'),
('KTCM/004/2026', 'Susan Nyambura', 'Nursing Diploma 2', '1234'),
('KTCM/005/2026', 'James Ochieng', 'ICT Diploma 1', '1234');

INSERT INTO audit_log (action, description, performed_by) VALUES
('SYSTEM_INIT', 'Production database initialized with indexes', 'System');

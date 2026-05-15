<?php
// ============================================
// API.PHP — Production Ready
// Rate limiting + Transactions + Bulk Import
// Kiharu Technical College Murang'a
// ============================================
require_once 'config.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

switch ($action) {

    // ============ STUDENT LOGIN ============
    case 'login':
        // Rate limit check
        if (!checkRateLimit($conn, $ip)) {
            jsonResponse(['success'=>false,'message'=>'Too many attempts. Please wait 15 minutes.']);
        }
        $student_id = sanitize($conn, $_POST['student_id'] ?? '');
        $pin        = $_POST['pin'] ?? '';
        if (!$student_id || !$pin) jsonResponse(['success'=>false,'message'=>'Please enter Student ID and PIN.']);

        logAttempt($conn, $ip, $student_id);

        $result = $conn->query("SELECT * FROM students WHERE student_id='$student_id' LIMIT 1");
        if ($result->num_rows === 0) jsonResponse(['success'=>false,'message'=>'Student ID not found.']);
        $student = $result->fetch_assoc();

        $pinMatch = ($pin === $student['pin']) || password_verify($pin, $student['pin']);
        if (!$pinMatch) jsonResponse(['success'=>false,'message'=>'Incorrect PIN.']);

        // Regenerate session ID on login (session fixation protection)
        session_regenerate_id(true);
        $_SESSION['student_id']   = $student['id'];
        $_SESSION['student_name'] = $student['full_name'];
        $_SESSION['has_voted']    = $student['has_voted'];

        jsonResponse(['success'=>true,'has_voted'=>(bool)$student['has_voted'],'receipt_code'=>$student['receipt_code'],'name'=>$student['full_name']]);

    // ============ CHANGE STUDENT PIN ============
    case 'change_pin':
        if (!isset($_SESSION['student_id'])) jsonResponse(['success'=>false,'message'=>'Not logged in.']);
        $old_pin = $_POST['old_pin'] ?? '';
        $new_pin = sanitize($conn, $_POST['new_pin'] ?? '');
        if (!$old_pin || !$new_pin) jsonResponse(['success'=>false,'message'=>'All fields required.']);
        if (strlen($new_pin) < 4) jsonResponse(['success'=>false,'message'=>'PIN must be at least 4 characters.']);
        $id = $_SESSION['student_id'];
        $result = $conn->query("SELECT pin FROM students WHERE id=$id LIMIT 1");
        $stu = $result->fetch_assoc();
        $pinMatch = ($old_pin === $stu['pin']) || password_verify($old_pin, $stu['pin']);
        if (!$pinMatch) jsonResponse(['success'=>false,'message'=>'Current PIN is incorrect.']);
        $hashed = password_hash($new_pin, PASSWORD_DEFAULT);
        $conn->query("UPDATE students SET pin='$hashed' WHERE id=$id");
        jsonResponse(['success'=>true,'message'=>'PIN changed successfully.']);

    // ============ GET ELECTION ============
    case 'get_election':
        $election = $conn->query("SELECT * FROM election_settings LIMIT 1")->fetch_assoc();
        jsonResponse(['success'=>true,'election'=>$election]);

    // ============ GET CANDIDATES ============
    case 'get_candidates':
        $positions = [];
        $posResult = $conn->query("SELECT * FROM positions ORDER BY display_order");
        while ($pos = $posResult->fetch_assoc()) {
            $pos_id = (int)$pos['id'];
            $candidates = [];
            $cr = $conn->query("SELECT id,position_id,full_name,class,manifesto,vote_count FROM candidates WHERE position_id=$pos_id");
            while ($c = $cr->fetch_assoc()) { $candidates[] = $c; }
            $pos['candidates'] = $candidates;
            $positions[] = $pos;
        }
        jsonResponse(['success'=>true,'positions'=>$positions]);

    // ============ CAST VOTE (with transaction) ============
    case 'cast_vote':
        if (!isset($_SESSION['student_id'])) jsonResponse(['success'=>false,'message'=>'Not logged in.']);
        $student_db_id = (int)$_SESSION['student_id'];
        $votes       = $_POST['votes'] ?? [];
        $fingerprint = sanitize($conn, $_POST['fingerprint'] ?? '');

        // BEGIN TRANSACTION — prevents race conditions with concurrent voters
        $conn->begin_transaction();
        try {
            // Lock the student row
            $check = $conn->query("SELECT has_voted, full_name FROM students WHERE id=$student_db_id LIMIT 1 FOR UPDATE");
            $stu   = $check->fetch_assoc();
            if ($stu['has_voted']) {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'You have already voted.']);
            }

            $totalPositions = $conn->query("SELECT COUNT(*) as c FROM positions")->fetch_assoc()['c'];
            if (count($votes) < $totalPositions) {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'Please vote for all positions.']);
            }

            $receipt = generateReceipt();
            $now     = date('Y-m-d H:i:s');

            foreach ($votes as $position_id => $candidate_id) {
                $pos_id  = (int)$position_id;
                $cand_id = (int)$candidate_id;
                $conn->query("INSERT INTO votes (receipt_code, position_id, candidate_id, voted_at) VALUES ('$receipt', $pos_id, $cand_id, '$now')");
                $conn->query("UPDATE candidates SET vote_count = vote_count + 1 WHERE id=$cand_id");
            }

            $conn->query("UPDATE students SET has_voted=1, voted_at='$now', receipt_code='$receipt', device_fingerprint='$fingerprint' WHERE id=$student_db_id");
            $conn->commit();

            logAudit($conn, 'VOTE_CAST', "Receipt: $receipt", $stu['full_name']);
            jsonResponse(['success'=>true,'receipt_code'=>$receipt]);

        } catch (Exception $e) {
            $conn->rollback();
            jsonResponse(['success'=>false,'message'=>'Vote failed. Please try again.']);
        }

    // ============ VERIFY RECEIPT ============
    case 'verify_receipt':
        $receipt = sanitize($conn, $_POST['receipt'] ?? '');
        if (!$receipt) jsonResponse(['success'=>false,'message'=>'Enter a receipt code.']);
        $result = $conn->query("SELECT v.*, p.title as position_title FROM votes v JOIN positions p ON v.position_id=p.id WHERE v.receipt_code='$receipt'");
        if ($result->num_rows === 0) jsonResponse(['success'=>false,'message'=>'Receipt code not found.']);
        $entries = [];
        while ($row = $result->fetch_assoc()) { $entries[] = ['position'=>$row['position_title'],'voted_at'=>$row['voted_at']]; }
        jsonResponse(['success'=>true,'entries'=>$entries,'message'=>'Your vote was recorded successfully!']);

    // ============ GET RESULTS ============
    case 'get_results':
        $positions   = [];
        $posResult   = $conn->query("SELECT * FROM positions ORDER BY display_order");
        $totalVoters = (int)$conn->query("SELECT COUNT(*) as t FROM students")->fetch_assoc()['t'];
        $totalVoted  = (int)$conn->query("SELECT COUNT(*) as t FROM students WHERE has_voted=1")->fetch_assoc()['t'];
        while ($pos = $posResult->fetch_assoc()) {
            $pos_id = (int)$pos['id'];
            $cr = $conn->query("SELECT id,full_name,class,vote_count FROM candidates WHERE position_id=$pos_id ORDER BY vote_count DESC");
            $rows = []; $maxVotes = 0;
            while ($c = $cr->fetch_assoc()) { if ($c['vote_count'] > $maxVotes) $maxVotes = $c['vote_count']; $rows[] = $c; }
            foreach ($rows as &$r) {
                $r['percentage'] = $totalVoted > 0 ? round(($r['vote_count']/$totalVoted)*100,1) : 0;
                $r['is_leading'] = ($r['vote_count'] == $maxVotes && $maxVotes > 0);
            }
            $pos['candidates'] = $rows;
            $positions[] = $pos;
        }
        jsonResponse(['success'=>true,'positions'=>$positions,'total_voters'=>$totalVoters,'total_voted'=>$totalVoted,'turnout_percent'=>$totalVoters>0?round(($totalVoted/$totalVoters)*100,1):0]);

    // ============ ADMIN LOGIN ============
    case 'admin_login':
        if (!checkRateLimit($conn, $ip)) {
            jsonResponse(['success'=>false,'message'=>'Too many attempts. Wait 15 minutes.']);
        }
        $username = sanitize($conn, $_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        logAttempt($conn, $ip, $username);
        $result = $conn->query("SELECT * FROM admins WHERE username='$username' LIMIT 1");
        if ($result->num_rows === 0) jsonResponse(['success'=>false,'message'=>'Invalid credentials.']);
        $admin = $result->fetch_assoc();
        $passMatch = ($password === $admin['password']) || password_verify($password, $admin['password']);
        if (!$passMatch) jsonResponse(['success'=>false,'message'=>'Invalid credentials.']);
        session_regenerate_id(true);
        $_SESSION['admin_id']   = $admin['id'];
        $_SESSION['admin_name'] = $admin['full_name'];
        logAudit($conn, 'ADMIN_LOGIN', 'Admin logged in', $admin['full_name']);
        jsonResponse(['success'=>true,'name'=>$admin['full_name']]);

    // ============ ADMIN CHANGE PASSWORD ============
    case 'admin_change_password':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $old = $_POST['old_password'] ?? '';
        $new = sanitize($conn, $_POST['new_password'] ?? '');
        if (!$old || !$new) jsonResponse(['success'=>false,'message'=>'All fields required.']);
        if (strlen($new) < 6) jsonResponse(['success'=>false,'message'=>'New password must be at least 6 characters.']);
        $id = (int)$_SESSION['admin_id'];
        $admin = $conn->query("SELECT password FROM admins WHERE id=$id LIMIT 1")->fetch_assoc();
        $match = ($old === $admin['password']) || password_verify($old, $admin['password']);
        if (!$match) jsonResponse(['success'=>false,'message'=>'Current password is incorrect.']);
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $conn->query("UPDATE admins SET password='$hashed' WHERE id=$id");
        logAudit($conn, 'ADMIN_PASSWORD_CHANGE', 'Admin changed password', $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Password changed successfully.']);

    // ============ ADMIN STATS ============
    case 'admin_stats':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $stats = [
            'total_students'   => (int)$conn->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'],
            'total_voted'      => (int)$conn->query("SELECT COUNT(*) as c FROM students WHERE has_voted=1")->fetch_assoc()['c'],
            'total_candidates' => (int)$conn->query("SELECT COUNT(*) as c FROM candidates")->fetch_assoc()['c'],
            'total_positions'  => (int)$conn->query("SELECT COUNT(*) as c FROM positions")->fetch_assoc()['c'],
        ];
        $stats['turnout'] = $stats['total_students'] > 0 ? round(($stats['total_voted']/$stats['total_students'])*100,1) : 0;
        $election = $conn->query("SELECT * FROM election_settings LIMIT 1")->fetch_assoc();
        jsonResponse(['success'=>true,'stats'=>$stats,'election'=>$election]);

    // ============ GET ALL STUDENTS ============
    case 'get_students':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $search = sanitize($conn, $_POST['search'] ?? '');
        $where  = $search ? "WHERE student_id LIKE '%$search%' OR full_name LIKE '%$search%' OR class LIKE '%$search%'" : '';
        $result = $conn->query("SELECT id,student_id,full_name,class,has_voted,voted_at,receipt_code,created_at FROM students $where ORDER BY created_at DESC");
        $students = [];
        while ($row = $result->fetch_assoc()) { $students[] = $row; }
        jsonResponse(['success'=>true,'students'=>$students]);

    // ============ ADD STUDENT ============
    case 'add_student':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $student_id = sanitize($conn, $_POST['student_id'] ?? '');
        $full_name  = sanitize($conn, $_POST['full_name'] ?? '');
        $class      = sanitize($conn, $_POST['class'] ?? '');
        $pin        = $_POST['pin'] ?? '1234';
        if (!$student_id || !$full_name || !$class) jsonResponse(['success'=>false,'message'=>'All fields are required.']);
        $check = $conn->query("SELECT id FROM students WHERE student_id='$student_id' LIMIT 1");
        if ($check->num_rows > 0) jsonResponse(['success'=>false,'message'=>'Student ID already exists.']);
        $hashed = password_hash($pin, PASSWORD_DEFAULT);
        $conn->query("INSERT INTO students (student_id,full_name,class,pin) VALUES ('$student_id','$full_name','$class','$hashed')");
        logAudit($conn, 'STUDENT_ADDED', "Added: $full_name ($student_id)", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Student added successfully.']);

    // ============ BULK IMPORT STUDENTS (CSV) ============
    case 'bulk_import':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $csv_data = $_POST['csv_data'] ?? '';
        if (!$csv_data) jsonResponse(['success'=>false,'message'=>'No CSV data provided.']);

        $lines   = explode("\n", trim($csv_data));
        $added   = 0; $skipped = 0; $errors = [];

        // Skip header row if present
        $start = 0;
        if (stripos($lines[0], 'student_id') !== false || stripos($lines[0], 'name') !== false) {
            $start = 1;
        }

        $conn->begin_transaction();
        try {
            for ($i = $start; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (!$line) continue;
                $parts = str_getcsv($line);
                if (count($parts) < 3) { $errors[] = "Row ".($i+1).": needs 3 columns (ID, Name, Class)"; $skipped++; continue; }
                $sid   = $conn->real_escape_string(trim($parts[0]));
                $name  = $conn->real_escape_string(trim($parts[1]));
                $class = $conn->real_escape_string(trim($parts[2]));
                $pin   = isset($parts[3]) ? trim($parts[3]) : '1234';
                if (!$sid || !$name || !$class) { $skipped++; continue; }
                $exists = $conn->query("SELECT id FROM students WHERE student_id='$sid' LIMIT 1");
                if ($exists->num_rows > 0) { $skipped++; continue; }
                $hashed = password_hash($pin, PASSWORD_DEFAULT);
                $conn->query("INSERT INTO students (student_id,full_name,class,pin) VALUES ('$sid','$name','$class','$hashed')");
                $added++;
            }
            $conn->commit();
            logAudit($conn, 'BULK_IMPORT', "Imported $added students, skipped $skipped", $_SESSION['admin_name']);
            jsonResponse(['success'=>true,'message'=>"✅ Imported $added students. Skipped $skipped duplicates.",'errors'=>$errors]);
        } catch (Exception $e) {
            $conn->rollback();
            jsonResponse(['success'=>false,'message'=>'Import failed. Please check your CSV format.']);
        }

    // ============ EDIT STUDENT ============
    case 'edit_student':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $id        = (int)$_POST['id'];
        $full_name = sanitize($conn, $_POST['full_name'] ?? '');
        $class     = sanitize($conn, $_POST['class'] ?? '');
        if (!$id || !$full_name || !$class) jsonResponse(['success'=>false,'message'=>'All fields required.']);
        $conn->query("UPDATE students SET full_name='$full_name', class='$class' WHERE id=$id");
        logAudit($conn, 'STUDENT_EDITED', "Edited student ID: $id", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Student updated successfully.']);

    // ============ DELETE STUDENT ============
    case 'delete_student':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM students WHERE id=$id");
        logAudit($conn, 'STUDENT_DELETED', "Deleted student ID: $id", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Student deleted.']);

    // ============ RESET STUDENT PIN ============
    case 'reset_student_pin':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $id  = (int)$_POST['id'];
        $pin = $_POST['pin'] ?? '1234';
        $hashed = password_hash($pin, PASSWORD_DEFAULT);
        $conn->query("UPDATE students SET pin='$hashed' WHERE id=$id");
        logAudit($conn, 'PIN_RESET', "Reset PIN for student ID: $id", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'PIN reset successfully.']);

    // ============ GET CANDIDATES (ADMIN) ============
    case 'get_candidates_admin':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $result = $conn->query("SELECT c.id,c.position_id,c.full_name,c.class,c.manifesto,c.vote_count,p.title as position_title FROM candidates c JOIN positions p ON c.position_id=p.id ORDER BY p.display_order,c.full_name");
        $candidates = [];
        while ($row = $result->fetch_assoc()) { $candidates[] = $row; }
        jsonResponse(['success'=>true,'candidates'=>$candidates]);

    // ============ ADD CANDIDATE ============
    case 'add_candidate':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $position_id = (int)$_POST['position_id'];
        $full_name   = sanitize($conn, $_POST['full_name'] ?? '');
        $class       = sanitize($conn, $_POST['class'] ?? '');
        $manifesto   = sanitize($conn, $_POST['manifesto'] ?? '');
        $photo       = $_POST['photo'] ?? '';
        if (!$position_id || !$full_name || !$class) jsonResponse(['success'=>false,'message'=>'Required fields missing.']);
        $photo_escaped = $conn->real_escape_string($photo);
        $conn->query("INSERT INTO candidates (position_id,full_name,class,manifesto,photo) VALUES ($position_id,'$full_name','$class','$manifesto','$photo_escaped')");
        logAudit($conn, 'CANDIDATE_ADDED', "Added: $full_name", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Candidate added successfully.']);

    // ============ EDIT CANDIDATE ============
    case 'edit_candidate':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $id          = (int)$_POST['id'];
        $full_name   = sanitize($conn, $_POST['full_name'] ?? '');
        $class       = sanitize($conn, $_POST['class'] ?? '');
        $manifesto   = sanitize($conn, $_POST['manifesto'] ?? '');
        $photo       = $_POST['photo'] ?? '';
        $position_id = (int)$_POST['position_id'];
        $photo_escaped = $conn->real_escape_string($photo);
        $conn->query("UPDATE candidates SET full_name='$full_name',class='$class',manifesto='$manifesto',photo='$photo_escaped',position_id=$position_id WHERE id=$id");
        logAudit($conn, 'CANDIDATE_EDITED', "Edited candidate ID: $id", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Candidate updated.']);

    // ============ DELETE CANDIDATE ============
    case 'delete_candidate':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM candidates WHERE id=$id");
        logAudit($conn, 'CANDIDATE_DELETED', "Deleted candidate ID: $id", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Candidate deleted.']);

    // ============ GET POSITIONS ============
    case 'get_positions':
        $result = $conn->query("SELECT * FROM positions ORDER BY display_order");
        $positions = [];
        while ($row = $result->fetch_assoc()) { $positions[] = $row; }
        jsonResponse(['success'=>true,'positions'=>$positions]);

    // ============ ADD POSITION ============
    case 'add_position':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $title       = sanitize($conn, $_POST['title'] ?? '');
        $description = sanitize($conn, $_POST['description'] ?? '');
        $order       = (int)($_POST['display_order'] ?? 0);
        if (!$title) jsonResponse(['success'=>false,'message'=>'Position title required.']);
        $conn->query("INSERT INTO positions (title,description,display_order) VALUES ('$title','$description',$order)");
        logAudit($conn, 'POSITION_ADDED', "Added position: $title", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Position added successfully.']);

    // ============ DELETE POSITION ============
    case 'delete_position':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM positions WHERE id=$id");
        logAudit($conn, 'POSITION_DELETED', "Deleted position ID: $id", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Position deleted.']);

    // ============ UPDATE ELECTION SETTINGS ============
    case 'update_election':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $name       = sanitize($conn, $_POST['election_name'] ?? '');
        $school     = sanitize($conn, $_POST['school_name'] ?? '');
        $start_time = sanitize($conn, $_POST['start_time'] ?? '');
        $end_time   = sanitize($conn, $_POST['end_time'] ?? '');
        $status     = sanitize($conn, $_POST['status'] ?? 'active');
        $conn->query("UPDATE election_settings SET election_name='$name',school_name='$school',start_time='$start_time',end_time='$end_time',status='$status'");
        logAudit($conn, 'ELECTION_UPDATED', "Election settings updated", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Election settings updated.']);

    // ============ UPDATE STATUS ============
    case 'update_status':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $status = sanitize($conn, $_POST['status'] ?? '');
        if (!in_array($status, ['pending','active','closed'])) jsonResponse(['success'=>false,'message'=>'Invalid status.']);
        $conn->query("UPDATE election_settings SET status='$status'");
        logAudit($conn, 'STATUS_CHANGED', "Status changed to: $status", $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'Status updated to '.$status]);

    // ============ ANNOUNCE WINNERS ============
    case 'announce_winners':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $conn->query("UPDATE election_settings SET status='closed', winner_announced=1");
        $winners = [];
        $posResult = $conn->query("SELECT * FROM positions ORDER BY display_order");
        while ($pos = $posResult->fetch_assoc()) {
            $pos_id = (int)$pos['id'];
            $winner = $conn->query("SELECT * FROM candidates WHERE position_id=$pos_id ORDER BY vote_count DESC LIMIT 1")->fetch_assoc();
            if ($winner) $winners[] = ['position'=>$pos['title'],'winner'=>$winner['full_name'],'class'=>$winner['class'],'votes'=>$winner['vote_count']];
        }
        logAudit($conn, 'WINNERS_ANNOUNCED', 'Winners announced', $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'winners'=>$winners,'message'=>'Winners announced!']);

    // ============ GET WINNERS ============
    case 'get_winners':
        $election = $conn->query("SELECT * FROM election_settings LIMIT 1")->fetch_assoc();
        if (!$election['winner_announced']) jsonResponse(['success'=>false,'message'=>'Winners not yet announced.']);
        $winners = [];
        $posResult = $conn->query("SELECT * FROM positions ORDER BY display_order");
        while ($pos = $posResult->fetch_assoc()) {
            $pos_id = (int)$pos['id'];
            $winner = $conn->query("SELECT * FROM candidates WHERE position_id=$pos_id ORDER BY vote_count DESC LIMIT 1")->fetch_assoc();
            if ($winner) $winners[] = ['position'=>$pos['title'],'winner'=>$winner['full_name'],'class'=>$winner['class'],'votes'=>$winner['vote_count']];
        }
        jsonResponse(['success'=>true,'winners'=>$winners]);

    // ============ GET AUDIT LOG ============
    case 'get_audit_log':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $result = $conn->query("SELECT * FROM audit_log ORDER BY performed_at DESC LIMIT 100");
        $logs = [];
        while ($row = $result->fetch_assoc()) { $logs[] = $row; }
        jsonResponse(['success'=>true,'logs'=>$logs]);

    // ============ EXPORT RESULTS CSV ============
    case 'export_results':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $totalVoted = (int)$conn->query("SELECT COUNT(*) as c FROM students WHERE has_voted=1")->fetch_assoc()['c'];
        $rows = [['Position','Candidate','Class','Votes','Percentage']];
        $posResult = $conn->query("SELECT * FROM positions ORDER BY display_order");
        while ($pos = $posResult->fetch_assoc()) {
            $pos_id = (int)$pos['id'];
            $cr = $conn->query("SELECT * FROM candidates WHERE position_id=$pos_id ORDER BY vote_count DESC");
            while ($c = $cr->fetch_assoc()) {
                $pct = $totalVoted > 0 ? round(($c['vote_count']/$totalVoted)*100,1) : 0;
                $rows[] = [$pos['title'],$c['full_name'],$c['class'],$c['vote_count'],$pct.'%'];
            }
        }
        $csv = '';
        foreach ($rows as $row) { $csv .= implode(',', array_map(fn($v) => '"'.$v.'"', $row))."\n"; }
        jsonResponse(['success'=>true,'csv'=>$csv,'filename'=>'KTCM_Results_'.date('Y-m-d').'.csv']);

    // ============ RESET ALL VOTES ============
    case 'reset_votes':
        if (!isset($_SESSION['admin_id'])) jsonResponse(['success'=>false,'message'=>'Unauthorized.']);
        $conn->query("DELETE FROM votes");
        $conn->query("UPDATE students SET has_voted=0,voted_at=NULL,receipt_code=NULL,device_fingerprint=NULL");
        $conn->query("UPDATE candidates SET vote_count=0");
        $conn->query("UPDATE election_settings SET winner_announced=0");
        logAudit($conn, 'VOTES_RESET', 'All votes reset', $_SESSION['admin_name']);
        jsonResponse(['success'=>true,'message'=>'All votes reset.']);

    // ============ LOGOUT ============
    case 'logout':
        session_destroy();
        jsonResponse(['success'=>true]);

    default:
        jsonResponse(['success'=>false,'message'=>'Invalid action.'],400);
}
?>
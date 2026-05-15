<?php
// ============================================
// HASH GENERATOR - Run this ONCE in browser
// Then DELETE this file immediately after!
// URL: http://localhost/ktcm/hash_generator.php
// ============================================
require_once 'config.php';

echo "<h2>KTCM - PIN Hash Generator</h2>";
echo "<p>Hashing all student PINs and admin password...</p>";

// Hash all student PINs
$students = $conn->query("SELECT id, pin FROM students");
$count = 0;
while ($s = $students->fetch_assoc()) {
    // Only hash if not already hashed
    if (strlen($s['pin']) < 20) {
        $hashed = password_hash($s['pin'], PASSWORD_DEFAULT);
        $conn->query("UPDATE students SET pin = '$hashed' WHERE id = {$s['id']}");
        $count++;
    }
}
echo "<p style='color:green'>✅ Hashed $count student PINs</p>";

// Hash admin password
$admins = $conn->query("SELECT id, password FROM admins");
$acount = 0;
while ($a = $admins->fetch_assoc()) {
    if (strlen($a['password']) < 20) {
        $hashed = password_hash($a['password'], PASSWORD_DEFAULT);
        $conn->query("UPDATE admins SET password = '$hashed' WHERE id = {$a['id']}");
        $acount++;
    }
}
echo "<p style='color:green'>✅ Hashed $acount admin password(s)</p>";
echo "<p style='color:red'><strong>⚠️ IMPORTANT: Delete this file now! (hash_generator.php)</strong></p>";
echo "<p>All done! <a href='index.html'>Go to Voting Portal</a> | <a href='admin.html'>Go to Admin</a></p>";
?>
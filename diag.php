<?php
// diag.php - ไฟล์วินิจฉัยปัญหาระบบ (ลบทิ้งหลังใช้งาน!)
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNOSTIC REPORT ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 1. PHP Version
echo "--- PHP Info ---\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n\n";

// 2. Test DB Connection
echo "--- Database Connection ---\n";
define('DB_HOST', 'sql211.infinityfree.com');
define('DB_USER', 'if0_42119828');
define('DB_PASS', 'Luepin098');
define('DB_NAME', 'if0_42119828_testlekha');

$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    echo "FAIL: " . $conn->connect_error . "\n";
} else {
    echo "OK: Connected to DB successfully!\n";
    // Check if users table exists
    $res = $conn->query("SELECT COUNT(*) as cnt FROM users");
    if ($res) {
        $row = $res->fetch_assoc();
        echo "users table: OK (" . $row['cnt'] . " records)\n";
    } else {
        echo "users table: ERROR - " . $conn->error . "\n";
    }
    $conn->close();
}
echo "\n";

// 3. Test file_put_contents
echo "--- File Write Test ---\n";
$testFile = __DIR__ . '/write_test.txt';
$result = @file_put_contents($testFile, 'write test ' . time());
if ($result !== false) {
    echo "OK: Can write files\n";
    @unlink($testFile);
} else {
    echo "FAIL: Cannot write files (permissions issue)\n";
}
echo "\n";

// 4. Check debug log
echo "--- Webhook Debug Log (last 20 lines) ---\n";
$logFile = __DIR__ . '/line_webhook_debug.log';
if (file_exists($logFile)) {
    $size = filesize($logFile);
    echo "Log file exists, size: $size bytes\n";
    $lines = file($logFile);
    $last20 = array_slice($lines, -20);
    echo implode('', $last20);
} else {
    echo "Log file does NOT exist - webhook.php may not be receiving any calls\n";
}
echo "\n";

// 5. Test LINE API (can we reach LINE?)
echo "--- LINE API Connectivity ---\n";
$ch = curl_init("https://api.line.me/v2/bot/info");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer akrq9QbZ55t9BHiFkn21aH34Fi1oWCdu4ibgQph671nyx54nrvqwvLS7RyFvFhKSLbF3etT8N4DzdvM6Bm0dFtQVEaIqeTHCtXtS7ke+tVPb3yGv/XrjypxlojdCUX57aMYlnWtbkxX4cw+j54kUpQdB04t89/1O/w1cDnyilFU="
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "FAIL: Cannot reach LINE API - " . $curlError . "\n";
} else {
    echo "HTTP: $httpCode\n";
    $data = json_decode($response, true);
    if (isset($data['displayName'])) {
        echo "OK: Bot name = " . $data['displayName'] . "\n";
    } else {
        echo "Response: $response\n";
    }
}

echo "\n=== END REPORT ===\n";

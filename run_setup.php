<?php
// run_setup.php
// สคริปต์รัน Setup ฐานข้อมูลผ่านเบราว์เซอร์
require_once 'config.php';

echo "<h2>กำลังตั้งค่าฐานข้อมูล...</h2>";

$host = DB_HOST;
$user = DB_USER;
$pass = DB_PASS;

$conn_init = new mysqli($host, $user, $pass);
if ($conn_init->connect_error) {
    die("<p style='color:red;'>การเชื่อมต่อ MySQL ล้มเหลว: " . $conn_init->connect_error . "</p>");
}

echo "<p style='color:green;'>1. เชื่อมต่อ MySQL สำเร็จ</p>";

$sql = file_get_contents('DB setup.sql');
if (!$sql) {
    die("<p style='color:red;'>ไม่สามารถเปิดไฟล์ DB setup.sql ได้</p>");
}

echo "<p style='color:green;'>2. อ่านไฟล์ DB setup.sql สำเร็จ</p>";

if ($conn_init->multi_query($sql)) {
    do {
        if ($result = $conn_init->store_result()) {
            $result->free();
        }
    } while ($conn_init->next_result());
    echo "<p style='color:green; font-weight:bold;'>3. สร้างฐานข้อมูลและตารางสำเร็จเรียบร้อย! (Database & Tables Created Successfully)</p>";
} else {
    echo "<p style='color:red;'>เกิดข้อผิดพลาด: " . $conn_init->error . "</p>";
}

$conn_init->close();
echo "<br><a href='register.php'>ไปยังหน้าลงทะเบียน</a>";
?>

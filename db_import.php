<?php
// db_import.php
// สคริปต์อัตโนมัติสำหรับติดตั้งและนำเข้าฐานข้อมูลจากไฟล์ DB setup.sql

$host = 'localhost';
$user = 'root';
$pass = '';

echo "กำลังเชื่อมต่อกับ MySQL..." . PHP_EOL;
$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error . PHP_EOL);
}

echo "กำลังอ่านไฟล์ DB setup.sql..." . PHP_EOL;
$sql = file_get_contents('DB setup.sql');

if (!$sql) {
    die("ไม่สามารถเปิดไฟล์ DB setup.sql ได้" . PHP_EOL);
}

echo "กำลังดำเนินการสร้างฐานข้อมูลและตาราง..." . PHP_EOL;
if ($conn->multi_query($sql)) {
    do {
        // ดึงผลลัพธ์ทั้งหมด
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->next_result());
    echo "นำเข้าข้อมูลและสร้างตารางสำเร็จเรียบร้อย! (Database & Tables Created Successfully)" . PHP_EOL;
} else {
    echo "เกิดข้อผิดพลาดในการดำเนินการ: " . $conn->error . PHP_EOL;
}

$conn->close();

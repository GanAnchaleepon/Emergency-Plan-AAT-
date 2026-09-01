<?php
// ตั้งค่า timezone เป็นเวลาประเทศไทย
date_default_timezone_set('Asia/Bangkok');

// ข้อมูลการเชื่อมต่อฐานข้อมูล
$host = "127.0.0.1:3306";
$username = "u829165346_BCP_AAT";
$password = "Gan05061997@";
$dbname = "u829165346_BCP_AAT";

// สร้างการเชื่อมต่อ
$conn = new mysqli($host, $username, $password, $dbname);

// ตรวจสอบการเชื่อมต่อ
if ($conn->connect_error) {
    die("การเชื่อมต่อฐานข้อมูลล้มเหลว: " . $conn->connect_error);
}

// ตั้งค่า charset เป็น utf8mb4 สำหรับรองรับภาษาไทย
$conn->set_charset("utf8mb4");

// ตั้งค่า timezone สำหรับ MySQL Connection
$conn->query("SET time_zone = '+07:00'");
?>

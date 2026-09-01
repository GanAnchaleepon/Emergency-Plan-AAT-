<?php
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอินเป็นแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตรวจสอบ/ซ่อมแซมฐานข้อมูล</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8f9fa;
            padding: 20px;
        }
        .container {
            max-width: 800px;
        }
        .card {
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .log-message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .log-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .log-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .log-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-database"></i> ผลการตรวจสอบและซ่อมแซมฐานข้อมูล
            </div>
            <div class="card-body">
                <?php
                echo "<h4><i class='fas fa-table'></i> ตรวจสอบตาราง `picking_plans`</h4>";

                // 1. ตรวจสอบว่าคอลัมน์ updated_at มีอยู่ในตาราง picking_plans หรือไม่
                $column_exists = false;
                $result = $conn->query("SHOW COLUMNS FROM `picking_plans` LIKE 'updated_at'");
                if ($result && $result->num_rows > 0) {
                    $column_exists = true;
                }

                if ($column_exists) {
                    echo "<div class='log-message log-success'><i class='fas fa-check-circle'></i> ตาราง `picking_plans` มีคอลัมน์ `updated_at` อยู่แล้ว ไม่ต้องดำเนินการใดๆ</div>";
                } else {
                    echo "<div class='log-message log-info'><i class='fas fa-info-circle'></i> กำลังเพิ่มคอลัมน์ `updated_at` ในตาราง `picking_plans`...</div>";
                    $sql = "ALTER TABLE `picking_plans` 
                            ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;";
                    
                    if ($conn->query($sql) === TRUE) {
                        echo "<div class='log-message log-success'><i class='fas fa-check-circle'></i> เพิ่มคอลัมน์ `updated_at` สำเร็จ!</div>";
                    } else {
                        echo "<div class='log-message log-error'><i class='fas fa-times-circle'></i> เกิดข้อผิดพลาดในการเพิ่มคอลัมน์: " . $conn->error . "</div>";
                    }
                }

                echo "<hr>";
                echo "<h4><i class='fas fa-tasks'></i> การตรวจสอบเสร็จสิ้น</h4>";
                echo "<p>ระบบพร้อมใช้งานแล้ว</p>";
                ?>
                <a href="index.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> กลับไปหน้าแดชบอร์ด</a>
            </div>
        </div>
    </div>
</body>
</html>

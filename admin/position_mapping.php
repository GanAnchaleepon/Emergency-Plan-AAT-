<?php
/**
 * ระบบกำหนดจุดเริ่มต้น Position โดยอิงจาก Rotation น้อยที่สุด
 * และจัดการค่า max_position ของแต่ละกลุ่ม
 */

// เริ่มต้น session และตรวจสอบการล็อกอิน
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// ตั้งค่าการแสดงข้อผิดพลาด
error_reporting(E_ALL);
ini_set('display_errors', 1);

// เชื่อมต่อฐานข้อมูล
require_once '../config/database.php';

// กำหนดค่า max_position ของแต่ละกลุ่ม
$maxPositionsConfig = [
    'GROUP 01' => 16,
    'GROUP 02' => 16,
    'GROUP 03' => 16,
    'GROUP 04' => 8,
    'GROUP 05' => 20,
    'GROUP 06L' => 20,
    'GROUP 06R' => 20,
    'GROUP 07' => 16,
    'GROUP 09L' => 12,
    'GROUP 09R' => 12,
    'GROUP 10' => 16,
    'GROUP 11' => 16
];

// ข้อความแจ้งเตือน
$message = '';
$alert_type = '';

// สร้างตาราง position_mappings ถ้ายังไม่มี
$sql = "CREATE TABLE IF NOT EXISTS `position_mappings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `group_name` varchar(20) NOT NULL,
    `start_rotation` varchar(50) NOT NULL,
    `start_position` int(11) NOT NULL,
    `max_position` int(11) NOT NULL,
    `created_by` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) !== TRUE) {
    die("ไม่สามารถสร้างตาราง position_mappings: " . $conn->error);
}

// สร้างตาราง group_settings ถ้ายังไม่มี
$sql = "CREATE TABLE IF NOT EXISTS `group_settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `group_name` varchar(20) NOT NULL,
    `max_position` int(11) NOT NULL DEFAULT 18,
    `last_position` int(11) DEFAULT 0,
    `created_by` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) !== TRUE) {
    die("ไม่สามารถสร้างตาราง group_settings: " . $conn->error);
}

// ดึงข้อมูลกลุ่มจากฐานข้อมูล
$groups = [];
$sql = "SELECT DISTINCT group_name FROM picking_plans ORDER BY group_name";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row['group_name'];
    }
} else {
    // ถ้าไม่พบกลุ่มในฐานข้อมูล ใช้ค่าจาก config
    $groups = array_keys($maxPositionsConfig);
}

// ดึงข้อมูล Rotation ของแต่ละกลุ่ม
$groupRotations = [];
foreach ($groups as $group) {
    $sql = "SELECT DISTINCT rotation FROM picking_plans WHERE group_name = ? ORDER BY STR_TO_DATE(date, '%Y-%m-%d') ASC, STR_TO_DATE(time, '%H:%i:%s') ASC, CAST(rotation AS UNSIGNED) ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $group);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rotations = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $rotations[] = $row['rotation'];
        }
    }
    
    if (!empty($rotations)) {
        $groupRotations[$group] = $rotations;
    }
    
    $stmt->close();
}

// ดึงข้อมูลการตั้งค่า Position ปัจจุบัน
$currentMappings = [];
$sql = "SELECT * FROM position_mappings";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $currentMappings[$row['group_name']] = $row;
    }
}

// ดึงข้อมูล Max Positions
$groupSettings = [];
$sql = "SELECT * FROM group_settings";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $groupSettings[$row['group_name']] = $row;
    }
}

// กำหนดตัวแปรสำหรับการแสดงผล
$selectedGroup = '';
$startRotation = '';
$startPosition = 1;
$maxPosition = 18; // ค่าเริ่มต้น
$rotationMappings = [];

// เมื่อเลือกกลุ่ม, คำนวณ Mapping, บันทึกการตั้งค่า, หรือนำ Mapping ไปใช้
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['group'])) || isset($_GET['group'])) {
    // รับค่าจาก POST หรือ GET (ให้ความสำคัญกับ POST มากกว่า)
    $selectedGroup = $_POST['group'] ?? $_GET['group'] ?? '';
    $startRotation = $_POST['start_rotation'] ?? $_GET['start_rotation'] ?? '';
    $startPosition = isset($_POST['start_position']) ? (int)$_POST['start_position'] : (isset($_GET['start_position']) ? (int)$_GET['start_position'] : 1);

    // กำหนดค่า maxPosition: ให้ความสำคัญกับค่าที่ส่งมาจากฟอร์ม (POST) ก่อน
    if (isset($_POST['max_position'])) {
        $maxPosition = (int)$_POST['max_position'];
    } elseif (!empty($selectedGroup) && isset($currentMappings[$selectedGroup])) { // ถ้าเป็นการโหลดข้อมูลกลุ่มผ่าน GET และมี mapping ที่บันทึกไว้
        $maxPosition = $currentMappings[$selectedGroup]['max_position'];
    } elseif (!empty($selectedGroup) && isset($groupSettings[$selectedGroup])) { // ถ้าไม่มีค่าจาก POST หรือ currentMappings ให้ใช้จาก groupSettings
        $maxPosition = $groupSettings[$selectedGroup]['max_position'];
    } elseif (!empty($selectedGroup) && isset($maxPositionsConfig[$selectedGroup])) { // ถ้าไม่มีจาก groupSettings ให้ใช้จาก config
        $maxPosition = $maxPositionsConfig[$selectedGroup];
    }
    // หากไม่มีแหล่งข้อมูลอื่น $maxPosition จะยังคงเป็นค่าเริ่มต้น (18)

    // ตรวจสอบว่ากลุ่มที่เลือกมีอยู่
    if (!empty($selectedGroup) && isset($groupRotations[$selectedGroup])) {
        $rotations = $groupRotations[$selectedGroup];
        
        // ตรวจสอบและปรับค่า start_rotation ถ้าไม่ได้ระบุ
        if (empty($startRotation) && !empty($rotations)) {
            $startRotation = $rotations[0]; // ใช้ค่าแรกเป็นค่าเริ่มต้น
        }
        
        // คำนวณ Mapping โดยใช้ $maxPosition ที่กำหนดไว้
        $rotationMappings = calculateMapping($rotations, $startRotation, $startPosition, $maxPosition);
    }
    
    // บันทึกการตั้งค่าถ้ากดปุ่มบันทึก
    if (isset($_POST['save_mapping']) && !empty($selectedGroup) && !empty($startRotation)) {
        $userId = $_SESSION['user_id'];
        // $maxPosition ที่ใช้ตรงนี้คือค่าที่มาจากฟอร์ม (ผ่านการกำหนดค่าด้านบนแล้ว)

        $conn->begin_transaction();
        try {
            // เก็บไว้ใน position_mappings
            $sql_pm = "INSERT INTO position_mappings (group_name, start_rotation, start_position, max_position, created_by) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        start_rotation = VALUES(start_rotation), 
                        start_position = VALUES(start_position), 
                        max_position = VALUES(max_position), 
                        updated_at = NOW()";
            $stmt_pm = $conn->prepare($sql_pm);
            $stmt_pm->bind_param("ssiii", 
                              $selectedGroup, $startRotation, $startPosition, $maxPosition, $userId);
            
            if (!$stmt_pm->execute()) {
                throw new Exception("เกิดข้อผิดพลาดในการบันทึก position_mappings: " . $stmt_pm->error);
            }
            $stmt_pm->close();

            // อัพเดท group_settings ด้วย
            $sql_gs = "INSERT INTO group_settings (group_name, max_position, created_by) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        max_position = VALUES(max_position), 
                        updated_at = NOW()";
            $stmt_gs = $conn->prepare($sql_gs);
            $stmt_gs->bind_param("sii", $selectedGroup, $maxPosition, $userId);
            if (!$stmt_gs->execute()) {
                throw new Exception("เกิดข้อผิดพลาดในการบันทึก group_settings: " . $stmt_gs->error);
            }
            $stmt_gs->close();
            
            // นำค่า mapping ไปใช้กับ picking_plans ทันที
            $updateCount = 0;
            $errorCount = 0;
            
            // $rotationMappings ควรถูกคำนวณไว้แล้วโดยใช้ $maxPosition จากฟอร์ม
            if (!empty($rotationMappings)) {
                foreach ($rotationMappings as $rotation => $position) {
                    $updatePlanSql = "UPDATE picking_plans SET position = ? WHERE group_name = ? AND rotation = ?";
                    $updatePlanStmt = $conn->prepare($updatePlanSql);
                    $updatePlanStmt->bind_param("iss", $position, $selectedGroup, $rotation);
                    
                    if ($updatePlanStmt->execute()) {
                        $updateCount += $updatePlanStmt->affected_rows;
                    } else {
                        $errorCount++;
                        // สามารถเพิ่มการบันทึก error เฉพาะจุดนี้ได้ถ้าต้องการ
                    }
                    $updatePlanStmt->close();
                }
            }

            $conn->commit(); // Commit transaction
            
            if ($errorCount == 0) {
                $message = "บันทึกการตั้งค่า Position และอัปเดตข้อมูลแผนงานสำหรับกลุ่ม $selectedGroup สำเร็จ (อัปเดต $updateCount รายการในแผนงาน)";
                $alert_type = "success";
            } else {
                $message = "บันทึกการตั้งค่า Position สำเร็จ แต่เกิดข้อผิดพลาดบางส่วนในการอัปเดตข้อมูลแผนงาน: อัปเดตสำเร็จ $updateCount รายการ, ผิดพลาด $errorCount รายการ";
                $alert_type = "warning";
            }
            
            // อัพเดทค่า currentMappings และ groupSettings สำหรับการแสดงผลทันที
            $currentMappings[$selectedGroup] = [
                'group_name' => $selectedGroup,
                'start_rotation' => $startRotation,
                'start_position' => $startPosition,
                'max_position' => $maxPosition
            ];
            if(isset($groupSettings[$selectedGroup])) {
                $groupSettings[$selectedGroup]['max_position'] = $maxPosition;
            } else {
                 $groupSettings[$selectedGroup] = ['max_position' => $maxPosition];
            }

        } catch (Exception $e) {
            $conn->rollback(); // Rollback หากเกิดข้อผิดพลาด
            $message = "เกิดข้อผิดพลาดในการบันทึก: " . $e->getMessage();
            $alert_type = "danger";
        }
    }
    
    // นำค่า mapping ไปใช้กับ picking_plans (ส่วนนี้สำหรับปุ่ม "นำไปใช้กับข้อมูลในฐานข้อมูล" โดยเฉพาะ)
    if (isset($_POST['apply_mapping']) && !empty($selectedGroup) && !empty($rotationMappings)) {
        // ประกาศตัวแปรเก็บจำนวนที่อัปเดต
        $updateCount = 0;
        $errorCount = 0;
        
        // อัปเดตตำแหน่งในตาราง picking_plans
        foreach ($rotationMappings as $rotation => $position) {
            $sql = "UPDATE picking_plans SET position = ? WHERE group_name = ? AND rotation = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $position, $selectedGroup, $rotation);
            
            if ($stmt->execute()) {
                $updateCount += $stmt->affected_rows;
            } else {
                $errorCount++;
            }
            $stmt->close();
        }
        
        // บันทึกการตั้งค่าไว้ใน position_mappings 
        if (!isset($currentMappings[$selectedGroup])) {
            $userId = $_SESSION['user_id'];
            $sql = "INSERT INTO position_mappings (group_name, start_rotation, start_position, max_position, created_by) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssiii", $selectedGroup, $startRotation, $startPosition, $maxPosition, $userId);
            $stmt->execute();
            $stmt->close();
        }
        
        // แสดงผลลัพธ์
        if ($errorCount == 0) {
            $message = "นำ Mapping ไปใช้กับข้อมูลในฐานข้อมูลสำเร็จ! อัปเดต $updateCount รายการ";
            $alert_type = "success";
        } else {
            $message = "เกิดข้อผิดพลาดบางส่วน: อัปเดตสำเร็จ $updateCount รายการ, ผิดพลาด $errorCount รายการ";
            $alert_type = "warning";
        }
    }
}

// ฟังก์ชันสำหรับคำนวณ Mapping
function calculateMapping($rotations, $startRotation, $startPosition, $maxPosition) {
    if (empty($rotations) || !in_array($startRotation, $rotations)) {
        return [];
    }
    
    // เรียงลำดับ Rotation จากน้อยไปมาก (ถ้ายังไม่ได้เรียง)
    sort($rotations, SORT_NATURAL);
    
    // หาตำแหน่งของ start_rotation ใน array
    $startIndex = array_search($startRotation, $rotations);
    
    // คำนวณ mapping
    $mapping = [];
    // สร้างตัวแปรเก็บ rotation ที่ไม่ซ้ำกัน เพื่อใช้ในการคำนวณ position
    $uniqueRotations = array_unique($rotations);
    sort($uniqueRotations, SORT_NATURAL); // เรียงลำดับใหม่
    $uniqueStartIndex = array_search($startRotation, $uniqueRotations);
    
    $count = count($uniqueRotations);
    
    // สร้าง mapping สำหรับ rotation ที่ไม่ซ้ำกัน
    $rotationPositionMap = [];
    
    for ($i = 0; $i < $count; $i++) {
        $rotation = $uniqueRotations[$i];
        
        // คำนวณตำแหน่งเทียบกับ startIndex
        $relativeIndex = ($i - $uniqueStartIndex + $count) % $count;
        
        // คำนวณ position ที่สอดคล้อง
        $position = (($relativeIndex + $startPosition - 1) % $maxPosition) + 1;
        
        // เก็บ mapping แต่ละ rotation
        $rotationPositionMap[$rotation] = $position;
    }
    
    // สร้าง mapping สำหรับทุก rotation (รวมถึง rotation ที่ซ้ำกัน)
    foreach ($rotations as $rotation) {
        $mapping[$rotation] = $rotationPositionMap[$rotation];
    }
    
    return $mapping;
}

// ดึงข้อมูล picking_plans ตาม group ที่เลือก
$planData = [];
if (!empty($selectedGroup)) {
    $sql = "SELECT id, rotation, position, part_number, status, date, time FROM picking_plans 
            WHERE group_name = ? 
            ORDER BY group_name ASC, STR_TO_DATE(date, '%Y-%m-%d') ASC, STR_TO_DATE(time, '%H:%i:%s') ASC, CAST(rotation AS UNSIGNED) ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $selectedGroup);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $planData[] = $row;
        }
    }
    
    $stmt->close();
}

// อัปเดตการแสดงข้อมูลในตาราง (ส่วนใน <tbody> ของตาราง)
// เพื่อให้แสดงข้อมูล Part Number ทั้งหมดที่มี Rotation เดียวกัน
$planByRotation = [];
foreach ($planData as $plan) {
    $rotation = $plan['rotation'];
    if (!isset($planByRotation[$rotation])) {
        $planByRotation[$rotation] = [];
    }
    $planByRotation[$rotation][] = $plan;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กำหนดจุดเริ่มต้น Position - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a4f72;
            --secondary-color: #2980b9;
            --accent-color: #f39c12;
            --success-color: #27ae60;
            --warning-color: #e67e22;
            --danger-color: #c0392b;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }
        
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f5f7fa;
        }
        
        /* ปรับปรุง Navbar */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            padding: 0;
            white-space: nowrap;
            height: 60px;
        }

        .navbar-brand {
            color: white !important;
            font-weight: 600;
            font-size: 1.2rem;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            background: rgba(0, 0, 0, 0.1);
            height: 60px;
            margin-right: 0;
            white-space: nowrap;
        }

        .navbar-brand i {
            margin-right: 0.5rem;
            font-size: 1.5rem;
            color: #ffcc00;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
        }

        .navbar-dark .navbar-nav .nav-link {
            color: rgba(255, 255, 255, 0.85);
            padding: 0 0.9rem;
            font-weight: 500;
            position: relative;
            transition: all 0.3s;
            height: 60px;
            line-height: 60px;
            white-space: nowrap;
        }

        .navbar-dark .navbar-nav .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .navbar-dark .navbar-nav .nav-link.active {
            color: white;
            font-weight: 600;
            background-color: rgba(0, 0, 0, 0.2);
            box-shadow: inset 0 -3px 0 #ffcc00;
        }
        
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
        }
        
        .card {
            border: none;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            border-top-left-radius: 10px !important;
            border-top-right-radius: 10px !important;
            display: flex;
            align-items: center;
        }
        
        .card-header i {
            margin-right: 10px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .form-group label {
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #16405d;
            border-color: #16405d;
        }
        
        .btn-success {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-success:hover {
            background-color: #1e8449;
            border-color: #1e8449;
        }
        
        .table {
            background-color: white;
            border-radius: 5px;
        }
        
        .table th {
            background-color: rgba(0, 0, 0, 0.03);
            font-weight: 600;
        }
        
        .explanation {
            background-color: var(--light-color);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .explanation h5 {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .explanation p {
            margin-bottom: 0.5rem;
        }
        
        .badge-info {
            background-color: var(--secondary-color);
        }
        
        .badge-success {
            background-color: var(--success-color);
        }
        
        .badge-warning {
            background-color: var(--warning-color);
        }
        
        .badge-danger {
            background-color: var(--danger-color);
        }
        
        .position-mapping-table th, 
        .position-mapping-table td {
            text-align: center;
        }
        
        .position-highlight {
            font-weight: bold;
            color: var(--danger-color);
        }
        
        .table-scroll {
            max-height: 500px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="../">
                <i class="fas fa-warehouse"></i>
                Emergency Picking (Operation TTV AAT-Wiring)
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-tachometer-alt"></i> แดชบอร์ด
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../Convert_BC/index.php">
                            <i class="fas fa-exchange-alt"></i> Convert BC
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="upload.php">
                            <i class="fas fa-upload"></i> อัปโหลด
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="print_bulk_stickers.php">
                            <i class="fas fa-print"></i> พิมพ์สติกเกอร์
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="print_summary.php">
                            <i class="fas fa-file-pdf"></i> พิมพ์ใบสรุป
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="history.php">
                            <i class="fas fa-history"></i> ประวัติ
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar"></i> รายงาน
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="position_mapping.php">
                            <i class="fas fa-map-marker-alt"></i> กำหนดจุดเริ่มต้น Position
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link logout-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- คำอธิบายการทำงาน -->
        <div class="explanation">
            <h5><i class="fas fa-info-circle"></i> กำหนดจุดเริ่มต้น Position</h5>
            <p>หน้านี้ใช้สำหรับกำหนดการจับคู่ระหว่าง Rotation และ Position ของแต่ละกลุ่ม</p>
            <p><strong>การทำงาน:</strong> ระบบจะเรียงลำดับ Rotation จากน้อยไปมาก แล้วเริ่มจัดสรร Position ตามที่กำหนด</p>
            <p><strong>ตัวอย่าง:</strong> หากคุณกำหนดให้ Rotation 5 อยู่ที่ Position 6 ระบบจะจับคู่ Rotation 6 กับ Position 7 และเรียงต่อไปเรื่อยๆ จนครบ Max Position แล้ววนกลับมาที่ Position 1 ใหม่</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $alert_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- ส่วนควบคุม -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-cog"></i> กำหนดค่า Position
            </div>
            <div class="card-body">
                <form method="post" action="position_mapping.php">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="group">เลือกกลุ่ม:</label>
                                <select class="form-control" id="group" name="group" required>
                                    <option value="">-- กรุณาเลือกกลุ่ม --</option>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo htmlspecialchars($group); ?>"
                                                data-max-position="<?php echo isset($maxPositionsConfig[$group]) ? $maxPositionsConfig[$group] : 18; ?>"
                                                <?php echo ($selectedGroup === $group) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($group); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="start_rotation">Rotation เริ่มต้น:</label>
                                <select class="form-control" id="start_rotation" name="start_rotation" required <?php echo empty($selectedGroup) ? 'disabled' : ''; ?>>
                                    <option value="">-- เลือก Rotation --</option>
                                    <?php if (!empty($selectedGroup) && isset($groupRotations[$selectedGroup])): ?>
                                        <?php foreach ($groupRotations[$selectedGroup] as $rotation): ?>
                                            <option value="<?php echo htmlspecialchars($rotation); ?>"
                                                    <?php echo ($startRotation === $rotation) ? 'selected' : ''; ?>>
                                                <?php echo str_pad(htmlspecialchars($rotation), 4, '0', STR_PAD_LEFT); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="start_position">เริ่มที่ Position:</label>
                                <input type="number" class="form-control" id="start_position" name="start_position" 
                                       min="1" max="<?php echo $maxPosition; ?>" value="<?php echo $startPosition; ?>" required>
                            </div>
                        </div>

                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="max_position">Max Position:</label>
                                <input type="number" class="form-control" id="max_position" name="max_position" min="1" max="100" value="<?php echo $maxPosition; ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" name="calculate_mapping" class="btn btn-primary">
                            <i class="fas fa-calculator"></i> คำนวณ Mapping
                        </button>
                        
                        <?php if (!empty($rotationMappings)): ?>
                            <button type="submit" name="save_mapping" class="btn btn-success">
                                <i class="fas fa-save"></i> บันทึกการตั้งค่า
                            </button>
                            
                            <button type="submit" name="apply_mapping" class="btn btn-warning" 
                                    onclick="return confirm('คุณต้องการนำ Mapping นี้ไปใช้กับข้อมูลในฐานข้อมูลใช่หรือไม่?');">
                                <i class="fas fa-check-circle"></i> นำไปใช้กับข้อมูลในฐานข้อมูล
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($rotationMappings)): ?>
        <!-- แสดงตาราง Mapping -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-table"></i> ตาราง Mapping สำหรับ <?php echo htmlspecialchars($selectedGroup); ?>
            </div>
            <div class="card-body">
                <div class="table-scroll">
                    <table class="table table-bordered table-striped position-mapping-table">
                        <thead>
                            <tr>
                                <th>Rotation</th>
                                <th>Position</th>
                                <th>Part Number</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>สถานะในระบบ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($planData as $plan) {
                                $rotation = $plan['rotation'];
                                $position = $plan['position'];
                                $partNumber = $plan['part_number'];
                                $status = $plan['status'];
                                $dateValue = isset($plan['date']) ? $plan['date'] : '';
                                $timeValue = isset($plan['time']) ? $plan['time'] : '';
                                // กำหนดสีสถานะ
                                $statusBadgeClass = 'badge-secondary';
                                $statusText = htmlspecialchars($status);
                                if ($status == 'completed' || $status == 'done') {
                                    $statusBadgeClass = 'badge-success';
                                    $statusText = 'เสร็จสมบูรณ์';
                                } elseif ($status == 'pending') {
                                    $statusBadgeClass = 'badge-warning';
                                    $statusText = 'รอดำเนินการ';
                                } elseif ($status == 'in_progress') {
                                    $statusBadgeClass = 'badge-info';
                                    $statusText = 'กำลังดำเนินการ';
                                }
                                ?>
                                <tr>
                                    <td><?php echo str_pad(htmlspecialchars($rotation), 4, '0', STR_PAD_LEFT); ?></td>
                                    <td class="position-highlight"><?php echo $position; ?></td>
                                    <td><?php echo htmlspecialchars($partNumber); ?></td>
                                    <td>
                                        <?php
                                        if ($dateValue) {
                                            $dateObj = DateTime::createFromFormat('j/n/Y', $dateValue);
                                            echo ($dateObj) ? $dateObj->format('d/m/Y') : '-';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($timeValue) {
                                            $timeObj = DateTime::createFromFormat('H:i:s', $timeValue);
                                            echo ($timeObj) ? $timeObj->format('H:i:s') : '-';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $statusBadgeClass; ?>"><?php echo $statusText; ?></span>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- การตั้งค่าปัจจุบัน -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-sliders-h"></i> การตั้งค่า Position ปัจจุบัน
            </div>
            <div class="card-body">
                <?php if (empty($currentMappings)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> ยังไม่มีการกำหนดค่า Position สำหรับกลุ่มใดๆ
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>กลุ่ม</th>
                                    <th>Rotation เริ่มต้น</th>
                                    <th>Position เริ่มต้น</th>
                                    <th>Max Position</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentMappings as $mapping): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($mapping['group_name']); ?></td>
                                        <td><?php echo htmlspecialchars($mapping['start_rotation']); ?></td>
                                        <td><?php echo $mapping['start_position']; ?></td>
                                        <td><?php echo $mapping['max_position']; ?></td>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Max Positions ของแต่ละกลุ่ม -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-ruler-horizontal"></i> Max Position ของแต่ละกลุ่ม
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> ค่า Max Position คือจำนวนตำแหน่งสูงสุดของแต่ละกลุ่ม เมื่อถึงตำแหน่งนี้แล้วจะวนกลับไปที่ Position 1
                </div>
                
                <div class="row">
                    <?php foreach ($maxPositionsConfig as $group => $maxPos): ?>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card">
                                <div class="card-body p-2 text-center">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($group); ?></h6>
                                    <div class="badge badge-primary"><?php echo $maxPos; ?> Positions</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if (isset($_POST['apply_mapping']) && !empty($selectedGroup)): ?>
        <div class="card mt-3">
            <div class="card-header bg-info text-white">
                <i class="fas fa-bug"></i> Debug Information
            </div>
            <div class="card-body">
                <h5>Query Debug:</h5>
                <p>Group: <?php echo htmlspecialchars($selectedGroup); ?></p>
                <p>จำนวน Rotations ที่จะอัปเดต: <?php echo count($rotationMappings); ?></p>
                
                <?php 
                // ตัวอย่าง Query ที่จะรัน
                if (!empty($rotationMappings)) {
                    $sampleRotation = array_key_first($rotationMappings);
                    $samplePosition = $rotationMappings[$sampleRotation];
                    echo "<p>ตัวอย่าง Query: UPDATE picking_plans SET position = $samplePosition WHERE group_name = '$selectedGroup' AND rotation = '$sampleRotation'</p>";
                    
                    // ตรวจสอบว่า Rotation นี้มีในฐานข้อมูลหรือไม่
                    $checkSql = "SELECT id, position FROM picking_plans WHERE group_name = ? AND rotation = ? LIMIT 1";
                    $checkStmt = $conn->prepare($checkSql);
                    $checkStmt->bind_param("ss", $selectedGroup, $sampleRotation);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        $row = $checkResult->fetch_assoc();
                        echo "<p style='color:green'>✓ พบ Rotation $sampleRotation ในฐานข้อมูล (ID: {$row['id']}, Position ปัจจุบัน: {$row['position']})</p>";
                    } else {
                        echo "<p style='color:red'>✗ ไม่พบ Rotation $sampleRotation ในฐานข้อมูล</p>";
                    }
                    $checkStmt->close();
                }
                ?>
                
                <h5 class="mt-3">ตรวจสอบข้อมูลในฐานข้อมูล:</h5>
                <?php
                // ตรวจสอบจำนวนข้อมูลในกลุ่มนี้
                $countSql = "SELECT COUNT(*) as total FROM picking_plans WHERE group_name = ?";
                $countStmt = $conn->prepare($countSql);
                $countStmt->bind_param("s", $selectedGroup);
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                $countRow = $countResult->fetch_assoc();
                echo "<p>จำนวนข้อมูลในกลุ่ม $selectedGroup: {$countRow['total']} รายการ</p>";
                $countStmt->close();
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // อัปเดทค่า max_position อัตโนมัติเมื่อเลือกกลุ่ม
            const groupSelect = document.getElementById('group');
            const maxPositionInput = document.getElementById('max_position');
            const startPositionInput = document.getElementById('start_position');
            const startRotationSelect = document.getElementById('start_rotation');
            
            // สร้าง mapping ของค่า max_position ตาม group
            const maxPositionMap = {
                'GROUP 01': 16,
                'GROUP 02': 16,
                'GROUP 03': 16,
                'GROUP 04': 8,
                'GROUP 05': 20,
                'GROUP 06L': 20,
                'GROUP 06R': 20,
                'GROUP 07': 16,
                'GROUP 09L': 12,
                'GROUP 09R': 12,
                'GROUP 10': 16,
                'GROUP 11': 16
            };
            
            if (groupSelect && maxPositionInput) {
                groupSelect.addEventListener('change', function() {
                    const selectedGroup = this.value;
                    
                    // อัปเดต max_position ตามกลุ่มที่เลือก
                    if (maxPositionMap[selectedGroup]) {
                        maxPositionInput.value = maxPositionMap[selectedGroup];
                        
                        // อัปเดต max ของ start_position ด้วย
                        if (startPositionInput) {
                            startPositionInput.max = maxPositionMap[selectedGroup];
                            
                            // ถ้าค่าปัจจุบันเกิน max ใหม่ ให้ปรับเป็น max
                            if (parseInt(startPositionInput.value) > maxPositionMap[selectedGroup]) {
                                startPositionInput.value = maxPositionMap[selectedGroup];
                            }
                        }
                    }
                    
                    // รีเซ็ต start_rotation
                    if (startRotationSelect) {
                        startRotationSelect.disabled = selectedGroup === '';
                        
                        // ล้าง options เก่า
                        while (startRotationSelect.options.length > 1) {
                            startRotationSelect.remove(1);
                        }
                        
                        // ส่งคำขอไปยังเซิร์ฟเวอร์เพื่อโหลดข้อมูลกลุ่มใหม่
                        if (selectedGroup) {
                            window.location.href = 'position_mapping.php?group=' + encodeURIComponent(selectedGroup);
                        }
                    }
                });
                
                // ทริกเกอร์ event change เมื่อโหลดหน้าถ้ามีการเลือกกลุ่มไว้แล้ว
                if (groupSelect.value && maxPositionMap[groupSelect.value]) {
                    maxPositionInput.value = maxPositionMap[groupSelect.value];
                    if (startPositionInput) {
                        startPositionInput.max = maxPositionMap[groupSelect.value];
                    }
                }
            }
            
            // ปรับขนาดความสูงของตารางให้เหมาะสม
            const tableScroll = document.querySelector('.table-scroll');
            if (tableScroll) {
                const windowHeight = window.innerHeight;
                const tableTop = tableScroll.getBoundingClientRect().top;
                const maxHeight = windowHeight - tableTop - 100; // ลบระยะห่างด้านล่าง
                
                if (maxHeight > 200) { // ต้องมีความสูงขั้นต่ำ
                    tableScroll.style.maxHeight = maxHeight + 'px';
                }
            }
        });
    </script>
</body>
</html>
<?php
// หน้ารายงานสำหรับแอดมิน
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// รับค่าพารามิเตอร์
$report_type = isset($_GET['type']) ? $_GET['type'] : 'summary';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$group = isset($_GET['group']) ? $_GET['group'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : '';

// ดึงรายชื่อกลุ่ม
$groups = [];
$sql = "SELECT DISTINCT group_name FROM picking_plans ORDER BY group_name";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row['group_name'];
    }
}

// ฟังก์ชันส่งออกรายงาน
if (!empty($format)) {
    $filename = 'emergency_picking_report_' . $report_type . '_' . date('Ymd');
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        // เขียนโค้ดส่งออกเป็น CSV ตามประเภทรายงาน
        
        // ตัวอย่างโค้ดส่งออก CSV สำหรับรายงานสรุป
        if ($report_type === 'summary') {
            $output = fopen('php://output', 'w');
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8
            
            // หัวคอลัมน์
            fputcsv($output, ['วันที่', 'กลุ่ม', 'จำนวนตำแหน่งทั้งหมด', 'จำนวนที่หยิบแล้ว', 'เปอร์เซ็นต์']);
            
            // ดึงข้อมูลรายงาน
            $sql = "SELECT 
                        DATE(p.created_at) as date,
                        p.group_name,
                        COUNT(*) as total_positions,
                        SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed_positions,
                        ROUND(SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) * 100 / COUNT(*), 2) as percentage
                    FROM 
                        picking_plans p
                    WHERE 
                        DATE(p.created_at) BETWEEN ? AND ?";
            
            if (!empty($group)) {
                $sql .= " AND p.group_name = ?";
            }
            
            $sql .= " GROUP BY DATE(p.created_at), p.group_name
                      ORDER BY DATE(p.created_at), p.group_name";
            
            $stmt = $conn->prepare($sql);
            
            if (!empty($group)) {
                $stmt->bind_param("sss", $date_from, $date_to, $group);
            } else {
                $stmt->bind_param("ss", $date_from, $date_to);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    $row['date'],
                    $row['group_name'],
                    $row['total_positions'],
                    $row['completed_positions'],
                    $row['percentage'] . '%'
                ]);
            }
            
            fclose($output);
        }
        
        exit;
    } elseif ($format === 'excel') {
        // โค้ดส่งออกเป็น Excel (ต้องใช้ library เช่น PhpSpreadsheet)
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        echo "Excel export is not implemented yet"; // ข้อความตัวอย่าง
        exit;
    } elseif ($format === 'pdf') {
        // โค้ดส่งออกเป็น PDF (ต้องใช้ library เช่น TCPDF, FPDF)
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        echo "PDF export is not implemented yet"; // ข้อความตัวอย่าง
        exit;
    }
}

// ฟังก์ชันดึงข้อมูลรายงานสรุป
function getSummaryReport($conn, $date_from, $date_to, $group) {
    $sql = "SELECT 
                DATE(p.created_at) as date,
                p.group_name,
                COUNT(*) as total_positions,
                SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed_positions,
                ROUND(SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) * 100 / COUNT(*), 2) as percentage
            FROM 
                picking_plans p
            WHERE 
                DATE(p.created_at) BETWEEN ? AND ?";
    
    if (!empty($group)) {
        $sql .= " AND p.group_name = ?";
    }
    
    $sql .= " GROUP BY DATE(p.created_at), p.group_name
              ORDER BY DATE(p.created_at), p.group_name";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($group)) {
        $stmt->bind_param("sss", $date_from, $date_to, $group);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    return $data;
}

// ฟังก์ชันดึงข้อมูลรายงานกิจกรรม
function getActivityReport($conn, $date_from, $date_to, $group) {
    $sql = "SELECT 
                a.id,
                a.created_at,
                a.action,
                a.status,
                a.scan_data,
                a.ip_address,
                p.group_name,
                p.part_number,
                p.position,
                p.rotation
            FROM 
                picking_activities a
            JOIN 
                picking_plans p ON a.plan_id = p.id
            WHERE 
                DATE(a.created_at) BETWEEN ? AND ?";
    
    if (!empty($group)) {
        $sql .= " AND p.group_name = ?";
    }
    
    $sql .= " ORDER BY a.created_at DESC
              LIMIT 1000";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($group)) {
        $stmt->bind_param("sss", $date_from, $date_to, $group);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    return $data;
}

// ฟังก์ชันดึงข้อมูลรายงานอัตราความเร็วการหยิบงาน
function getSpeedReport($conn, $date_from, $date_to, $group) {
    $sql = "SELECT 
                p.group_name,
                COUNT(DISTINCT p.position) as total_positions,
                MIN(TIMESTAMPDIFF(MINUTE, first_activity.created_at, last_activity.created_at)) as min_minutes,
                MAX(TIMESTAMPDIFF(MINUTE, first_activity.created_at, last_activity.created_at)) as max_minutes,
                AVG(TIMESTAMPDIFF(MINUTE, first_activity.created_at, last_activity.created_at)) as avg_minutes
            FROM 
                picking_plans p
            JOIN 
                (SELECT plan_id, MIN(created_at) as created_at FROM picking_activities GROUP BY plan_id) as first_activity
                ON p.id = first_activity.plan_id
            JOIN 
                (SELECT plan_id, MAX(created_at) as created_at FROM picking_activities GROUP BY plan_id) as last_activity
                ON p.id = last_activity.plan_id
            WHERE 
                p.status = 'completed'
                AND DATE(p.created_at) BETWEEN ? AND ?";
    
    if (!empty($group)) {
        $sql .= " AND p.group_name = ?";
    }
    
    $sql .= " GROUP BY p.group_name
              ORDER BY p.group_name";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($group)) {
        $stmt->bind_param("sss", $date_from, $date_to, $group);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    return $data;
}

// ฟังก์ชันดึงข้อมูลรายงานข้อผิดพลาด
function getErrorReport($conn, $date_from, $date_to, $group) {
    $sql = "SELECT 
                a.created_at,
                a.action,
                a.scan_data,
                a.status,
                a.ip_address,
                p.group_name,
                p.part_number,
                p.position,
                p.rotation
            FROM 
                picking_activities a
            JOIN 
                picking_plans p ON a.plan_id = p.id
            WHERE 
                a.status = 'error'
                AND DATE(a.created_at) BETWEEN ? AND ?";
    
    if (!empty($group)) {
        $sql .= " AND p.group_name = ?";
    }
    
    $sql .= " ORDER BY a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($group)) {
        $stmt->bind_param("sss", $date_from, $date_to, $group);
    } else {
        $stmt->bind_param("ss", $date_from, $date_to);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    return $data;
}

// ดึงข้อมูลตามประเภทรายงาน
$reportData = [];
$chartData = [];
$chartLabels = [];

switch ($report_type) {
    case 'summary':
        $reportData = getSummaryReport($conn, $date_from, $date_to, $group);
        
        // เตรียมข้อมูลสำหรับกราฟ
        $tempData = [];
        foreach ($reportData as $row) {
            if (!isset($tempData[$row['date']])) {
                $tempData[$row['date']] = [];
            }
            $tempData[$row['date']][$row['group_name']] = $row['percentage'];
            
            if (!in_array($row['group_name'], $chartLabels)) {
                $chartLabels[] = $row['group_name'];
            }
        }
        
        // จัดรูปแบบข้อมูลสำหรับ Chart.js
        $chartDates = array_keys($tempData);
        sort($chartDates);
        
        foreach ($chartLabels as $groupName) {
            $groupData = [];
            foreach ($chartDates as $date) {
                $groupData[] = isset($tempData[$date][$groupName]) ? $tempData[$date][$groupName] : 0;
            }
            $chartData[] = [
                'label' => $groupName,
                'data' => $groupData
            ];
        }
        
        $chartLabels = $chartDates;
        break;
        
    case 'activity':
        $reportData = getActivityReport($conn, $date_from, $date_to, $group);
        break;
        
    case 'speed':
        $reportData = getSpeedReport($conn, $date_from, $date_to, $group);
        
        // เตรียมข้อมูลสำหรับกราฟ
        foreach ($reportData as $row) {
            $chartLabels[] = $row['group_name'];
            $chartData[] = round($row['avg_minutes'], 2);
        }
        break;
        
    case 'error':
        $reportData = getErrorReport($conn, $date_from, $date_to, $group);
        break;
        
    default:
        $reportData = getSummaryReport($conn, $date_from, $date_to, $group);
        break;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงาน - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary-color: #1a4f72; /* สีน้ำเงินเข้ม สื่อถึงความเป็นทางการ */
            --secondary-color: #2980b9; /* สีน้ำเงิน สำหรับการเน้นลำดับความสำคัญรอง */
            --accent-color: #f39c12; /* สีส้ม สำหรับการเน้นและแจ้งเตือน */
            --success-color: #27ae60; /* สีเขียว สำหรับความสำเร็จ */
            --warning-color: #e67e22; /* สีส้มเข้ม สำหรับคำเตือน */
            --danger-color: #c0392b; /* สีแดง สำหรับข้อผิดพลาด */
            --light-color: #ecf0f1; /* สีเทาอ่อน สำหรับพื้นหลัง */
            --dark-color: #2c3e50; /* สีเทาเข้ม สำหรับข้อความ */
            --border-radius: 0.25rem;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f5f5f5;
            color: var(--dark-color);
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

        .navbar-dark .navbar-nav .nav-link i {
            margin-right: 0.3rem;
            font-size: 1rem;
            vertical-align: middle;
        }

        .navbar-toggler {
            border: none;
            padding: 0.8rem;
        }

        .navbar-toggler:focus {
            outline: none;
            box-shadow: none;
        }

        .badge-new {
            position: absolute;
            top: 10px;
            right: 3px;
            font-size: 0.65rem;
            padding: 0.15rem 0.35rem;
            background-color: #e74c3c;
            border-radius: 10px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .navbar-nav .logout-link {
            background-color: rgba(224, 79, 79, 0.2);
            margin-left: 0.5rem;
            transition: all 0.3s ease;
        }

        .navbar-nav .logout-link:hover {
            background-color: #e74c3c;
            color: white;
        }

        .navbar-nav .logout-link i {
            color: #ffcc00;
        }

        /* ปรับแต่งเพิ่มเติมสำหรับ Responsive */
        @media (max-width: 992px) {
            .navbar-nav .nav-link {
                padding: 0.5rem 1rem;
                height: auto;
                line-height: normal;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }

            .navbar-dark .navbar-nav .nav-link.active {
                box-shadow: inset 3px 0 0 #ffcc00;
            }

            .navbar-nav .logout-link {
                margin: 0.5rem 1rem;
                text-align: center;
            }
        }

        .main-content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }

        .card-header {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.125);
            display: flex;
            align-items: center;
        }

        .card-header i {
            margin-right: 0.5rem;
            font-size: 1.2rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-control {
            border: 1px solid #ced4da;
            border-radius: var(--border-radius);
            padding: 0.5rem 0.75rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(41, 128, 185, 0.25);
        }

        .btn {
            font-weight: 500;
            border-radius: var(--border-radius);
            padding: 0.5rem 1rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: #16405d;
            border-color: #16405d;
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
        }

        .btn-secondary:hover {
            background-color: #2471a3;
            border-color: #2471a3;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .table {
            color: var(--dark-color);
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .table thead th {
            background-color: var(--light-color);
            color: var(--dark-color);
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: 0.25rem;
        }

        .footer {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 1rem 0;
            margin-top: 2rem;
        }

        /* Filter styles */
        .filter-card {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .filter-card .form-group {
            margin-bottom: 0.5rem;
        }

        .filter-card label {
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        /* Stats card */
        .stats-card {
            border-left: 4px solid var(--primary-color);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: white;
            box-shadow: var(--box-shadow);
        }

        .stats-card.success {
            border-left-color: var(--success-color);
        }

        .stats-card.warning {
            border-left-color: var(--warning-color);
        }

        .stats-card.danger {
            border-left-color: var(--danger-color);
        }

        .stats-card .value {
            font-size: 2rem;
            font-weight: 700;
        }

        .stats-card .label {
            color: var(--dark-color);
            font-weight: 500;
        }

        .stats-card .icon {
            float: right;
            font-size: 2.5rem;
            color: rgba(0, 0, 0, 0.1);
        }

        /* Warehouse Theme */
        .warehouse-header {
            background-color: var(--primary-color);
            color: white;
            padding: 1rem;
            margin-bottom: 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .warehouse-header h1 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .warehouse-header i {
            margin-right: 1rem;
            font-size: 2rem;
        }

        .warehouse-header p {
            margin: 0.5rem 0 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Report Type Nav */
        .report-nav {
            margin-bottom: 1.5rem;
        }

        .report-nav .nav-link {
            color: var(--dark-color);
            border: 1px solid #dee2e6;
            margin-right: 0.5rem;
            background: white;
            transition: all 0.3s;
        }

        .report-nav .nav-link:hover {
            background-color: #f8f9fa;
        }

        .report-nav .nav-link.active {
            color: white;
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .report-nav .nav-link i {
            margin-right: 0.5rem;
        }

        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 1.5rem;
        }

        /* Export Button Group */
        .export-buttons {
            margin-bottom: 1.5rem;
        }

        .export-buttons .btn {
            margin-right: 0.5rem;
        }

        /* Date Range Picker */
        .flatpickr-input {
            background-color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .card-header {
                padding: 0.5rem 1rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .warehouse-header h1 {
                font-size: 1.5rem;
            }
            
            .warehouse-header i {
                font-size: 1.5rem;
            }
            
            .stats-card .value {
                font-size: 1.5rem;
            }

            .report-nav {
                overflow-x: auto;
                white-space: nowrap;
                padding-bottom: 0.5rem;
            }
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
                        <a class="nav-link active" href="reports.php">
                            <i class="fas fa-chart-bar"></i> รายงาน
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="position_mapping.php">
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

    <!-- Main Content -->
    <div class="main-content">
        <!-- Warehouse Header -->
        <div class="warehouse-header">
            <div class="container">
                <h1><i class="fas fa-chart-bar"></i> รายงานและวิเคราะห์ข้อมูล</h1>
                <p>ศูนย์รวมรายงานและการวิเคราะห์ข้อมูลสำหรับระบบ Emergency Picking</p>
            </div>
        </div>

        <!-- Report Type Navigation -->
        <div class="report-nav">
            <ul class="nav nav-pills">
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type == 'summary' ? 'active' : ''; ?>" href="?type=summary">
                        <i class="fas fa-chart-pie"></i> รายงานสรุป
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type == 'activity' ? 'active' : ''; ?>" href="?type=activity">
                        <i class="fas fa-clipboard-list"></i> กิจกรรมการหยิบงาน
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type == 'speed' ? 'active' : ''; ?>" href="?type=speed">
                        <i class="fas fa-tachometer-alt"></i> อัตราความเร็ว
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $report_type == 'error' ? 'active' : ''; ?>" href="?type=error">
                        <i class="fas fa-exclamation-triangle"></i> รายงานข้อผิดพลาด
                    </a>
                </li>
            </ul>
        </div>

        <!-- Filter Card -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-filter"></i> ตัวกรองข้อมูล
            </div>
            <div class="card-body">
                <form method="get" class="filter-form">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="date_from">ตั้งแต่วันที่</label>
                                <input type="text" class="form-control date-picker" id="date_from" name="date_from" placeholder="เลือกวันที่" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="date_to">ถึงวันที่</label>
                                <input type="text" class="form-control date-picker" id="date_to" name="date_to" placeholder="เลือกวันที่" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="group">กลุ่มงาน</label>
                                <select class="form-control" id="group" name="group">
                                    <option value="">ทั้งหมด</option>
                                    <?php foreach ($groups as $groupName): ?>
                                    <option value="<?php echo htmlspecialchars($groupName); ?>" <?php echo $group === $groupName ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($groupName); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-group mb-0 w-100">
                                <button type="submit" class="btn btn-primary mr-2">
                                    <i class="fas fa-search"></i> ค้นหา
                                </button>
                                <a href="?type=<?php echo htmlspecialchars($report_type); ?>" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> รีเซ็ต
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($report_type === 'summary'): ?>
        <!-- Summary Report -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-pie"></i> รายงานสรุปการหยิบงาน
            </div>
            <div class="card-body">
                
                <!-- Summary Stats -->
                <div class="row mb-4">
                    <?php
                    $totalPositions = 0;
                    $completedPositions = 0;
                    
                    foreach ($reportData as $row) {
                        $totalPositions += $row['total_positions'];
                        $completedPositions += $row['completed_positions'];
                    }
                    
                    $overallPercentage = $totalPositions > 0 ? round(($completedPositions / $totalPositions) * 100, 2) : 0;
                    ?>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="icon"><i class="fas fa-layer-group"></i></div>
                            <div class="value"><?php echo $totalPositions; ?></div>
                            <div class="label">ตำแหน่งทั้งหมด</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card success">
                            <div class="icon"><i class="fas fa-check-circle"></i></div>
                            <div class="value"><?php echo $completedPositions; ?></div>
                            <div class="label">ตำแหน่งที่หยิบแล้ว</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card <?php echo $overallPercentage >= 70 ? 'success' : ($overallPercentage >= 30 ? 'warning' : 'danger'); ?>">
                            <div class="icon"><i class="fas fa-percentage"></i></div>
                            <div class="value"><?php echo $overallPercentage; ?>%</div>
                            <div class="label">เปอร์เซ็นต์ความสำเร็จ</div>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>กลุ่ม</th>
                                <th>จำนวนตำแหน่งทั้งหมด</th>
                                <th>จำนวนที่หยิบแล้ว</th>
                                <th>เปอร์เซ็นต์</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="6" class="text-center">ไม่พบข้อมูล</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($row['date'])); ?></td>
                                <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                <td><?php echo $row['total_positions']; ?></td>
                                <td><?php echo $row['completed_positions']; ?></td>
                                <td><?php echo $row['percentage']; ?>%</td>
                                <td>
                                    <?php if ($row['percentage'] == 100): ?>
                                    <span class="badge badge-success">เสร็จสมบูรณ์</span>
                                    <?php elseif ($row['percentage'] > 0): ?>
                                    <span class="badge badge-warning">กำลังดำเนินการ</span>
                                    <?php else: ?>
                                    <span class="badge badge-secondary">ยังไม่เริ่ม</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php elseif ($report_type === 'activity'): ?>
        <!-- Activity Report -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-clipboard-list"></i> รายงานกิจกรรมการหยิบงาน
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>วันที่-เวลา</th>
                                <th>กลุ่ม</th>
                                <th>ตำแหน่ง</th>
                                <th>Part Number</th>
                                <th>กิจกรรม</th>
                                <th>สถานะ</th>
                                <th>ข้อมูลที่สแกน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="7" class="text-center">ไม่พบข้อมูล</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($row['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                <td><?php echo $row['position']; ?></td>
                                <td><?php echo htmlspecialchars($row['part_number']); ?></td>
                                <td>
                                    <?php
                                    switch ($row['action']) {
                                        case 'scan_part':
                                            echo '<span class="badge badge-info">สแกนชิ้นส่วน</span>';
                                            break;
                                        case 'print_label':
                                            echo '<span class="badge badge-primary">พิมพ์ฉลาก</span>';
                                            break;
                                        case 'scan_box':
                                            echo '<span class="badge badge-secondary">สแกนกล่อง</span>';
                                            break;
                                        case 'scan_qr':
                                            echo '<span class="badge badge-warning">สแกน QR</span>';
                                            break;
                                        case 'scan_position':
                                            echo '<span class="badge badge-dark">สแกนตำแหน่ง</span>';
                                            break;
                                        case 'complete_picking':
                                            echo '<span class="badge badge-success">เสร็จสมบูรณ์</span>';
                                            break;
                                        default:
                                            echo htmlspecialchars($row['action']);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'success'): ?>
                                    <span class="badge badge-success">สำเร็จ</span>
                                    <?php elseif ($row['status'] === 'error'): ?>
                                    <span class="badge badge-danger">ผิดพลาด</span>
                                    <?php else: ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($row['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['scan_data']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (count($reportData) >= 1000): ?>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> แสดงผล 1,000 รายการล่าสุดเท่านั้น กรุณาใช้ตัวกรองเพื่อจำกัดผลลัพธ์
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($report_type === 'speed'): ?>
        <!-- Speed Report -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-tachometer-alt"></i> รายงานอัตราความเร็วการหยิบงาน
            </div>
            <div class="card-body">
                <!-- Chart -->
                <div class="chart-container">
                    <canvas id="speedChart"></canvas>
                </div>
                
                <!-- Speed Table -->
                <div class="table-responsive mt-4">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>กลุ่ม</th>
                                <th>จำนวนตำแหน่ง</th>
                                <th>เวลาเฉลี่ย (นาที)</th>
                                <th>เวลาน้อยที่สุด (นาที)</th>
                                <th>เวลามากที่สุด (นาที)</th>
                                <th>เวลาเฉลี่ยต่อตำแหน่ง (นาที)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="6" class="text-center">ไม่พบข้อมูล</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                <td><?php echo $row['total_positions']; ?></td>
                                <td><?php echo round($row['avg_minutes'], 2); ?></td>
                                <td><?php echo round($row['min_minutes'], 2); ?></td>
                                <td><?php echo round($row['max_minutes'], 2); ?></td>
                                <td><?php echo $row['total_positions'] > 0 ? round($row['avg_minutes'] / $row['total_positions'], 2) : 0; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> เวลาที่แสดงคำนวณจากเวลาที่เริ่มกิจกรรมแรกจนถึงกิจกรรมสุดท้ายของแต่ละตำแหน่ง
                </div>
            </div>
        </div>

        <?php elseif ($report_type === 'error'): ?>
        <!-- Error Report -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-exclamation-triangle"></i> รายงานข้อผิดพลาด
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>วันที่-เวลา</th>
                                <th>กลุ่ม</th>
                                <th>ตำแหน่ง</th>
                                <th>Part Number</th>
                                <th>กิจกรรม</th>
                                <th>ข้อมูลที่สแกน</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reportData)): ?>
                            <tr>
                                <td colspan="7" class="text-center">ไม่พบข้อมูลข้อผิดพลาด</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($row['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                <td><?php echo $row['position']; ?></td>
                                <td><?php echo htmlspecialchars($row['part_number']); ?></td>
                                <td>
                                    <?php
                                    switch ($row['action']) {
                                        case 'scan_part':
                                            echo '<span class="badge badge-info">สแกนชิ้นส่วน</span>';
                                            break;
                                        case 'print_label':
                                            echo '<span class="badge badge-primary">พิมพ์ฉลาก</span>';
                                            break;
                                        case 'scan_box':
                                            echo '<span class="badge badge-secondary">สแกนกล่อง</span>';
                                            break;
                                        case 'scan_qr':
                                            echo '<span class="badge badge-warning">สแกน QR</span>';
                                            break;
                                        case 'scan_position':
                                            echo '<span class="badge badge-dark">สแกนตำแหน่ง</span>';
                                            break;
                                        default:
                                            echo htmlspecialchars($row['action']);
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['scan_data']); ?></td>
                                <td><?php echo htmlspecialchars($row['ip_address']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($reportData)): ?>
                <div class="alert alert-warning mt-3">
                    <h5><i class="fas fa-exclamation-circle"></i> ข้อสังเกตเกี่ยวกับข้อผิดพลาด</h5>
                    <p>พบข้อผิดพลาดจำนวน <?php echo count($reportData); ?> รายการในช่วงเวลาที่เลือก ข้อผิดพลาดส่วนใหญ่มักเกิดจาก:</p>
                    <ul>
                        <li>การสแกนชิ้นส่วนผิด (Part Number ไม่ตรงกัน)</li>
                        <li>การสแกน QR Code ที่ไม่ตรงกับ Rotation</li>
                        <li>การสแกนตำแหน่งผิด</li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Emergency Picking System - By Gan Anchaleepon</p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/th.js"></script>
    <script>
        // Date Range Picker
        flatpickr(".date-picker", {
            dateFormat: "Y-m-d",
            locale: "th",
            allowInput: true,
            altInput: true,
            altFormat: "d/m/Y",
            monthSelectorType: "static"
        });
        
        <?php if ($report_type === 'summary' && !empty($chartLabels) && !empty($chartData)): ?>
        // Summary Chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('summaryChart').getContext('2d');
            
            // สร้างชุดข้อมูลสำหรับกราฟ
            const datasets = [];
            const colors = [
                'rgba(41, 128, 185, 0.7)', // สีน้ำเงิน
                'rgba(39, 174, 96, 0.7)',  // สีเขียว
                'rgba(243, 156, 18, 0.7)', // สีเหลือง
                'rgba(231, 76, 60, 0.7)',  // สีแดง
                'rgba(142, 68, 173, 0.7)', // สีม่วง
                'rgba(230, 126, 34, 0.7)', // สีส้ม
                'rgba(149, 165, 166, 0.7)' // สีเทา
            ];
            
            <?php foreach ($chartData as $index => $dataset): ?>
            datasets.push({
                label: '<?php echo addslashes($dataset['label']); ?>',
                data: <?php echo json_encode($dataset['data']); ?>,
                backgroundColor: colors[<?php echo $index % count($colors); ?>],
                borderColor: colors[<?php echo $index % count($colors); ?>].replace('0.7', '1'),
                borderWidth: 1
            });
            <?php endforeach; ?>
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_map(function($date) { return date('d/m/Y', strtotime($date)); }, $chartLabels)); ?>,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'ความคืบหน้าตามกลุ่มและวันที่'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        },
                        legend: { // Corrected: legend is an object within plugins
                            position: 'top',
                        }
                    },
                    scales: { // Corrected: scales is an object
                        y: { // Corrected: y-axis scale is an object within scales
                            beginAtZero: true,
                            max: 100,
                            title: {
                                display: true,
                                text: 'เปอร์เซ็นต์ความสำเร็จ (%)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'วันที่'
                            }
                        }
                    }
                }
            });
        });
        <?php endif; ?>
        
        <?php if ($report_type === 'speed' && !empty($chartLabels) && !empty($chartData)): ?>
        // Speed Chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('speedChart').getContext('2d');
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chartLabels); ?>,
                    datasets: [{
                        label: 'เวลาเฉลี่ย (นาที)',
                        data: <?php echo json_encode($chartData); ?>,
                        backgroundColor: 'rgba(41, 128, 185, 0.7)',
                        borderColor: 'rgba(41, 128, 185, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'เวลาเฉลี่ยในการหยิบงานแต่ละกลุ่ม'
                        },
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'เวลา (นาที)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'กลุ่ม'
                            }
                        }
                    }
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>

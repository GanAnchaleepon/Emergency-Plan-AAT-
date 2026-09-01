<?php
date_default_timezone_set('Asia/Bangkok');
// หน้าแสดงประวัติการอัปโหลดแผนการหยิบงาน (สำหรับแอดมิน)
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// รับพารามิเตอร์การกรองและการค้นหา
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'upload_date';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// สร้าง SQL query สำหรับดึงข้อมูลประวัติการอัปโหลด
$sql = "SELECT u.id, u.filename, u.upload_date, u.status, u.rows_total, 
               u.rows_success, u.rows_duplicate, u.rows_error, us.name as uploaded_by 
        FROM uploads u 
        JOIN users us ON u.uploaded_by = us.id 
        WHERE 1=1";

// เพิ่มเงื่อนไขการค้นหา
if (!empty($searchTerm)) {
    $searchTerm = '%' . $searchTerm . '%';
    $sql .= " AND (u.filename LIKE ? OR us.name LIKE ?)";
}

// เพิ่มเงื่อนไขการกรองตามวันที่
if (!empty($dateFrom)) {
    $sql .= " AND DATE(u.upload_date) >= ?";
}
if (!empty($dateTo)) {
    $sql .= " AND DATE(u.upload_date) <= ?";
}

// เพิ่มเงื่อนไขการกรองตามสถานะ
if (!empty($status)) {
    $sql .= " AND u.status = ?";
}

// เพิ่มการเรียงลำดับ
$sql .= " ORDER BY $sortBy $sortOrder";

// เตรียม statement และผูกพารามิเตอร์
$stmt = $conn->prepare($sql);

$paramTypes = "";
$paramValues = [];

if (!empty($searchTerm)) {
    $paramTypes .= "ss";
    $paramValues[] = $searchTerm;
    $paramValues[] = $searchTerm;
}

if (!empty($dateFrom)) {
    $paramTypes .= "s";
    $paramValues[] = $dateFrom;
}

if (!empty($dateTo)) {
    $paramTypes .= "s";
    $paramValues[] = $dateTo;
}

if (!empty($status)) {
    $paramTypes .= "s";
    $paramValues[] = $status;
}

if (!empty($paramTypes)) {
    $bindParams = [$paramTypes];
    foreach ($paramValues as $key => $value) {
        $bindParams[] = &$paramValues[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

// ดึงข้อมูล
$stmt->execute();
$result = $stmt->get_result();
$uploadHistory = [];

while ($row = $result->fetch_assoc()) {
    $uploadHistory[] = $row;
}

// ฟังก์ชันสร้าง URL สำหรับการเรียงลำดับ
function getSortUrl($field) {
    global $sortBy, $sortOrder, $searchTerm, $dateFrom, $dateTo, $status;
    
    $newOrder = ($sortBy === $field && $sortOrder === 'ASC') ? 'DESC' : 'ASC';
    
    $params = [
        'sort' => $field,
        'order' => $newOrder
    ];
    
    if (!empty($searchTerm)) {
        $params['search'] = $searchTerm;
    }
    
    if (!empty($dateFrom)) {
        $params['date_from'] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $params['date_to'] = $dateTo;
    }
    
    if (!empty($status)) {
        $params['status'] = $status;
    }
    
    return '?' . http_build_query($params);
}

// ฟังก์ชันแสดงไอคอนการเรียงลำดับ
function getSortIcon($field) {
    global $sortBy, $sortOrder;
    
    if ($sortBy !== $field) {
        return '<i class="fas fa-sort text-muted"></i>';
    }
    
    return ($sortOrder === 'ASC') 
        ? '<i class="fas fa-sort-up text-primary"></i>' 
        : '<i class="fas fa-sort-down text-primary"></i>';
}

// ฟังก์ชันสำหรับส่งออกข้อมูลเป็น CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // ตั้งค่า header สำหรับไฟล์ CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=upload_history_' . date('Y-m-d') . '.csv');
    
    // เปิด output stream
    $output = fopen('php://output', 'w');
    
    // เขียน BOM เพื่อรองรับภาษาไทยใน Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // เขียนหัวคอลัมน์
    fputcsv($output, [
        'ID', 'ชื่อไฟล์', 'วันที่อัปโหลด', 'สถานะ', 'รวมแถว', 
        'แถวสำเร็จ', 'แถวซ้ำ', 'แถวผิดพลาด', 'อัปโหลดโดย'
    ]);
    
    // เขียนข้อมูลแต่ละแถว
    foreach ($uploadHistory as $row) {
        fputcsv($output, [
            $row['id'],
            $row['filename'],
            $row['upload_date'],
            $row['status'],
            $row['rows_total'],
            $row['rows_success'],
            $row['rows_duplicate'],
            $row['rows_error'],
            $row['uploaded_by']
        ]);
    }
    
    fclose($output);
    exit;
}

// จัดการการลบไฟล์
if (isset($_POST['delete_file']) && isset($_POST['upload_id'])) {
    $uploadId = (int)$_POST['upload_id'];
    
    try {
        // เริ่ม Transaction
        mysqli_begin_transaction($conn);
        
        // ดึงข้อมูลไฟล์ก่อนลบเพื่อใช้ในการบันทึกประวัติ
        $fileInfoSql = "SELECT filename, rows_total FROM uploads WHERE id = ?";
        $fileInfoStmt = mysqli_prepare($conn, $fileInfoSql);
        mysqli_stmt_bind_param($fileInfoStmt, "i", $uploadId);
        mysqli_stmt_execute($fileInfoStmt);
        $fileInfoResult = mysqli_stmt_get_result($fileInfoStmt);
        
        if ($fileInfoRow = mysqli_fetch_assoc($fileInfoResult)) {
            $fileName = $fileInfoRow['filename'];
            $rowsTotal = $fileInfoRow['rows_total'];
            
            // ลบข้อมูลจากตาราง uploads
            $deleteSql = "DELETE FROM uploads WHERE id = ?";
            $deleteStmt = mysqli_prepare($conn, $deleteSql);
            mysqli_stmt_bind_param($deleteStmt, "i", $uploadId);
            
            if (mysqli_stmt_execute($deleteStmt)) {
                // บันทึกประวัติการลบไฟล์
                $logSql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
                $logStmt = mysqli_prepare($conn, $logSql);
                $action = "delete_file";
                $details = "ลบไฟล์: $fileName (จำนวน $rowsTotal รายการ)";
                $ipAddress = $_SERVER['REMOTE_ADDR'];
                
                mysqli_stmt_bind_param($logStmt, "isss", $_SESSION['user_id'], $action, $details, $ipAddress);
                mysqli_stmt_execute($logStmt);
                
                // Commit Transaction
                mysqli_commit($conn);
                
                $alert = "ลบไฟล์ $fileName เรียบร้อยแล้ว";
                $alertType = "success";
            } else {
                throw new Exception("ไม่สามารถลบไฟล์ได้: " . mysqli_error($conn));
            }
        } else {
            throw new Exception("ไม่พบข้อมูลไฟล์ที่ต้องการลบ");
        }
    } catch (Exception $e) {
        // Rollback หากเกิดข้อผิดพลาด
        mysqli_rollback($conn);
        
        $alert = "เกิดข้อผิดพลาดในการลบไฟล์: " . $e->getMessage();
        $alertType = "danger";
    }
}

// === เพิ่มส่วนจัดการการลบข้อมูลจากหลายตารางพร้อมกัน ===
if (isset($_POST['truncate_all_data'])) {
    try {
        // ปิด Foreign Key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        
        // เริ่ม Transaction
        mysqli_begin_transaction($conn);
        
        $tables = ['completed_rounds', 'logs', 'picking_activities', 'picking_plans', 'position_mappings', 'uploads'];
        $truncatedCount = 0;
        
        foreach ($tables as $table) {
            if ($conn->query("TRUNCATE TABLE `$table`")) {
                $truncatedCount++;
            }
        }
        
        // บันทึกประวัติการลบข้อมูล
        $logSql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
        $logStmt = $conn->prepare($logSql);
        $action = "truncate_all_data";
        $details = "ลบข้อมูลทั้งหมดจาก " . $truncatedCount . " ตาราง: " . implode(', ', $tables);
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        
        $logStmt->bind_param("isss", $_SESSION['user_id'], $action, $details, $ipAddress);
        $logStmt->execute();
        
        // Commit Transaction
        mysqli_commit($conn);
        
        // เปิด Foreign Key checks กลับมา
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        
        $alert = "ลบข้อมูลทั้งหมดจาก " . $truncatedCount . " ตารางเรียบร้อยแล้ว";
        $alertType = "success";
    } catch (Exception $e) {
        // Rollback หากเกิดข้อผิดพลาด
        mysqli_rollback($conn);
        
        // เปิด Foreign Key checks กลับมา
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        
        $alert = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
        $alertType = "danger";
    }
}
// === สิ้นสุดส่วนจัดการการลบข้อมูล ===

// ดึงข้อมูลประวัติการอัปโหลด
$historySql = "SELECT u.*, 
               COUNT(p.id) as plans_count, 
               SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
               CONCAT(ROUND((SUM(CASE WHEN p.status = 'completed' THEN 1 ELSE 0 END) / COUNT(p.id)) * 100), '%') as progress,
               us.name as uploader_name
               FROM uploads u 
               LEFT JOIN picking_plans p ON u.id = p.upload_id
               LEFT JOIN users us ON u.uploaded_by = us.id
               GROUP BY u.id
               ORDER BY u.upload_date DESC";
$historyResult = mysqli_query($conn, $historySql);

// --- เพิ่มโค้ดส่วนนี้เพื่อดึงข้อมูลแผนการหยิบทั้งหมด ---
$allPlans = [];

// รับค่าพารามิเตอร์การกรองสำหรับตารางแผนการหยิบทั้งหมด
$filter_group = isset($_GET['filter_group']) ? $_GET['filter_group'] : '';
$filter_part_number = isset($_GET['filter_part_number']) ? $_GET['filter_part_number'] : '';
$filter_rotation = isset($_GET['filter_rotation']) ? $_GET['filter_rotation'] : '';
$filter_supplier = isset($_GET['filter_supplier']) ? $_GET['filter_supplier'] : '';
$filter_location = isset($_GET['filter_location']) ? $_GET['filter_location'] : '';

// สร้างคำสั่ง SQL สำหรับดึงข้อมูลแผนการหยิบทั้งหมดพร้อมเงื่อนไขกรอง
$allPlansSql = "SELECT rotation, position, group_name, part_number, part_name, model, item_lot, supplier, location, quantity, date, time
                FROM picking_plans
                WHERE 1=1"; // เพิ่ม model

$allPlansParams = [];
$allPlansParamTypes = "";

if (!empty($filter_group)) {
    $allPlansSql .= " AND group_name = ?";
    $allPlansParams[] = $filter_group;
    $allPlansParamTypes .= "s";
}

if (!empty($filter_part_number)) {
    $allPlansSql .= " AND part_number LIKE ?";
    $allPlansParams[] = "%" . $filter_part_number . "%";
    $allPlansParamTypes .= "s";
}

if (!empty($filter_rotation)) {
    $allPlansSql .= " AND rotation LIKE ?";
    $allPlansParams[] = "%" . $filter_rotation . "%";
    $allPlansParamTypes .= "s";
}

if (!empty($filter_supplier)) {
    $allPlansSql .= " AND supplier LIKE ?";
    $allPlansParams[] = "%" . $filter_supplier . "%";
    $allPlansParamTypes .= "s";
}

if (!empty($filter_location)) {
    $allPlansSql .= " AND location LIKE ?";
    $allPlansParams[] = "%" . $filter_location . "%";
    $allPlansParamTypes .= "s";
}

// --- จัดการการส่งออก CSV สำหรับแผนการหยิบทั้งหมด ---
if (isset($_GET['export_plans']) && $_GET['export_plans'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=all_picking_plans_' . date('Ymd') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, "\xEF\xBB\xBF"); // BOM for UTF-8

    fputcsv($output, [
        'กลุ่ม', 'ตำแหน่ง', 'Rotation', 'Part Number', 'Part Name', 'Model',
        'Item Lot', 'Supplier', 'Location', 'จำนวน', 'สถานะ', 'อัปเดตล่าสุด'
    ]);

    // เพิ่ม status และ updated_at ใน SQL สำหรับ export
    $exportSql = str_replace(
        "SELECT rotation, position, group_name, part_number, part_name, model, item_lot, supplier, location, quantity",
        "SELECT rotation, position, group_name, part_number, part_name, model, item_lot, supplier, location, quantity, status, updated_at",
        $allPlansSql
    );
    
    $exportStmt = $conn->prepare($exportSql . " ORDER BY group_name, CAST(rotation AS UNSIGNED), position");
    if (!empty($allPlansParamTypes)) {
        $bindParams = [$allPlansParamTypes];
        foreach ($allPlansParams as $key => $value) {
            $bindParams[] = &$allPlansParams[$key];
        }
        call_user_func_array([$exportStmt, 'bind_param'], $bindParams);
    }
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();

    if ($exportResult) {
        while ($row = $exportResult->fetch_assoc()) {
            fputcsv($output, [
                $row['group_name'],
                $row['position'],
                $row['rotation'],
                $row['part_number'],
                $row['part_name'],
                $row['model'] ?? '',
                $row['item_lot'] ?? '',
                $row['supplier'] ?? '',
                $row['location'] ?? '',
                $row['quantity'],
                $row['status'],
                $row['updated_at']
            ]);
        }
    }
    
    fclose($output);
    exit;
}
// --- สิ้นสุดการจัดการส่งออก ---


$allPlansSql .= " ORDER BY group_name ASC, updated_at ASC, CAST(rotation AS UNSIGNED) ASC"; // เรียงตามกลุ่ม, วันที่เก่าสุดไว้บน, Rotation น้อยไว้บนสุด

$allPlansStmt = $conn->prepare($allPlansSql);

if (!empty($allPlansParamTypes)) {
    $bindParams = [$allPlansParamTypes];
    foreach ($allPlansParams as $key => $value) {
        $bindParams[] = &$allPlansParams[$key];
    }
    call_user_func_array([$allPlansStmt, 'bind_param'], $bindParams);
}

$allPlansStmt->execute();
$allPlansResult = $allPlansStmt->get_result();


if ($allPlansResult) {
    while ($row = mysqli_fetch_assoc($allPlansResult)) {
        $allPlans[] = $row;
    }
}
// --- สิ้นสุดโค้ดส่วนเพิ่ม ---

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการอัปโหลด - ระบบ Emergency Picking</title>
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

        .section-title {
            position: relative;
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }

        .section-title:after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100px;
            height: 3px;
            background-color: var(--primary-color);
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
            white-space: nowrap;
            padding: 0.75rem 0.5rem;
        }

        .table thead th a {
            color: var(--dark-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.04);
        }

        .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
            border-radius: 0.25rem;
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

        .badge-secondary {
            background-color: var(--secondary-color);
        }

        .badge-light {
            background-color: var(--light-color);
            color: var(--dark-color);
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

        /* Sortable columns */
        .sortable {
            cursor: pointer;
        }

        .sortable:hover {
            background-color: rgba(0, 0, 0, 0.05);
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

            .table {
                font-size: 0.9rem;
            }
        }

        /* Date range picker */
        .flatpickr-input {
            background-color: white;
        }

        /* --- เพิ่ม CSS สำหรับปรับขนาดตัวอักษรในตารางรายการแผนการหยิบทั้งหมด --- */
        .all-plans-table, .all-plans-table th, .all-plans-table td {
            font-size: 12px !important;
        }
        .all-plans-table thead th {
            text-align: center;
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
                        <a class="nav-link active" href="history.php">
                            <i class="fas fa-history"></i> ประวัติ
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
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
                <h1><i class="fas fa-history"></i> ประวัติการอัปโหลดแผนงาน</h1>
                <p>บันทึกประวัติการอัปโหลดแผนการหยิบงานฉุกเฉิน</p>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-filter"></i> ตัวกรองข้อมูล
            </div>
            <div class="card-body">
                <form method="get" class="filter-form">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="search">ค้นหา</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="ชื่อไฟล์, ผู้อัปโหลด" value="<?php echo htmlspecialchars($searchTerm); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="date_from">ตั้งแต่วันที่</label>
                                <input type="text" class="form-control date-picker" id="date_from" name="date_from" placeholder="เลือกวันที่" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="date_to">ถึงวันที่</label>
                                <input type="text" class="form-control date-picker" id="date_to" name="date_to" placeholder="เลือกวันที่" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label for="status">สถานะ</label>
                                <select class="form-control" id="status" name="status">
                                    <option value="">ทั้งหมด</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                    <option value="error" <?php echo $status === 'error' ? 'selected' : ''; ?>>Error</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-group mb-0 w-100">
                                <button type="submit" class="btn btn-primary mr-2">
                                    <i class="fas fa-search"></i> ค้นหา
                                </button>
                                <a href="history.php" class="btn btn-secondary">
                                    <i class="fas fa-redo"></i> รีเซ็ต
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Stats Summary -->
        <div class="row">
            <?php 
            $totalUploads = count($uploadHistory);
            $successfulUploads = 0;
            $totalRows = 0;
            $successRows = 0;
            $duplicateRows = 0;
            $errorRows = 0;

            foreach ($uploadHistory as $upload) {
                $totalRows += $upload['rows_total'];
                $successRows += $upload['rows_success'];
                $duplicateRows += $upload['rows_duplicate'];
                $errorRows += $upload['rows_error'];
                
                if ($upload['rows_error'] == 0 && $upload['rows_success'] > 0) {
                    $successfulUploads++;
                }
            }
            ?>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="icon"><i class="fas fa-file-upload"></i></div>
                    <div class="value"><?php echo $totalUploads; ?></div>
                    <div class="label">การอัปโหลดทั้งหมด</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card success">
                    <div class="icon"><i class="fas fa-check-circle"></i></div>
                    <div class="value"><?php echo $successRows; ?></div>
                    <div class="label">แถวสำเร็จ</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card warning">
                    <div class="icon"><i class="fas fa-copy"></i></div>
                    <div class="value"><?php echo $duplicateRows; ?></div>
                    <div class="label">แถวซ้ำ</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card danger">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="value"><?php echo $errorRows; ?></div>
                    <div class="label">แถวผิดพลาด</div>
                </div>
            </div>
        </div>

        <!-- Upload History Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-history"></i> ประวัติการอัปโหลด
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($uploadHistory)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">ไม่พบประวัติการอัปโหลด</h5>
                        <p class="text-muted">ยังไม่มีการอัปโหลดแผนงานเข้าระบบ</p>
                        <a href="upload.php" class="btn btn-primary mt-2">
                            <i class="fas fa-upload"></i> อัปโหลดแผนงาน
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead>
                                <tr>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('id'); ?>">
                                            ID <?php echo getSortIcon('id'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('filename'); ?>">
                                            ชื่อไฟล์ <?php echo getSortIcon('filename'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('upload_date'); ?>">
                                            วันที่อัปโหลด <?php echo getSortIcon('upload_date'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('status'); ?>">
                                            สถานะ <?php echo getSortIcon('status'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('rows_total'); ?>">
                                            รวมแถว <?php echo getSortIcon('rows_total'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('rows_success'); ?>">
                                            สำเร็จ <?php echo getSortIcon('rows_success'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('rows_duplicate'); ?>">
                                            ซ้ำ <?php echo getSortIcon('rows_duplicate'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('rows_error'); ?>">
                                            ผิดพลาด <?php echo getSortIcon('rows_error'); ?>
                                        </a>
                                    </th>
                                    <th class="sortable">
                                        <a href="<?php echo getSortUrl('uploaded_by'); ?>">
                                            อัปโหลดโดย <?php echo getSortIcon('uploaded_by'); ?>
                                        </a>
                                    </th>
                                    <th>การดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uploadHistory as $upload): 
                                    $statusClass = 'secondary';
                                    if ($upload['status'] === 'active') {
                                        $statusClass = 'success';
                                    } elseif ($upload['status'] === 'error') {
                                        $statusClass = 'danger';
                                    } elseif ($upload['status'] === 'archived') {
                                        $statusClass = 'warning';
                                    }
                                    
                                    $successPercent = $upload['rows_total'] > 0 
                                        ? round(($upload['rows_success'] / $upload['rows_total']) * 100) 
                                        : 0;
                                ?>
                                <tr>
                                    <td><?php echo $upload['id']; ?></td>
                                    <td><?php echo htmlspecialchars($upload['filename']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($upload['upload_date'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($upload['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $upload['rows_total']; ?></td>
                                    <td><?php echo $upload['rows_success']; ?> (<?php echo $successPercent; ?>%)</td>
                                    <td><?php echo $upload['rows_duplicate']; ?></td>
                                    <td><?php echo $upload['rows_error']; ?></td>
                                    <td><?php echo htmlspecialchars($upload['uploaded_by']); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="view_upload.php?id=<?php echo $upload['id']; ?>" class="btn btn-sm btn-info" title="ดูรายละเอียด">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($upload['status'] === 'active'): ?>
                                            <!-- ปุ่มเก็บถาวรถูกลบออก -->
                                            <?php else: ?>
                                            </a>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger delete-file-btn" 
                                                    data-toggle="modal" 
                                                    data-target="#deleteFileModal" 
                                                    data-id="<?php echo $upload['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($upload['filename']); ?>"
                                                    title="ลบไฟล์">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- --- เพิ่มส่วนแสดงรายการแผนการหยิบทั้งหมด --- -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="fas fa-list-alt"></i> รายการแผนการหยิบทั้งหมดในระบบ (<?php echo count($allPlans); ?> รายการ)
                </div>
                <div>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export_plans' => 'csv'])); ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </a>
                </div>
            </div>
            <div class="card-body">
                <!-- ปรับขนาดตัวอักษรให้เล็กลง -->
                <style>
                    .all-plans-table, .all-plans-table th, .all-plans-table td {
                        font-size: 12px !important;
                    }
                </style>
                <!-- Filter Form for All Plans -->
                <form method="get" class="form-inline mb-4">
                    <div class="form-group mr-2 mb-2">
                        <label for="filter_group" class="mr-2">กลุ่ม:</label>
                        <input type="text" class="form-control form-control-sm" id="filter_group" name="filter_group" placeholder="เช่น GROUP 01" value="<?php echo htmlspecialchars($filter_group); ?>">
                    </div>
                     <div class="form-group mr-2 mb-2">
                        <label for="filter_rotation" class="mr-2">Rotation:</label>
                        <input type="text" class="form-control form-control-sm" id="filter_rotation" name="filter_rotation" placeholder="เช่น 1234" value="<?php echo htmlspecialchars($filter_rotation); ?>">
                    </div>
                    <div class="form-group mr-2 mb-2">
                        <label for="filter_part_number" class="mr-2">Part Number:</label>
                        <input type="text" class="form-control form-control-sm" id="filter_part_number" name="filter_part_number" placeholder="เช่น ABC-123" value="<?php echo htmlspecialchars($filter_part_number); ?>">
                    </div>
                    <div class="form-group mr-2 mb-2">
                        <label for="filter_supplier" class="mr-2">Supplier:</label>
                        <input type="text" class="form-control form-control-sm" id="filter_supplier" name="filter_supplier" placeholder="เช่น XYZ" value="<?php echo htmlspecialchars($filter_supplier); ?>">
                    </div>
                     <div class="form-group mr-2 mb-2">
                        <label for="filter_location" class="mr-2">Location:</label>
                        <input type="text" class="form-control form-control-sm" id="filter_location" name="filter_location" placeholder="เช่น A1-B2" value="<?php echo htmlspecialchars($filter_location); ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-sm mb-2">
                        <i class="fas fa-filter"></i> กรอง
                    </button>
                    <a href="history.php" class="btn btn-secondary btn-sm mb-2 ml-2">
                        <i class="fas fa-redo"></i> รีเซ็ตตัวกรอง
                    </a>
                </form>

                <?php if (empty($allPlans)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">ไม่พบรายการแผนการหยิบในระบบ</h5>
                        <p class="text-muted">กรุณาอัปโหลดแผนงานใหม่ หรือปรับเงื่อนไขตัวกรอง</p>
                        <a href="upload.php" class="btn btn-primary mt-2">
                            <i class="fas fa-upload"></i> อัปโหลดแผนงาน
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-striped table-hover mb-0 all-plans-table">
                            <thead>
                                <tr>
                                    <th>กลุ่ม</th>
                                    <th>ตำแหน่ง</th>
                                    <th>Rotation</th>
                                    <th>Part Number</th>
                                    <th>Part Name</th>
                                    <th>Model</th>
                                    <th>Item Lot</th>
                                    <th>Supplier</th>
                                    <th>Location</th>
                                    <th>Quantity</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allPlans as $plan): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($plan['group_name']); ?></td>
                                    <td><?php echo htmlspecialchars($plan['position']); ?></td>
                                    <td><?php echo str_pad(htmlspecialchars($plan['rotation']), 4, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo htmlspecialchars($plan['part_number']); ?></td>
                                    <td><?php echo htmlspecialchars($plan['part_name']); ?></td>
                                    <td><?php echo htmlspecialchars($plan['model'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['item_lot'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['supplier'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['location'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['quantity']); ?></td>
                                    <td>
                                        <?php
                                        $dateValue = isset($plan['date']) ? $plan['date'] : '';
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
                                        $timeValue = isset($plan['time']) ? $plan['time'] : '';
                                        if ($timeValue) {
                                            $timeObj = DateTime::createFromFormat('H:i:s', $timeValue);
                                            echo ($timeObj) ? $timeObj->format('H:i:s') : '-';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- --- สิ้นสุดส่วนแสดงรายการแผนการหยิบทั้งหมด --- -->


        <!-- === เพิ่มส่วนปุ่มลบข้อมูลจากหลายตาราง === -->
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <i class="fas fa-exclamation-triangle"></i> ลบข้อมูลทั้งหมด (ยืนยันผ่าน Modal)
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger" role="alert">
                            <h5 class="alert-heading">
                                <i class="fas fa-skull-crossbones"></i> ข้อเตือน: การลบข้อมูล!
                            </h5>
                            <p>ปุ่มนี้จะลบข้อมูลทั้งหมดจากตารางต่อไปนี้:</p>
                            <ul class="mb-0">
                                <li><strong>completed_rounds</strong> - บันทึก Round ที่เสร็จสิ้น</li>
                                <li><strong>logs</strong> - บันทึกกิจกรรมของระบบ</li>
                                <li><strong>picking_activities</strong> - ประวัติการสแกน</li>
                                <li><strong>picking_plans</strong> - แผนการหยิบงาน</li>
                                <li><strong>position_mappings</strong> - Mapping ตำแหน่ง</li>
                                <li><strong>uploads</strong> - ประวัติการอัปโหลด</li>
                            </ul>
                        </div>
                        <button type="button" class="btn btn-danger btn-lg" data-toggle="modal" data-target="#truncateAllModal">
                            <i class="fas fa-trash-alt"></i> ลบข้อมูลทั้งหมด (ผ่าน Modal)
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- === สิ้นสุดส่วนปุ่มลบข้อมูล === -->
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Emergency Picking System - By Gan Anchaleepon</p>
        </div>
    </footer>

    <!-- Modal ยืนยันการลบไฟล์ -->
    <div class="modal fade" id="deleteFileModal" tabindex="-1" role="dialog" aria-labelledby="deleteFileModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteFileModalLabel">
                        <i class="fas fa-exclamation-triangle"></i> ยืนยันการลบไฟล์
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>คุณต้องการลบไฟล์ <strong id="fileName"></strong> ใช่หรือไม่?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> คำเตือน: การลบไฟล์จะลบข้อมูลทั้งหมดที่เกี่ยวข้องกับไฟล์นี้ด้วย รวมถึง:
                        <ul>
                            <li>แผนงานหยิบทั้งหมดที่มาจากไฟล์นี้</li>
                            <li>ประวัติการหยิบที่เกี่ยวข้องกับแผนงานในไฟล์นี้</li>
                        </ul>
                        <p class="mb-0"><strong>การดำเนินการนี้ไม่สามารถย้อนกลับได้!</strong></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="post">
                        <input type="hidden" name="upload_id" id="uploadId">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                        <button type="submit" name="delete_file" class="btn btn-danger">
                            <i class="fas fa-trash"></i> ลบไฟล์
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- === เพิ่ม Modal สำหรับยืนยันการลบข้อมูลจากหลายตาราง === -->
    <div class="modal fade" id="truncateAllModal" tabindex="-1" role="dialog" aria-labelledby="truncateAllModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="truncateAllModalLabel">
                        <i class="fas fa-skull-crossbones"></i> ยืนยันการลบข้อมูลทั้งหมด
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <h5 class="alert-heading">
                            <i class="fas fa-exclamation-circle"></i> ข้อเตือนเชิงสำคัญ!
                        </h5>
                        <p class="mb-2">การดำเนินการนี้จะ <strong>ลบข้อมูลทั้งหมด</strong> จากตารางต่อไปนี้:</p>
                        <ol>
                            <li><strong>completed_rounds</strong> - บันทึก Round ที่เสร็จสิ้น</li>
                            <li><strong>logs</strong> - บันทึกกิจกรรมและการเข้าใช้ระบบ</li>
                            <li><strong>picking_activities</strong> - ประวัติการสแกนและกิจกรรมการหยิบ</li>
                            <li><strong>picking_plans</strong> - แผนการหยิบงานทั้งหมด</li>
                            <li><strong>position_mappings</strong> - Mapping ตำแหน่ง</li>
                            <li><strong>uploads</strong> - ประวัติการอัปโหลดไฟล์ CSV</li>
                        </ol>
                        <p class="mb-0"><strong style="color: #721c24;">⚠️ การดำเนินการนี้ไม่สามารถย้อนกลับได้! ข้อมูลทั้งหมดจะหายไปตลอดไป!</strong></p>
                    </div>

                    <div class="alert alert-info">
                        <h6 class="alert-heading">
                            <i class="fas fa-info-circle"></i> ข้อมูลที่จะถูกลบ:
                        </h6>
                        <ul class="mb-0">
                            <li>
                                <strong>completed_rounds</strong> 
                                <span class="badge badge-secondary">ลบทั้งหมด</span>
                                - บันทึก Round ทั้ง 4 รายการ
                            </li>
                            <li>
                                <strong>logs</strong> 
                                <span class="badge badge-secondary">ลบทั้งหมด</span>
                                - บันทึก Activity ทั้ง 10 รายการ
                            </li>
                            <li>
                                <strong>picking_activities</strong> 
                                <span class="badge badge-secondary">ลบทั้งหมด</span>
                                - ประวัติการสแกน 238+ รายการ
                            </li>
                            <li>
                                <strong>picking_plans</strong> 
                                <span class="badge badge-secondary">ลบทั้งหมด</span>
                                - แผนการหยิบ 61+ รายการ
                            </li>
                            <li>
                                <strong>position_mappings</strong> 
                                <span class="badge badge-secondary">ลบทั้งหมด</span>
                                - Mapping 2 รายการ
                            </li>
                            <li>
                                <strong>uploads</strong> 
                                <span class="badge badge-secondary">ลบทั้งหมด</span>
                                - ประวัติการอัปโหลด 6 ไฟล์
                            </li>
                        </ul>
                    </div>

                    <div class="form-group">
                        <label for="confirmDeleteText">
                            <strong>กรุณาพิมพ์ "ยืนยันการลบ" เพื่อยืนยันการดำเนินการนี้:</strong>
                        </label>
                        <input type="text" class="form-control form-control-lg" id="confirmDeleteText" placeholder="พิมพ์: ยืนยันการลบ">
                        <small class="form-text text-danger mt-2">
                            <i class="fas fa-asterisk"></i> ข้อความต้องตรงกันทุกตัวอักษร
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="post" id="truncateForm">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                            <i class="fas fa-times"></i> ยกเลิก
                        </button>
                        <button type="submit" name="truncate_all_data" class="btn btn-danger btn-lg" id="confirmTruncateBtn" disabled>
                            <i class="fas fa-trash-alt"></i> ยืนยันการลบข้อมูลทั้งหมด
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <!-- === สิ้นสุด Modal ยืนยันการลบข้อมูล === -->

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
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

        // จัดการการยืนยันลบไฟล์
        $(document).ready(function() {
            $('.delete-file-btn').click(function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                
                $('#uploadId').val(id);
                $('#fileName').text(name);
            });

            // === เพิ่มโค้ด JavaScript สำหรับควบคุมปุ่มยืนยันการลบข้อมูลทั้งหมด ===
            const requiredText = 'ยืนยันการลบ';
            const confirmInput = $('#confirmDeleteText');
            const submitBtn = $('#confirmTruncateBtn');

            // ตรวจสอบข้อความที่พิมพ์
            confirmInput.on('input', function() {
                const inputValue = $(this).val();
                
                // ตรวจสอบว่าข้อความตรงกันหรือไม่
                if (inputValue === requiredText) {
                    submitBtn.prop('disabled', false);
                    submitBtn.removeClass('btn-outline-danger').addClass('btn-danger');
                } else {
                    submitBtn.prop('disabled', true);
                    submitBtn.removeClass('btn-danger').addClass('btn-outline-danger');
                }
            });

            // จัดการการส่ง form
            $('#truncateForm').on('submit', function(e) {
                // ตรวจสอบอีกครั้งว่าข้อความถูกต้องหรือไม่
                if (confirmInput.val() !== requiredText) {
                    e.preventDefault();
                    alert('ข้อความยืนยันไม่ถูกต้อง!');
                    return false;
                }

                // แสดง confirmation final
                if (!confirm('คุณแน่ใจหรือไม่ที่จะลบข้อมูลทั้งหมด?\nการดำเนินการนี้ไม่สามารถย้อนกลับได้!')) {
                    e.preventDefault();
                    return false;
                }
            });

            // เมื่อปิด Modal ให้ล้างข้อมูล
            $('#truncateAllModal').on('hidden.bs.modal', function() {
                confirmInput.val('');
                submitBtn.prop('disabled', true);
            });
        });
        // === สิ้นสุดโค้ด JavaScript === 
    </script>
</body>
</html>
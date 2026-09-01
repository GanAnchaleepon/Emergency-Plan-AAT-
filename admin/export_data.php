<?php
// หน้าส่งออกข้อมูลในรูปแบบ CSV (สำหรับแอดมิน)
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// รับค่า ID และรูปแบบจาก URL
$upload_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$group_filter = isset($_GET['group']) ? $_GET['group'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// ตรวจสอบว่ามีค่า ID
if ($upload_id <= 0) {
    echo "กรุณาระบุรหัสการอัปโหลด";
    exit;
}

// ตรวจสอบว่ามีข้อมูลการอัปโหลดตาม ID ที่ระบุ
$sql = "SELECT id, filename FROM uploads WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $upload_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "ไม่พบข้อมูลการอัปโหลดตามรหัสที่ระบุ";
    exit;
}

$upload = $result->fetch_assoc();
$filename = pathinfo($upload['filename'], PATHINFO_FILENAME);
$export_filename = $filename . '_export_' . date('Ymd_His');

// สร้าง SQL สำหรับดึงข้อมูล
$sql_conditions = ["upload_id = ?"];
$sql_params = [$upload_id];
$param_types = "i";

if (!empty($group_filter)) {
    $sql_conditions[] = "group_name = ?";
    $sql_params[] = $group_filter;
    $param_types .= "s";
    $export_filename .= '_' . str_replace(' ', '_', $group_filter);
}

if (!empty($status_filter)) {
    $sql_conditions[] = "status = ?";
    $sql_params[] = $status_filter;
    $param_types .= "s";
    $export_filename .= '_' . $status_filter;
}

$sql_where = implode(" AND ", $sql_conditions);

// ดึงข้อมูล
$sql = "SELECT 
            group_name, 
            position, 
            part_number, 
            part_name, 
            quantity, 
            rotation, 
            status, 
            location, 
            supplier, 
            created_at
        FROM picking_plans 
        WHERE $sql_where 
        ORDER BY group_name, position";

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$sql_params);
$stmt->execute();
$result = $stmt->get_result();

// ถ้าเป็นรูปแบบ CSV
if ($format === 'csv') {
    // ตั้งค่า header
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export_filename . '.csv"');
    
    // เปิด output stream
    $output = fopen('php://output', 'w');
    
    // ใส่ BOM สำหรับ Excel ที่รองรับ UTF-8
    fputs($output, "\xEF\xBB\xBF");
    
    // ใส่ header columns
    fputcsv($output, [
        'กลุ่ม',
        'ตำแหน่ง',
        'Part Number',
        'Part Name',
        'จำนวน',
        'Rotation',
        'สถานะ',
        'Location',
        'Supplier',
        'วันที่สร้าง'
    ]);
    
    // ใส่ข้อมูล
    while ($row = $result->fetch_assoc()) {
        // แปลงสถานะเป็นภาษาไทย
        switch ($row['status']) {
            case 'pending':
                $status = 'รอดำเนินการ';
                break;
            case 'inprogress':
                $status = 'กำลังดำเนินการ';
                break;
            case 'completed':
                $status = 'เสร็จสมบูรณ์';
                break;
            default:
                $status = $row['status'];
        }
        
        fputcsv($output, [
            $row['group_name'],
            $row['position'],
            $row['part_number'],
            $row['part_name'],
            $row['quantity'],
            $row['rotation'],
            $status,
            $row['location'],
            $row['supplier'],
            $row['created_at']
        ]);
    }
    
    // บันทึกล็อกการส่งออก
    $admin_id = $_SESSION['user_id'];
    $action_detail = "ส่งออกข้อมูลในรูปแบบ CSV (Upload ID: $upload_id)";
    if (!empty($group_filter)) {
        $action_detail .= " กลุ่ม: $group_filter";
    }
    if (!empty($status_filter)) {
        $action_detail .= " สถานะ: $status_filter";
    }
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'export_data', ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("iss", $admin_id, $action_detail, $ip);
    $log_stmt->execute();
} 
// ถ้าเป็นรูปแบบ Excel (XLSX)
elseif ($format === 'excel') {
    // ถ้าต้องการรองรับ Excel ให้เพิ่ม library เช่น PhpSpreadsheet ที่นี่
    echo "รูปแบบ Excel ยังไม่รองรับในขณะนี้ กรุณาใช้รูปแบบ CSV แทน";
    exit;
} 
// ถ้าเป็นรูปแบบ JSON
elseif ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export_filename . '.json"');
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // บันทึกล็อกการส่งออก
    $admin_id = $_SESSION['user_id'];
    $action_detail = "ส่งออกข้อมูลในรูปแบบ JSON (Upload ID: $upload_id)";
    if (!empty($group_filter)) {
        $action_detail .= " กลุ่ม: $group_filter";
    }
    if (!empty($status_filter)) {
        $action_detail .= " สถานะ: $status_filter";
    }
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'export_data', ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("iss", $admin_id, $action_detail, $ip);
    $log_stmt->execute();
} 
// รูปแบบไม่รองรับ
else {
    echo "รูปแบบไม่รองรับ กรุณาใช้ format=csv หรือ format=json";
    exit;
}

$conn->close();
?>

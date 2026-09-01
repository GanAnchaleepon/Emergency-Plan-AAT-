<?php
// หน้าเก็บถาวรการอัปโหลดเฉพาะรายการ (สำหรับแอดมิน)
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// ตรวจสอบว่ามีค่า ID ถูกส่งมา
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['status_message'] = "ไม่พบรหัสการอัปโหลด";
    $_SESSION['status_type'] = "danger";
    header("Location: history.php");
    exit;
}

$upload_id = intval($_GET['id']);

try {
    // เริ่ม Transaction
    $conn->begin_transaction();
    
    // ตรวจสอบว่ามีข้อมูลอัปโหลดตาม ID ที่ระบุหรือไม่
    $check_sql = "SELECT id, filename, status FROM uploads WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $upload_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("ไม่พบข้อมูลการอัปโหลดตามรหัสที่ระบุ");
    }
    
    $upload = $result->fetch_assoc();
    
    // ตรวจสอบว่าเป็นสถานะ active หรือไม่
    if ($upload['status'] !== 'active') {
        throw new Exception("รายการนี้ถูกเก็บถาวรไปแล้ว");
    }
    
    // อัปเดตสถานะเป็น archived
    $update_sql = "UPDATE uploads SET status = 'archived' WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("i", $upload_id);
    $update_stmt->execute();
    
    if ($update_stmt->affected_rows === 0) {
        throw new Exception("ไม่สามารถอัปเดตสถานะได้");
    }
    
    // บันทึกล็อกการทำงาน
    $admin_id = $_SESSION['user_id'];
    $action_detail = "เก็บถาวรการอัปโหลด ID: $upload_id (ชื่อไฟล์: {$upload['filename']})";
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'archive_upload', ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("iss", $admin_id, $action_detail, $ip);
    $log_stmt->execute();
    
    // Commit Transaction
    $conn->commit();
    
    $_SESSION['status_message'] = "เก็บถาวรรายการอัปโหลดเรียบร้อยแล้ว";
    $_SESSION['status_type'] = "success";
    
} catch (Exception $e) {
    // Rollback หากเกิดข้อผิดพลาด
    $conn->rollback();
    
    $_SESSION['status_message'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
    $_SESSION['status_type'] = "danger";
}

// กลับไปหน้าประวัติการอัปโหลด
header("Location: history.php");
exit;
?>

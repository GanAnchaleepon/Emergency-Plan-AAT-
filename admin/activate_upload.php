<?php
// หน้าเปิดใช้งานการอัปโหลดที่เก็บถาวรไว้ (สำหรับแอดมิน)
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
    
    // ตรวจสอบว่าเป็นสถานะ archived หรือไม่
    if ($upload['status'] !== 'archived') {
        throw new Exception("รายการนี้เปิดใช้งานอยู่แล้ว");
    }
    
    // ตรวจสอบว่ามีรายการที่เปิดใช้งานอยู่หรือไม่
    $active_check_sql = "SELECT COUNT(*) as active_count FROM uploads WHERE status = 'active'";
    $active_result = $conn->query($active_check_sql);
    $active_count = $active_result->fetch_assoc()['active_count'];
    
    if ($active_count > 0) {
        // มีรายการที่เปิดใช้งานอยู่แล้ว ให้ถามยืนยัน
        if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'true') {
            $_SESSION['status_message'] = "มีการอัปโหลดที่เปิดใช้งานอยู่แล้ว คุณต้องการเปิดใช้งานรายการนี้เพิ่มหรือไม่?";
            $_SESSION['status_type'] = "warning";
            $_SESSION['confirm_action'] = "activate_upload.php?id={$upload_id}&confirm=true";
            header("Location: history.php");
            exit;
        }
    }
    
    // อัปเดตสถานะเป็น active
    $update_sql = "UPDATE uploads SET status = 'active' WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("i", $upload_id);
    $update_stmt->execute();
    
    if ($update_stmt->affected_rows === 0) {
        throw new Exception("ไม่สามารถอัปเดตสถานะได้");
    }
    
    // บันทึกล็อกการทำงาน
    $admin_id = $_SESSION['user_id'];
    $action_detail = "เปิดใช้งานการอัปโหลด ID: $upload_id (ชื่อไฟล์: {$upload['filename']})";
    $ip = $_SERVER['REMOTE_ADDR'];
    
    $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'activate_upload', ?, ?)";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->bind_param("iss", $admin_id, $action_detail, $ip);
    $log_stmt->execute();
    
    // Commit Transaction
    $conn->commit();
    
    $_SESSION['status_message'] = "เปิดใช้งานรายการอัปโหลดเรียบร้อยแล้ว";
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

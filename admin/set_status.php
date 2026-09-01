<?php
// หน้าสำหรับเปลี่ยนสถานะรายการในระบบ
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอินเป็นแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// กำหนดค่าเริ่มต้น
$message = '';
$messageType = '';
$redirect_url = 'index.php';

// ตรวจสอบว่ามีข้อมูลที่ส่งมาถูกต้อง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type']) && isset($_POST['id']) && isset($_POST['status'])) {
    // รับค่าจากฟอร์ม
    $type = $_POST['type'];
    $id = intval($_POST['id']);
    $status = $_POST['status'];
    $redirect_url = isset($_POST['redirect']) ? $_POST['redirect'] : 'index.php';
    
    // ตรวจสอบความถูกต้องของข้อมูล
    $validRequest = false;
    $sql = '';
    $params = [];
    $param_types = '';
    $action_details = '';
    
    if ($type === 'plan' && in_array($status, ['pending', 'inprogress', 'completed'])) {
        // อัปเดตสถานะแผนการหยิบ
        $sql = "UPDATE picking_plans SET status = ? WHERE id = ?";
        $params = [$status, $id];
        $param_types = 'si';
        $validRequest = true;
        $action_details = "เปลี่ยนสถานะแผนการหยิบ ID: $id เป็น: $status";
    } elseif ($type === 'upload' && in_array($status, ['active', 'archived'])) {
        // อัปเดตสถานะการอัปโหลด
        $sql = "UPDATE uploads SET status = ? WHERE id = ?";
        $params = [$status, $id];
        $param_types = 'si';
        $validRequest = true;
        $action_details = "เปลี่ยนสถานะการอัปโหลด ID: $id เป็น: $status";
    }
    
    // ดำเนินการอัปเดตถ้าข้อมูลถูกต้อง
    if ($validRequest) {
        try {
            // เริ่ม Transaction
            $conn->begin_transaction();
            
            // อัปเดตสถานะ
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                // บันทึกล็อกการเปลี่ยนแปลง
                $admin_id = $_SESSION['user_id'];
                $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'update_status', ?, ?)";
                $log_stmt = $conn->prepare($log_sql);
                $ip = $_SERVER['REMOTE_ADDR'];
                $log_stmt->bind_param("iss", $admin_id, $action_details, $ip);
                $log_stmt->execute();
                
                // Commit Transaction
                $conn->commit();
                
                $message = "อัปเดตสถานะสำเร็จ";
                $messageType = "success";
            } else {
                throw new Exception("ไม่มีการเปลี่ยนแปลงเกิดขึ้น");
            }
        } catch (Exception $e) {
            // Rollback หากเกิดข้อผิดพลาด
            $conn->rollback();
            
            $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
            $messageType = "danger";
        }
    } else {
        $message = "คำขอไม่ถูกต้อง";
        $messageType = "danger";
    }
}

// บันทึกข้อความแจ้งเตือนไว้ใน session
if (!empty($message)) {
    $_SESSION['status_message'] = $message;
    $_SESSION['status_type'] = $messageType;
}

// Redirect กลับไปหน้าเดิม
header("Location: $redirect_url");
exit;
?>

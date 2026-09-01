<?php
/**
 * หน้าสำหรับออกจากระบบ (Logout) และทำลาย session
 */
session_start();

// บันทึก log การออกจากระบบ
if (isset($_SESSION['user_id'])) {
    // เชื่อมต่อฐานข้อมูล
    $host = "127.0.0.1:3306";
    $username = "u892799997_emergency_plan";
    $password = "Gan05061997@";
    $dbname = "u892799997_emergency_plan";
    
    try {
        $conn = new mysqli($host, $username, $password, $dbname);
        if (!$conn->connect_error) {
            // บันทึก log การออกจากระบบ
            $user_id = $_SESSION['user_id'];
            $ip = $_SERVER['REMOTE_ADDR'];
            
            $sql = "INSERT INTO logs (user_id, action, details, ip_address)
                    VALUES (?, 'logout', 'ออกจากระบบ', ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $user_id, $ip);
            $stmt->execute();
            
            $conn->close();
        }
    } catch (Exception $e) {
        // ไม่ต้องทำอะไรถ้าบันทึก log ไม่สำเร็จ
    }
}

// ลบข้อมูล session ทั้งหมด
$_SESSION = array();

// ลบ session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// ทำลาย session
session_destroy();

// Redirect ไปยังหน้าล็อกอิน พร้อมข้อความแจ้งว่าออกจากระบบแล้ว
header("Location: login.php?logout=success");
exit;
?>

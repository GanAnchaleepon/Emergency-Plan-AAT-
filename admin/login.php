<?php
// หน้าล็อกอินสำหรับแอดมิน
session_start();
require_once '../config/database.php';

// ถ้าล็อกอินแล้วให้ redirect ไปหน้าหลัก
if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header("Location: index.php");
    exit;
}

$error = '';

// ประมวลผลการล็อกอิน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    // ตรวจสอบว่ามีข้อมูลครบหรือไม่
    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        // ดึงข้อมูลผู้ใช้จากฐานข้อมูล
        $sql = "SELECT id, username, password, name, role FROM users WHERE username = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // ตรวจสอบรหัสผ่าน
                if (password_verify($password, $user['password'])) {
                    // สร้าง session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    
                    // บันทึกประวัติการล็อกอิน
                    $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'login', 'เข้าสู่ระบบสำเร็จ', ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_stmt->bind_param("is", $user['id'], $ip);
                    $log_stmt->execute();
                    
                    // ไปยังหน้าหลักหลังล็อกอิน
                    header("Location: index.php");
                    exit;
                } else {
                    $error = 'รหัสผ่านไม่ถูกต้อง';
                    
                    // บันทึกประวัติการล็อกอินล้มเหลว
                    $log_sql = "INSERT INTO logs (action, details, ip_address) VALUES ('login_failed', ?, ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $details = "ล็อกอินล้มเหลว: รหัสผ่านผิด (username: $username)";
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_stmt->bind_param("ss", $details, $ip);
                    $log_stmt->execute();
                }
            } else {
                $error = 'ไม่พบผู้ใช้นี้ในระบบ';
                
                // บันทึกประวัติการล็อกอินล้มเหลว
                $log_sql = "INSERT INTO logs (action, details, ip_address) VALUES ('login_failed', ?, ?)";
                $log_stmt = $conn->prepare($log_sql);
                $details = "ล็อกอินล้มเหลว: ไม่พบผู้ใช้ (username: $username)";
                $ip = $_SERVER['REMOTE_ADDR'];
                $log_stmt->bind_param("ss", $details, $ip);
                $log_stmt->execute();
            }
            
            $stmt->close();
        } else {
            $error = 'เกิดข้อผิดพลาดในการเชื่อมต่อกับฐานข้อมูล';
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - Emergency Picking System</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a4f72; /* สีน้ำเงินเข้ม สื่อถึงความเป็นทางการ */
            --secondary-color: #2980b9; /* สีน้ำเงิน สำหรับการเน้นลำดับความสำคัญรอง */
            --accent-color: #f39c12; /* สีส้ม สำหรับการเน้นและแจ้งเตือน */
            --success-color: #27ae60; /* สีเขียว สำหรับความสำเร็จ */
            --light-color: #ecf0f1; /* สีเทาอ่อน สำหรับพื้นหลัง */
            --dark-color: #2c3e50; /* สีเทาเข้ม สำหรับข้อความ */
            --border-radius: 0.25rem;
            --box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #1a4f72 0%, #2980b9 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        
        .login-container {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            display: flex;
        }
        
        .login-image {
            background-image: url('../assets/warehouse.jpg');
            background-size: cover;
            background-position: center;
            flex: 1;
            position: relative;
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Fallback if image is missing */
        .login-image-fallback {
            background-color: var(--primary-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            padding: 2rem;
        }
        
        .login-image-fallback i {
            font-size: 5rem;
            margin-bottom: 1rem;
        }
        
        .login-image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(26, 79, 114, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .login-image-overlay h2 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .login-image-overlay p {
            font-size: 1.1rem;
            max-width: 80%;
            line-height: 1.6;
        }
        
        .login-form {
            flex: 1;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .login-logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-logo i {
            font-size: 3rem;
            color: var(--primary-color);
        }
        
        .login-title {
            text-align: center;
            margin-bottom: 2rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: block;
            color: var(--dark-color);
        }
        
        .form-control {
            height: 50px;
            border-radius: var(--border-radius);
            border: 1px solid #ced4da;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(26, 79, 114, 0.25);
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group-append {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            padding-right: 15px;
        }
        
        .btn-eye {
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            transition: color 0.3s;
        }
        
        .btn-eye:hover {
            color: var(--dark-color);
        }
        
        .btn-login {
            height: 50px;
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            background-color: #16405d;
            border-color: #16405d;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 2rem;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .login-footer a {
            color: var(--primary-color);
            font-weight: 500;
            text-decoration: none;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
            }
            
            .login-image {
                min-height: 200px;
            }
            
            .login-form {
                padding: 2rem;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-form {
            animation: fadeIn 0.5s ease-out;
        }
        
        /* Toast Message */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 350px;
        }
        
        .toast {
            background-color: rgba(44, 62, 80, 0.95);
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            margin-bottom: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
        }
        
        .toast-error {
            background-color: rgba(192, 57, 43, 0.95);
        }
        
        .toast-icon {
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .toast-message {
            flex: 1;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- ส่วนแสดงภาพ -->
        <div class="login-image">
            <div class="login-image-fallback" id="imageFallback">
                <i class="fas fa-warehouse"></i>
                <h2>Emergency Picking System</h2>
                <p>ระบบช่วยหยิบงานฉุกเฉิน ลดข้อผิดพลาด เพิ่มความแม่นยำ</p>
            </div>
            <div class="login-image-overlay">
                <h2>Emergency Picking System</h2>
                <p>ระบบช่วยหยิบงานฉุกเฉิน ลดข้อผิดพลาด เพิ่มความแม่นยำ</p>
            </div>
        </div>
        
        <!-- ฟอร์มล็อกอิน -->
        <div class="login-form">
            <div class="login-logo">
                <i class="fas fa-warehouse"></i>
            </div>
            <h3 class="login-title">เข้าสู่ระบบ (TTV AAT-Wiring)</h3>

            <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <div class="form-group">
                    <label for="username">ชื่อผู้ใช้</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="username" name="username" placeholder="กรอกชื่อผู้ใช้" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                        <div class="input-group-append">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">รหัสผ่าน</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" placeholder="กรอกรหัสผ่าน" required>
                        <div class="input-group-append">
                            <button type="button" class="btn-eye" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-login">
                    <i class="fas fa-sign-in-alt mr-2"></i>เข้าสู่ระบบ (Operation TTV AAT-Wiring)
                </button>
            </form>
            
            <div class="login-footer">
                <p>สำหรับผู้ดูแลระบบเท่านั้น</p>
                <p>หากไม่มีบัญชีผู้ใช้ โปรดติดต่อผู้ดูแลระบบ</p>
                <p><a href="../index.php">กลับไปหน้าหลัก</a></p>
            </div>
        </div>
    </div>
    
    <script>
        // ฟังก์ชันสลับการแสดงรหัสผ่าน
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // ฟังก์ชันตรวจสอบการโหลดรูปภาพ
        document.addEventListener('DOMContentLoaded', function() {
            const loginImage = document.querySelector('.login-image');
            const imageFallback = document.getElementById('imageFallback');
            
            if (loginImage) {
                // ตรวจสอบว่ารูปภาพโหลดสำเร็จหรือไม่
                const img = new Image();
                img.src = '../assets/warehouse.jpg';
                
                img.onload = function() {
                    // รูปภาพโหลดสำเร็จ
                    if (imageFallback) {
                        imageFallback.style.display = 'none';
                    }
                };
                
                img.onerror = function() {
                    // รูปภาพโหลดไม่สำเร็จ
                    if (imageFallback) {
                        imageFallback.style.display = 'flex';
                    }
                };
            }
        });
    </script>
</body>
</html>

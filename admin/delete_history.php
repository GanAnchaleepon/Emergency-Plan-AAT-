<?php
// หน้าลบประวัติการอัปโหลดทั้งหมด (สำหรับแอดมิน)
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = '';
$messageType = '';
$confirmCode = 'DELETE_ALL_HISTORY';

// สถิติข้อมูลในระบบ
$stats = [
    'uploads' => 0,
    'picking_plans' => 0,
    'activities' => 0
];

// ดึงข้อมูลสถิติ
$sql = "SELECT 
            (SELECT COUNT(*) FROM uploads) AS uploads_count,
            (SELECT COUNT(*) FROM picking_plans) AS plans_count,
            (SELECT COUNT(*) FROM picking_activities) AS activities_count";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $stats['uploads'] = $row['uploads_count'];
    $stats['picking_plans'] = $row['plans_count'];
    $stats['activities'] = $row['activities_count'];
}

// ตรวจสอบการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmation = isset($_POST['confirmation']) ? $_POST['confirmation'] : '';
    
    // ตรวจสอบรหัสยืนยัน
    if ($confirmation !== $confirmCode) {
        $message = 'รหัสยืนยันไม่ถูกต้อง กรุณาลองใหม่';
        $messageType = 'danger';
    } else {
        try {
            // เริ่ม Transaction
            $conn->begin_transaction();
            
            // บันทึกข้อมูลก่อนลบเพื่อใช้ในการบันทึกล็อก
            $totalUploads = $stats['uploads'];
            $totalPlans = $stats['picking_plans'];
            $totalActivities = $stats['activities'];
            
            // ล้างข้อมูลตามลำดับ - ตารางที่มี Foreign Key ก่อน
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $conn->query("TRUNCATE TABLE picking_activities");
            $conn->query("TRUNCATE TABLE picking_plans");
            $conn->query("TRUNCATE TABLE uploads");
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
            // บันทึกล็อกการทำงาน
            $admin_id = $_SESSION['user_id'];
            $action_detail = "ลบประวัติการอัปโหลดทั้งหมด: {$totalUploads} รายการ, แผนการหยิบ: {$totalPlans} รายการ, กิจกรรม: {$totalActivities} รายการ";
            $ip = $_SERVER['REMOTE_ADDR'];
            
            $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'delete_history', ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bind_param("iss", $admin_id, $action_detail, $ip);
            $log_stmt->execute();
            
            // Commit Transaction
            $conn->commit();
            
            $message = "ลบประวัติการอัปโหลดทั้งหมดเรียบร้อยแล้ว";
            $messageType = 'success';
            
            // รีเซ็ตข้อมูลสถิติ
            $stats['uploads'] = 0;
            $stats['picking_plans'] = 0;
            $stats['activities'] = 0;
            
        } catch (Exception $e) {
            // Rollback หากเกิดข้อผิดพลาด
            $conn->rollback();
            
            $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลบประวัติการอัปโหลด - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .navbar {
            background-color: var(--primary-color);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            padding: 0.5rem 1rem;
            z-index: 1000;
        }

        .navbar-brand {
            color: white;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .navbar-brand i {
            margin-right: 0.5rem;
            font-size: 1.5rem;
        }

        .navbar-nav .nav-link {
            color: rgba(255, 255, 255, 0.85);
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .navbar-nav .nav-link:hover,
        .navbar-nav .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .navbar-nav .nav-link i {
            margin-right: 0.5rem;
        }

        .main-content {
            padding: 2rem;
            max-width: 1000px;
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

        .card-header.danger-header {
            background-color: var(--danger-color);
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

        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #a93226;
            border-color: #a93226;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .stats-card {
            border-left: 4px solid var(--primary-color);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: white;
            box-shadow: var(--box-shadow);
        }

        .stats-card .value {
            font-size: 2rem;
            font-weight: 700;
        }

        .stats-card .label {
            color: var(--dark-color);
            font-weight: 500;
        }

        .confirmation-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: var(--border-radius);
        }

        .confirmation-code {
            font-family: monospace;
            font-size: 1.2rem;
            background: #fff3cd;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
        }

        .danger-zone {
            border: 2px dashed var(--danger-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            position: relative;
            margin-top: 2rem;
        }

        .danger-zone::before {
            content: "โซนอันตราย";
            position: absolute;
            top: -0.75rem;
            left: 1rem;
            background: white;
            padding: 0 0.5rem;
            color: var(--danger-color);
            font-weight: 600;
            font-size: 0.85rem;
        }

        .footer {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 1rem 0;
            margin-top: 2rem;
        }

        .footer p {
            margin-bottom: 0;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
            
            .stats-card .value {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#"><i class="fas fa-warehouse"></i> Emergency Picking</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php"><i class="fas fa-home"></i> หน้าหลัก</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="upload.php"><i class="fas fa-upload"></i> อัปโหลดแผนงาน</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="history.php"><i class="fas fa-history"></i> ประวัติการอัปโหลด</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php"><i class="fas fa-chart-bar"></i> รายงาน</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <h1 class="section-title">
            <i class="fas fa-trash-alt"></i> ลบประวัติการอัปโหลดทั้งหมด
        </h1>
        
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header danger-header">
                <i class="fas fa-exclamation-triangle"></i> คำเตือน - การดำเนินการนี้ไม่สามารถย้อนกลับได้
            </div>
            <div class="card-body">
                <div class="alert alert-danger">
                    <h4 class="alert-heading"><i class="fas fa-exclamation-circle"></i> โปรดอ่านก่อนดำเนินการ!</h4>
                    <p>การลบประวัติการอัปโหลดจะลบข้อมูลต่อไปนี้ทั้งหมด:</p>
                    <ul>
                        <li>ประวัติการอัปโหลดไฟล์ทั้งหมด</li>
                        <li>แผนการหยิบงานทั้งหมด</li>
                        <li>ประวัติกิจกรรมการหยิบงานทั้งหมด</li>
                    </ul>
                    <p class="mb-0">การดำเนินการนี้<strong>ไม่สามารถย้อนกลับได้</strong> และจะส่งผลให้ข้อมูลทั้งหมดถูกลบออกจากระบบอย่างถาวร</p>
                </div>
                
                <!-- Stats Overview -->
                <h5 class="mt-4">ข้อมูลที่จะถูกลบ:</h5>
                <div class="row">
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="value"><?php echo number_format($stats['uploads']); ?></div>
                            <div class="label">การอัปโหลด</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="value"><?php echo number_format($stats['picking_plans']); ?></div>
                            <div class="label">แผนการหยิบงาน</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="value"><?php echo number_format($stats['activities']); ?></div>
                            <div class="label">กิจกรรมการหยิบ</div>
                        </div>
                    </div>
                </div>
                
                <?php if ($stats['uploads'] > 0): ?>
                <div class="danger-zone">
                    <h5>ยืนยันการลบข้อมูล</h5>
                    <p>หากคุณแน่ใจว่าต้องการลบข้อมูลทั้งหมด โปรดพิมพ์ <strong><?php echo $confirmCode; ?></strong> ในช่องด้านล่างแล้วกดปุ่ม "ลบข้อมูลทั้งหมด"</p>
                    
                    <div class="confirmation-box">
                        <p><strong>รหัสยืนยัน:</strong> <span class="confirmation-code"><?php echo $confirmCode; ?></span></p>
                    </div>
                    
                    <form method="post" onsubmit="return confirmDeletion()">
                        <div class="form-group">
                            <label for="confirmation">พิมพ์รหัสยืนยัน:</label>
                            <input type="text" class="form-control" id="confirmation" name="confirmation" required placeholder="พิมพ์รหัสยืนยันที่นี่...">
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-danger btn-lg">
                                <i class="fas fa-trash-alt"></i> ลบข้อมูลทั้งหมด
                            </button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="alert alert-info mt-4">
                    <i class="fas fa-info-circle"></i> ไม่มีข้อมูลในระบบที่ต้องลบ
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <a href="history.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> กลับไปหน้าประวัติการอัปโหลด
            </a>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Emergency Picking System - By Gan Anchaleepon</p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        function confirmDeletion() {
            const confirmInput = document.getElementById('confirmation').value.trim();
            const expectedCode = '<?php echo $confirmCode; ?>';
            
            if (confirmInput !== expectedCode) {
                alert('รหัสยืนยันไม่ถูกต้อง กรุณาลองใหม่');
                return false;
            }
            
            return confirm('คุณแน่ใจหรือไม่ที่จะลบข้อมูลทั้งหมด?\n\nการดำเนินการนี้ไม่สามารถย้อนกลับได้!');
        }
        
        // คัดลอกรหัสยืนยันด้วยการคลิก
        document.addEventListener('DOMContentLoaded', function() {
            const confirmationCode = document.querySelector('.confirmation-code');
            
            if (confirmationCode) {
                confirmationCode.addEventListener('click', function() {
                    const range = document.createRange();
                    range.selectNode(confirmationCode);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                    document.execCommand('copy');
                    window.getSelection().removeAllRanges();
                    
                    confirmationCode.classList.add('bg-success');
                    confirmationCode.classList.add('text-white');
                    
                    setTimeout(function() {
                        confirmationCode.classList.remove('bg-success');
                        confirmationCode.classList.remove('text-white');
                    }, 500);
                });
            }
        });
    </script>
</body>
</html>

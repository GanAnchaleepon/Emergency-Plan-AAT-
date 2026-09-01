<?php
// หน้าเก็บถาวรการอัปโหลดทั้งหมด (สำหรับแอดมิน)
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = '';
$messageType = '';
$confirmCode = 'ARCHIVE_ALL';
$stats = [
    'total' => 0,
    'archived' => 0,
    'active' => 0
];

// ดึงสถิติข้อมูลในระบบ
$sql = "SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived_count,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count
        FROM uploads";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $stats['total'] = $row['total_count'];
    $stats['archived'] = $row['archived_count'];
    $stats['active'] = $row['active_count'];
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
            
            // จำนวนรายการที่อัปเดต
            $activeCount = $stats['active'];
            
            // อัปเดตสถานะทุกรายการเป็น archived
            $updateSql = "UPDATE uploads SET status = 'archived' WHERE status = 'active'";
            $conn->query($updateSql);
            
            $affectedRows = $conn->affected_rows;
            
            // บันทึกล็อกการทำงาน
            $admin_id = $_SESSION['user_id'];
            $action_detail = "เก็บถาวรการอัปโหลดทั้งหมด: $affectedRows รายการ";
            $ip = $_SERVER['REMOTE_ADDR'];
            
            $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'archive_all', ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bind_param("iss", $admin_id, $action_detail, $ip);
            $log_stmt->execute();
            
            // Commit Transaction
            $conn->commit();
            
            if ($affectedRows > 0) {
                $message = "เก็บถาวรการอัปโหลดทั้งหมดเรียบร้อยแล้ว จำนวน $affectedRows รายการ";
                $messageType = 'success';
                
                // อัปเดตสถิติ
                $stats['archived'] += $affectedRows;
                $stats['active'] = 0;
            } else {
                $message = "ไม่มีรายการที่ต้องเก็บถาวร (ทุกรายการถูกเก็บถาวรแล้ว)";
                $messageType = 'info';
            }
            
        } catch (Exception $e) {
            // Rollback หากเกิดข้อผิดพลาด
            $conn->rollback();
            
            $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            $messageType = 'danger';
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
    <title>เก็บถาวรทั้งหมด - ระบบ Emergency Picking</title>
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
            max-width: 800px;
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

        .card-header.warning-header {
            background-color: var(--warning-color);
        }

        .card-header i {
            margin-right: 0.5rem;
            font-size: 1.2rem;
        }

        .card-body {
            padding: 1.5rem;
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

        .stats-card.active {
            border-left-color: var(--success-color);
        }

        .stats-card.archived {
            border-left-color: var(--warning-color);
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
            <i class="fas fa-archive"></i> เก็บถาวรการอัปโหลดทั้งหมด
        </h1>
        
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Stats Overview -->
        <div class="row">
            <div class="col-md-4">
                <div class="stats-card">
                    <div class="value"><?php echo number_format($stats['total']); ?></div>
                    <div class="label">การอัปโหลดทั้งหมด</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card active">
                    <div class="value"><?php echo number_format($stats['active']); ?></div>
                    <div class="label">กำลังใช้งาน</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stats-card archived">
                    <div class="value"><?php echo number_format($stats['archived']); ?></div>
                    <div class="label">เก็บถาวรแล้ว</div>
                </div>
            </div>
        </div>
        
        <!-- Archive Form -->
        <div class="card">
            <div class="card-header warning-header">
                <i class="fas fa-exclamation-triangle"></i> คำเตือน - โปรดอ่านก่อนดำเนินการ
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <h5>การเก็บถาวรจะทำอะไร?</h5>
                    <p>การเก็บถาวรการอัปโหลดทั้งหมดจะเปลี่ยนสถานะของรายการอัปโหลดทั้งหมดที่เป็น "active" ให้กลายเป็น "archived" ทั้งหมด</p>
                    <ul>
                        <li>รายการที่เก็บถาวรแล้วจะไม่แสดงในหน้าเลือกกลุ่มงานของพนักงานหยิบงาน</li>
                        <li><strong>ข้อมูลจะยังคงอยู่ในฐานข้อมูล</strong> แต่จะถูกปิดการใช้งาน</li>
                        <li>คุณสามารถกลับไปเปิดใช้งานแต่ละรายการได้จากหน้าประวัติการอัปโหลด</li>
                    </ul>
                </div>
                
                <?php if ($stats['active'] > 0): ?>
                <div class="confirmation-box">
                    <p><strong>รหัสยืนยัน:</strong> <span class="confirmation-code"><?php echo $confirmCode; ?></span></p>
                    <p>จำนวนรายการที่จะเก็บถาวร: <strong><?php echo number_format($stats['active']); ?> รายการ</strong></p>
                </div>
                
                <form method="post" onsubmit="return confirmArchive()">
                    <div class="form-group">
                        <label for="confirmation">พิมพ์รหัสยืนยัน:</label>
                        <input type="text" class="form-control" id="confirmation" name="confirmation" required placeholder="พิมพ์รหัสยืนยันที่นี่...">
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-warning btn-lg">
                            <i class="fas fa-archive"></i> เก็บถาวรทั้งหมด
                        </button>
                    </div>
                </form>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> ไม่มีรายการที่ต้องเก็บถาวร (ทุกรายการถูกเก็บถาวรแล้ว)
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <a href="history.php" class="btn btn-secondary btn-lg">
                <i class="fas fa-arrow-left"></i> กลับไปหน้าประวัติการอัปโหลด
            </a>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Emergency Picking System - Warehouse Management</p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        function confirmArchive() {
            const confirmInput = document.getElementById('confirmation').value.trim();
            const expectedCode = '<?php echo $confirmCode; ?>';
            
            if (confirmInput !== expectedCode) {
                alert('รหัสยืนยันไม่ถูกต้อง กรุณาลองใหม่');
                return false;
            }
            
            return confirm('คุณแน่ใจหรือไม่ที่จะเก็บถาวรการอัปโหลดทั้งหมด?');
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

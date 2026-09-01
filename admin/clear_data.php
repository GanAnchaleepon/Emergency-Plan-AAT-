<?php
// หน้าสำหรับล้างข้อมูลในระบบ Emergency Picking (สำหรับแอดมินเท่านั้น)
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอินเป็นแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = '';
$messageType = '';
$confirmCode = 'CLEAR' . date('Ymd');

// ดึงข้อมูลสถิติ
$stats = [
    'picking_plans' => 0,
    'uploads' => 0,
    'activities' => 0,
    'logs' => 0
];

$sql = "SELECT 
            (SELECT COUNT(*) FROM picking_plans) AS plans_count,
            (SELECT COUNT(*) FROM uploads) AS uploads_count,
            (SELECT COUNT(*) FROM picking_activities) AS activities_count,
            (SELECT COUNT(*) FROM logs) AS logs_count";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $stats['picking_plans'] = $row['plans_count'];
    $stats['uploads'] = $row['uploads_count'];
    $stats['activities'] = $row['activities_count'];
    $stats['logs'] = $row['logs_count'];
}

// ประมวลผลคำสั่งล้างข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_type'])) {
    $clear_type = $_POST['clear_type'];
    $confirmation = isset($_POST['confirmation']) ? $_POST['confirmation'] : '';
    
    // ตรวจสอบรหัสยืนยัน
    if ($confirmation !== $confirmCode) {
        $message = 'รหัสยืนยันไม่ถูกต้อง กรุณาลองใหม่';
        $messageType = 'danger';
    } else {
        try {
            // เริ่ม Transaction
            $conn->begin_transaction();
            
            switch ($clear_type) {
                case 'all':
                    // ล้างข้อมูลทั้งหมด (ยกเว้น users)
                    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                    $conn->query("TRUNCATE TABLE picking_activities");
                    $conn->query("TRUNCATE TABLE picking_plans");
                    $conn->query("TRUNCATE TABLE uploads");
                    $conn->query("TRUNCATE TABLE logs");
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $message = 'ล้างข้อมูลทั้งหมดเรียบร้อยแล้ว';
                    break;
                    
                case 'uploads_plans':
                    // ล้างข้อมูลแผนการหยิบและประวัติการอัปโหลด
                    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                    $conn->query("TRUNCATE TABLE picking_activities");
                    $conn->query("TRUNCATE TABLE picking_plans");
                    $conn->query("TRUNCATE TABLE uploads");
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $message = 'ล้างข้อมูลแผนการหยิบและประวัติการอัปโหลดเรียบร้อยแล้ว';
                    break;
                    
                case 'activities':
                    // ล้างเฉพาะกิจกรรมการหยิบ
                    $conn->query("TRUNCATE TABLE picking_activities");
                    
                    // รีเซ็ตสถานะในตาราง picking_plans
                    $conn->query("UPDATE picking_plans SET status = 'pending'");
                    
                    $message = 'ล้างข้อมูลกิจกรรมการหยิบเรียบร้อยแล้ว และรีเซ็ตสถานะแผนการหยิบเป็น "รอดำเนินการ"';
                    break;
                    
                case 'logs':
                    // ล้างเฉพาะบันทึกการทำงาน
                    $conn->query("TRUNCATE TABLE logs");
                    $message = 'ล้างข้อมูลบันทึกการทำงานเรียบร้อยแล้ว';
                    break;
                    
                case 'specific_upload':
                    // ล้างข้อมูลการอัปโหลดเฉพาะรายการ
                    if (isset($_POST['upload_id']) && is_numeric($_POST['upload_id'])) {
                        $upload_id = (int) $_POST['upload_id'];
                        
                        // ลบข้อมูล (Foreign Key CASCADE จะลบข้อมูลที่เกี่ยวข้องด้วย)
                        $stmt = $conn->prepare("DELETE FROM uploads WHERE id = ?");
                        $stmt->bind_param("i", $upload_id);
                        $stmt->execute();
                        
                        if ($stmt->affected_rows > 0) {
                            $message = 'ลบข้อมูลการอัปโหลดและแผนการหยิบที่เกี่ยวข้องเรียบร้อยแล้ว';
                        } else {
                            $message = 'ไม่พบข้อมูลการอัปโหลดที่ระบุ';
                            $messageType = 'warning';
                        }
                    } else {
                        $message = 'กรุณาระบุรหัสการอัปโหลด';
                        $messageType = 'warning';
                    }
                    break;
                    
                default:
                    $message = 'ไม่รู้จักคำสั่งที่ระบุ';
                    $messageType = 'danger';
                    throw new Exception('Invalid clear type');
            }
            
            // บันทึกการทำงาน
            $admin_id = $_SESSION['user_id'];
            $action_detail = "ล้างข้อมูล: $clear_type";
            $ip = $_SERVER['REMOTE_ADDR'];
            
            $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'clear_data', ?, ?)";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bind_param("iss", $admin_id, $action_detail, $ip);
            $log_stmt->execute();
            
            // Commit การเปลี่ยนแปลง
            $conn->commit();
            
            // อัปเดตสถิติ
            $result = $conn->query($sql);
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stats['picking_plans'] = $row['plans_count'];
                $stats['uploads'] = $row['uploads_count'];
                $stats['activities'] = $row['activities_count'];
                $stats['logs'] = $row['logs_count'];
            }
            
            // ตั้งค่าข้อความ
            if (empty($messageType)) {
                $messageType = 'success';
            }
            
        } catch (Exception $e) {
            // Rollback หากเกิดข้อผิดพลาด
            $conn->rollback();
            
            $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ดึงรายการการอัปโหลดเพื่อแสดงในส่วนลบเฉพาะรายการ
$uploads = [];
$sql = "SELECT id, filename, upload_date, status FROM uploads ORDER BY upload_date DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $uploads[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ล้างข้อมูล - ระบบ Emergency Picking</title>
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
        }

        .card-header-danger {
            background-color: var(--danger-color);
        }

        .card-header-warning {
            background-color: var(--warning-color);
        }

        .card-header i {
            margin-right: 0.5rem;
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

        .confirmation-box {
            background-color: #ffecb3;
            border-left: 4px solid #ffa000;
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

        .btn-danger-soft {
            color: var(--danger-color);
            background-color: #ffe5e5;
            border-color: #ffcccc;
        }

        .btn-danger-soft:hover {
            color: white;
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }

        .btn-warning-soft {
            color: var(--warning-color);
            background-color: #fff2e0;
            border-color: #ffe5cc;
        }

        .btn-warning-soft:hover {
            color: white;
            background-color: var(--warning-color);
            border-color: var(--warning-color);
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

        /* Warning markers */
        .danger-zone {
            border: 2px dashed var(--danger-color);
            border-radius: var(--border-radius);
            padding: 0.5rem;
            position: relative;
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

        /* Hover effects */
        .btn {
            transition: all 0.3s ease;
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
                        <a class="nav-link" href="history.php"><i class="fas fa-history"></i> ประวัติการอัปโหลด</a>
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
            <i class="fas fa-eraser"></i> ล้างข้อมูล
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
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> ข้อมูลในระบบปัจจุบัน
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="value"><?php echo number_format($stats['uploads']); ?></div>
                            <div class="label">การอัปโหลด</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="value"><?php echo number_format($stats['picking_plans']); ?></div>
                            <div class="label">ตำแหน่งหยิบงาน</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="value"><?php echo number_format($stats['activities']); ?></div>
                            <div class="label">กิจกรรมการหยิบ</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="value"><?php echo number_format($stats['logs']); ?></div>
                            <div class="label">บันทึกการทำงาน</div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i> การล้างข้อมูลจะไม่ส่งผลกระทบต่อข้อมูลผู้ใช้งานระบบ
                </div>
            </div>
        </div>
        
        <!-- Clear Options -->
        <div class="row">
            <!-- Reset Activities -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header card-header-warning">
                        <i class="fas fa-broom"></i> รีเซ็ตข้อมูลกิจกรรม
                    </div>
                    <div class="card-body">
                        <p>การดำเนินการนี้จะ:</p>
                        <ul>
                            <li>ล้างประวัติกิจกรรมการหยิบทั้งหมด</li>
                            <li>รีเซ็ตสถานะแผนการหยิบเป็น "รอดำเนินการ"</li>
                            <li><strong>ไม่ลบ</strong> ข้อมูลแผนการหยิบและการอัปโหลด</li>
                        </ul>
                        
                        <div class="confirmation-box">
                            <p><strong>รหัสยืนยัน:</strong> <span class="confirmation-code"><?php echo $confirmCode; ?></span></p>
                            <p>โปรดคัดลอกรหัสนี้ไปวางในช่องยืนยัน</p>
                        </div>
                        
                        <form method="post" onsubmit="return confirmClear('กิจกรรมการหยิบ')">
                            <input type="hidden" name="clear_type" value="activities">
                            <div class="form-group">
                                <label for="confirmation_activities">ยืนยันรหัส:</label>
                                <input type="text" class="form-control" id="confirmation_activities" name="confirmation" required placeholder="กรอกรหัสยืนยัน">
                            </div>
                            <button type="submit" class="btn btn-warning-soft">
                                <i class="fas fa-broom"></i> รีเซ็ตกิจกรรมการหยิบ
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Clear Logs -->
                <div class="card">
                    <div class="card-header card-header-warning">
                        <i class="fas fa-trash-alt"></i> ล้างบันทึกการทำงาน
                    </div>
                    <div class="card-body">
                        <p>การดำเนินการนี้จะล้างบันทึกการทำงานของระบบทั้งหมด (Logs)</p>
                        
                        <div class="confirmation-box">
                            <p><strong>รหัสยืนยัน:</strong> <span class="confirmation-code"><?php echo $confirmCode; ?></span></p>
                            <p>โปรดคัดลอกรหัสนี้ไปวางในช่องยืนยัน</p>
                        </div>
                        
                        <form method="post" onsubmit="return confirmClear('บันทึกการทำงาน')">
                            <input type="hidden" name="clear_type" value="logs">
                            <div class="form-group">
                                <label for="confirmation_logs">ยืนยันรหัส:</label>
                                <input type="text" class="form-control" id="confirmation_logs" name="confirmation" required placeholder="กรอกรหัสยืนยัน">
                            </div>
                            <button type="submit" class="btn btn-warning-soft">
                                <i class="fas fa-trash-alt"></i> ล้างบันทึกการทำงาน
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Danger Zone -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header card-header-danger">
                        <i class="fas fa-exclamation-triangle"></i> โซนอันตราย - ล้างข้อมูลทั้งหมด
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger">
                            <strong>คำเตือน!</strong> การดำเนินการในส่วนนี้จะลบข้อมูลจริงออกจากฐานข้อมูลและไม่สามารถเรียกคืนได้
                        </div>
                        
                        <div class="danger-zone">
                            <h5>ล้างข้อมูลทั้งหมด</h5>
                            <p>การดำเนินการนี้จะล้างข้อมูลทั้งหมด ได้แก่:</p>
                            <ul>
                                <li>ประวัติการอัปโหลดทั้งหมด</li>
                                <li>แผนการหยิบทั้งหมด</li>
                                <li>กิจกรรมการหยิบทั้งหมด</li>
                                <li>บันทึกการทำงานทั้งหมด</li>
                            </ul>
                            
                            <div class="confirmation-box">
                                <p><strong>รหัสยืนยัน:</strong> <span class="confirmation-code"><?php echo $confirmCode; ?></span></p>
                                <p>โปรดคัดลอกรหัสนี้ไปวางในช่องยืนยัน</p>
                            </div>
                            
                            <form method="post" onsubmit="return confirmClear('ทั้งหมด')">
                                <input type="hidden" name="clear_type" value="all">
                                <div class="form-group">
                                    <label for="confirmation_all">ยืนยันรหัส:</label>
                                    <input type="text" class="form-control" id="confirmation_all" name="confirmation" required placeholder="กรอกรหัสยืนยัน">
                                </div>
                                <button type="submit" class="btn btn-danger-soft">
                                    <i class="fas fa-trash-alt"></i> ล้างข้อมูลทั้งหมด
                                </button>
                            </form>
                        </div>
                        
                        <div class="danger-zone mt-4">
                            <h5>ล้างข้อมูลแผนงานและการอัปโหลด</h5>
                            <p>การดำเนินการนี้จะล้างข้อมูล:</p>
                            <ul>
                                <li>ประวัติการอัปโหลดทั้งหมด</li>
                                <li>แผนการหยิบทั้งหมด</li>
                                <li>กิจกรรมการหยิบทั้งหมด</li>
                                <li><strong>ไม่ลบ</strong> บันทึกการทำงาน (Logs)</li>
                            </ul>
                            
                            <div class="confirmation-box">
                                <p><strong>รหัสยืนยัน:</strong> <span class="confirmation-code"><?php echo $confirmCode; ?></span></p>
                                <p>โปรดคัดลอกรหัสนี้ไปวางในช่องยืนยัน</p>
                            </div>
                            
                            <form method="post" onsubmit="return confirmClear('แผนงานและการอัปโหลด')">
                                <input type="hidden" name="clear_type" value="uploads_plans">
                                <div class="form-group">
                                    <label for="confirmation_uploads">ยืนยันรหัส:</label>
                                    <input type="text" class="form-control" id="confirmation_uploads" name="confirmation" required placeholder="กรอกรหัสยืนยัน">
                                </div>
                                <button type="submit" class="btn btn-danger-soft">
                                    <i class="fas fa-trash-alt"></i> ล้างข้อมูลแผนงานและการอัปโหลด
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Delete Specific Upload -->
                <?php if (!empty($uploads)): ?>
                <div class="card">
                    <div class="card-header card-header-warning">
                        <i class="fas fa-trash"></i> ลบการอัปโหลดเฉพาะรายการ
                    </div>
                    <div class="card-body">
                        <p>เลือกรายการที่ต้องการลบ:</p>
                        
                        <form method="post" onsubmit="return confirmDeleteUpload()">
                            <input type="hidden" name="clear_type" value="specific_upload">
                            <input type="hidden" name="confirmation" value="<?php echo $confirmCode; ?>">
                            
                            <div class="form-group">
                                <select class="form-control" name="upload_id" required>
                                    <option value="">เลือกรายการอัปโหลด...</option>
                                    <?php foreach ($uploads as $upload): ?>
                                    <option value="<?php echo $upload['id']; ?>">
                                        <?php echo date('d/m/Y H:i', strtotime($upload['upload_date'])); ?> - 
                                        <?php echo htmlspecialchars($upload['filename']); ?> 
                                        (<?php echo htmlspecialchars($upload['status']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-warning-soft">
                                <i class="fas fa-trash"></i> ลบรายการที่เลือก
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Back button -->
        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> กลับไปหน้าหลัก
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
        function confirmClear(type) {
            return confirm(`คุณแน่ใจหรือไม่ที่จะล้างข้อมูล${type}?\n\nการดำเนินการนี้ไม่สามารถเรียกคืนได้!`);
        }
        
        function confirmDeleteUpload() {
            return confirm('คุณแน่ใจหรือไม่ที่จะลบการอัปโหลดรายการนี้?\n\nการดำเนินการนี้จะลบแผนการหยิบและกิจกรรมที่เกี่ยวข้องทั้งหมด และไม่สามารถเรียกคืนได้!');
        }
        
        // คัดลอกรหัสยืนยันด้วยการคลิก
        document.addEventListener('DOMContentLoaded', function() {
            const confirmationCodes = document.querySelectorAll('.confirmation-code');
            
            confirmationCodes.forEach(function(code) {
                code.addEventListener('click', function() {
                    const range = document.createRange();
                    range.selectNode(code);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                    document.execCommand('copy');
                    window.getSelection().removeAllRanges();
                    
                    code.classList.add('bg-success');
                    code.classList.add('text-white');
                    
                    setTimeout(function() {
                        code.classList.remove('bg-success');
                        code.classList.remove('text-white');
                    }, 500);
                });
            });
        });
    </script>
</body>
</html>

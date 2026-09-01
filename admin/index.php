<?php
// หน้าแดชบอร์ดหลักสำหรับแอดมิน
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// ดึงข้อมูลการอัปโหลดล่าสุด
$latestUpload = null;
$sql = "SELECT id, filename, upload_date, rows_total, rows_success, rows_duplicate, rows_error 
        FROM uploads ORDER BY upload_date DESC LIMIT 1";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $latestUpload = $result->fetch_assoc();
}

// ดึงข้อมูลความคืบหน้าของทุกกลุ่ม
$groups = [];
$groupProgress = [];
$totalPositions = 0;
$completedPositions = 0;

// ดึงข้อมูลสรุปของทุก Group
$groupSql = "SELECT 
                group_name, 
                COUNT(*) as total_positions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_positions
            FROM picking_plans 
            GROUP BY group_name
            ORDER BY group_name";
$groupResult = $conn->query($groupSql);

if ($groupResult && $groupResult->num_rows > 0) {
    while ($row = $groupResult->fetch_assoc()) {
        $groups[] = $row['group_name'];
        $groupProgress[$row['group_name']] = [
            'total' => $row['total_positions'],
            'completed' => $row['completed_positions'],
            'percentage' => $row['total_positions'] > 0 
                ? round(($row['completed_positions'] / $row['total_positions']) * 100) 
                : 0
        ];
        
        $totalPositions += $row['total_positions'];
        $completedPositions += $row['completed_positions'];
    }
}

// ดึงข้อมูลสรุปของทุก Rotation
$rotations = [];
$rotationProgress = [];

$rotationSql = "SELECT 
                rotation, 
                COUNT(*) as total_positions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_positions
              FROM picking_plans 
              GROUP BY rotation
              ORDER BY rotation";
$rotationResult = $conn->query($rotationSql);

if ($rotationResult && $rotationResult->num_rows > 0) {
    while ($row = $rotationResult->fetch_assoc()) {
        $rotations[] = $row['rotation'];
        $rotationProgress[$row['rotation']] = [
            'total' => $row['total_positions'],
            'completed' => $row['completed_positions'],
            'percentage' => $row['total_positions'] > 0 
                ? round(($row['completed_positions'] / $row['total_positions']) * 100) 
                : 0
        ];
    }
}

// คำนวณความคืบหน้ารวม
$overallPercentage = $totalPositions > 0 
    ? round(($completedPositions / $totalPositions) * 100) 
    : 0;

// ดึงกิจกรรมล่าสุด
$recentActivities = [];
$sql = "SELECT 
            a.created_at, a.action, a.status, 
            p.group_name, p.position, p.part_number, p.rotation
        FROM picking_activities a
        JOIN picking_plans p ON a.plan_id = p.id
        ORDER BY a.created_at DESC
        LIMIT 10";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $recentActivities[] = $row;
    }
}

// คำนวณสถิติเพิ่มเติม
$activeUsers = 0;
$completedGroups = 0;
$pendingGroups = 0;
$completedRotations = 0;
$pendingRotations = 0;

if (!empty($groups)) {
    foreach ($groupProgress as $progress) {
        if ($progress['completed'] > 0) {
            $activeUsers++;
        }
        
        if ($progress['percentage'] == 100) {
            $completedGroups++;
        } else if ($progress['completed'] > 0) {
            $pendingGroups++;
        }
    }
}

if (!empty($rotations)) {
    foreach ($rotationProgress as $progress) {
        if ($progress['percentage'] == 100) {
            $completedRotations++;
        } else if ($progress['completed'] > 0) {
            $pendingRotations++;
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
    <title>แดชบอร์ดแอดมิน - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../includes/navbar.css">
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

        /* ส่วนของ CSS สำหรับเนื้อหาอื่นๆ */
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

        .progress {
            height: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .progress-bar {
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: width 0.6s ease;
            font-weight: 500;
            color: white;
        }

        .progress-bar-success {
            background-color: var(--success-color);
        }

        .progress-bar-warning {
            background-color: var(--warning-color);
        }

        .progress-bar-danger {
            background-color: var(--danger-color);
        }

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

        .group-card {
            position: relative;
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: var(--box-shadow);
            transition: transform 0.2s;
        }

        .group-card:hover {
            transform: translateY(-3px);
        }

        .group-card .group-name {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .group-card .progress {
            height: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .group-card .stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--secondary-color);
        }

        .rotation-card {
            position: relative;
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: var(--box-shadow);
            transition: transform 0.2s;
            border-left: 3px solid var(--accent-color);
        }

        .rotation-card:hover {
            transform: translateY(-3px);
        }

        .rotation-card .rotation-name {
            font-weight: 600;
            color: var(--accent-color);
            margin-bottom: 0.5rem;
        }

        .rotation-card .progress {
            height: 0.5rem;
            margin-bottom: 0.5rem;
        }

        .rotation-card .stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--accent-color);
        }

        .badge-status {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }

        .badge-completed {
            background-color: var(--success-color);
            color: white;
        }

        .badge-inprogress {
            background-color: var(--warning-color);
            color: white;
        }

        .badge-pending {
            background-color: var(--secondary-color);
            color: white;
        }

        .badge-empty {
            background-color: #adb5bd;
            color: white;
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
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }

        .footer {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 1rem 0;
            margin-top: 2rem;
        }

        .summary-box {
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            color: white;
        }

        .summary-box.primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        }

        .summary-box.success {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
        }

        .summary-box.warning {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
        }

        .summary-box.danger {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        }

        .summary-box .title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            opacity: 0.8;
        }

        .summary-box .value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .summary-box .description {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .summary-box .icon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 3rem;
            opacity: 0.2;
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
            
            .stats-card .value {
                font-size: 1.5rem;
            }
            
            .summary-box .value {
                font-size: 1.8rem;
            }
            
            .summary-box .icon {
                font-size: 2rem;
            }
        }

        /* Animation */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .pulse {
            animation: pulse 2s infinite;
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
        
        .nav-tabs {
            border-bottom: 2px solid var(--light-color);
            margin-bottom: 1rem;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--dark-color);
            font-weight: 500;
            padding: 0.5rem 1rem;
            margin-right: 0.5rem;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }
        
        .nav-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }
        
        .nav-tabs .nav-link:hover:not(.active) {
            background-color: var(--light-color);
            border: none;
        }

        .tab-content > .tab-pane {
            padding-top: 1rem;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Warehouse Header -->
        <div class="warehouse-header">
            <div class="container">
                <h1><i class="fas fa-tachometer-alt"></i> แดชบอร์ด (Operation TTV AAT-Wiring)</h1>
                <p>ระบบบริหารจัดการการหยิบงานฉุกเฉิน สำหรับผู้ดูแลคลังสินค้า</p>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="row">
            <div class="col-md-3">
                <div class="summary-box primary position-relative">
                    <i class="fas fa-dolly icon"></i>
                    <div class="title">ความคืบหน้ารวม</div>
                    <div class="value"><?php echo $overallPercentage; ?>%</div>
                    <div class="description">
                        <?php echo $completedPositions; ?> / <?php echo $totalPositions; ?> ตำแหน่ง
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-box success position-relative">
                    <i class="fas fa-check-circle icon"></i>
                    <div class="title">กลุ่มที่เสร็จสมบูรณ์</div>
                    <div class="value"><?php echo $completedGroups; ?></div>
                    <div class="description">จาก <?php echo count($groups); ?> กลุ่ม</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-box warning position-relative">
                    <i class="fas fa-hourglass-half icon"></i>
                    <div class="title">Rotation ที่เสร็จสมบูรณ์</div>
                    <div class="value"><?php echo $completedRotations; ?></div>
                    <div class="description">จาก <?php echo count($rotations); ?> Rotation</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="summary-box danger position-relative">
                    <i class="fas fa-sync-alt icon"></i>
                    <div class="title">Rotation ที่ดำเนินการ</div>
                    <div class="value"><?php echo $pendingRotations; ?></div>
                    <div class="description">กำลังอยู่ในระหว่างหยิบงาน</div>
                </div>
            </div>
        </div>

        <!-- Groups and Rotations Tabs -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-layer-group"></i> ความคืบหน้าตามกลุ่มและ Rotation</span>
                <a href="group_progress.php" target="_blank" class="btn btn-sm btn-light">
                    <i class="fas fa-expand-alt"></i> ดูความคืบหน้าทุกกลุ่ม (FHD)
                </a>
            </div>
            <div class="card-body">
                <!-- Nav tabs -->
                <ul class="nav nav-tabs" id="progressTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="groups-tab" data-toggle="tab" href="#groups" role="tab" aria-controls="groups" aria-selected="true">
                            <i class="fas fa-layer-group"></i> กลุ่ม (<?php echo count($groups); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="rotations-tab" data-toggle="tab" href="#rotations" role="tab" aria-controls="rotations" aria-selected="false">
                            <i class="fas fa-sync-alt"></i> Rotation (<?php echo count($rotations); ?>)
                        </a>
                    </li>
                </ul>
                
                <!-- Tab panes -->
                <div class="tab-content">
                    <!-- Groups Tab -->
                    <div class="tab-pane fade show active" id="groups" role="tabpanel" aria-labelledby="groups-tab">
                        <?php if (empty($groups)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> ยังไม่มีข้อมูลกลุ่มในระบบ กรุณาอัปโหลดข้อมูล
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($groups as $group): 
                                    $progress = $groupProgress[$group];
                                    $badgeClass = 'badge-empty';
                                    $badgeText = 'ว่าง';
                                    
                                    if ($progress['percentage'] == 100) {
                                        $badgeClass = 'badge-completed';
                                        $badgeText = 'เสร็จสมบูรณ์';
                                    } elseif ($progress['completed'] > 0) {
                                        $badgeClass = 'badge-inprogress';
                                        $badgeText = 'กำลังดำเนินการ';
                                    } elseif ($progress['total'] > 0) {
                                        $badgeClass = 'badge-pending';
                                        $badgeText = 'รอดำเนินการ';
                                    }
                                ?>
                                <div class="col-md-4">
                                    <div class="group-card">
                                        <span class="badge <?php echo $badgeClass; ?> badge-status"><?php echo $badgeText; ?></span>
                                        <div class="group-name"><?php echo htmlspecialchars($group); ?></div>
                                        <div class="progress">
                                            <div class="progress-bar" style="width: <?php echo $progress['percentage']; ?>%"></div>
                                        </div>
                                        <div class="stats">
                                            <div><?php echo $progress['completed']; ?> / <?php echo $progress['total']; ?> ตำแหน่ง</div>
                                            <div><?php echo $progress['percentage']; ?>%</div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Rotations Tab -->
                    <div class="tab-pane fade" id="rotations" role="tabpanel" aria-labelledby="rotations-tab">
                        <?php if (empty($rotations)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i> ยังไม่มีข้อมูล Rotation ในระบบ กรุณาอัปโหลดข้อมูล
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($rotations as $rotation): 
                                    $progress = $rotationProgress[$rotation];
                                    $badgeClass = 'badge-empty';
                                    $badgeText = 'ว่าง';
                                    
                                    if ($progress['percentage'] == 100) {
                                        $badgeClass = 'badge-completed';
                                        $badgeText = 'เสร็จสมบูรณ์';
                                    } elseif ($progress['completed'] > 0) {
                                        $badgeClass = 'badge-inprogress';
                                        $badgeText = 'กำลังดำเนินการ';
                                    } elseif ($progress['total'] > 0) {
                                        $badgeClass = 'badge-pending';
                                        $badgeText = 'รอดำเนินการ';
                                    }
                                ?>
                                <div class="col-md-3">
                                    <div class="rotation-card">
                                        <span class="badge <?php echo $badgeClass; ?> badge-status"><?php echo $badgeText; ?></span>
                                        <div class="rotation-name"><?php echo htmlspecialchars($rotation); ?></div>
                                        <div class="progress">
                                            <div class="progress-bar" style="width: <?php echo $progress['percentage']; ?>%"></div>
                                        </div>
                                        <div class="stats">
                                            <div><?php echo $progress['completed']; ?> / <?php echo $progress['total']; ?> ตำแหน่ง</div>
                                            <div><?php echo $progress['percentage']; ?>%</div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Status -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-cogs"></i> สถานะระบบ
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="stats-card success">
                            <div class="icon"><i class="fas fa-database"></i></div>
                            <div class="value">พร้อมใช้งาน</div>
                            <div class="label">สถานะฐานข้อมูล</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="icon"><i class="fas fa-print"></i></div>
                            <div class="value">พร้อมใช้งาน</div>
                            <div class="label">ระบบพิมพ์</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="icon"><i class="fas fa-font"></i></div>
                            <div class="value">โหลดแล้ว</div>
                            <div class="label">Barcode Font</div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <p><strong>ข้อมูลเซิร์ฟเวอร์:</strong> <?php echo $_SERVER['SERVER_SOFTWARE']; ?></p>
                    <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                    <p><strong>เวลาปัจจุบันของระบบ:</strong> <?php echo date('d/m/Y H:i:s'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> Emergency Picking System - By Gan Anchaleepon</p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Auto-refresh ทุก 2 นาที
        setTimeout(function() {
            location.reload();
        }, 120000);
        
        // เพิ่ม Animation pulse ที่การ์ดกลุ่มที่กำลังดำเนินการ
        document.addEventListener('DOMContentLoaded', function() {
            const inProgressCards = document.querySelectorAll('.badge-inprogress');
            inProgressCards.forEach(function(card) {
                const parent = card.closest('.group-card, .rotation-card');
                if (parent) {
                    parent.classList.add('pulse');
                }
            });
        });
    </script>
</body>
</html>
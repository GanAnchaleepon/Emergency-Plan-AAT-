<?php
// หน้ารายงานและส่งออกข้อมูล
session_start();
require_once '../config/database.php';

// ตรวจสอบว่าผู้ใช้ล็อกอินแล้วหรือไม่
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// รับพารามิเตอร์การค้นหา
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$group = isset($_GET['group']) ? $_GET['group'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';

// ดึงรายชื่อกลุ่มทั้งหมด
$groups = [];
$groupSql = "SELECT DISTINCT group_name FROM picking_plans ORDER BY group_name";
$groupResult = $conn->query($groupSql);
if ($groupResult) {
    while ($row = $groupResult->fetch_assoc()) {
        $groups[] = $row['group_name'];
    }
}

// สร้างคำสั่ง SQL สำหรับรายงาน
$reportSql = "SELECT 
                pp.id, pp.group_name, pp.position, pp.part_number, pp.part_name, 
                pp.quantity, pp.rotation, pp.supplier, pp.location, pp.status,
                (SELECT MAX(created_at) FROM picking_activities WHERE plan_id = pp.id) as last_activity,
                (SELECT scan_data FROM picking_activities WHERE plan_id = pp.id AND action = 'scan_box' LIMIT 1) as box_lot
              FROM picking_plans pp
              WHERE 1=1";

$params = [];
$types = "";

if (!empty($start_date) && !empty($end_date)) {
    $reportSql .= " AND pp.upload_id IN (SELECT id FROM uploads WHERE upload_date BETWEEN ? AND ?)";
    $params[] = $start_date . ' 00:00:00';
    $params[] = $end_date . ' 23:59:59';
    $types .= "ss";
}

if (!empty($group)) {
    $reportSql .= " AND pp.group_name = ?";
    $params[] = $group;
    $types .= "s";
}

if (!empty($status)) {
    $reportSql .= " AND pp.status = ?";
    $params[] = $status;
    $types .= "s";
}

$reportSql .= " ORDER BY pp.group_name, pp.position";

// ดำเนินการดึงข้อมูล
$reportData = [];
$stmt = $conn->prepare($reportSql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reportData[] = $row;
    }
}

// จัดการการส่งออกข้อมูล CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // ตั้งค่า header สำหรับการดาวน์โหลด CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=emergency_picking_report_' . date('Ymd') . '.csv');
    
    // สร้าง output stream
    $output = fopen('php://output', 'w');
    
    // เพิ่ม BOM สำหรับ UTF-8 (ช่วยให้ Excel เปิดไฟล์ภาษาไทยได้ถูกต้อง)
    fprintf($output, "\xEF\xBB\xBF");
    
    // เพิ่มหัวข้อคอลัมน์
    fputcsv($output, [
        'กลุ่ม', 'ตำแหน่ง', 'รหัสชิ้นส่วน', 'ชื่อชิ้นส่วน', 'จำนวน', 'Rotation', 'สถานะ', 'รหัสกล่อง/ล็อต', 'วันที่-เวลาล่าสุด'
    ]);
    
    // เพิ่มข้อมูล
    foreach ($reportData as $row) {
        $status_text = ($row['status'] == 'completed') ? 'หยิบแล้ว' : 'รอหยิบ';
        fputcsv($output, [
            $row['group_name'],
            $row['position'],
            $row['part_number'],
            $row['part_name'],
            $row['quantity'],
            $row['rotation'],
            $status_text,
            $row['box_lot'] ?? '',
            $row['last_activity'] ?? ''
        ]);
    }
    
    // บันทึกประวัติการส่งออก
    $logSql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'export_csv', ?, ?)";
    $logDetails = "ส่งออกรายงาน CSV (กลุ่ม: " . ($group ?: 'ทั้งหมด') . ", สถานะ: " . ($status ?: 'ทั้งหมด') . ")";
    $logStmt = $conn->prepare($logSql);
    $logStmt->bind_param("iss", $_SESSION['user_id'], $logDetails, $_SERVER['REMOTE_ADDR']);
    $logStmt->execute();
    
    fclose($output);
    exit();
}

// สร้างข้อมูลสำหรับกราฟสรุป
$chartData = [
    'groups' => [],
    'completed' => [],
    'pending' => []
];

$chartSql = "SELECT 
                group_name, 
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
             FROM picking_plans
             WHERE upload_id = (SELECT MAX(id) FROM uploads WHERE status = 'active')
             GROUP BY group_name
             ORDER BY group_name";
$chartResult = $conn->query($chartSql);

if ($chartResult) {
    while ($row = $chartResult->fetch_assoc()) {
        $chartData['groups'][] = $row['group_name'];
        $chartData['completed'][] = intval($row['completed']);
        $chartData['pending'][] = intval($row['pending']);
    }
}

// คำนวณสถิติรวม
$totalStats = [
    'total' => 0,
    'completed' => 0,
    'pending' => 0,
    'completion_rate' => 0
];

if (!empty($reportData)) {
    $totalStats['total'] = count($reportData);
    foreach ($reportData as $row) {
        if ($row['status'] == 'completed') {
            $totalStats['completed']++;
        } else {
            $totalStats['pending']++;
        }
    }
    $totalStats['completion_rate'] = ($totalStats['total'] > 0) ? 
        round(($totalStats['completed'] / $totalStats['total']) * 100, 2) : 0;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงาน - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f8f9fa;
        }
        .sidebar {
            min-height: 100vh;
            background-color: #343a40;
            color: white;
            padding-top: 20px;
        }
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
        }
        .sidebar .nav-link:hover {
            color: white;
        }
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.1);
        }
        .sidebar .nav-link i {
            margin-right: 10px;
        }
        .main-content {
            padding: 20px;
        }
        .card {
            margin-bottom: 20px;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .stats-card {
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar no-print">
                <h4 class="text-center mb-4">Emergency Picking</h4>
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-tachometer-alt"></i> หน้าแรก
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="upload.php">
                            <i class="fas fa-upload"></i> อัปโหลดแผนงาน
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="history.php">
                            <i class="fas fa-history"></i> ประวัติการอัปโหลด
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="report.php">
                            <i class="fas fa-chart-bar"></i> รายงาน
                        </a>
                    </li>
                    <li class="nav-item mt-5">
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Main content -->
            <div class="col-md-10 main-content">
                <h2 class="mb-4 no-print">
                    <i class="fas fa-chart-bar"></i> รายงานและส่งออกข้อมูล
                    <small class="text-muted">สรุปข้อมูลการหยิบงาน</small>
                </h2>
                
                <!-- แบบฟอร์มค้นหา -->
                <div class="card no-print">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">ค้นหาและกรองข้อมูล</h5>
                    </div>
                    <div class="card-body">
                        <form method="get" class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="start_date">วันที่เริ่มต้น</label>
                                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="end_date">วันที่สิ้นสุด</label>
                                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="group">กลุ่ม</label>
                                    <select class="form-control" id="group" name="group">
                                        <option value="">ทั้งหมด</option>
                                        <?php foreach ($groups as $groupItem): ?>
                                        <option value="<?php echo $groupItem; ?>" <?php echo ($group == $groupItem) ? 'selected' : ''; ?>>
                                            <?php echo $groupItem; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="status">สถานะ</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="">ทั้งหมด</option>
                                        <option value="completed" <?php echo ($status == 'completed') ? 'selected' : ''; ?>>หยิบแล้ว</option>
                                        <option value="pending" <?php echo ($status == 'pending') ? 'selected' : ''; ?>>รอหยิบ</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> ค้นหา
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- สรุปสถิติ -->
                <div class="card">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">สรุปสถิติ</h5>
                            <div class="no-print">
                                <a href="<?php echo $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'export=csv'; ?>" class="btn btn-success btn-sm">
                                    <i class="fas fa-file-csv"></i> ส่งออก CSV
                                </a>
                                <button onclick="window.print()" class="btn btn-info btn-sm ml-2">
                                    <i class="fas fa-print"></i> พิมพ์รายงาน
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="card bg-light stats-card">
                                    <div class="card-body text-center">
                                        <h6 class="card-title">จำนวนทั้งหมด</h6>
                                        <p class="card-text display-4"><?php echo $totalStats['total']; ?></p>
                                        <p class="card-text text-muted">รายการ</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-success text-white stats-card">
                                    <div class="card-body text-center">
                                        <h6 class="card-title">หยิบแล้ว</h6>
                                        <p class="card-text display-4"><?php echo $totalStats['completed']; ?></p>
                                        <p class="card-text">รายการ</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning stats-card">
                                    <div class="card-body text-center">
                                        <h6 class="card-title">รอหยิบ</h6>
                                        <p class="card-text display-4"><?php echo $totalStats['pending']; ?></p>
                                        <p class="card-text">รายการ</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-info text-white stats-card">
                                    <div class="card-body text-center">
                                        <h6 class="card-title">อัตราการหยิบ</h6>
                                        <p class="card-text display-4"><?php echo $totalStats['completion_rate']; ?>%</p>
                                        <p class="card-text">เสร็จสมบูรณ์</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- กราฟสรุป -->
                        <?php if (!empty($chartData['groups'])): ?>
                        <div class="chart-container mt-4">
                            <canvas id="groupChart"></canvas>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- ตารางรายงาน -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0">รายงานละเอียด</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>กลุ่ม</th>
                                        <th>ตำแหน่ง</th>
                                        <th>รหัสชิ้นส่วน</th>
                                        <th>ชื่อชิ้นส่วน</th>
                                        <th>Supplier</th>
                                        <th>Location</th>
                                        <th>Rotation</th>
                                        <th>สถานะ</th>
                                        <th>รหัสกล่อง/ล็อต</th>
                                        <th>วันที่-เวลาล่าสุด</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($reportData)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-3">ไม่พบข้อมูลตามเงื่อนไขที่ค้นหา</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($reportData as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                            <td><?php echo $row['position']; ?></td>
                                            <td><?php echo htmlspecialchars($row['part_number']); ?></td>
                                            <td><?php echo htmlspecialchars($row['part_name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['supplier'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['location'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['rotation']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $row['status'] == 'completed' ? 'badge-success' : 'badge-warning'; ?>">
                                                    <?php echo $row['status'] == 'completed' ? 'หยิบแล้ว' : 'รอหยิบ'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['box_lot'] ?? ''); ?></td>
                                            <td><?php echo $row['last_activity'] ? date('d/m/Y H:i', strtotime($row['last_activity'])) : ''; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <?php if (!empty($chartData['groups'])): ?>
    <script>
        // สร้างกราฟแสดงผล
        document.addEventListener('DOMContentLoaded', function() {
            var ctx = document.getElementById('groupChart').getContext('2d');
            var groupChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chartData['groups']); ?>,
                    datasets: [
                        {
                            label: 'หยิบแล้ว',
                            data: <?php echo json_encode($chartData['completed']); ?>,
                            backgroundColor: 'rgba(40, 167, 69, 0.7)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'รอหยิบ',
                            data: <?php echo json_encode($chartData['pending']); ?>,
                            backgroundColor: 'rgba(255, 193, 7, 0.7)',
                            borderColor: 'rgba(255, 193, 7, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'จำนวนรายการ'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'กลุ่ม'
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'สถานะการหยิบงานแยกตามกลุ่ม'
                        }
                    }
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>

<?php
// หน้าดูรายละเอียดการอัปโหลด (สำหรับแอดมิน)
session_start();
require_once '../config/database.php';

// ตรวจสอบว่ามีการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// รับค่า upload_id จาก URL
$upload_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($upload_id <= 0) {
    header("Location: history.php");
    exit;
}

// ฟิลเตอร์สำหรับแสดงข้อมูล
$group_filter = isset($_GET['group']) ? $_GET['group'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'position';
$order = isset($_GET['order']) ? $_GET['order'] : 'asc';

// รับค่าหน้าปัจจุบัน
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 50; // จำนวนรายการต่อหน้า

// ดึงข้อมูลการอัปโหลด
$sql = "SELECT u.*, 
               users.name as uploaded_by_name,
               (SELECT COUNT(*) FROM picking_plans WHERE upload_id = u.id) as total_plans,
               (SELECT COUNT(*) FROM picking_plans WHERE upload_id = u.id AND status = 'completed') as completed_plans
        FROM uploads u
        LEFT JOIN users ON u.uploaded_by = users.id
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $upload_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: history.php");
    exit;
}

$upload = $result->fetch_assoc();

// คำนวณความคืบหน้า
$progress = $upload['total_plans'] > 0 ? round(($upload['completed_plans'] / $upload['total_plans']) * 100) : 0;

// ดึงรายชื่อกลุ่มทั้งหมดในการอัปโหลดนี้
$groups = [];
$sql = "SELECT DISTINCT group_name FROM picking_plans WHERE upload_id = ? ORDER BY group_name";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $upload_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $groups[] = $row['group_name'];
}

// ดึงข้อมูลความคืบหน้าแต่ละกลุ่ม
$group_progress = [];
$sql = "SELECT group_name, 
               COUNT(*) as total, 
               SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM picking_plans 
        WHERE upload_id = ? 
        GROUP BY group_name";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $upload_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $group_progress[$row['group_name']] = [
        'total' => $row['total'],
        'completed' => $row['completed'],
        'percentage' => $row['total'] > 0 ? round(($row['completed'] / $row['total']) * 100) : 0
    ];
}

// สร้างคำสั่ง SQL สำหรับดึงข้อมูลแผนการหยิบ
$sql_conditions = ["upload_id = ?"];
$sql_params = [$upload_id];
$param_types = "i";

if (!empty($group_filter)) {
    $sql_conditions[] = "group_name = ?";
    $sql_params[] = $group_filter;
    $param_types .= "s";
}

if (!empty($status_filter)) {
    $sql_conditions[] = "status = ?";
    $sql_params[] = $status_filter;
    $param_types .= "s";
}

if (!empty($search)) {
    $search_term = "%$search%";
    $sql_conditions[] = "(part_number LIKE ? OR part_name LIKE ? OR rotation LIKE ? OR location LIKE ?)";
    $sql_params[] = $search_term;
    $sql_params[] = $search_term;
    $sql_params[] = $search_term;
    $sql_params[] = $search_term;
    $param_types .= "ssss";
}

$sql_where = implode(" AND ", $sql_conditions);

// นับจำนวนรายการทั้งหมด
$count_sql = "SELECT COUNT(*) as total FROM picking_plans WHERE $sql_where";
$stmt = $conn->prepare($count_sql);
$stmt->bind_param($param_types, ...$sql_params);
$stmt->execute();
$total_items = $stmt->get_result()->fetch_assoc()['total'];

// คำนวณจำนวนหน้าทั้งหมด
$total_pages = ceil($total_items / $per_page);
$page = max(1, min($page, $total_pages)); // ให้หน้าอยู่ในช่วงที่ถูกต้อง
$offset = ($page - 1) * $per_page;

// ดึงข้อมูลแผนการหยิบ
// ดึงข้อมูลแผนการหยิบทั้งหมด ไม่แบ่งหน้า
$plans_sql = "SELECT * FROM picking_plans 
              WHERE $sql_where 
              ORDER BY group_name ASC, 
                       STR_TO_DATE(date, '%Y-%m-%d') ASC, 
                       CAST(rotation AS UNSIGNED) ASC, 
                       CAST(position AS UNSIGNED) ASC";
$stmt = $conn->prepare($plans_sql);

// ไม่ต้อง bind offset/per_page
$all_bind_params = $sql_params;

$stmt->bind_param($param_types, ...$all_bind_params);
$stmt->execute();
$plans = $stmt->get_result();

// ประมวลผลการเปลี่ยนสถานะแผนการหยิบ
$status_message = '';
$status_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (isset($_POST['plan_id']) && isset($_POST['new_status'])) {
        $plan_id = intval($_POST['plan_id']);
        $new_status = $_POST['new_status'];
        
        if (in_array($new_status, ['pending', 'inprogress', 'completed'])) {
            $update_sql = "UPDATE picking_plans SET status = ? WHERE id = ? AND upload_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("sii", $new_status, $plan_id, $upload_id);
            
            if ($update_stmt->execute()) {
                $status_message = "อัปเดตสถานะสำเร็จ";
                $status_type = "success";
                
                // บันทึกล็อก
                $admin_id = $_SESSION['user_id'];
                $log_sql = "INSERT INTO logs (user_id, action, details, ip_address) 
                           VALUES (?, 'update_status', ?, ?)";
                $details = "อัปเดตสถานะแผนการหยิบ ID: $plan_id เป็น: $new_status";
                $ip = $_SERVER['REMOTE_ADDR'];
                
                $log_stmt = $conn->prepare($log_sql);
                $log_stmt->bind_param("iss", $admin_id, $details, $ip);
                $log_stmt->execute();
                
                // Redirect เพื่อป้องกันการ resubmit form
                header("Location: view_upload.php?id=$upload_id&group=$group_filter&status=$status_filter&search=$search&sort=$sort&order=$order&page=$page&success=1");
                exit;
            } else {
                $status_message = "เกิดข้อผิดพลาดในการอัปเดตสถานะ";
                $status_type = "danger";
            }
        }
    }
}

// แสดงข้อความสำเร็จจาก URL parameter
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $status_message = "อัปเดตสถานะสำเร็จ";
    $status_type = "success";
}

// ฟังก์ชันสร้าง URL สำหรับการเรียงลำดับ
function sortUrl($field, $currentSort, $currentOrder, $page, $group, $status, $search) {
    $newOrder = ($currentSort === $field && $currentOrder === 'asc') ? 'desc' : 'asc';
    return "?id=" . $_GET['id'] . "&sort=$field&order=$newOrder&page=$page&group=$group&status=$status&search=$search";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดการอัปโหลด - ระบบ Emergency Picking</title>
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
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            padding: 0;
            white-space: nowrap;
            height: 60px;
        }

        .navbar-brand {
            color: white !important;
            font-weight: 600;
            font-size: 1.2rem;
            padding: 0.5rem 1rem;
            display: flex;
            align-items: center;
            background: rgba(0, 0, 0, 0.1);
            height: 60px;
            margin-right: 0;
            white-space: nowrap;
        }

        .navbar-brand i {
            margin-right: 0.5rem;
            font-size: 1.5rem;
            color: #ffcc00;
        }

        .navbar-nav {
            display: flex;
            align-items: center;
        }

        .navbar-dark .navbar-nav .nav-link {
            color: rgba(255, 255, 255, 0.85);
            padding: 0 0.9rem;
            font-weight: 500;
            position: relative;
            transition: all 0.3s;
            height: 60px;
            line-height: 60px;
            white-space: nowrap;
        }

        .navbar-dark .navbar-nav .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .navbar-dark .navbar-nav .nav-link.active {
            color: white;
            font-weight: 600;
            background-color: rgba(0, 0, 0, 0.2);
            box-shadow: inset 0 -3px 0 #ffcc00;
        }
        
        .navbar-dark .navbar-nav .nav-link i {
            margin-right: 0.3rem;
            font-size: 1rem;
            vertical-align: middle;
        }

        .navbar-toggler {
            border: none;
            padding: 0.8rem;
        }

        .navbar-toggler:focus {
            outline: none;
            box-shadow: none;
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

        .navbar-nav .logout-link {
            background-color: rgba(224, 79, 79, 0.2);
            margin-left: 0.5rem;
            transition: all 0.3s ease;
        }

        .navbar-nav .logout-link:hover {
            background-color: #e74c3c;
            color: white;
        }

        .navbar-nav .logout-link i {
            color: #ffcc00;
        }

        /* ปรับแต่งเพิ่มเติมสำหรับ Responsive */
        @media (max-width: 992px) {
            .navbar-nav .nav-link {
                padding: 0.5rem 1rem;
                height: auto;
                line-height: normal;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }

            .navbar-dark .navbar-nav .nav-link.active {
                box-shadow: inset 3px 0 0 #ffcc00;
            }

            .navbar-nav .logout-link {
                margin: 0.5rem 1rem;
                text-align: center;
            }
        }

        .main-content {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
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

        .card-header i {
            margin-right: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .progress {
            height: 20px;
            border-radius: 0.25rem;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            border-top: none;
            border-bottom: 2px solid #dee2e6;
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--dark-color);
        }

        .table thead th a {
            color: var(--dark-color);
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .table thead th a i {
            margin-left: 0.25rem;
            font-size: 0.8rem;
        }

        .table tbody tr:hover {
            background-color: rgba(236, 240, 241, 0.5);
        }

        .badge {
            font-weight: 500;
            padding: 0.4em 0.6em;
        }

        .badge-pending {
            background-color: var(--secondary-color);
        }

        .badge-inprogress {
            background-color: var(--warning-color);
        }

        .badge-completed {
            background-color: var(--success-color);
        }

        .badge-archived {
            background-color: #6c757d;
        }

        .badge-active {
            background-color: var(--success-color);
        }

        .pagination {
            justify-content: center;
            margin-top: 1rem;
        }

        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .page-link {
            color: var(--primary-color);
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

        .stats-card {
            border-left: 4px solid var(--primary-color);
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: white;
            box-shadow: var(--box-shadow);
        }

        .stats-card .value {
            font-size: 1.8rem;
            font-weight: 700;
        }

        .stats-card .label {
            color: var(--dark-color);
            font-weight: 500;
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

        .progress-sm {
            height: 10px;
        }

        .group-progress-row {
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            border-radius: 0.25rem;
            transition: background-color 0.2s;
        }

        .group-progress-row:hover {
            background-color: #f8f9fa;
        }

        .status-dropdown .dropdown-menu {
            min-width: 0;
        }

        .dropdown-item.pending {
            color: var(--secondary-color);
        }

        .dropdown-item.inprogress {
            color: var(--warning-color);
        }

        .dropdown-item.completed {
            color: var(--success-color);
        }

        .status-icon {
            margin-right: 0.25rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="../">
                <i class="fas fa-warehouse"></i>
                Emergency Picking (Operation TTV AAT-Wiring)
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-tachometer-alt"></i> แดชบอร์ด
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../Convert_BC/index.php">
                            <i class="fas fa-exchange-alt"></i> Convert BC
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="upload.php">
                            <i class="fas fa-upload"></i> อัปโหลด
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="print_bulk_stickers.php">
                            <i class="fas fa-print"></i> พิมพ์สติกเกอร์
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="print_summary.php">
                            <i class="fas fa-file-pdf"></i> พิมพ์ใบสรุป
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="history.php">
                            <i class="fas fa-history"></i> ประวัติ
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar"></i> รายงาน
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="position_mapping.php">
                            <i class="fas fa-map-marker-alt"></i> กำหนดจุดเริ่มต้น Position
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link logout-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <?php if (!empty($status_message)): ?>
        <div class="alert alert-<?php echo $status_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $status_message; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Upload Info -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> ข้อมูลการอัปโหลด
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="stats-card">
                            <div class="label">ชื่อไฟล์</div>
                            <div class="value" style="font-size: 1.2rem;"><?php echo htmlspecialchars($upload['filename']); ?></div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="label">อัปโหลดโดย</div>
                            <div class="value" style="font-size: 1.2rem;"><?php echo htmlspecialchars($upload['uploaded_by_name']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="label">วันที่อัปโหลด</div>
                            <div class="value" style="font-size: 1.2rem;"><?php echo date('d/m/Y H:i', strtotime($upload['upload_date'])); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-3">
                        <div class="stats-card success">
                            <div class="label">ตำแหน่งทั้งหมด</div>
                            <div class="value"><?php echo number_format($upload['total_plans']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card warning">
                            <div class="label">หยิบเสร็จแล้ว</div>
                            <div class="value"><?php echo number_format($upload['completed_plans']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="stats-card">
                            <div class="label">ความคืบหน้า (<?php echo $progress; ?>%)</div>
                            <div class="progress">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $progress; ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Plans Filter and Search -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-filter"></i> ค้นหาและกรอง
            </div>
            <div class="card-body">
                <form method="get" class="form-inline">
                    <input type="hidden" name="id" value="<?php echo $upload_id; ?>">
                    <input type="hidden" name="sort" value="<?php echo $sort; ?>">
                    <input type="hidden" name="order" value="<?php echo $order; ?>">
                    
                    <div class="form-group mr-2 mb-2">
                        <label for="group" class="mr-2">กลุ่ม:</label>
                        <select class="form-control" id="group" name="group">
                            <option value="">ทั้งหมด</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?php echo htmlspecialchars($group); ?>" <?php echo $group_filter === $group ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($group); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group mr-2 mb-2">
                        <label for="status" class="mr-2">สถานะ:</label>
                        <select class="form-control" id="status" name="status">
                            <option value="">ทั้งหมด</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>รอดำเนินการ</option>
                            <option value="inprogress" <?php echo $status_filter === 'inprogress' ? 'selected' : ''; ?>>กำลังดำเนินการ</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>เสร็จสมบูรณ์</option>
                        </select>
                    </div>
                    
                    <div class="form-group mr-2 mb-2">
                        <input type="text" class="form-control" name="search" placeholder="ค้นหา..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary mb-2">
                        <i class="fas fa-search"></i> ค้นหา
                    </button>
                    
                    <a href="view_upload.php?id=<?php echo $upload_id; ?>" class="btn btn-secondary mb-2 ml-2">
                        <i class="fas fa-sync"></i> รีเซ็ต
                    </a>
                </form>
            </div>
        </div>
        
        <!-- Plans Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><i class="fas fa-list"></i> รายการแผนการหยิบ</div>
                <span>แสดงทั้งหมด <?php echo $total_items; ?> รายการ</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                        <table class="table table-striped table-hover mb-0 all-plans-table">
                        <thead>
                            <tr>
                                <th>ลำดับ</th>
                                <th>กลุ่ม</th>
                                <th>Rotation</th>
                                <th>Part Number</th>
                                <th>Part Name</th>
                                <th>Quantity</th>
                                <th>Supplier</th>
                                <th>Location</th>
                                <th>Date</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($plans->num_rows === 0) { ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">ไม่พบข้อมูลที่ตรงกับเงื่อนไขการค้นหา</td>
                            </tr>
                            <?php } else { ?>
                                <?php $rowIndex = 1; while ($plan = $plans->fetch_assoc()) { ?>
                                <tr>
                                    <td><?php echo $rowIndex++; ?></td>
                                    <td><?php echo htmlspecialchars($plan['group_name']); ?></td>
                                    <td><?php echo str_pad(htmlspecialchars($plan['rotation']), 4, '0', STR_PAD_LEFT); ?></td>
                                    <td><?php echo htmlspecialchars($plan['part_number']); ?></td>
                                    <td><?php echo htmlspecialchars($plan['part_name']); ?></td>
                                    <td><?php echo htmlspecialchars($plan['quantity'] ?? '1'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['supplier'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['location'] ?? '-'); ?></td>
                                    <td>
                                        <?php 
                                            if (!empty($plan['date'])) {
                                                $date = $plan['date'];
                                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                                                    $date = date('d/m/Y', strtotime($date));
                                                }
                                                echo htmlspecialchars($date);
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            if (!empty($plan['time'])) {
                                                echo htmlspecialchars($plan['time']);
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                        </table>
                    </div>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Pagination -->
    <!-- Pagination removed: now showing all rows in the table -->
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
</body>
</html>

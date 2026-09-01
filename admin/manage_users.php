<?php
// หน้าจัดการผู้ใช้งานฝั่งแอดมิน (เพิ่ม/ลบผู้ใช้)
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$message = '';
$messageType = '';

// เพิ่มผู้ใช้งานใหม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $username   = trim($_POST['username'] ?? '');
    $firstName  = trim($_POST['first_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $fullName   = trim($firstName . ' ' . $lastName);

    if ($username === '' || $firstName === '' || $lastName === '' || $password === '') {
        $message = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
        $messageType = 'danger';
    } elseif (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
        $message = 'ชื่อผู้ใช้ (username) ใช้ได้เฉพาะตัวอักษรอังกฤษ ตัวเลข _ หรือ . ความยาว 3-50 ตัวอักษร';
        $messageType = 'danger';
    } elseif (strlen($password) < 6) {
        $message = 'รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร';
        $messageType = 'danger';
    } elseif ($password !== $confirm) {
        $message = 'รหัสผ่านและการยืนยันรหัสผ่านไม่ตรงกัน';
        $messageType = 'danger';
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $message = 'มีชื่อผู้ใช้นี้ในระบบแล้ว กรุณาใช้ชื่ออื่น';
            $messageType = 'danger';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $role = 'admin';
            $insertStmt = $conn->prepare("INSERT INTO users (username, password, name, role) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("ssss", $username, $hashedPassword, $fullName, $role);

            if ($insertStmt->execute()) {
                $newUserId = $insertStmt->insert_id;
                $ip = $_SERVER['REMOTE_ADDR'];
                $details = "เพิ่มผู้ใช้งานใหม่: $username ($fullName)";
                $logStmt = $conn->prepare("INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, 'create_user', ?, ?)");
                $logStmt->bind_param("iss", $_SESSION['user_id'], $details, $ip);
                $logStmt->execute();
                $logStmt->close();

                $message = "เพิ่มผู้ใช้งาน \"$fullName\" เรียบร้อยแล้ว";
                $messageType = 'success';
            } else {
                $message = 'เกิดข้อผิดพลาดในการเพิ่มผู้ใช้งาน: ' . $conn->error;
                $messageType = 'danger';
            }
            $insertStmt->close();
        }
        $checkStmt->close();
    }
}

// ลบผู้ใช้งาน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $deleteId = (int)($_POST['user_id'] ?? 0);

    if ($deleteId === (int)$_SESSION['user_id']) {
        $message = 'ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้';
        $messageType = 'danger';
    } else {
        $delStmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $delStmt->bind_param("i", $deleteId);
        if ($delStmt->execute()) {
            $message = 'ลบผู้ใช้งานเรียบร้อยแล้ว';
            $messageType = 'success';
        } else {
            $message = 'เกิดข้อผิดพลาดในการลบผู้ใช้งาน: ' . $conn->error;
            $messageType = 'danger';
        }
        $delStmt->close();
    }
}

// ดึงรายชื่อผู้ใช้งานทั้งหมด
$users = [];
$result = $conn->query("SELECT id, username, name, role, created_at FROM users ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้ใช้งาน - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../includes/navbar.css">
    <style>
        :root {
            --primary-color: #1a4f72;
            --secondary-color: #2980b9;
            --success-color: #27ae60;
            --danger-color: #c0392b;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f5f5f5;
            color: var(--dark-color);
        }
        .main-content {
            padding: 2rem;
            max-width: 1000px;
            margin: 0 auto;
        }
        .card {
            border: none;
            border-radius: 0.25rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }
        .card-header {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        .card-header i {
            margin-right: 0.5rem;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .btn-primary:hover {
            background-color: #16405d;
            border-color: #16405d;
        }
        .badge-role-admin {
            background-color: var(--secondary-color);
            color: white;
        }
        .table thead th {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="main-content">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="fas fa-user-plus"></i> เพิ่มผู้ใช้งาน (ฝั่งแอดมิน)</div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_user">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="first_name">ชื่อ</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="last_name">นามสกุล</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="username">ชื่อผู้ใช้ (Username)</label>
                        <input type="text" class="form-control" id="username" name="username" pattern="[A-Za-z0-9_.]{3,50}" required>
                        <small class="form-text text-muted">ตัวอักษรอังกฤษ/ตัวเลข/_/. ความยาว 3-50 ตัวอักษร</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="password">รหัสผ่าน</label>
                            <input type="password" class="form-control" id="password" name="password" minlength="6" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="confirm_password">ยืนยันรหัสผ่าน</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="6" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> เพิ่มผู้ใช้งาน
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-users"></i> รายชื่อผู้ใช้งานทั้งหมด</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>Username</th>
                            <th>สิทธิ์</th>
                            <th>สร้างเมื่อ</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo (int)$user['id']; ?></td>
                                <td><?php echo htmlspecialchars($user['name']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><span class="badge badge-role-admin"><?php echo htmlspecialchars($user['role']); ?></span></td>
                                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                                <td class="text-center">
                                    <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                                        <form method="POST" action="" onsubmit="return confirm('ยืนยันการลบผู้ใช้งาน <?php echo htmlspecialchars(addslashes($user['name'])); ?> ?');" class="d-inline">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash-alt"></i> ลบ
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">บัญชีปัจจุบัน</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>

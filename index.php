<?php
// หน้าแรกของระบบ Emergency Picking
session_start();

// ตรวจสอบว่ามีการล็อกอินเป็นแอดมินหรือไม่
$isAdmin = isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบ Emergency Picking</title>
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
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .main-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 2.5rem 0;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            margin-bottom: 2rem;
        }
        
        .main-header h1 {
            font-weight: 700;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .main-header p {
            font-size: 1.2rem;
            max-width: 800px;
            margin: 0 auto;
            opacity: 0.9;
        }
        
        .main-content {
            flex: 1;
            padding: 2rem 1rem;
        }
        
        .action-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .action-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }
        
        .action-card .card-header {
            padding: 1.5rem;
            text-align: center;
            border: none;
        }
        
        .action-card .admin-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }
        
        .action-card .picker-header {
            background: linear-gradient(135deg, var(--success-color) 0%, #1abc9c 100%);
            color: white;
        }
        
        .action-card .tool-header {
            background: linear-gradient(135deg, var(--warning-color) 0%, var(--accent-color) 100%);
            color: white;
        }
        
        .action-card .icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .action-card .card-body {
            padding: 1.5rem;
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .action-card .description {
            color: #666;
            margin-bottom: 1.5rem;
        }
        
        .btn-lg {
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .btn-admin {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .btn-admin:hover {
            background: #16405d;
            border-color: #16405d;
            color: white;
        }
        
        .btn-picker {
            background: var(--success-color);
            border-color: var(--success-color);
            color: white;
        }
        
        .btn-picker:hover {
            background: #1e8449;
            border-color: #1e8449;
            color: white;
        }
        
        .btn-tool {
            background: var(--warning-color);
            border-color: var(--warning-color);
            color: white;
        }
        
        .btn-tool:hover {
            background: #d35400;
            border-color: #d35400;
            color: white;
        }
        
        .feature-section {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .feature-title {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-align: center;
            position: relative;
        }
        
        .feature-title::after {
            content: '';
            display: block;
            width: 80px;
            height: 3px;
            background: var(--primary-color);
            margin: 0.5rem auto 0;
        }
        
        .feature-item {
            display: flex;
            margin-bottom: 1.5rem;
            align-items: center;
            padding: 1rem;
            border-radius: 5px;
            transition: all 0.2s;
        }
        
        .feature-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .feature-icon {
            color: var(--primary-color);
            font-size: 2rem;
            margin-right: 1rem;
            flex-shrink: 0;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--light-color);
            border-radius: 50%;
        }
        
        .feature-content {
            flex: 1;
        }
        
        .feature-content h4 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .feature-content p {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 0;
        }
        
        .footer {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 1.5rem 0;
            margin-top: 3rem;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .footer p {
            margin-bottom: 0;
        }
        
        /* Responsive styles */
        @media (max-width: 768px) {
            .main-header h1 {
                font-size: 2rem;
            }
            
            .main-header p {
                font-size: 1rem;
            }
            
            .feature-item {
                flex-direction: column;
                text-align: center;
            }
            
            .feature-icon {
                margin-right: 0;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header Section -->
    <header class="main-header">
        <div class="container">
            <h1><i class="fas fa-warehouse"></i> ระบบ Emergency Picking (Operation TTV AAT-Wiring)</h1>
            <p>ระบบช่วยหยิบงานฉุกเฉิน ตรวจสอบความถูกต้อง ลดข้อผิดพลาด เพิ่มความแม่นยำในการทำงาน</p>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <!-- Action Cards -->
            <div class="row">
                <!-- Admin Card -->
                <div class="col-md-6">
                    <div class="action-card">
                        <div class="card-header admin-header">
                            <div class="icon"><i class="fas fa-user-shield"></i></div>
                            <h3>สำหรับแอดมิน</h3>
                        </div>
                        <div class="card-body">
                            <div class="description">
                                <p>จัดการระบบ อัปโหลดแผนงาน ดูรายงาน และจัดการผู้ใช้งาน</p>
                                <ul class="text-left">
                                    <li>อัปโหลดแผนงาน</li>
                                    <li>ดูรายงานความคืบหน้า</li>
                                    <li>ตรวจสอบประวัติการอัปโหลด</li>
                                </ul>
                            </div>
                            <?php if ($isAdmin): ?>
                                <a href="admin/" class="btn btn-lg btn-admin btn-block">
                                    <i class="fas fa-sign-in-alt"></i> เข้าสู่หน้าแอดมิน
                                </a>
                            <?php else: ?>
                                <a href="admin/login.php" class="btn btn-lg btn-admin btn-block">
                                    <i class="fas fa-lock"></i> เข้าสู่ระบบ
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Picker Card -->
                <div class="col-md-6">
                    <div class="action-card">
                        <div class="card-header picker-header">
                            <div class="icon"><i class="fas fa-dolly"></i></div>
                            <h3>สำหรับพนักงานหยิบงาน</h3>
                        </div>
                        <div class="card-body">
                            <div class="description">
                                <p>เริ่มกระบวนการหยิบงาน ดูความคืบหน้า และติดตามงาน</p>
                                <ul class="text-left">
                                    <li>เลือกกลุ่มงานที่ต้องการหยิบ</li>
                                    <li>ทำตามขั้นตอนที่ระบบแนะนำ</li>
                                    <li>พิมพ์ฉลากและตรวจสอบงาน</li>
                                </ul>
                            </div>
                            <a href="picker/" class="btn btn-lg btn-picker btn-block">
                                <i class="fas fa-hand-paper"></i> เข้าสู่หน้าหยิบงาน
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Features Section -->
            <div class="feature-section">
                <h3 class="feature-title">คุณสมบัติของระบบ</h3>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-check-double"></i>
                            </div>
                            <div class="feature-content">
                                <h4>ลดข้อผิดพลาดในการหยิบงาน</h4>
                                <p>ระบบตรวจสอบความถูกต้องทุกขั้นตอน ป้องกันการหยิบผิดชิ้นส่วน</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-tachometer-alt"></i>
                            </div>
                            <div class="feature-content">
                                <h4>ติดตามความคืบหน้าแบบเรียลไทม์</h4>
                                <p>ดูสถานะการหยิบงานแต่ละกลุ่มได้ทันที รู้ว่างานใดเสร็จแล้ว งานใดกำลังดำเนินการ</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-barcode"></i>
                            </div>
                            <div class="feature-content">
                                <h4>สร้างและพิมพ์ฉลากได้ทันที</h4>
                                <p>ระบบสร้างฉลากบาร์โค้ดและ QR Code เพื่อติดตามชิ้นส่วน</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-file-upload"></i>
                            </div>
                            <div class="feature-content">
                                <h4>นำเข้าข้อมูลง่ายด้วยไฟล์ CSV</h4>
                                <p>อัปโหลดแผนงานหยิบจากไฟล์ CSV ได้ทันที ตรวจสอบความถูกต้องโดยอัตโนมัติ</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Emergency Picking System - By Gan Anchaleepon</p>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
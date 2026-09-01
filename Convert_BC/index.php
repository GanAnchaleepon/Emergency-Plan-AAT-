<?php
// หน้าหลักของระบบ Convert BC
session_start();

require_once __DIR__ . '/../config/database.php';

// ส่วนประมวลผล Import Master
$import_master_result = '';
$import_master_info = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['master_file'])) {
    $file = $_FILES['master_file']['tmp_name'];
    $filename = $_FILES['master_file']['name'];
    $filetime = filemtime($file);
    $import_master_info = "<div class='alert alert-info'>ไฟล์ที่อัปโหลด: <strong>$filename</strong><br>วันที่ไฟล์: <strong>" . date('d/m/Y H:i:s', $filetime) . "</strong></div>";
    $delimiter = ",";
    // ใช้ $conn จาก config/database.php
    if ($conn->connect_error) {
        $import_master_result = "<div class='alert alert-danger'>Connection failed: " . $conn->connect_error . "</div>";
    } else {
        $filedate = date('Y-m-d H:i:s', $filetime);
        $stmt = $conn->prepare("INSERT INTO master_uploads (filename, filedate) VALUES (?, ?)");
        $stmt->bind_param("ss", $filename, $filedate);
        $stmt->execute();
        $stmt->close();
        $conn->query("TRUNCATE TABLE master_data");
        if (($handle = fopen($file, "r")) !== FALSE) {
            fgetcsv($handle); // ข้าม header
            $imported = 0;
            $updated = 0;
            $duplicate = 0;
            $duplicate_msgs = [];
            while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                if (count($data) >= 7) {
                    $part_group = isset($data[7]) ? $conn->real_escape_string($data[7]) : '';
                    $part_number = isset($data[2]) ? $conn->real_escape_string(str_replace('-', '', $data[2])) : '';
                    $supplier = isset($data[8]) ? $conn->real_escape_string($data[8]) : '';
                    $location_pick = isset($data[14]) ? $conn->real_escape_string($data[14]) : '';
                    if (!empty($part_number)) {
                        // ตรวจสอบข้อมูลซ้ำ
                        $sql_check = "SELECT location_pick FROM master_data WHERE part_group='$part_group' AND part_number='$part_number' AND supplier='$supplier' LIMIT 1";
                        $result_check = $conn->query($sql_check);
                        if ($result_check && $result_check->num_rows > 0) {
                            $row = $result_check->fetch_assoc();
                            if ($row['location_pick'] !== $location_pick) {
                                // location_pick ใหม่ ให้ update
                                $sql_update = "UPDATE master_data SET location_pick='$location_pick', created_at=NOW() WHERE part_group='$part_group' AND part_number='$part_number' AND supplier='$supplier'";
                                if ($conn->query($sql_update)) {
                                    $updated++;
                                }
                            } else {
                                // ข้อมูลซ้ำทุก field - เพิ่มข้อมูลละเอียดมากขึ้น
                                $duplicate++;
                                $duplicate_msgs[] = "Part Group: <strong>$part_group</strong>, Part Number: <strong>$part_number</strong>, Supplier: <strong>$supplier</strong>, Location: <strong>$location_pick</strong>";
                            }
                        } else {
                            // ไม่มีข้อมูลซ้ำ ให้ insert
                            $sql = "INSERT INTO master_data (part_group, part_number, supplier, location_pick) VALUES ('$part_group', '$part_number', '$supplier', '$location_pick')";
                            if ($conn->query($sql)) {
                                $imported++;
                            }
                        }
                    }
                }
            }
            fclose($handle);
            // แจ้งผลภาษาไทย แยกสี ละเอียดยิ่งขึ้น
            $import_master_result = "";

            // แสดงสรุปภาพรวมข้อมูล
            $total_records = $imported + $updated + $duplicate;
            if ($total_records > 0) {
                $import_master_result .= "<div class='alert alert-primary' style='border-left: 5px solid #007bff;'>
                    <h5><i class='fas fa-file-import'></i> สรุปผลการนำเข้าข้อมูล:</h5>
                    <div class='row'>
                        <div class='col-md-4 text-center'>
                            <div style='font-size: 24px; font-weight: bold; color: green;'>$imported</div>
                            <div>รายการใหม่</div>
                        </div>
                        <div class='col-md-4 text-center'>
                            <div style='font-size: 24px; font-weight: bold; color: blue;'>$updated</div>
                            <div>รายการอัปเดต</div>
                        </div>
                        <div class='col-md-4 text-center'>
                            <div style='font-size: 24px; font-weight: bold; color: orange;'>$duplicate</div>
                            <div>รายการซ้ำ</div>
                        </div>
                    </div>
                    <div class='mt-2'>จำนวนข้อมูลทั้งหมด: <strong>$total_records</strong> รายการ</div>
                </div>";
            }

            // รายละเอียดการนำเข้าข้อมูลใหม่
            if ($imported > 0) {
                $import_master_result .= "<div class='alert alert-success' style='border-left: 5px solid #28a745;'>
                    <h5><i class='fas fa-plus-circle'></i> นำเข้าข้อมูลใหม่สำเร็จ:</h5>
                    <div>จำนวน <span style='color:green; font-weight:bold; font-size: 18px;'>$imported</span> รายการ</div>
                    <small class='text-muted'>ข้อมูลที่ไม่เคยมีในระบบ (Part Group, Part Number, Supplier ไม่ซ้ำกับข้อมูลเดิม)</small>
                </div>";
            }

            // รายละเอียดการอัปเดตข้อมูล
            if ($updated > 0) {
                $import_master_result .= "<div class='alert alert-info' style='border-left: 5px solid #17a2b8;'>
                    <h5><i class='fas fa-sync-alt'></i> อัปเดตข้อมูล Location Pick:</h5>
                    <div>จำนวน <span style='color:blue; font-weight:bold; font-size: 18px;'>$updated</span> รายการ</div>
                    <small class='text-muted'>ข้อมูลที่มี Part Group, Part Number, Supplier ตรงกับข้อมูลเดิม แต่ Location Pick แตกต่าง</small>
                </div>";
            }

            // รายละเอียดข้อมูลซ้ำ
            if ($duplicate > 0) {
                $import_master_result .= "<div class='alert alert-warning' style='border-left: 5px solid #ffc107;'>
                    <h5><i class='fas fa-exclamation-triangle'></i> พบข้อมูลซ้ำ (ไม่นำเข้า):</h5>
                    <div>จำนวน <span style='color:orange; font-weight:bold; font-size: 18px;'>$duplicate</span> รายการ</div>
                    <small class='text-muted'>ข้อมูลที่มี Part Group, Part Number, Supplier และ Location Pick ตรงกับข้อมูลเดิมทั้งหมด</small>";
                
                // แสดงตัวอย่างข้อมูลซ้ำไม่เกิน 5 รายการทันที
                if (!empty($duplicate_msgs)) {
                    $sample_duplicates = array_slice($duplicate_msgs, 0, 5);
                    $import_master_result .= "<div class='mt-2'>
                        <h6>ตัวอย่างข้อมูลซ้ำ:</h6>
                        <ul style='font-size: 12px;'>";
                    foreach ($sample_duplicates as $msg) {
                        $import_master_result .= "<li>$msg</li>";
                    }
                    $import_master_result .= "</ul></div>";
                }
                
                // ถ้ามีรายการซ้ำมากกว่า 5 รายการ แสดงปุ่มให้ดูทั้งหมด
                if (count($duplicate_msgs) > 5) {
                    $import_master_result .= "<div class='mt-2'>
                        <button class='btn btn-sm btn-outline-warning' type='button' data-toggle='collapse' data-target='#collapseDuplicates'>
                            แสดงรายการซ้ำทั้งหมด (" . count($duplicate_msgs) . " รายการ)
                        </button>
                        <div class='collapse mt-2' id='collapseDuplicates'>
                            <div class='card card-body' style='max-height: 300px; overflow-y: auto;'>
                                <ul style='font-size: 12px; margin-bottom: 0;'>";
                    foreach ($duplicate_msgs as $msg) {
                        $import_master_result .= "<li>$msg</li>";
                    }
                    $import_master_result .= "</ul>
                            </div>
                        </div>
                    </div>";
                }
                
                $import_master_result .= "</div>";
            }

            // ถ้าไม่มีข้อมูลนำเข้าเลย
            if ($imported == 0 && $updated == 0 && $duplicate == 0) {
                $import_master_result .= "<div class='alert alert-danger' style='border-left: 5px solid #dc3545;'>
                    <h5><i class='fas fa-times-circle'></i> ไม่พบข้อมูลนำเข้า</h5>
                    <div>ไม่พบข้อมูลใหม่ที่สามารถนำเข้าได้ กรุณาตรวจสอบไฟล์ของคุณ</div>
                    <small class='text-muted'>อาจเกิดจากไฟล์ว่างเปล่า หรือรูปแบบข้อมูลไม่ถูกต้อง</small>
                </div>";
            }
        } else {
            $import_master_result = "<div class='alert alert-danger'>File not found or cannot open.</div>";
        }
        // ไม่ต้อง $conn->close();
    }
}

// ส่วนประมวลผล Convert SSC
$convert_ssc_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ssc_file'])) {
    // ใช้ชื่อไฟล์ที่อัปโหลดจริง
    $ssc_uploaded_name = $_FILES['ssc_file']['name'];
    $upload_name = $ssc_uploaded_name;
    if (move_uploaded_file($_FILES['ssc_file']['tmp_name'], $upload_name)) {
        $convert_ssc_result .= '<div class="alert alert-success">อัปโหลดไฟล์เรียบร้อย กำลัง Convert...</div>';
        // ใช้ $conn จาก config/database.php
        if ($conn->connect_error) {
            $convert_ssc_result .= "<div class='alert alert-danger'>Connection failed: " . $conn->connect_error . "</div>";
        } else {
            $ssc_file = $upload_name;
            $file_content = file_get_contents($ssc_file);
            $output_rows = [];
            $rotation_list = [];
            $blocks = explode("~LISTEN", $file_content);
            for ($i = 1; $i < count($blocks); $i++) {
                $block_content = $blocks[$i];
                $end_pos = strpos($block_content, "END");
                if ($end_pos === false) { continue; }
                $block_data = substr($block_content, 0, $end_pos + 3);

                // รองรับ rotation ทั้ง 3;30;1;1; และ 5;30;1;1; (เช่น HCTWA)
                if (!preg_match('/(?:3|5);30;1;1;"(\d+)/', $block_data, $rotation_matches)) { continue; }
                $rotation = trim($rotation_matches[1]);
                $rotation_list[] = $rotation;

                $date = '';
                $time = '';
                $model = '';
                if (preg_match('/(?:3|5);14;1;1;"([A-Z0-9]{3,})/', $block_data, $model_match)) {
                    $model = rtrim($model_match[1]);
                }
                if (preg_match('/34;2;1;1;"(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})"/', $block_data, $date_matches)) {
                    $date = $date_matches[1];
                    $time = $date_matches[2];
                } elseif (preg_match('/TRIM ON DATE \/ TIME"/i', $block_data) && preg_match('/\d+;21;1;1;"(\d{8})\s+(\d{6})/', $block_data, $trim_dt)) { // TRIM ON DATE / TIME รูปแบบ YYYYMMDD HHMMSS
                    $raw_d = $trim_dt[1];
                    $raw_t = $trim_dt[2];
                    $date = substr($raw_d,0,4) . '-' . substr($raw_d,4,2) . '-' . substr($raw_d,6,2);
                    $time = substr($raw_t,0,2) . ':' . substr($raw_t,2,2) . ':' . substr($raw_t,4,2);
                } elseif (preg_match('/\d+;19;1;1;"(\d{4}-\d{2}-\d{2})"/', $block_data, $date_only)) { // fallback HCTWA date only
                    $date = $date_only[1];
                }

                // ดึง Part Number จากคอลัมน์ 12 หรือ 26
                preg_match_all('/\d+;(?:12|26);1;1;"([A-Z0-9\-]+)/', $block_data, $part_matches);
                if (empty($part_matches[1])) { continue; }

                foreach ($part_matches[1] as $part_number_raw) {
                    $part_number = rtrim($part_number_raw);
                    $part_number_db = str_replace('-', '', $part_number);
                    if ($part_number_db === '') { continue; }
                    $sql = "SELECT part_group, supplier, location_pick FROM master_data WHERE part_number = '" . $conn->real_escape_string($part_number_db) . "' LIMIT 1";
                    $result = $conn->query($sql);
                    if ($result && $row = $result->fetch_assoc()) {
                        $date_for_output = $date ? date('d/m/Y', strtotime($date)) : date('d/m/Y');
                        $output_rows[] = [
                            $rotation,
                            $row['part_group'],
                            $part_number,
                            $row['supplier'],
                            $row['location_pick'],
                            $model,
                            $date_for_output,
                            $time
                        ];
                    }
                }
            }
            if (count($output_rows) > 0) {
                usort($output_rows, function($a, $b) {
                    return intval($a[0]) <=> intval($b[0]);
                });
                // ดึงช่วงตัวเลขจากชื่อไฟล์ .ssc ที่อัปโหลด เช่น 8500-8529
                $csv_range = '';
                if (preg_match('/(\d{4,})-(\d{4,})/', $ssc_uploaded_name, $matches)) {
                    $csv_range = $matches[1] . '-' . $matches[2];
                } else {
                    $csv_range = date('His'); // fallback กรณีไม่มีช่วงตัวเลขในชื่อไฟล์
                }
                $output_csv = "TTV AAT-Wiring Rotation {$csv_range}.csv";
                $output_file = fopen($output_csv, 'w');
                if ($output_file) {
                    fwrite($output_file, chr(0xEF).chr(0xBB).chr(0xBF));
                    fwrite($output_file, "Rotation,PartGroup,Part_Number,Supplier,Location Pick,Model,Date,Time\n");
                    foreach ($output_rows as $row) {
                        $csv_line = implode(",", $row) . "\n";
                        fwrite($output_file, $csv_line);
                    }
                    fclose($output_file);
                    $convert_ssc_result .= "<div class='alert alert-success'>ไฟล์ CSV สร้างเสร็จสมบูรณ์ (" . count($output_rows) . " แถว)</div>";
                    $convert_ssc_result .= "<div><a href='$output_csv' download='$output_csv'>ดาวน์โหลดไฟล์ผลลัพธ์ (.csv)</a></div>";
                }
            } else {
                $convert_ssc_result .= "<div class='alert alert-warning'>ไม่พบข้อมูลที่ match กับฐานข้อมูล Master</div>";
            }
            // ไม่ต้อง $conn->close();
        }
    } else {
        $convert_ssc_result .= '<div class="alert alert-danger">เกิดข้อผิดพลาดในการอัปโหลดไฟล์</div>';
    }
}

// ดึงข้อมูลการอัปโหลดล่าสุดจาก master_uploads โดยใช้ $conn จาก config/database.php
$latest_master_upload = '';
$sql = "SELECT filename, filedate, uploaded_at FROM master_uploads ORDER BY uploaded_at DESC LIMIT 1";
$result = $conn->query($sql);
if ($result && $row = $result->fetch_assoc()) {
    $latest_master_upload = "<div class='alert alert-secondary'>ไฟล์ล่าสุด: <strong>{$row['filename']}</strong><br>วันที่ไฟล์: <strong>" . date('d/m/Y H:i:s', strtotime($row['filedate'])) . "</strong><br>อัปโหลดเมื่อ: <strong>" . date('d/m/Y H:i:s', strtotime($row['uploaded_at'])) . "</strong></div>";
}

// เพิ่มตัวแปรสำหรับไฟล์ใหญ่และการแสดงผลความคืบหน้า
$max_execution_time = 300; // 5 นาที
ini_set('max_execution_time', $max_execution_time);
ini_set('memory_limit', '256M');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Convert BC - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../includes/navbar.css">
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

        /* Main Content */
        .main-content {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

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
        
        .tool-box {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        
        .tool-box:hover {
            transform: translateY(-3px);
        }
        
        .tool-box h3 {
            color: var(--primary-color);
            font-size: 1.4rem;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .tool-box p {
            color: #666;
            margin-bottom: 15px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: #16405d;
            border-color: #16405d;
        }
        
        /* เพิ่ม style สำหรับ progress bar */
        .api-sync-box {
            background: #f1f8f4;
            border: 1px solid #cfe8d8;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 15px;
        }

        .progress-container {
            margin-top: 15px;
            display: none;
        }
        
        .progress {
            height: 20px;
            margin-bottom: 10px;
        }
        
        .progress-bar {
            transition: width 0.3s ease;
        }
        
        .progress-status {
            font-size: 12px;
            text-align: center;
            margin-bottom: 5px;
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
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Warehouse Header -->
        <div class="warehouse-header">
            <div class="container">
                <h1><i class="fas fa-exchange-alt"></i> Convert BC (Operation TTV AAT-Wiring)</h1>
                <p>ระบบแปลงไฟล์ Barcode และข้อมูล Master สำหรับงาน Emergency Picking</p>
            </div>
        </div>

        <!-- ฟอร์ม Import Master และ Convert SSC -->
        <div class="row">
            <div class="col-md-6">
                <div class="tool-box">
                    <h3><i class="fas fa-database"></i> Import Master Data</h3>

                    <div class="api-sync-box">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <div>
                                <strong><i class="fas fa-cloud-download-alt"></i> ดึงจากระบบ FTM SEQ</strong>
                                <div class="small text-muted">รอบอัตโนมัติ: <span id="apiSchedule">-</span> น.</div>
                                <div class="small" id="apiCountdown"></div>
                            </div>
                            <button type="button" id="apiSyncBtn" class="btn btn-success btn-sm">
                                <i class="fas fa-sync-alt"></i> ดึงข้อมูลเดี๋ยวนี้
                            </button>
                        </div>
                        <div id="apiSyncStatus" class="small mt-2 text-muted">กำลังตรวจสอบสถานะ...</div>
                        <div id="apiSyncResult"></div>
                    </div>

                    <hr>
                    <p class="small text-muted mb-2">หรือนำเข้าจากไฟล์ CSV ด้วยตนเอง</p>
                    <form id="importMasterForm" method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="master_file">เลือกไฟล์ Master (.csv):</label>
                            <input type="file" name="master_file" id="master_file" class="form-control" accept=".csv" required>
                        </div>
                        <button type="submit" id="importBtn" class="btn btn-primary">Import Master Data</button>
                    </form>
                    
                    <!-- เพิ่ม Progress Bar สำหรับแสดงความคืบหน้า -->
                    <div class="progress-container" id="progressContainer">
                        <div class="progress-status">กำลังอัปโหลดไฟล์...</div>
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" aria-valuenow="0" aria-valuemin="0" 
                                 aria-valuemax="100" style="width: 0%">0%</div>
                        </div>
                        <div class="text-center" id="progressInfo">รอสักครู่...</div>
                    </div>
                    
                    <div id="importResultContainer">
                        <?php echo $import_master_info; ?>
                        <?php echo $latest_master_upload; ?>
                        <?php echo $import_master_result; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="tool-box">
                    <h3><i class="fas fa-sync"></i> Convert SSC File</h3>
                    <form id="convertSscForm" method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <label for="ssc_file">เลือกไฟล์ SSC (.ssc):</label>
                            <input type="file" name="ssc_file" id="ssc_file" class="form-control" accept=".ssc" required>
                        </div>
                        <button type="submit" id="convertBtn" class="btn btn-primary">Convert SSC File</button>
                    </form>
                    
                    <!-- เพิ่ม Progress Bar สำหรับแสดงความคืบหน้า -->
                    <div class="progress-container" id="convertProgressContainer" style="display:none;">
                        <div class="progress-status">กำลังอัปโหลดไฟล์...</div>
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                 role="progressbar" aria-valuenow="0" aria-valuemin="0" 
                                 aria-valuemax="100" style="width: 0%">0%</div>
                        </div>
                        <div class="text-center" id="convertProgressInfo">รอสักครู่...</div>
                    </div>
                    
                    <div id="convertResultContainer">
                        <?php echo $convert_ssc_result; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> คำแนะนำการใช้งาน
            </div>
            <div class="card-body">
                <h5>ขั้นตอนการใช้งาน:</h5>
                <ol>
                    <li>Import Master Data ก่อนเพื่อให้มีข้อมูลพื้นฐานในฐานข้อมูล</li>
                    <li>Upload ไฟล์ SSC เพื่อแปลงเป็นไฟล์ CSV</li>
                    <li>ตรวจสอบข้อมูลในไฟล์ CSV ที่ได้ว่าถูกต้องและครบถ้วน</li>
                    <li>นำไฟล์ CSV ไปใช้งานในระบบ Emergency Picking</li>
                </ol>
                <p class="mt-3 mb-0"><strong>หมายเหตุ:</strong> ระบบนี้ช่วยแปลงข้อมูลจากไฟล์ SSC (Re_TITAN_Raw) ให้เป็นรูปแบบที่เหมาะสมสำหรับการอัปโหลดเข้าระบบหยิบงานฉุกเฉิน</p>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    
    <!-- เพิ่ม script สำหรับจัดการการอัปโหลดและแสดงความคืบหน้า -->
    <script>
        // ดึง Master Data จาก FTM SEQ API
        document.addEventListener('DOMContentLoaded', function() {
            const syncBtn = document.getElementById('apiSyncBtn');
            const syncStatus = document.getElementById('apiSyncStatus');
            const syncResult = document.getElementById('apiSyncResult');
            const scheduleEl = document.getElementById('apiSchedule');
            const countdownEl = document.getElementById('apiCountdown');

            // นับถอยหลังในเบราว์เซอร์ล้วน ใช้ offset เทียบนาฬิกาเซิร์ฟเวอร์ ไม่ยิง request ซ้ำ
            let clockOffset = 0;
            let nextSlot = 0;
            let refreshedAfterSlot = false;

            function pad(n) { return String(n).padStart(2, '0'); }

            function tick() {
                if (!nextSlot) { return; }
                const now = Math.floor(Date.now() / 1000) + clockOffset;
                let left = nextSlot - now;

                if (left <= 0) {
                    countdownEl.innerHTML = '<span class="text-primary">ถึงรอบดึงข้อมูลแล้ว กำลังรอผล...</span>';
                    // ดึงสถานะซ้ำครั้งเดียวหลังเลยรอบไป 1 นาที เพื่อดูผลลัพธ์
                    if (!refreshedAfterSlot && left <= -60) {
                        refreshedAfterSlot = true;
                        loadStatus();
                    }
                    return;
                }

                const h = Math.floor(left / 3600);
                const m = Math.floor((left % 3600) / 60);
                const s = left % 60;
                countdownEl.innerHTML = 'รอบถัดไปอีก <strong>' + pad(h) + ':' + pad(m) + ':' + pad(s) + '</strong>';
            }

            setInterval(tick, 1000);

            function loadStatus() {
                fetch('api_sync_master.php?action=status')
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'ok') {
                            syncStatus.textContent = data.message || 'อ่านสถานะไม่สำเร็จ';
                            return;
                        }
                        scheduleEl.textContent = (data.schedule || []).join(' และ ');

                        clockOffset = data.server_time - Math.floor(Date.now() / 1000);
                        nextSlot = data.next_slot || 0;
                        refreshedAfterSlot = false;
                        tick();

                        if (!data.configured) {
                            syncBtn.disabled = true;
                            syncStatus.innerHTML = '<span class="text-danger">ยังไม่ได้ตั้งค่า — กรุณากรอก user/pass ในไฟล์ config/ftmseq.local.php</span>';
                            return;
                        }
                        if (!data.last_time) {
                            syncStatus.textContent = 'ยังไม่เคยดึงข้อมูล';
                            return;
                        }
                        const ok = data.last_status === 'success';
                        syncStatus.innerHTML = 'รอบล่าสุด: <strong>' + data.last_time + '</strong>'
                            + ' (' + (data.last_trigger === 'cron' ? 'อัตโนมัติ' : 'ดึงเอง') + ') '
                            + '<span class="' + (ok ? 'text-success' : 'text-danger') + '">' + data.last_message + '</span>';
                    })
                    .catch(err => { syncStatus.textContent = 'อ่านสถานะไม่สำเร็จ: ' + err.message; });
            }

            syncBtn.addEventListener('click', function() {
                if (!confirm('ระบบจะล้างข้อมูล Master เดิมแล้วแทนที่ด้วยข้อมูลจาก FTM SEQ ต้องการดำเนินการต่อหรือไม่?')) {
                    return;
                }
                syncBtn.disabled = true;
                syncResult.innerHTML = '';
                syncStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังดึงข้อมูลจาก FTM SEQ...';

                const body = new URLSearchParams({ action: 'run' });
                fetch('api_sync_master.php', { method: 'POST', body: body })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            syncResult.innerHTML = '<div class="alert alert-success mt-2 mb-0">' + data.message + '</div>';
                        } else {
                            syncResult.innerHTML = '<div class="alert alert-danger mt-2 mb-0">' + data.message + '</div>';
                        }
                        loadStatus();
                    })
                    .catch(err => {
                        syncResult.innerHTML = '<div class="alert alert-danger mt-2 mb-0">' + err.message + '</div>';
                        loadStatus();
                    })
                    .finally(() => { syncBtn.disabled = false; });
            });

            loadStatus();
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const importForm = document.getElementById('importMasterForm');
            const importBtn = document.getElementById('importBtn');
            const progressContainer = document.getElementById('progressContainer');
            const progressBar = document.querySelector('.progress-bar');
            const progressInfo = document.getElementById('progressInfo');
            const progressStatus = document.querySelector('.progress-status');
            const importResultContainer = document.getElementById('importResultContainer');
            
            importForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // ตรวจสอบว่ามีการเลือกไฟล์หรือไม่
                const fileInput = document.getElementById('master_file');
                if (fileInput.files.length === 0) {
                    alert('กรุณาเลือกไฟล์ Master (.csv)');
                    return;
                }
                
                // เตรียมข้อมูลสำหรับส่ง
                const formData = new FormData(importForm);
                
                // เปลี่ยนปุ่มและแสดง progress bar
                importBtn.disabled = true;
                progressContainer.style.display = 'block';
                importResultContainer.innerHTML = '';
                updateProgress(0, 'กำลังอัปโหลดไฟล์...');
                
                // ส่งข้อมูลแบบ AJAX
                fetch('import_master_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('เกิดข้อผิดพลาดในการอัปโหลด: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'uploading') {
                        // ถ้าอัปโหลดสำเร็จ เริ่มดึงสถานะการนำเข้าข้อมูล
                        updateProgress(10, 'อัปโหลดไฟล์เสร็จสิ้น กำลังประมวลผลข้อมูล...');
                        checkImportProgress();
                    } else {
                        // แสดงข้อความหากเกิดข้อผิดพลาด
                        showError(data.message || 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ');
                    }
                })
                .catch(error => {
                    showError(error.message);
                });
            });
            
            // ฟังก์ชันตรวจสอบความคืบหน้าการนำเข้าข้อมูล
            function checkImportProgress() {
                fetch('check_import_progress.php')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'processing') {
                        // อัปเดตความคืบหน้า
                        let percent = Math.min(10 + Math.floor(data.progress * 90 / 100), 99);
                        updateProgress(percent, `กำลังนำเข้าข้อมูล... (${data.current}/${data.total} รายการ)`);
                        
                        // ตรวจสอบอีกครั้งหลังจาก 1 วินาที
                        setTimeout(checkImportProgress, 1000);
                    } else if (data.status === 'completed') {
                        // นำเข้าข้อมูลเสร็จสิ้น
                        updateProgress(100, 'นำเข้าข้อมูลเสร็จสิ้น!');
                        
                        // แสดงผลลัพธ์
                        setTimeout(function() {
                            importResultContainer.innerHTML = data.result;
                            importBtn.disabled = false;
                            // ซ่อน progress bar หลังจาก 1 วินาที
                            setTimeout(function() {
                                progressContainer.style.display = 'none';
                            }, 1000);
                        }, 500);
                    } else {
                        // แสดงข้อความหากเกิดข้อผิดพลาด
                        showError(data.message || 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ');
                    }
                })
                .catch(error => {
                    showError(error.message);
                });
            }
            
            // ฟังก์ชันอัปเดต progress bar
            function updateProgress(percent, message) {
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
                progressBar.setAttribute('aria-valuenow', percent);
                progressInfo.textContent = message;
                
                // อัปเดตสีของ progress bar ตามความคืบหน้า
                if (percent < 30) {
                    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
                } else if (percent < 70) {
                    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary';
                } else {
                    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-success';
                }
            }
            
            // ฟังก์ชันแสดงข้อผิดพลาด
            function showError(message) {
                updateProgress(100, 'เกิดข้อผิดพลาด');
                progressBar.className = 'progress-bar progress-bar-striped bg-danger';
                importResultContainer.innerHTML = `<div class='alert alert-danger'>${message}</div>`;
                importBtn.disabled = false;
                
                // ซ่อน progress bar หลังจาก 3 วินาที
                setTimeout(function() {
                    progressContainer.style.display = 'none';
                }, 3000);
            }

            // Convert SSC Form script
            const convertForm = document.getElementById('convertSscForm');
            const convertBtn = document.getElementById('convertBtn');
            const convertProgressContainer = document.getElementById('convertProgressContainer');
            const convertProgressBar = convertProgressContainer.querySelector('.progress-bar');
            const convertProgressInfo = document.getElementById('convertProgressInfo');
            const convertProgressStatus = convertProgressContainer.querySelector('.progress-status');
            const convertResultContainer = document.getElementById('convertResultContainer');
            
            convertForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // ตรวจสอบว่ามีการเลือกไฟล์หรือไม่
                const fileInput = document.getElementById('ssc_file');
                if (fileInput.files.length === 0) {
                    alert('กรุณาเลือกไฟล์ SSC (.ssc)');
                    return;
                }
                
                // เตรียมข้อมูลสำหรับส่ง
                const formData = new FormData(convertForm);
                
                // เปลี่ยนปุ่มและแสดง progress bar
                convertBtn.disabled = true;
                convertProgressContainer.style.display = 'block';
                convertResultContainer.innerHTML = '';
                updateConvertProgress(0, 'กำลังอัปโหลดไฟล์...');
                
                // ส่งข้อมูลแบบ AJAX
                fetch('convert_ssc_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('เกิดข้อผิดพลาดในการอัปโหลด: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'uploading') {
                        // ถ้าอัปโหลดสำเร็จ เริ่มดึงสถานะการแปลงไฟล์
                        updateConvertProgress(10, 'อัปโหลดไฟล์เสร็จสิ้น กำลังประมวลผลข้อมูล...');
                        checkConvertProgress();
                    } else {
                        // แสดงข้อความหากเกิดข้อผิดพลาด
                        showConvertError(data.message || 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ');
                    }
                })
                .catch(error => {
                    showConvertError(error.message);
                });
            });
            
            // ฟังก์ชันตรวจสอบความคืบหน้าการแปลงไฟล์
            function checkConvertProgress() {
                fetch('check_convert_progress.php')
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'processing') {
                        // อัปเดตความคืบหน้า
                        let percent = Math.min(10 + Math.floor(data.progress * 90 / 100), 99);
                        updateConvertProgress(percent, data.message || 'กำลังประมวลผลข้อมูล...');
                        
                        // ตรวจสอบอีกครั้งหลังจาก 1 วินาที
                        setTimeout(checkConvertProgress, 1000);
                    } else if (data.status === 'completed') {
                        // แปลงไฟล์เสร็จสิ้น
                        updateConvertProgress(100, 'แปลงไฟล์เสร็จสิ้น!');
                        
                        // แสดงผลลัพธ์
                        setTimeout(function() {
                            convertResultContainer.innerHTML = data.result;
                            convertBtn.disabled = false;
                            // ซ่อน progress bar หลังจาก 1 วินาที
                            setTimeout(function() {
                                convertProgressContainer.style.display = 'none';
                            }, 1000);
                        }, 500);
                    } else {
                        // แสดงข้อความหากเกิดข้อผิดพลาด
                        showConvertError(data.message || 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ');
                    }
                })
                .catch(error => {
                    showConvertError(error.message);
                });
            }
            
            // ฟังก์ชันอัปเดต progress bar สำหรับการแปลงไฟล์
            function updateConvertProgress(percent, message) {
                convertProgressBar.style.width = percent + '%';
                convertProgressBar.textContent = percent + '%';
                convertProgressBar.setAttribute('aria-valuenow', percent);
                convertProgressInfo.textContent = message;
                
                // อัปเดตสีของ progress bar ตามความคืบหน้า
                if (percent < 30) {
                    convertProgressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
                } else if (percent < 70) {
                    convertProgressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary';
                } else {
                    convertProgressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-success';
                }
            }
            
            // ฟังก์ชันแสดงข้อผิดพลาดสำหรับการแปลงไฟล์
            function showConvertError(message) {
                updateConvertProgress(100, 'เกิดข้อผิดพลาด');
                convertProgressBar.className = 'progress-bar progress-bar-striped bg-danger';
                convertResultContainer.innerHTML = `<div class='alert alert-danger'>${message}</div>`;
                convertBtn.disabled = false;
                
                // ซ่อน progress bar หลังจาก 3 วินาที
                setTimeout(function() {
                    convertProgressContainer.style.display = 'none';
                }, 3000);
            }
        });
    </script>
</body>
</html>
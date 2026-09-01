<?php
// ไฟล์สำหรับรับการอัปโหลดไฟล์ Master Data แบบ AJAX
session_start();

// ตั้งค่าเพื่อรองรับไฟล์ขนาดใหญ่
ini_set('max_execution_time', 300); // 5 นาที
ini_set('memory_limit', '256M');

// ตรวจสอบว่ามีการส่งไฟล์มาหรือไม่
if (!isset($_FILES['master_file'])) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบไฟล์ที่อัปโหลด']);
    exit;
}

// เรียกใช้ database connection
require_once __DIR__ . '/../config/database.php';

// เช็คการเชื่อมต่อกับฐานข้อมูล
if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อกับฐานข้อมูล: ' . $conn->connect_error]);
    exit;
}

try {
    $file = $_FILES['master_file']['tmp_name'];
    $filename = $_FILES['master_file']['name'];
    $filetime = filemtime($file);
    $filedate = date('Y-m-d H:i:s', $filetime);
    
    // บันทึกข้อมูลการอัปโหลดลงตาราง master_uploads
    $stmt = $conn->prepare("INSERT INTO master_uploads (filename, filedate) VALUES (?, ?)");
    $stmt->bind_param("ss", $filename, $filedate);
    $stmt->execute();
    $stmt->close();
    
    // ตั้งค่าไฟล์ session สำหรับการประมวลผล
    $_SESSION['import_file'] = [
        'path' => $file,
        'name' => $filename,
        'time' => $filetime,
        'total_lines' => 0,
        'current_line' => 0,
        'imported' => 0,
        'updated' => 0, 
        'duplicate' => 0,
        'duplicate_msgs' => [],
        'status' => 'waiting'
    ];
    
    // นับจำนวนบรรทัดทั้งหมดในไฟล์
    $lineCount = 0;
    $handle = fopen($file, "r");
    if ($handle) {
        while (!feof($handle)) {
            fgets($handle);
            $lineCount++;
        }
        fclose($handle);
    }
    
    $_SESSION['import_file']['total_lines'] = max(1, $lineCount - 1); // ลบ header ออก 1 บรรทัด
    $_SESSION['import_file']['status'] = 'ready';
    
    // เริ่มประมวลผลในพื้นหลัง
    // ในการใช้งานจริงอาจจะต้องใช้ background process หรือ queue ถ้าเป็นไฟล์ใหญ่มาก
    
    // ส่งผลกลับไปว่าอัปโหลดสำเร็จ
    echo json_encode([
        'status' => 'uploading', 
        'filename' => $filename,
        'filedate' => $filedate,
        'total_lines' => $_SESSION['import_file']['total_lines']
    ]);
    
    // เริ่มประมวลผลข้อมูลโดยไม่รอให้ request นี้เสร็จสิ้น
    $_SESSION['import_file']['status'] = 'processing';
    session_write_close();
    
    // ล้างข้อมูลเก่า
    $conn->query("TRUNCATE TABLE master_data");
    
    // เริ่มประมวลผลข้อมูล
    $imported = 0;
    $updated = 0;
    $duplicate = 0;
    $duplicate_msgs = [];
    $delimiter = ",";
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        fgetcsv($handle); // ข้าม header
        $current_line = 0;
        
        while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
            $current_line++;
            
            // อัปเดตความคืบหน้าทุก 100 บรรทัด
            if ($current_line % 100 === 0) {
                session_start();
                $_SESSION['import_file']['current_line'] = $current_line;
                $_SESSION['import_file']['imported'] = $imported;
                $_SESSION['import_file']['updated'] = $updated;
                $_SESSION['import_file']['duplicate'] = $duplicate;
                session_write_close();
            }
            
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
                            // ข้อมูลซ้ำทุก field
                            $duplicate++;
                            if (count($duplicate_msgs) < 100) { // เก็บเฉพาะ 100 รายการแรกเพื่อไม่ให้ session ใหญ่เกินไป
                                $duplicate_msgs[] = "Part Group: <strong>$part_group</strong>, Part Number: <strong>$part_number</strong>, Supplier: <strong>$supplier</strong>, Location: <strong>$location_pick</strong>";
                            }
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
        
        // อัปเดตข้อมูลสุดท้าย
        session_start();
        $_SESSION['import_file']['current_line'] = $current_line;
        $_SESSION['import_file']['imported'] = $imported;
        $_SESSION['import_file']['updated'] = $updated;
        $_SESSION['import_file']['duplicate'] = $duplicate;
        $_SESSION['import_file']['duplicate_msgs'] = $duplicate_msgs;
        $_SESSION['import_file']['status'] = 'completed';
        session_write_close();
    }
    
} catch (Exception $e) {
    // บันทึกข้อผิดพลาด
    error_log('Import error: ' . $e->getMessage());
    
    // อัปเดต session ว่าเกิดข้อผิดพลาด
    session_start();
    $_SESSION['import_file']['status'] = 'error';
    $_SESSION['import_file']['error_message'] = $e->getMessage();
    session_write_close();
    
    // ส่งข้อผิดพลาดกลับไป (ถ้า client ยังรอรับข้อมูลอยู่)
    if (!headers_sent()) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>

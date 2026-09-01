<?php
// ไฟล์สำหรับรับการอัปโหลดไฟล์ SSC แบบ AJAX
session_start();

// ตั้งค่าเพื่อรองรับไฟล์ขนาดใหญ่
ini_set('max_execution_time', 300); // 5 นาที
ini_set('memory_limit', '256M');

// ตรวจสอบว่ามีการส่งไฟล์มาหรือไม่
if (!isset($_FILES['ssc_file'])) {
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
    // ใช้ชื่อไฟล์ที่อัปโหลดจริง
    $ssc_uploaded_name = $_FILES['ssc_file']['name'];
    $upload_name = $ssc_uploaded_name;
    if (move_uploaded_file($_FILES['ssc_file']['tmp_name'], $upload_name)) {
        // ตั้งค่า session สำหรับการติดตามความคืบหน้า
        $_SESSION['convert_ssc'] = [
            'file_path' => $upload_name,
            'file_name' => $_FILES['ssc_file']['name'],
            'total_blocks' => 0,
            'processed_blocks' => 0,
            'found_matches' => 0,
            'total_part_numbers' => 0,
            'output_rows' => [],
            'status' => 'uploading',
            'start_time' => microtime(true)
        ];
        
        // อ่านไฟล์เพื่อนับจำนวนบล็อกทั้งหมด
        $file_content = file_get_contents($upload_name);
        $blocks = explode("~LISTEN", $file_content);
        $_SESSION['convert_ssc']['total_blocks'] = count($blocks) - 1; // ลบบล็อกแรกออก
        
        // ส่งข้อมูลสถานะกลับไป
        echo json_encode([
            'status' => 'uploading',
            'message' => 'อัปโหลดไฟล์สำเร็จ กำลังเริ่มประมวลผล',
            'total_blocks' => $_SESSION['convert_ssc']['total_blocks']
        ]);
        
        // เริ่มประมวลผลในพื้นหลัง
        $_SESSION['convert_ssc']['status'] = 'processing';
        session_write_close();
        
        // เริ่มประมวลผลข้อมูลตามที่มีอยู่เดิมในไฟล์ index.php
        $output_rows = [];
        
        for ($i = 1; $i < count($blocks); $i++) {
            $block_content = $blocks[$i];
            $end_pos = strpos($block_content, "END");
            
            // อัปเดตความคืบหน้าทุก 10 บล็อก
            if ($i % 10 === 0) {
                session_start();
                $_SESSION['convert_ssc']['processed_blocks'] = $i;
                session_write_close();
            }
            
            if ($end_pos !== false) {
                $block_data = substr($block_content, 0, $end_pos + 3);
                
                // รองรับทั้งรูปแบบเดิม (3;30;...) และรูปแบบ HCTWA (5;30;...)
                if (preg_match('/(?:3|5);30;1;1;"(\d+)/', $block_data, $rotation_matches)) {
                    $rotation = trim($rotation_matches[1]);
                    $date = '';
                    $time = '';
                    $model = '';

                    // ดึง Model (เช่น P703, U704) อยู่ที่ (3|5);14;1;1;"CODE"
                    if (preg_match('/(?:3|5);14;1;1;"([A-Z0-9]{3,})/', $block_data, $model_match)) {
                        $model = rtrim($model_match[1]);
                    }

                    // 1) รูปแบบเดิม: มีวันที่+เวลา อยู่ที่ 34;2;1;1;
                    if (preg_match('/34;2;1;1;"(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})"/', $block_data, $date_matches)) {
                        $date = $date_matches[1];
                        $time = $date_matches[2];
                    }
                    // 2) รูปแบบ TRIM ON DATE / TIME (เช่น 20250708 061051) พบคู่บรรทัด  ;3;1;1;"TRIM ON DATE / TIME"  และ ;21;1;1;"YYYYMMDD HHMMSS"
                    if ($date === '' && preg_match('/TRIM ON DATE \/ TIME"/i', $block_data)) {
                        if (preg_match('/\d+;21;1;1;"(\d{8})\s+(\d{6})/', $block_data, $trim_dt)) {
                            $raw_d = $trim_dt[1]; // YYYYMMDD
                            $raw_t = $trim_dt[2]; // HHMMSS
                            $date = substr($raw_d,0,4) . '-' . substr($raw_d,4,2) . '-' . substr($raw_d,6,2);
                            $time = substr($raw_t,0,2) . ':' . substr($raw_t,2,2) . ':' . substr($raw_t,4,2);
                        }
                    }
                    // 3) Fallback: วันที่อย่างเดียว (เช่น HCTWA) อยู่คอลัมน์ ;19;1;1;
                    if ($date === '' && preg_match('/\d+;19;1;1;"(\d{4}-\d{2}-\d{2})"/', $block_data, $date_only)) {
                        $date = $date_only[1];
                        $time = '';
                    }

                    // รองรับ Part Number ทั้งที่คอลัมน์ 12 และ 26 (HCTWA ใช้ 26)
                    preg_match_all('/\d+;(?:12|26);1;1;"([A-Z0-9\-]+)/', $block_data, $part_matches);

                    if (isset($part_matches[1]) && !empty($part_matches[1])) {
                        foreach ($part_matches[1] as $part_number_raw) {
                            $part_number = rtrim($part_number_raw); // ตัดช่องว่างท้าย
                            $part_number_db = str_replace('-', '', $part_number);
                            if ($part_number_db === '') { continue; }
                            $sql = "SELECT part_group, supplier, location_pick FROM master_data WHERE part_number = '" . $conn->real_escape_string($part_number_db) . "' LIMIT 1";
                            $result = $conn->query($sql);

                            if ($result && $row = $result->fetch_assoc()) {
                                // ถ้าไม่มีวันที่ให้ใช้วันที่ปัจจุบันเป็น default เพื่อไม่ให้ว่าง
                                $date_for_output = $date ? date('d/m/Y', strtotime($date)) : date('d/m/Y');
                                $output_rows[] = [
                                    $rotation,                 // Rotation
                                    $row['part_group'],        // PartGroup
                                    $part_number,              // Part_Number (raw with dashes)
                                    $row['supplier'],          // Supplier
                                    $row['location_pick'],     // Location Pick
                                    $model,                    // Model (ใหม่)
                                    $date_for_output,          // Date
                                    $time                      // Time
                                ];

                                session_start();
                                $_SESSION['convert_ssc']['found_matches']++;
                                session_write_close();
                            }
                        }
                    }
                }
            }
        }
        
        if (count($output_rows) > 0) {
            usort($output_rows, function($a, $b) {
                return intval($a[0]) <=> intval($b[0]);
            });
            // ใช้ min/max ของ Rotation ที่พบในไฟล์ .ssc
            $rotation_list = array_column($output_rows, 0); // column 0 คือ Rotation
            if (!empty($rotation_list)) {
                $csv_range = min($rotation_list) . '-' . max($rotation_list);
            } else {
                $csv_range = date('His'); // fallback กรณีไม่มีข้อมูล
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

                // ส่งไฟล์ .csv ไปยัง admin/upload.php โดยอัตโนมัติ
                // NOTE: admin/upload.php ต้องมีทั้งไฟล์ csv_file และพารามิเตอร์โพสต์ชื่อ upload_csv ถึงจะประมวลผล
                $uploadUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/../admin/upload.php';
                if (file_exists($output_csv)) {
                    $curl = curl_init();
                    $cfile = new CURLFile($output_csv, 'text/csv', basename($output_csv));
                    // สามารถเปิด/ปิด auto forward ได้ด้วยตัวแปรนี้
                    $autoForward = true; // ตั้ง false หากไม่ต้องการอัปโหลดอัตโนมัติ
                    $autoDummy  = true;  // ตั้ง false หากไม่ต้องการสร้าง Dummy Rotation อัตโนมัติใน upload.php
                    if ($autoForward) {
                        // ใส่ upload_csv => 1 เพื่อให้ upload.php เข้าเงื่อนไขการประมวลผลไฟล์
                        $postFields = [
                            'csv_file'   => $cfile,
                            'upload_csv' => '1',
                            // auto_dummy = 1 ให้ upload.php เรียก logic สร้าง Dummy Rotation (GROUP 03,05,09L,09R)
                            'auto_dummy' => $autoDummy ? '1' : '0'
                        ];
                        curl_setopt($curl, CURLOPT_URL, $uploadUrl);
                        curl_setopt($curl, CURLOPT_POST, 1);
                        curl_setopt($curl, CURLOPT_POSTFIELDS, $postFields);
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        // ส่ง session cookie เฉพาะถ้ามีการล็อกอิน admin
                        if (isset($_COOKIE[session_name()])) {
                            curl_setopt($curl, CURLOPT_COOKIE, session_name() . '=' . $_COOKIE[session_name()]);
                        }
                        $response = curl_exec($curl);
                        // บันทึก log เบื้องต้น (เงียบหาก curl ปิดใช้งาน)
                        if ($response === false) {
                            error_log('Auto-upload CSV failed: ' . curl_error($curl));
                        } else {
                            error_log('Auto-upload CSV response length: ' . strlen($response));
                        }
                        curl_close($curl);
                    }
                }

                // บันทึกผลลัพธ์ลงใน session
                session_start();
                $_SESSION['convert_ssc']['output_rows'] = $output_rows;
                $_SESSION['convert_ssc']['output_csv'] = $output_csv;
                $_SESSION['convert_ssc']['status'] = 'completed';
                $_SESSION['convert_ssc']['end_time'] = microtime(true);
                session_write_close();
            }
        } else {
            // ไม่พบข้อมูลที่ match
            session_start();
            $_SESSION['convert_ssc']['status'] = 'completed';
            $_SESSION['convert_ssc']['end_time'] = microtime(true);
            session_write_close();
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์']);
    }
} catch (Exception $e) {
    // บันทึกข้อผิดพลาด
    error_log('Convert SSC error: ' . $e->getMessage());
    
    // อัปเดต session ว่าเกิดข้อผิดพลาด
    session_start();
    $_SESSION['convert_ssc']['status'] = 'error';
    $_SESSION['convert_ssc']['error_message'] = $e->getMessage();
    session_write_close();
    
    // ส่งข้อผิดพลาดกลับไป (ถ้า client ยังรอรับข้อมูลอยู่)
    if (!headers_sent()) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
?>

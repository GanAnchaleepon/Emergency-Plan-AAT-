<?php
// convert_ssc.php
// อ่านไฟล์ Re_TITAN_Raw_8500-8529.ssc, Match กับ Master, สร้างไฟล์ Rol 8500-8529.csv

require_once __DIR__ . '/../config/database.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$result_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ssc_file'])) {
    $upload_name = 'Re_TITAN_Raw_8500-8529.ssc';
    if (move_uploaded_file($_FILES['ssc_file']['tmp_name'], $upload_name)) {
    $result_message .= '<div class="result" id="convert-status">อัปโหลดไฟล์เรียบร้อย กำลัง Convert...</div>';

        // --- Convert Logic ---
        require_once __DIR__ . '/../config/database.php';

        $conn = new mysqli($servername, $username, $password, $dbname);
        if ($conn->connect_error) {
            $result_message .= "<div class='error'>Connection failed: " . $conn->connect_error . "</div>";
        } else {
            $ssc_file = $upload_name;
            $output_csv = 'Rol 8500-8529.csv';

            // อ่านไฟล์ทั้งหมดเป็นข้อความเดียว
            $file_content = file_get_contents($ssc_file);
            
            // สร้างไฟล์ CSV ใหม่
            $output = fopen($output_csv, 'w');
            if (!$output) {
                $result_message .= "<div class='error'>ไม่สามารถเปิดไฟล์เพื่อเขียน: {$output_csv}</div>";
            } else {
                // ใส่ BOM UTF-8 เพื่อรองรับ Excel/ภาษาไทย
                fwrite($output, chr(0xEF).chr(0xBB).chr(0xBF));
                fwrite($output, ",Rotation,PartGroup,Part_Number,Supplier,Location Pick,Date,Time\n");
                fclose($output);
            }
            
            // สำหรับ Debug
            $debug_info = [];
            $found_matches = 0;
            $total_part_numbers = 0;
            $output_rows = []; // เก็บข้อมูลที่จะเขียนลงไฟล์
            $listen_count = 0; // นับจำนวน ~LISTEN ที่เจอ
            $end_count = 0; // นับจำนวน END ที่เจอ
            $rotations_found = [];
            
            // แบ่งไฟล์ตาม ~LISTEN เป็นบล็อกๆ
            $blocks = explode("~LISTEN", $file_content);
            
            // เริ่มจาก index 1 เพราะ index 0 จะเป็นข้อมูลก่อน ~LISTEN แรก
            for ($i = 1; $i < count($blocks); $i++) {
                $listen_count++;
                $block_content = $blocks[$i];
                
                // หาตำแหน่ง END
                $end_pos = strpos($block_content, "END");
                if ($end_pos !== false) {
                    $end_count++;
                    // ตัดเอาเฉพาะส่วนก่อน END
                    $block_data = substr($block_content, 0, $end_pos + 3); // +3 เพื่อรวม "END"
                    
                    // หา Rotation รองรับทั้ง 3;30 และ 5;30
                    if (preg_match('/(?:3|5);30;1;1;"(\d+)/', $block_data, $rotation_matches)) {
                        $rotation = trim($rotation_matches[1]);
                        $rotations_found[] = $rotation;
                        $debug_info[] = "พบ Rotation: {$rotation} ในบล็อก {$i}";

                        // หา Date / Time หลายรูปแบบ
                        $date = '';
                        $time = '';
                        // รูปแบบเดิม YYYY-MM-DD HH:MM:SS ที่คอลัมน์ 34;2;1;1;
                        if (preg_match('/34;2;1;1;"(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})"/', $block_data, $dt_match)) {
                            $date = $dt_match[1];
                            $time = $dt_match[2];
                            $debug_info[] = "พบ Date/Time (รูปแบบหลัก): {$date} {$time} ในบล็อก {$i}";
                        }
                        // รูปแบบ TRIM ON DATE / TIME เช่น บรรทัด 'TRIM ON DATE' แล้วมีคอลัมน์ 21;1;1; "YYYYMMDD HHMMSS"
                        if ($date === '' && strpos($block_data, 'TRIM ON DATE') !== false && preg_match('/\d+;21;1;1;"(\d{8})\s+(\d{6})"/', $block_data, $trim_dt)) {
                            $rawDate = $trim_dt[1]; // YYYYMMDD
                            $rawTime = $trim_dt[2]; // HHMMSS
                            $date = substr($rawDate,0,4) . '-' . substr($rawDate,4,2) . '-' . substr($rawDate,6,2);
                            $time = substr($rawTime,0,2) . ':' . substr($rawTime,2,2) . ':' . substr($rawTime,4,2);
                            $debug_info[] = "พบ Date/Time (TRIM): {$date} {$time} ในบล็อก {$i}";
                        }
                        // รูปแบบมีแต่วันที่คอลัมน์ 19;1;1;
                        if ($date === '' && preg_match('/\d+;19;1;1;"(\d{4}-\d{2}-\d{2})"/', $block_data, $date_only)) {
                            $date = $date_only[1];
                            $time = '';
                            $debug_info[] = "พบ Date only: {$date} ในบล็อก {$i}";
                        }

                        // หา Part Numbers ทั้งหมด (คอลัมน์ 12 หรือ 26) เช่น HCTWA ใช้ 26
                        $part_numbers = [];
                        preg_match_all('/\d+;(?:12|26);1;1;"([A-Z0-9\-]+)/', $block_data, $part_matches);
                        if (isset($part_matches[1]) && !empty($part_matches[1])) {
                            foreach ($part_matches[1] as $part_number_raw) {
                                $part_number = trim($part_number_raw);
                                $part_numbers[] = $part_number;
                                $total_part_numbers++;
                                $debug_info[] = "พบ Part Number: {$part_number} ใน Rotation {$rotation}";
                            }

                            foreach ($part_numbers as $part_number) {
                                $part_number_db = str_replace('-', '', $part_number);
                                $sql = "SELECT part_group, supplier, location_pick FROM master_data WHERE part_number = '" . $conn->real_escape_string($part_number_db) . "' LIMIT 1";
                                $result = $conn->query($sql);
                                if ($result && $row = $result->fetch_assoc()) {
                                    $found_matches++;
                                    $date_for_output = $date ? date('d/m/Y', strtotime($date)) : date('d/m/Y');
                                    $output_row = [
                                        $rotation,
                                        $row['part_group'],
                                        $part_number,
                                        $row['supplier'],
                                        $row['location_pick'],
                                        $date_for_output,
                                        $time
                                    ];
                                    $output_rows[] = $output_row;
                                } else {
                                    $debug_info[] = "ไม่พบข้อมูลที่ตรงกันสำหรับ: {$part_number} (ไม่มีขีด: {$part_number_db}) ใน Rotation {$rotation}";
                                }
                            }
                        } else {
                            $debug_info[] = "ไม่พบ Part Number (คอลัมน์ 12 หรือ 26) ใน Rotation {$rotation}";
                        }
                    }
                }
            }
            
            // เรียงข้อมูลตาม Rotation
            if (count($output_rows) > 0) {
                usort($output_rows, function($a, $b) {
                    return intval($a[0]) <=> intval($b[0]);
                });
                
                // เขียนไฟล์ CSV
                $output_file = fopen($output_csv, 'w');
                if ($output_file) {
                    // ใส่ BOM UTF-8
                    fwrite($output_file, chr(0xEF).chr(0xBB).chr(0xBF));
                    
                    // เขียนหัวตาราง
                    fwrite($output_file, ",Rotation,PartGroup,Part_Number,Supplier,Location Pick,Date,Time\n");
                    
                    // เขียนข้อมูลทั้งหมด
                    $row_number = 1;
                    $rows_written = 0;
                    
                    // Debug ตรวจสอบก่อนเขียนไฟล์
                    $debug_info[] = "กำลังเขียนข้อมูลทั้งหมด " . count($output_rows) . " แถวลงไฟล์ CSV";
                    
                    foreach ($output_rows as $row) {
                        // เตรียมข้อมูลสำหรับเขียนลงไฟล์
                        $csv_line = $row_number . ",";
                        
                        // ข้อมูล Rotation
                        $csv_line .= $row[0] . ",";
                        
                        // ข้อมูล PartGroup, Part_Number, Supplier, Location Pick
                        for ($i = 1; $i < 5; $i++) {
                            $field = isset($row[$i]) ? $row[$i] : '';
                            if (strpos($field, ',') !== false || strpos($field, ' ') !== false) {
                                $csv_line .= '"' . str_replace('"', '""', $field) . '",';
                            } else {
                                $csv_line .= $field . ",";
                            }
                        }
                        
                        // ข้อมูล Date และ Time
                        $date = isset($row[5]) ? $row[5] : '';
                        $time = isset($row[6]) ? $row[6] : '';
                        $csv_line .= $date . "," . $time . "\n";
                        
                        // เขียนข้อมูลลงไฟล์
                        if (fwrite($output_file, $csv_line) !== false) {
                            $rows_written++;
                        }
                        
                        $row_number++;
                    }
                    
                    fclose($output_file);
                    
                    $debug_info[] = "จำนวนแถวที่เขียนลงไฟล์ CSV: {$rows_written}";
                    
                    $result_message .= "<p style='color:green'>ไฟล์ CSV สร้างเสร็จสมบูรณ์ (" . $rows_written . " แถว)</p>";
                    $result_message .= "<div class='result'><a href='Rol 8500-8529.csv' download='Rol 8500-8529.csv'>ดาวน์โหลดไฟล์ผลลัพธ์ (.csv)</a></div>";
                }
            }
            
            // แสดงข้อมูล Debug
            $result_message .= "<div class='debug-info'>";
            $result_message .= "<h3>ข้อมูล Debug</h3>";
            $result_message .= "<p>จำนวนบล็อกที่พบในไฟล์ SSC: " . (count($blocks) - 1) . "</p>";
            $result_message .= "<p>จำนวน ~LISTEN ที่พบ: {$listen_count}</p>";
            $result_message .= "<p>จำนวน END ที่พบ: {$end_count}</p>";
            $result_message .= "<p>จำนวน block ที่ประมวลผล: {$end_count}</p>";
            $result_message .= "<p>จำนวน Part Number ที่พบใน SSC: {$total_part_numbers}</p>";
            $result_message .= "<p>จำนวนที่ match กับฐานข้อมูล Master: {$found_matches}</p>";
            $result_message .= "<p>จำนวนแถวที่จะเขียนลงไฟล์ CSV: " . count($output_rows) . "</p>";
            
            // แสดงสรุป Rotations ที่พบทั้งหมด
            if (!empty($rotations_found)) {
                $unique_rotations = array_unique($rotations_found);
                sort($unique_rotations, SORT_NUMERIC);
                $result_message .= "<p>Rotations ที่พบทั้งหมด (" . count($unique_rotations) . "): " . implode(", ", $unique_rotations) . "</p>";
            } else {
                $result_message .= "<p style='color:red'>ไม่พบ Rotation ใดๆ!</p>";
            }
            
            // แสดงตัวอย่างข้อมูลที่จะเขียนลง CSV
            if (count($output_rows) > 0) {
                $result_message .= "<p>ตัวอย่างข้อมูลที่จะเขียนลงไฟล์ CSV (5 รายการแรก):</p>";
                $result_message .= "<table border='1' cellpadding='3' style='border-collapse: collapse; margin-top: 10px;'>";
                $result_message .= "<tr><th>ลำดับ</th><th>Rotation</th><th>PartGroup</th><th>Part_Number</th><th>Supplier</th><th>Location Pick</th><th>วันที่</th><th>เวลา</th></tr>";
                
                $count = 0;
                $row_num = 1;
                foreach ($output_rows as $row) {
                    if ($count < 5) {
                        $result_message .= "<tr>";
                        $result_message .= "<td>" . $row_num . "</td>";
                        foreach ($row as $cell) {
                            $result_message .= "<td>" . htmlspecialchars($cell) . "</td>";
                        }
                        $result_message .= "</tr>";
                        $count++;
                        $row_num++;
                    } else {
                        break;
                    }
                }
                $result_message .= "</table>";
                
                // แสดงสรุป Rotations ที่พบในไฟล์ CSV
                $rotations = array_unique(array_column($output_rows, 0));
                sort($rotations, SORT_NUMERIC);
                $result_message .= "<p>Rotations ที่พบในไฟล์ CSV: " . implode(", ", $rotations) . "</p>";
            }
            $result_message .= "</div>";
        }
    } else {
        $result_message .= '<div class="error">เกิดข้อผิดพลาดในการอัปโหลดไฟล์</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Convert SSC File</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #f8f8f8; }
        .container { max-width: 800px; margin: 40px auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 2px 8px #ccc; }
        h2 { color: #2c3e50; }
        h3 { color: #34495e; margin-top: 30px; }
        .result { margin-top: 20px; color: #27ae60; }
        .error { color: #c0392b; }
        .debug-info { margin-top: 30px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px; }
        .debug-info ul { margin-top: 5px; }
    </style>
</head>
<body>
<div class="container">
    <h2>Convert SSC File</h2>
    <form action="" method="post" enctype="multipart/form-data">
        <input type="file" name="ssc_file" accept=".ssc" required>
        <button type="submit">Convert</button>
    </form>
    <?php echo $result_message; ?>
</div>
</body>
</html>
</body>
</html>
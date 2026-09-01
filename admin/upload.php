<?php
// หน้าอัปโหลดไฟล์ CSV สำหรับ Admin
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ตรวจสอบการล็อกอิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// เชื่อมต่อฐานข้อมูล
require_once '../config/database.php';

// --- เพิ่มส่วนจัดการดาวน์โหลด Template CSV ---
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'emergency_picking_template.csv';

    // กำหนด Headers สำหรับการดาวน์โหลดไฟล์ CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // สร้าง output stream
    $output = fopen('php://output', 'w');

    // กำหนด BOM สำหรับ UTF-8 เพื่อให้ Excel เปิดได้ถูกต้อง
    fwrite($output, "\xEF\xBB\xBF");

    // กำหนดหัวคอลัมน์ (ต้องตรงกับที่ mapColumns คาดหวังหรือใกล้เคียง)
    // แก้ไขหัวคอลัมน์ตามที่ผู้ใช้ต้องการ
    // เพิ่ม Date, Time ตามความต้องการใหม่
    $headers = ['Rotation', 'Part_Group', 'Part_Number', 'Supplier', 'Location Pick', 'Model', 'Date', 'Time'];
    fputcsv($output, $headers);

    // เพิ่มตัวอย่างข้อมูล (ไม่บังคับ แต่ช่วยให้ผู้ใช้เข้าใจ)
    // แก้ไขข้อมูลตัวอย่างให้ตรงกับหัวคอลัมน์ใหม่
    $today = date('d/m/Y');
    $example_data = [
        ['8574', 'GROUP 11R', 'N1WB-16450-CB', 'ARK', 'PFA-02-01', 'U704', $today, '08:30:00'],
        ['8575', 'GROUP 01', 'RB3T-14290-ABC', 'SEWT', 'RACK-A-01', 'P703', $today, '08:30:30'],
    ];

    foreach ($example_data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit(); // หยุดการทำงานหลังจากส่งไฟล์
}
// --- สิ้นสุดส่วนจัดการดาวน์โหลด Template CSV ---

// เพิ่ม Config ค่า Max Position เริ่มต้น (คล้ายกับใน position_mapping.php)
$defaultMaxPositions = [
    'Group01' => 16,
    'Group02' => 16,
    'Group03' => 16,
    'Group04' => 8,
    'Group05' => 20,
    'Group06L' => 20,
    'Group06R' => 20,
    'Group07' => 16,
    'Group09L' => 12,
    'Group09R' => 12,
    'Group10' => 16,
    'Group11' => 16
];
$fallbackDefaultMaxPosition = 18; // ค่า default หากกลุ่มไม่ได้อยู่ใน config ข้างบน

// ข้อความแจ้งเตือน
$alert = '';
$alertType = '';
$errors = [];

// ลบข้อมูลทั้งหมด
if (isset($_POST['clear_all_data']) && $_POST['confirm_clear'] === 'yes') {
    try {
        // เริ่ม Transaction
        mysqli_begin_transaction($conn);
        
        // ลบข้อมูลจากตารางต่างๆ
        $tables = ['picking_activities', 'picking_plans', 'uploads'];
        
        foreach ($tables as $table) {
            $sql = "DELETE FROM `$table`";
            if (!mysqli_query($conn, $sql)) {
                throw new Exception("ไม่สามารถลบข้อมูลในตาราง $table: " . mysqli_error($conn));
            }
        }
        
        // รีเซ็ต AUTO_INCREMENT
        foreach ($tables as $table) {
            $sql = "ALTER TABLE `$table` AUTO_INCREMENT = 1";
            mysqli_query($conn, $sql);
        }
        
        // บันทึกประวัติการลบข้อมูล
        $logSql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
        $logStmt = mysqli_prepare($conn, $logSql);
        $action = "clear_data";
        $details = "ล้างข้อมูล: all";
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        
        mysqli_stmt_bind_param($logStmt, "isss", $_SESSION['user_id'], $action, $details, $ipAddress);
        mysqli_stmt_execute($logStmt);
        
        // Commit Transaction
        mysqli_commit($conn);
        
        $alert = "ลบข้อมูลทั้งหมดสำเร็จ!";
        $alertType = "success";
    } catch (Exception $e) {
        // Rollback หากเกิดข้อผิดพลาด
        mysqli_rollback($conn);
        
        $alert = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
        $alertType = "danger";
    }
}

// ฟังก์ชันตรวจสอบข้อมูลซ้ำในไฟล์ CSV
function checkDuplicatesInCSV($csvData, $headerMap) {
    $uniqueKeys = [];
    $duplicates = [];
    
    // ดัชนีของคอลัมน์ที่ใช้ตรวจสอบ
    $rotationIndex = isset($headerMap['rotation']) ? $headerMap['rotation'] : -1;
    $groupIndex = isset($headerMap['group_name']) ? $headerMap['group_name'] : -1;
    $partNumberIndex = isset($headerMap['part_number']) ? $headerMap['part_number'] : -1;
    $locationIndex = isset($headerMap['location']) ? $headerMap['location'] : -1;
    $modelIndex = isset($headerMap['model']) ? $headerMap['model'] : -1; // เพิ่ม Model Index
    $dateIndex = isset($headerMap['date']) ? $headerMap['date'] : -1;   // Date Index (optional)
    $timeIndex = isset($headerMap['time']) ? $headerMap['time'] : -1;   // Time Index (optional)
    
    // ตรวจสอบว่ามีดัชนีที่จำเป็นครบหรือไม่
    if ($rotationIndex < 0 || $groupIndex < 0 || $partNumberIndex < 0) {
        return [
            'error' => 'ไม่พบคอลัมน์ที่จำเป็นสำหรับการตรวจสอบข้อมูลซ้ำ',
            'duplicate_count' => 0
        ];
    }
    
    // ตรวจสอบแต่ละแถวในไฟล์ CSV (ข้ามแถวแรกซึ่งเป็นหัวตาราง)
    for ($i = 1; $i < count($csvData); $i++) {
        $row = $csvData[$i];
        
        // ข้ามแถวที่ไม่มีข้อมูลครบตามคอลัมน์ที่แมปได้สูงสุด
    $maxIndex = max($rotationIndex, $groupIndex, $partNumberIndex, $locationIndex, $modelIndex, $dateIndex, $timeIndex);
        if (count($row) <= $maxIndex) {
             // บันทึก log หากแถวมีคอลัมน์น้อยกว่าที่คาดหวัง
            error_log("Warning: Row " . ($i + 1) . " has fewer columns (" . count($row) . ") than expected (max index: $maxIndex). Skipping row.");
            continue;
        }
        
        $rotation = trim($row[$rotationIndex]);
        $group = trim($row[$groupIndex]);
        $partNumber = trim($row[$partNumberIndex]);
        $location = ($locationIndex >= 0 && isset($row[$locationIndex])) ? trim($row[$locationIndex]) : '';
    $model = ($modelIndex >= 0 && isset($row[$modelIndex])) ? trim($row[$modelIndex]) : '';
    $dateVal = ($dateIndex >= 0 && isset($row[$dateIndex])) ? trim($row[$dateIndex]) : '';
    $timeVal = ($timeIndex >= 0 && isset($row[$timeIndex])) ? trim($row[$timeIndex]) : '';
        
        // ข้ามแถวที่มีข้อมูลสำคัญไม่ครบ
        if (empty($rotation) || empty($group) || empty($partNumber)) {
             error_log("Warning: Row " . ($i + 1) . " is missing required data (Rotation, Group, or Part Number). Skipping row.");
            continue;
        }
        
        // สร้างคีย์เพื่อตรวจสอบข้อมูลซ้ำในไฟล์ CSV (รวม Model)
    // ไม่ใส่ date/time ใน key เพื่อตรวจซ้ำ (ถือว่า rotation+group+part+location+model คือเอกลักษณ์)
    $key = $rotation . '|' . $group . '|' . $partNumber . '|' . $location . '|' . $model;
        
        if (isset($uniqueKeys[$key])) {
            // พบข้อมูลซ้ำในไฟล์
            $duplicates[] = [
                'row' => $i + 1, // +1 เพราะแถวเริ่มที่ 1 ไม่ใช่ 0
                'rotation' => $rotation,
                'group' => $group,
                'part_number' => $partNumber,
                'location' => $location,
                'model' => $model,
                'date' => $dateVal,
                'time' => $timeVal,
                'duplicate_of_row' => $uniqueKeys[$key]['row']
            ];
        } else {
            // บันทึกข้อมูลนี้เป็นข้อมูลต้นฉบับ
            $uniqueKeys[$key] = [
                'row' => $i + 1,
                'data' => $row
            ];
        }
    }
    
    return [
        'duplicates' => $duplicates,
        'unique_count' => count($uniqueKeys),
        'duplicate_count' => count($duplicates),
        'unique_keys' => $uniqueKeys
    ];
}

// ฟังก์ชันตรวจสอบข้อมูลซ้ำในฐานข้อมูล
function checkDuplicatesInDatabase($data, $headerMap, $conn) {
    $duplicates = [];
    $nonDuplicates = []; // เพิ่มการเก็บข้อมูลที่ไม่ซ้ำ
    
    // ตรวจสอบว่าเชื่อมต่อฐานข้อมูลถูกต้องหรือไม่
    if (!$conn) {
        return [
            'error' => 'ไม่สามารถเชื่อมต่อกับฐานข้อมูล',
            'duplicate_count' => 0,
            'non_duplicate_count' => 0
        ];
    }
    
    // ดัชนีของคอลัมน์ที่ใช้ตรวจสอบ
    $rotationIndex = isset($headerMap['rotation']) ? $headerMap['rotation'] : -1;
    $groupIndex = isset($headerMap['group_name']) ? $headerMap['group_name'] : -1;
    $partNumberIndex = isset($headerMap['part_number']) ? $headerMap['part_number'] : -1;
    $locationIndex = isset($headerMap['location']) ? $headerMap['location'] : -1;
    $modelIndex = isset($headerMap['model']) ? $headerMap['model'] : -1; // เพิ่ม Model Index
    $dateIndex = isset($headerMap['date']) ? $headerMap['date'] : -1;   // Date Index
    $timeIndex = isset($headerMap['time']) ? $headerMap['time'] : -1;   // Time Index
    
    // ตรวจสอบว่ามีดัชนีที่จำเป็นครบหรือไม่
    if ($rotationIndex < 0 || $groupIndex < 0 || $partNumberIndex < 0) {
        return [
            'error' => 'ไม่พบคอลัมน์ที่จำเป็นสำหรับการตรวจสอบข้อมูลซ้ำ',
            'duplicate_count' => 0,
            'non_duplicate_count' => 0
        ];
    }
    
    try {
        // ตรวจสอบแต่ละแถวในไฟล์ CSV (ข้ามแถวแรกซึ่งเป็นหัวตาราง)
        for ($i = 1; $i < count($data); $i++) {
            $row = $data[$i];
            
            // ข้ามแถวที่ไม่มีข้อมูลครบตามคอลัมน์ที่แมปได้สูงสุด
            // ปรับ maxIndex ให้รวมเฉพาะคอลัมน์ที่ใช้ในการตรวจสอบซ้ำใน DB
            $maxIndexCheck = max($rotationIndex, $groupIndex, $partNumberIndex, $locationIndex);
             if (count($row) <= $maxIndexCheck) {
                 // บันทึก log หากแถวมีคอลัมน์น้อยกว่าที่คาดหวัง
                error_log("Warning: Row " . ($i + 1) . " has fewer columns (" . count($row) . ") than expected (max index for check: $maxIndexCheck). Skipping row for DB check.");
                continue;
            }
            
            $rotation = mysqli_real_escape_string($conn, trim($row[$rotationIndex]));
            $group = mysqli_real_escape_string($conn, trim($row[$groupIndex]));
            $partNumber = mysqli_real_escape_string($conn, trim($row[$partNumberIndex]));
            $location = ($locationIndex >= 0 && isset($row[$locationIndex])) ? 
                       mysqli_real_escape_string($conn, trim($row[$locationIndex])) : '';
        $model = ($modelIndex >= 0 && isset($row[$modelIndex])) ? 
            mysqli_real_escape_string($conn, trim($row[$modelIndex])) : '';
        $dateVal = ($dateIndex >= 0 && isset($row[$dateIndex])) ? mysqli_real_escape_string($conn, trim($row[$dateIndex])) : '';
        $timeVal = ($timeIndex >= 0 && isset($row[$timeIndex])) ? mysqli_real_escape_string($conn, trim($row[$timeIndex])) : '';
            
            // ข้ามแถวที่มีข้อมูลสำคัญไม่ครบ
            if (empty($rotation) || empty($group) || empty($partNumber)) {
                 error_log("Warning: Row " . ($i + 1) . " is missing required data (Rotation, Group, or Part Number). Skipping row for DB check.");
                continue;
            }
            
            // ตรวจสอบว่ามีข้อมูลนี้ในฐานข้อมูลหรือไม่ (ไม่รวม Model ในเงื่อนไข WHERE)
            $sql = "SELECT id FROM picking_plans WHERE 
                    rotation = '$rotation' AND 
                    group_name = '$group' AND 
                    part_number = '$partNumber'";
            
            // เพิ่มเงื่อนไข location ถ้ามีข้อมูล
            if (!empty($location)) {
                $sql .= " AND location = '$location'";
            } else {
                 // ถ้า location ใน CSV เป็นค่าว่าง ให้ตรวจสอบว่าใน DB เป็นค่าว่างหรือ NULL
                 $sql .= " AND (location = '' OR location IS NULL)";
            }
            
            // *** ลบคอลัมน์ model ออกจากเงื่อนไข WHERE ***
            // if (!empty($model)) {
            //      $sql .= " AND model = '$model'";
            // } else {
            //      // ถ้า model ใน CSV เป็นค่าว่าง ให้ตรวจสอบว่าใน DB เป็นค่าว่างหรือ NULL
            //      $sql .= " AND (model = '' OR model IS NULL)";
            // }
            
            $result = mysqli_query($conn, $sql);
            
            if ($result && mysqli_num_rows($result) > 0) {
                // พบข้อมูลซ้ำในฐานข้อมูล
                $duplicateRow = mysqli_fetch_assoc($result);
                $duplicates[] = [
                    'row' => $i + 1,
                    'original_row' => $row,
                    'rotation' => $rotation,
                    'group' => $group,
                    'part_number' => $partNumber,
                    'location' => $location,
                    'model' => $model, // เพิ่ม Model (สำหรับแสดงผล)
                    'existing_id' => $duplicateRow['id'],
                    'date' => $dateVal,
                    'time' => $timeVal
                ];
            } else {
                // ไม่พบข้อมูลซ้ำ - เก็บไว้สำหรับการอัปโหลด
                $nonDuplicates[] = [
                    'row' => $i + 1,
                    'original_row' => $row,
                    'rotation' => $rotation,
                    'group' => $group,
                    'part_number' => $partNumber,
                    'location' => $location,
                    'model' => $model,
                    'date' => $dateVal,
                    'time' => $timeVal
                ];
            }
        }
        
        return [
            'duplicates' => $duplicates,
            'duplicate_count' => count($duplicates),
            'non_duplicates' => $nonDuplicates,
            'non_duplicate_count' => count($nonDuplicates)
        ];
    } catch (Exception $e) {
        return [
            'error' => 'เกิดข้อผิดพลาดในการตรวจสอบข้อมูลซ้ำ: ' . $e->getMessage(),
            'duplicate_count' => 0,
            'non_duplicate_count' => 0
        ];
    }
}

// แปลงชื่อคอลัมน์เป็น index - แก้ไขให้แมปถูกต้องกับข้อมูลจริง
function mapColumns($headers) {
    $columnMap = [];
    
    // ตรวจสอบคอลัมน์พิเศษตามชื่อที่ต้องตรงแน่นอน
    for ($i = 0; $i < count($headers); $i++) {
        $headerLower = strtolower(trim($headers[$i]));
        if ($headerLower === 'part_number') {
            $columnMap['part_number'] = $i;
            error_log("แมป part_number กับคอลัมน์ '{$headers[$i]}' (index: $i) แบบตรงชื่อพอดี");
        }
        else if ($headerLower === 'part_group') {
            $columnMap['group_name'] = $i;
            error_log("แมป group_name กับคอลัมน์ '{$headers[$i]}' (index: $i) แบบตรงชื่อพอดี");
        }
        else if ($headerLower === 'model') { // เพิ่มการแมป Model
            $columnMap['model'] = $i;
            error_log("แมป model กับคอลัมน์ '{$headers[$i]}' (index: $i) แบบตรงชื่อพอดี");
        }
        else if ($headerLower === 'date') {
            $columnMap['date'] = $i;
            error_log("แมป date กับคอลัมน์ '{$headers[$i]}' (index: $i) แบบตรงชื่อพอดี");
        }
        else if ($headerLower === 'time') {
            $columnMap['time'] = $i;
            error_log("แมป time กับคอลัมน์ '{$headers[$i]}' (index: $i) แบบตรงชื่อพอดี");
        }
    }
    
    // แก้ไขการแมปปิ้งให้ตรงกับโครงสร้างไฟล์ CSV
    $expectedColumns = [
        'rotation' => ['rotation', 'rot', 'rotation_number', 'rotation_id'],
        'group_name' => ['part_group', 'group', 'grp', 'group_name', 'groupname'],
        'part_number' => ['part_number', 'part_no', 'part', 'partnumber', 'pn', 'partno'],
        'part_name' => ['part_name', 'name', 'description', 'desc', 'part_description'],
        'quantity' => ['quantity', 'qty', 'amount', 'count'],
        'supplier' => ['supplier', 'sup', 'vendor', 'supplier_name'],
        'location' => ['location', 'loc', 'position', 'pos', 'storage', 'location pick'], // เพิ่ม 'location pick'
    'model' => ['model', 'mdl', 'car_model'],
    'date' => ['date', 'plan_date', 'dt'],
    'time' => ['time', 'plan_time', 'tm']
    ];

    // เพิ่มล็อกแสดงการแมปปิ้ง
    $logFile = fopen("column_mapping.log", "a");
    fwrite($logFile, "========= " . date('Y-m-d H:i:s') . " =========\n");
    fwrite($logFile, "คอลัมน์ที่พบในไฟล์ CSV:\n");
    foreach ($headers as $index => $header) {
        fwrite($logFile, "[$index] $header\n");
    }
    
    // ข้ามคอลัมน์ที่มีการแมปพิเศษแล้ว
    foreach ($expectedColumns as $key => $variations) {
        // ข้ามถ้าได้แมปแล้วจากการตรวจสอบพิเศษ
        if (isset($columnMap[$key])) {
            continue;
        }
        
        foreach ($headers as $index => $header) {
            $header = strtolower(trim($header));
            foreach ($variations as $variation) {
                if ($header === $variation || strpos($header, $variation) !== false) {
                    $columnMap[$key] = $index;
                    fwrite($logFile, "แมปฟิลด์ $key กับคอลัมน์ $header (index: $index)\n");
                    break 2;
                }
            }
        }
    }
    
    // ถ้าไม่พบการแมปตามปกติ ให้ใช้ตำแหน่งคอลัมน์ตามลำดับ (ปรับปรุงลำดับให้ตรงกับตัวอย่าง)
    // ตัวอย่าง: Rotation, PartGroup, Part_Number, Supplier, Location Pick, Model
    $defaultOrder = ['rotation', 'group_name', 'part_number', 'supplier', 'location', 'model', 'date', 'time'];
    $headerCount = count($headers);

    foreach ($defaultOrder as $index => $key) {
        if (!isset($columnMap[$key]) && $index < $headerCount) {
            $columnMap[$key] = $index;
            fwrite($logFile, "ใช้ตำแหน่งเริ่มต้น: $key = คอลัมน์ $index\n");
        }
    }
    
    // ตรวจสอบความถูกต้องของ part_number และ group_name (ยังคงไว้เผื่อกรณีชื่อคอลัมน์ไม่ตรง)
    if (isset($columnMap['part_number']) && isset($columnMap['group_name'])) {
        $partNumIdx = $columnMap['part_number'];
        $groupIdx = $columnMap['group_name'];
        
        // หากพบว่า part_number และ group_name อาจถูกสลับกัน
        if ($partNumIdx === 1 && $groupIdx === 2) {
            // ตรวจสอบตัวอย่างข้อมูลหากมี
            if (isset($headers[1]) && stripos($headers[1], 'group') !== false &&
                isset($headers[2]) && stripos($headers[2], 'part') !== false) {
                // สลับกลับให้ถูกต้อง
                $columnMap['part_number'] = 2;
                $columnMap['group_name'] = 1;
                fwrite($logFile, "แก้ไขการแมปผิด: สลับ part_number(1) และ group_name(2)\n");
            }
        }
    }
    
    fwrite($logFile, "ผลลัพธ์การแมปปิ้ง: " . json_encode($columnMap, JSON_UNESCAPED_UNICODE) . "\n\n");
    fclose($logFile);
    
    return $columnMap;
}

// ฟังก์ชันสำหรับดึงค่า last_position ของแต่ละกลุ่ม
function getLastPositions($conn) {
    $lastPositions = [];
    
    // 1. ดึงค่า max_position จาก group_settings เพื่อใช้เป็นค่าพื้นฐาน
    $sql_settings = "SELECT group_name, max_position FROM group_settings";
    $result_settings = $conn->query($sql_settings);
    if ($result_settings) {
        while ($row = $result_settings->fetch_assoc()) {
            $lastPositions[$row['group_name']] = [
                'last_position' => 0, // ค่าเริ่มต้น (จะถูกแทนที่ด้วยข้อมูลจริง)
                'max_position' => (int)$row['max_position']
            ];
        }
    }
    
    // 2. ดึง position ล่าสุดที่ใช้จริงจาก picking_plans สำหรับแต่ละกลุ่ม
    // โดยดึงจากแถวที่มี ID ล่าสุด (ล่าสุดที่เพิ่มเข้าไป) สำหรับแต่ละกลุ่ม
    $sql = "SELECT p1.group_name, p1.position, p1.rotation 
            FROM picking_plans p1
            INNER JOIN (
                SELECT group_name, MAX(id) as max_id
                FROM picking_plans
                GROUP BY group_name
            ) p2 ON p1.id = p2.max_id";
    
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $groupName = $row['group_name'];
            $lastPosition = (int)$row['position'];
            $rotation = $row['rotation'];
            
            if (isset($lastPositions[$groupName])) {
                $lastPositions[$groupName]['last_position'] = $lastPosition;
                $lastPositions[$groupName]['last_rotation'] = $rotation;
            } else {
                // ถ้าไม่มีในกลุ่มที่มีในตาราง group_settings ให้เพิ่มใหม่
                global $fallbackDefaultMaxPosition;
                $lastPositions[$groupName] = [
                    'last_position' => $lastPosition,
                    'max_position' => $fallbackDefaultMaxPosition,
                    'last_rotation' => $rotation
                ];
            }
            
            // บันทึกข้อมูลเพื่อการตรวจสอบ
            error_log("กลุ่ม $groupName: Position ล่าสุด = $lastPosition, Rotation = $rotation");
        }
    }
    
    // 3. อัปเดตค่า last_position ใน group_settings ให้ตรงกับค่าที่ดึงได้จาก picking_plans
    foreach ($lastPositions as $groupName => $data) {
        if (isset($data['last_position']) && $data['last_position'] > 0) {
            $updateSql = "UPDATE group_settings SET last_position = ? WHERE group_name = ?";
            $updateStmt = $conn->prepare($updateSql);
            $lastPosition = $data['last_position'];
            $updateStmt->bind_param("is", $lastPosition, $groupName);
            $updateStmt->execute();
            $updateStmt->close();
            
            error_log("อัปเดต last_position ใน group_settings สำหรับกลุ่ม $groupName เป็น $lastPosition");
        }
    }
    
    return $lastPositions;
}

// ฟังก์ชันสำหรับอัปเดตค่า last_position และ max_position (ถ้าเป็นกลุ่มใหม่) ของแต่ละกลุ่ม
function updateLastPositions($conn, $newLastPositionsData, $userId, $defaultMaxPositions, $fallbackDefaultMaxPosition) {
    // ใช้ UPSERT เพื่อทำทั้ง INSERT และ UPDATE ในคำสั่งเดียว
    $stmt = $conn->prepare("
        INSERT INTO group_settings (group_name, max_position, last_position, created_by) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE last_position = VALUES(last_position)
    ");

    foreach ($newLastPositionsData as $groupName => $data) {
        $lastPosition = $data['last_position'];
        $maxPosition = $data['max_position']; 

        $stmt->bind_param("siii", $groupName, $maxPosition, $lastPosition, $userId);
        
        if ($stmt->execute()) {
            error_log("อัปเดต/เพิ่มข้อมูลสำหรับกลุ่ม $groupName: max_position=$maxPosition, last_position=$lastPosition");
        } else {
            error_log("ERROR: ไม่สามารถอัปเดต/เพิ่มข้อมูลสำหรับกลุ่ม $groupName: " . $stmt->error);
        }
    }
    
    $stmt->close();
    
    // บันทึก log การตรวจสอบหลังอัปเดต
    $sql = "SELECT group_name, last_position, max_position FROM group_settings 
            WHERE group_name IN ('" . implode("','", array_keys($newLastPositionsData)) . "')";
    $result = $conn->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            error_log("หลังอัปเดต: กลุ่ม {$row['group_name']} - last_position={$row['last_position']}, max_position={$row['max_position']}");
        }
    }
}

// ประมวลผลการอัปโหลดเฉพาะข้อมูลที่ไม่ซ้ำ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_non_duplicates']) && isset($_SESSION['non_duplicates'])) {
    try {
        // เริ่ม Transaction
        mysqli_begin_transaction($conn);
        
        $nonDuplicatesFromSession = $_SESSION['non_duplicates']; // เปลี่ยนชื่อตัวแปรเพื่อความชัดเจน
        $headerMap = $_SESSION['header_map'];
        $fileName = $_SESSION['file_name'];
        
        // บันทึกประวัติการอัปโหลด
        $uploadSql = "INSERT INTO uploads (filename, uploaded_by, rows_total, rows_success, rows_duplicate, rows_error) 
                    VALUES (?, ?, ?, ?, ?, ?)";
        $uploadStmt = mysqli_prepare($conn, $uploadSql);
        
        $uploadedBy = $_SESSION['user_id'];
        $totalRows = count($nonDuplicatesFromSession); // จำนวนแถวไม่ซ้ำเท่านั้น
        $rowsSuccess = 0; // จะอัปเดตภายหลัง
        $rowsDuplicate = 0; // ไม่มีข้อมูลซ้ำ
        $rowsError = 0;
        
        mysqli_stmt_bind_param($uploadStmt, "ssiiis", $fileName, $uploadedBy, $totalRows, $rowsSuccess, $rowsDuplicate, $rowsError);
        mysqli_stmt_execute($uploadStmt);
        $uploadId = mysqli_insert_id($conn);
        
        // ดึงค่า last_position และ max_position ของแต่ละกลุ่มจาก DB
        error_log("=== เริ่มกระบวนการอัปโหลดข้อมูลที่ไม่ซ้ำ ===");
        $lastPositionsFromDB = getLastPositions($conn);
        $adminUserId = $_SESSION['user_id'];
        
        // Debug: แสดงค่า last_position ที่ดึงมา
        error_log("ค่า last_position ที่ดึงมาจาก picking_plans (Rotation ล่าสุดของแต่ละกลุ่ม):");
        foreach ($lastPositionsFromDB as $group => $data) {
            error_log("กลุ่ม: $group - last_position: {$data['last_position']}, max_position: {$data['max_position']}");
        }
        
        // แยกข้อมูลตามกลุ่ม
        $groupedData = [];
        foreach ($nonDuplicatesFromSession as $item) {
            $row = $item['original_row'];
            $group = isset($headerMap['group_name']) && isset($row[$headerMap['group_name']]) ? trim($row[$headerMap['group_name']]) : '';
            $rotation = isset($headerMap['rotation']) && isset($row[$headerMap['rotation']]) ? trim($row[$headerMap['rotation']]) : '';
            
            if (!empty($group) && !empty($rotation)) {
                if (!isset($groupedData[$group])) {
                    $groupedData[$group] = [];
                }
                $groupedData[$group][] = [
                    'rotation' => $rotation,
                    'row_data' => $row, // เก็บข้อมูลทั้งแถว
                    'item' => $item // 'item' key holds the original non-duplicate item structure
                ];
            }
        }
        
        // เรียงลำดับข้อมูลตาม rotation จากน้อยไปมาก และกำหนด position
        $newLastPositionsToUpdate = [];
        $dataToInsert = [];
        
        foreach ($groupedData as $group => $items) {
            // เรียงลำดับตาม rotation จากน้อยไปมาก
            usort($items, function($a, $b) {
                return strnatcmp($a['rotation'], $b['rotation']);
            });
            
            // กำหนด max_position และ last_position เริ่มต้นสำหรับกลุ่มนี้
            $maxPositionForGroup = $lastPositionsFromDB[$group]['max_position'] ?? ($defaultMaxPositions[$group] ?? $fallbackDefaultMaxPosition);
            $currentLastPosInGroup = $lastPositionsFromDB[$group]['last_position'] ?? 0;
            
            // Debug: บันทึก log เพื่อตรวจสอบค่า last_position ที่อ่านมา
            error_log("=== เริ่มประมวลผลกลุ่ม: $group ===");
            error_log("กลุ่ม: $group - ค่า last_position เริ่มต้น (จาก Rotation ล่าสุดใน picking_plans): $currentLastPosInGroup, max_position: $maxPositionForGroup");
            error_log("จำนวน unique rotations ในกลุ่มนี้: " . count($items));
            
            $rotationToAssignedPositionMap = []; // Map สำหรับ rotation ที่ไม่ซ้ำกันใน batch นี้
            
            foreach ($items as $itemData) { // $itemData คือ ['rotation' => ..., 'row_data' => ..., 'item' => ...]
                $rotation = $itemData['rotation'];
                
                if (!isset($rotationToAssignedPositionMap[$rotation])) {
                    // เป็น rotation ใหม่ใน batch นี้สำหรับกลุ่มนี้, คำนวณ position ถัดไป
                    $currentLastPosInGroup = ($currentLastPosInGroup % $maxPositionForGroup) + 1;
                    $rotationToAssignedPositionMap[$rotation] = $currentLastPosInGroup;
                    
                    // Debug: บันทึก log การคำนวณ position ใหม่
                    error_log("กำหนด position ใหม่: กลุ่ม $group, rotation $rotation, position $currentLastPosInGroup");
                }
                $assignedPosition = $rotationToAssignedPositionMap[$rotation];
                
                // เก็บข้อมูลเพื่อเตรียมบันทึก
                $dataToInsert[] = [
                    'group' => $group,
                    'position' => $assignedPosition,
                    'rotation' => $rotation,
                    'row_data' => $itemData['row_data'] // เก็บ row_data เพื่อดึงคอลัมน์อื่นๆ
                ];
            }
            
            // อัปเดต last position สำหรับกลุ่มนี้
            $newLastPositionsToUpdate[$group] = [
                'last_position' => $currentLastPosInGroup,
                'max_position' => $maxPositionForGroup // ส่ง max_position ที่ใช้ไปด้วย
            ];
            
            // Debug: บันทึก log ค่า last_position ที่จะอัปเดต
            error_log("ค่า last_position สุดท้ายสำหรับกลุ่ม $group: $currentLastPosInGroup (จะอัปเดตเข้า group_settings)");
        }
        
        // อัปเดตค่า last_position และ max_position (ถ้าเป็นกลุ่มใหม่) ของแต่ละกลุ่ม
        error_log("=== กำลังอัปเดต last_position ใน group_settings ===");
        updateLastPositions($conn, $newLastPositionsToUpdate, $adminUserId, $defaultMaxPositions, $fallbackDefaultMaxPosition);
        
    // บันทึกข้อมูล Picking Plans จากข้อมูลที่ไม่ซ้ำและมีการกำหนด position แล้ว
    // เพิ่มคอลัมน์ `model`, `date`, `time` ใน INSERT statement
    $insertSql = "INSERT INTO picking_plans (upload_id, group_name, position, part_number, part_name, quantity, rotation, supplier, location, model, date, time) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = mysqli_prepare($conn, $insertSql);
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($dataToInsert as $data) {
            $row = $data['row_data']; // เข้าถึง original_row จาก item
            $group = $data['group'];
            $position = $data['position']; 
            
            // ตรวจสอบและกำหนดค่าเริ่มต้นสำหรับ position หากไม่มีค่า
            if (empty($position) || $position === null) {
                $position = 1; // กำหนดค่าเริ่มต้นเป็น 1 เมื่อไม่มีค่า
                error_log("NOTICE: Position was null for group: $group, rotation: {$data['rotation']} - Using default value 1");
            }
            
            $rotation = $data['rotation'];
            
            // ดึงข้อมูลตามคอลัมน์ - ตรวจสอบอย่างละเอียด
            $partNumber = isset($headerMap['part_number']) && isset($row[$headerMap['part_number']]) ? trim($row[$headerMap['part_number']]) : '';
            $supplier = isset($headerMap['supplier']) && isset($row[$headerMap['supplier']]) ? trim($row[$headerMap['supplier']]) : '';
            $location = isset($headerMap['location']) && isset($row[$headerMap['location']]) ? trim($row[$headerMap['location']]) : '';
            $model = isset($headerMap['model']) && isset($row[$headerMap['model']]) ? trim($row[$headerMap['model']]) : '';
            $dateVal = isset($headerMap['date']) && isset($row[$headerMap['date']]) ? trim($row[$headerMap['date']]) : '';
            $timeVal = isset($headerMap['time']) && isset($row[$headerMap['time']]) ? trim($row[$headerMap['time']]) : '';
            
            // ใช้ part_number เป็น part_name ถ้าไม่มี part_name
            $partName = isset($headerMap['part_name']) && isset($row[$headerMap['part_name']]) ? trim($row[$headerMap['part_name']]) : $partNumber;
            
            // ถ้าไม่มี quantity ให้ใช้ค่าเริ่มต้นเป็น 1
            $quantity = isset($headerMap['quantity']) && isset($row[$headerMap['quantity']]) ? (int)trim($row[$headerMap['quantity']]) : 1;
            
            // ตรวจสอบข้อมูลสำคัญ
            if (empty($group) || empty($partNumber) || empty($rotation)) {
                // บันทึกล็อกข้อผิดพลาด
                error_log("ข้อมูลไม่ครบ: group=$group, part_number=$partNumber, rotation=$rotation");
                $errorCount++;
                continue;
            }
            
            // ทำการบันทึก - เพิ่ม model,date,time ใน bind_param
            mysqli_stmt_bind_param($insertStmt, "isisssisssss", $uploadId, $group, $position, $partNumber, $partName, $quantity, $rotation, $supplier, $location, $model, $dateVal, $timeVal);
            
            if (mysqli_stmt_execute($insertStmt)) {
                $successCount++;
            } else {
                $errorCount++;
                error_log("SQL Error: " . mysqli_stmt_error($insertStmt) . " for group=$group, position=$position, part_number=$partNumber, rotation=$rotation");
            }
        }
        
        // อัปเดตค่า last_position และ max_position (ถ้าเป็นกลุ่มใหม่) ของแต่ละกลุ่ม
        updateLastPositions($conn, $newLastPositionsToUpdate, $adminUserId, $defaultMaxPositions, $fallbackDefaultMaxPosition);
        
        // อัปเดตจำนวนแถวที่สำเร็จและผิดพลาด
        $updateSql = "UPDATE uploads SET rows_success = ?, rows_error = ? WHERE id = ?";
        $updateStmt = mysqli_prepare($conn, $updateSql);
        mysqli_stmt_bind_param($updateStmt, "iii", $successCount, $errorCount, $uploadId);
        mysqli_stmt_execute($updateStmt);
        
        // Commit Transaction
        mysqli_commit($conn);
        
        $alert = "อัปโหลดเฉพาะข้อมูลที่ไม่ซ้ำสำเร็จ! บันทึกข้อมูล {$successCount} รายการ, ผิดพลาด {$errorCount} รายการ";
        $alertType = 'success';
        
        // บันทึกประวัติการอัปโหลด
        $logSql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
        $logStmt = mysqli_prepare($conn, $logSql);
        $action = "upload_non_duplicates";
        $details = "อัปโหลดเฉพาะข้อมูลที่ไม่ซ้ำจากไฟล์ {$fileName} สำเร็จ: {$successCount} รายการ";
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        
        mysqli_stmt_bind_param($logStmt, "isss", $_SESSION['user_id'], $action, $details, $ipAddress);
        mysqli_stmt_execute($logStmt);
        
        // ล้างข้อมูลใน session
        unset($_SESSION['non_duplicates']);
        unset($_SESSION['header_map']);
        unset($_SESSION['file_name']);
        
    } catch (Exception $e) {
        // Rollback หากเกิดข้อผิดพลาด
        mysqli_rollback($conn);
        
        $alert = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        $alertType = 'danger';
    }
}

// ฟังก์ชันตรวจสอบว่าไฟล์ CSV มีข้อมูลของกลุ่มเป้าหมายหรือไม่
function hasTargetGroupData($csvData, $headerMap, $targetGroups = ['GROUP 05', 'GROUP 03', 'GROUP 09L', 'GROUP 09R']) {
    if (!isset($headerMap['group_name'])) {
        return false;
    }
    
    $groupNameIdx = $headerMap['group_name'];
    
    // ข้ามแถวแรกซึ่งเป็นส่วนหัวตาราง
    for ($i = 1; $i < count($csvData); $i++) {
        $row = $csvData[$i];
        if (isset($row[$groupNameIdx]) && in_array(trim($row[$groupNameIdx]), $targetGroups)) {
            return true;
        }
    }
    
    return false;
}

// เพิ่มฟังก์ชันสร้าง Dummy Rotation
function fillMissingRotations($csvData, $headerMap, $targetGroup) {
    // ตรวจสอบว่ามีคอลัมน์ที่จำเป็นครบหรือไม่
    if (!isset($headerMap['rotation']) || !isset($headerMap['group_name']) || !isset($headerMap['part_number'])) {
        return [
            'error' => 'ไม่พบคอลัมน์ที่จำเป็นสำหรับการสร้าง Dummy Rotation',
            'data' => $csvData
        ];
    }

    // แยกข้อมูลตามกลุ่ม
    $groupData = [];
    $otherData = [];
    $rotationIdx = $headerMap['rotation'];
    $groupIdx = $headerMap['group_name'];
    $allRotationsInFile = []; // ใช้ตรวจสอบขอบ (edge) ต่ำสุด/สูงสุด ของทุกแถวในไฟล์

    // ข้ามแถวแรกซึ่งเป็นส่วนหัวตาราง
    $headers = $csvData[0];
    
    // รวบรวมข้อมูลที่เป็นกลุ่มเป้าหมายและกลุ่มอื่นๆ
    for ($i = 1; $i < count($csvData); $i++) {
        $row = $csvData[$i];
        // ข้ามแถวที่ไม่มีข้อมูลเพียงพอ
        if (!isset($row[$rotationIdx]) || !isset($row[$groupIdx])) {
            continue;
        }
        
        $rotation = trim($row[$rotationIdx]);
        $group = trim($row[$groupIdx]);

        // บันทึก rotation ทั้งหมดที่เป็นตัวเลข เพื่อนำไปประเมิน edge ภายหลัง
        if (is_numeric($rotation)) {
            $allRotationsInFile[] = (int)$rotation;
        }
        
        if ($group === $targetGroup) {
            $groupData[(int)$rotation] = $row;
        } else {
            $otherData[] = $row;
        }
    }
    
    // ถ้าไม่มีข้อมูลในกลุ่มเป้าหมาย
    if (empty($groupData)) {
        return [
            'error' => "ไม่พบข้อมูลสำหรับกลุ่ม $targetGroup",
            'data' => $csvData
        ];
    }
    
    // หาค่า Rotation ต่ำสุดและสูงสุด
    $rotations = array_keys($groupData);
    $minRotation = min($rotations);
    $maxRotation = max($rotations);

    // กำหนดค่า min / max ของทุก rotation ในไฟล์ (ทุกกลุ่ม)
    $fileMin = !empty($allRotationsInFile) ? min($allRotationsInFile) : $minRotation;
    $fileMax = !empty($allRotationsInFile) ? max($allRotationsInFile) : $maxRotation;
    $edgeLowerMissing = [];
    $edgeUpperMissing = [];
    // หากปลายด้านล่างของกลุ่มสูงกว่า min ของทั้งไฟล์ แสดงว่ามี edge ที่ไม่ได้เติม
    if ($fileMin < $minRotation) {
        // จำกัดจำนวนที่แสดงเพื่อไม่ให้ยาวเกิน (สูงสุด 30 รายการ)
        $rangeLower = range($fileMin, $minRotation - 1);
        $edgeLowerMissing = array_slice($rangeLower, 0, 30);
    }
    // หากปลายด้านบนของกลุ่มต่ำกว่า max ของทั้งไฟล์ แสดงว่ามี edge ที่ไม่ได้เติม
    if ($fileMax > $maxRotation) {
        $rangeUpper = range($maxRotation + 1, $fileMax);
        $edgeUpperMissing = array_slice($rangeUpper, 0, 30);
    }
    
    // ตรวจสอบและเพิ่ม Dummy Rotation ที่ขาดหายไป (ช่วงภายในที่พบจริงในกลุ่ม)
    $dummyAdded = 0;
    $dummyRows = [];
    
    for ($rotation = $minRotation; $rotation <= $maxRotation; $rotation++) {
        if (!isset($groupData[$rotation])) {
            // สร้างแถว Dummy โดยคัดลอกจากแถวที่มีอยู่
            $dummyRow = $groupData[reset($rotations)]; // ใช้แถวแรกเป็นต้นแบบ
            
            // แก้ไขค่าเป็น Dummy
            $dummyRow[$rotationIdx] = $rotation;
            
            // หาดัชนีของคอลัมน์อื่นๆ และแก้ไขเป็น Dummy
            $partNumberIdx = isset($headerMap['part_number']) ? $headerMap['part_number'] : -1;
            $supplierIdx = isset($headerMap['supplier']) ? $headerMap['supplier'] : -1;
            $locationIdx = isset($headerMap['location']) ? $headerMap['location'] : -1;
            $modelIdx = isset($headerMap['model']) ? $headerMap['model'] : -1;
            
            // กำหนดค่า Dummy สำหรับคอลัมน์ที่พบ
            if ($partNumberIdx >= 0) $dummyRow[$partNumberIdx] = 'Dummy';
            if ($supplierIdx >= 0) $dummyRow[$supplierIdx] = 'HTT'; // กำหนดค่า Supplier เป็น HTT ตามตัวอย่าง
            if ($locationIdx >= 0) $dummyRow[$locationIdx] = 'Dummy';
            if ($modelIdx >= 0) $dummyRow[$modelIdx] = 'Dummy';
            
            // เก็บ Dummy Row
            $dummyRows[] = $dummyRow;
            $groupData[$rotation] = $dummyRow;
            $dummyAdded++;
        }
    }
    
    // เติม edge เฉพาะกรณี wrap endpoints (0001 และ 9999) ป้องกัน overfill เช่นจาก 0020 ไปถึง 0070
    $edgeFilledLower = [];
    $edgeFilledUpper = [];
    // เงื่อนไขเติม 0001: มีช่องว่างภายในเริ่มที่ 0002 (minRotation == 2) หรือ minRotation ใกล้จุดเริ่ม (<=3) และไม่มี 1
    if ($minRotation <= 3 && !isset($groupData[1])) {
        // สร้าง dummy สำหรับ 1
        $dummyRow = $groupData[reset($rotations)];
        $dummyRow[$rotationIdx] = 1;
        $partNumberIdx = isset($headerMap['part_number']) ? $headerMap['part_number'] : -1;
        $supplierIdx = isset($headerMap['supplier']) ? $headerMap['supplier'] : -1;
        $locationIdx = isset($headerMap['location']) ? $headerMap['location'] : -1;
        $modelIdx = isset($headerMap['model']) ? $headerMap['model'] : -1;
        if ($partNumberIdx >= 0) $dummyRow[$partNumberIdx] = 'Dummy';
        if ($supplierIdx >= 0) $dummyRow[$supplierIdx] = 'HTT';
        if ($locationIdx >= 0) $dummyRow[$locationIdx] = 'Dummy';
        if ($modelIdx >= 0) $dummyRow[$modelIdx] = 'Dummy';
        $groupData[1] = $dummyRow;
        $dummyRows[] = $dummyRow;
        $dummyAdded++;
        $edgeFilledLower[] = 1;
    }
    // เงื่อนไขเติม 9999: maxRotation ใกล้ปลาย (>= 9996) และยังไม่มี 9999 หรือ pattern wrap (มีค่า 9998 แต่ไม่มี 9999)
    if ($maxRotation >= 9996 && $maxRotation < 9999 && !isset($groupData[9999])) {
        $dummyRow = $groupData[end($rotations)];
        $dummyRow[$rotationIdx] = 9999;
        $partNumberIdx = isset($headerMap['part_number']) ? $headerMap['part_number'] : -1;
        $supplierIdx = isset($headerMap['supplier']) ? $headerMap['supplier'] : -1;
        $locationIdx = isset($headerMap['location']) ? $headerMap['location'] : -1;
        $modelIdx = isset($headerMap['model']) ? $headerMap['model'] : -1;
        if ($partNumberIdx >= 0) $dummyRow[$partNumberIdx] = 'Dummy';
        if ($supplierIdx >= 0) $dummyRow[$supplierIdx] = 'HTT';
        if ($locationIdx >= 0) $dummyRow[$locationIdx] = 'Dummy';
        if ($modelIdx >= 0) $dummyRow[$modelIdx] = 'Dummy';
        $groupData[9999] = $dummyRow;
        $dummyRows[] = $dummyRow;
        $dummyAdded++;
        $edgeFilledUpper[] = 9999;
    }

    // เรียงลำดับ Rotation ใหม่ (หลังเติม edge)
    ksort($groupData);
    
    // สร้าง CSV ข้อมูลใหม่
    $newCsvData = [$headers]; // เริ่มด้วยหัวตาราง
    foreach ($groupData as $row) {
        $newCsvData[] = $row;
    }
    foreach ($otherData as $row) {
        $newCsvData[] = $row;
    }
    
    return [
        'data' => $newCsvData,
        'dummy_added' => $dummyAdded,
        'dummy_rows' => $dummyRows,
        // รายงาน edge ที่ไม่ถูกเติม (นอกช่วงภายในที่ระบบใช้สร้าง dummy)
    'edge_lower_missing' => array_values(array_diff($edgeLowerMissing, $edgeFilledLower)),
    'edge_upper_missing' => array_values(array_diff($edgeUpperMissing, $edgeFilledUpper)),
    'edge_filled_lower' => $edgeFilledLower,
    'edge_filled_upper' => $edgeFilledUpper,
        'range_used' => [$minRotation, $maxRotation],
        'file_range' => [$fileMin, $fileMax]
    ];
}

// ประมวลผลการอัปโหลดไฟล์ - แก้ไขส่วนนี้เพื่อเพิ่มการตรวจสอบ GROUP 03 และ GROUP 05 และสร้าง Dummy Rotation อัตโนมัติ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && isset($_POST['upload_csv'])) {
    $file = $_FILES['csv_file'];
    
    // ตรวจสอบว่ามีไฟล์ถูกอัปโหลดหรือไม่
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $alert = 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์: ' . $file['error'];
        $alertType = 'danger';
    } else {
        $tempFile = $file['tmp_name'];
        
        // ตรวจสอบนามสกุลไฟล์
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($fileExtension !== 'csv') {
            $alert = 'กรุณาอัปโหลดไฟล์ CSV เท่านั้น';
            $alertType = 'danger';
        } else {
            // อ่านข้อมูลจากไฟล์ CSV
            $csvData = [];
            $handle = fopen($tempFile, 'r');
            
            // ตรวจสอบ BOM (Byte Order Mark) และข้ามถ้ามี
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                // ไม่มี BOM ให้กลับไปที่จุดเริ่มต้นไฟล์
                rewind($handle);
            }
            
            // กำหนดตัวคั่น (Delimiter)
            $delimiter = ',';
            
            // อ่านแถวแรกเพื่อตรวจสอบหัวคอลัมน์
            if (($headers = fgetcsv($handle, 0, $delimiter)) !== false) {
                $csvData[] = $headers;
                
                // อ่านข้อมูลที่เหลือ
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $csvData[] = $row;
                }
            }
            
            fclose($handle);
            
            // แปลงชื่อคอลัมน์เป็น index
            $headerMap = mapColumns($headers);
            
            // ตรวจสอบว่ามีคอลัมน์ที่จำเป็นครบหรือไม่
            $requiredColumns = ['rotation', 'group_name', 'part_number'];
            $missingColumns = [];
            
            foreach ($requiredColumns as $column) {
                if (!isset($headerMap[$column])) {
                    $missingColumns[] = $column;
                }
            }
            
            if (!empty($missingColumns)) {
                $alert = 'ไฟล์ CSV ไม่มีคอลัมน์ที่จำเป็น: ' . implode(', ', $missingColumns);
                $alertType = 'danger';
            } else {
                // ตรวจสอบว่ามีข้อมูลของกลุ่มเป้าหมายหรือไม่
                $targetGroups = ['GROUP 05', 'GROUP 03', 'GROUP 09L', 'GROUP 09R'];
                $hasTargetGroups = hasTargetGroupData($csvData, $headerMap, $targetGroups);

                // ถ้ามีข้อมูลกลุ่มเป้าหมาย ให้สร้าง Dummy Rotation อัตโนมัติ
                if ($hasTargetGroups && isset($_POST['auto_dummy']) && $_POST['auto_dummy'] == '1') {
                    $dummyMessages = [];
                    // สร้าง Dummy Rotation สำหรับแต่ละกลุ่ม
                    foreach ($targetGroups as $groupName) {
                        $result = fillMissingRotations($csvData, $headerMap, $groupName);
                        if (isset($result['error'])) {
                            $dummyMessages[] = "$groupName: " . $result['error'];
                        } else {
                            $csvData = $result['data'];
                            $dummyAdded = $result['dummy_added'];
                            $rangeUsed = isset($result['range_used']) ? $result['range_used'] : null;
                            $fileRange = isset($result['file_range']) ? $result['file_range'] : null;
                            $edgeLower = $result['edge_lower_missing'] ?? [];
                            $edgeUpper = $result['edge_upper_missing'] ?? [];
                            $edgeFilledLower = $result['edge_filled_lower'] ?? [];
                            $edgeFilledUpper = $result['edge_filled_upper'] ?? [];

                            $msgParts = [];
                            if ($dummyAdded > 0) {
                                $msgParts[] = "สร้าง Dummy Rotation $dummyAdded รายการ (ช่วงภายใน {$rangeUsed[0]}–{$rangeUsed[1]})";
                            } else {
                                $msgParts[] = "ไม่มีช่องว่างภายใน (ช่วง {$rangeUsed[0]}–{$rangeUsed[1]})";
                            }
                            // รายงาน edge ที่ระบบไม่เติม
                            if (!empty($edgeLower) || !empty($edgeUpper) || !empty($edgeFilledLower) || !empty($edgeFilledUpper)) {
                                $edgeNotes = [];
                                $formatList = function($arr) { return implode(',', array_map(function($n){ return str_pad($n,4,'0',STR_PAD_LEFT); }, $arr)); };
                                if (!empty($edgeLower)) {
                                    $edgeNotes[] = "ขอบล่างไม่เติม: " . $formatList($edgeLower) . (count($edgeLower) >= 30 ? ' ...' : '');
                                }
                                if (!empty($edgeUpper)) {
                                    $edgeNotes[] = "ขอบบนไม่เติม: " . $formatList($edgeUpper) . (count($edgeUpper) >= 30 ? ' ...' : '');
                                }
                                if (!empty($edgeFilledLower)) {
                                    $edgeNotes[] = "ขอบล่างเติม: " . $formatList($edgeFilledLower);
                                }
                                if (!empty($edgeFilledUpper)) {
                                    $edgeNotes[] = "ขอบบนเติม: " . $formatList($edgeFilledUpper);
                                }
                                $msgParts[] = 'Edge: ' . implode(' | ', $edgeNotes) . ( $fileRange ? " (ช่วง rotation ทั้งไฟล์ {$fileRange[0]}–{$fileRange[1]})" : '' );
                            }
                            $dummyMessages[] = $groupName . ': ' . implode(' ; ', $msgParts);
                        }
                    }
                    if (!empty($dummyMessages)) {
                        $warningMessage = "ระบบได้สร้าง Dummy Rotation โดยอัตโนมัติ:<br>" . implode("<br>", $dummyMessages);
                    }
                }
                // ตรวจสอบว่ามีข้อมูลซ้ำกันในไฟล์ CSV หรือไม่
                $csvDuplicateResults = checkDuplicatesInCSV($csvData, $headerMap);
                
                if (isset($csvDuplicateResults['error'])) {
                    $alert = $csvDuplicateResults['error'];
                    $alertType = 'danger';
                } else {
                    // ตรวจสอบข้อมูลซ้ำในฐานข้อมูล
                    $dbDuplicateResults = checkDuplicatesInDatabase($csvData, $headerMap, $conn);
                    
                    if (isset($dbDuplicateResults['error'])) {
                        $alert = $dbDuplicateResults['error'];
                        $alertType = 'danger';
                    } else {
                        $csvDuplicateCount = $csvDuplicateResults['duplicate_count'];
                        $dbDuplicateCount = $dbDuplicateResults['duplicate_count'];
                        $nonDuplicateCount = $dbDuplicateResults['non_duplicate_count'];
                        
                        // เก็บข้อมูลที่ไม่ซ้ำใน session สำหรับการอัปโหลดภายหลัง
                        if ($nonDuplicateCount > 0) {
                            $_SESSION['non_duplicates'] = $dbDuplicateResults['non_duplicates'];
                            $_SESSION['header_map'] = $headerMap;
                            $_SESSION['file_name'] = $file['name'];
                        }
                        
                        if ($dbDuplicateCount > 0 && $nonDuplicateCount > 0) {
                            // มีทั้งข้อมูลซ้ำและไม่ซ้ำ
                            $alertType = 'warning';
                            $alert = "พบข้อมูลซ้ำในฐานข้อมูลจำนวน {$dbDuplicateCount} รายการ และข้อมูลที่ไม่ซ้ำจำนวน {$nonDuplicateCount} รายการ";
                            
                            // เก็บข้อมูลสำหรับแสดง
                            $csvDuplicates = $csvDuplicateResults['duplicates'] ?? [];
                            $dbDuplicates = $dbDuplicateResults['duplicates'] ?? [];
                            $nonDuplicates = $dbDuplicateResults['non_duplicates'] ?? [];
                        } elseif ($dbDuplicateCount > 0 && $nonDuplicateCount == 0) {
                            // มีข้อมูลซ้ำทั้งหมด
                            $alertType = 'danger';
                            $alert = "พบข้อมูลซ้ำในฐานข้อมูลทั้งหมด {$dbDuplicateCount} รายการ ไม่สามารถอัปโหลดได้";
                            
                            // เก็บข้อมูลสำหรับแสดง
                            $csvDuplicates = $csvDuplicateResults['duplicates'] ?? [];
                            $dbDuplicates = $dbDuplicateResults['duplicates'] ?? [];
                        } elseif ($dbDuplicateCount == 0 && $nonDuplicateCount > 0) {
                            // ไม่มีข้อมูลซ้ำเลย, ดำเนินการอัปโหลดตามปกติ
                            try {
                                // เริ่ม Transaction
                                mysqli_begin_transaction($conn);
                                
                                // บันทึกประวัติการอัปโหลด
                                $uploadSql = "INSERT INTO uploads (filename, uploaded_by, rows_total, rows_success, rows_duplicate, rows_error) 
                                            VALUES (?, ?, ?, ?, ?, ?)";
                                $uploadStmt = mysqli_prepare($conn, $uploadSql);
                                
                                $fileName = $file['name'];
                                $uploadedBy = $_SESSION['user_id'];
                                $totalRows = count($csvData) - 1; // ไม่นับแถวหัวคอลัมน์
                                $rowsSuccess = 0; 
                                $rowsDuplicate = $csvDuplicateResults['duplicate_count']; // ข้อมูลซ้ำในไฟล์ CSV
                                $rowsError = 0;
                                
                                mysqli_stmt_bind_param($uploadStmt, "ssiiis", $fileName, $uploadedBy, $totalRows, $rowsSuccess, $rowsDuplicate, $rowsError);
                                mysqli_stmt_execute($uploadStmt);
                                $uploadId = mysqli_insert_id($conn);
                                
                                // ดึงค่า last_position และ max_position ของแต่ละกลุ่มจาก DB
                                error_log("=== เริ่มกระบวนการอัปโหลดไฟล์ใหม่ ===");
                                $lastPositionsFromDB = getLastPositions($conn);
                                $adminUserId = $_SESSION['user_id'];

                                // Debug: บันทึก log ค่า last_position ของแต่ละกลุ่มที่ดึงมา
                                error_log("ค่า last_position ที่ดึงมาจาก picking_plans (Rotation ล่าสุดของแต่ละกลุ่ม):");
                                foreach ($lastPositionsFromDB as $group => $data) {
                                    error_log("กลุ่ม: $group - last_position: {$data['last_position']}, max_position: {$data['max_position']}");
                                }

                                // แยกข้อมูลตามกลุ่ม (จากข้อมูลที่ไม่ซ้ำในไฟล์ CSV)
                                $groupedData = [];
                                $uniqueRowsFromCSV = $csvDuplicateResults['unique_keys']; // unique_keys เก็บข้อมูลที่ไม่ซ้ำใน CSV

                                foreach ($uniqueRowsFromCSV as $key => $item) {
                                    $row = $item['data'];
                                    $group = isset($headerMap['group_name']) && isset($row[$headerMap['group_name']]) ? trim($row[$headerMap['group_name']]) : '';
                                    $rotation = isset($headerMap['rotation']) && isset($row[$headerMap['rotation']]) ? trim($row[$headerMap['rotation']]) : '';
                                    
                                    if (!empty($group) && !empty($rotation)) {
                                        if (!isset($groupedData[$group])) {
                                            $groupedData[$group] = [];
                                        }
                                        $groupedData[$group][] = [
                                            'rotation' => $rotation,
                                            'row_data' => $row // เก็บข้อมูลทั้งแถว
                                        ];
                                    }
                                }
                                
                                $newLastPositionsToUpdate = [];
                                $dataToInsert = [];

                                foreach ($groupedData as $group => $items) {
                                    usort($items, function($a, $b) {
                                        return strnatcmp($a['rotation'], $b['rotation']);
                                    });

                                    $maxPositionForGroup = $lastPositionsFromDB[$group]['max_position'] ?? ($defaultMaxPositions[$group] ?? $fallbackDefaultMaxPosition);
                                    $currentLastPosInGroup = $lastPositionsFromDB[$group]['last_position'] ?? 0;
                                    
                                    // Debug: บันทึก log ค่า last_position ก่อนคำนวณ position ใหม่
                                    error_log("=== เริ่มประมวลผลกลุ่ม: $group ===");
                                    error_log("กลุ่ม: $group - ค่า last_position เริ่มต้น (จาก Rotation ล่าสุดใน picking_plans): $currentLastPosInGroup, max_position: $maxPositionForGroup");
                                    
                                    $rotationToAssignedPositionMap = [];

                                    foreach ($items as $itemData) {
                                        $rotation = $itemData['rotation'];
                                        
                                        if (!isset($rotationToAssignedPositionMap[$rotation])) {
                                            // ใช้ modulo เพื่อวนกลับมาที่ 1 เมื่อถึง Max Position
                                            $currentLastPosInGroup = ($currentLastPosInGroup % $maxPositionForGroup) + 1;
                                            $rotationToAssignedPositionMap[$rotation] = $currentLastPosInGroup;
                                            
                                            // Debug: บันทึก log การคำนวณ position ใหม่
                                            error_log("กำหนด position ใหม่: กลุ่ม $group, rotation $rotation, position $currentLastPosInGroup");
                                        }
                                        $assignedPosition = $rotationToAssignedPositionMap[$rotation];
                                        
                                        $dataToInsert[] = [
                                            'group' => $group,
                                            'position' => $assignedPosition,
                                            'rotation' => $rotation,
                                            'row_data' => $itemData['row_data']
                                        ];
                                    }
                                    $newLastPositionsToUpdate[$group] = [
                                        'last_position' => $currentLastPosInGroup,
                                        'max_position' => $maxPositionForGroup
                                    ];
                                    
                                    // Debug: บันทึก log ค่า last_position ที่จะอัปเดต
                                    error_log("ค่า last_position สุดท้ายสำหรับกลุ่ม $group: $currentLastPosInGroup (จะอัปเดตเข้า group_settings)");
                                }
                                
                                // อัปเดตค่า last_position และ max_position (ถ้าเป็นกลุ่มใหม่) ของแต่ละกลุ่ม
                                error_log("=== กำลังอัปเดต last_position ใน group_settings ===");
                                updateLastPositions($conn, $newLastPositionsToUpdate, $adminUserId, $defaultMaxPositions, $fallbackDefaultMaxPosition);

                                // บันทึกข้อมูล Picking Plans จากข้อมูลที่ไม่ซ้ำในไฟล์ CSV
                                // เพิ่มคอลัมน์ `model`, `date`, `time` ใน INSERT statement
                                $insertSql = "INSERT INTO picking_plans (upload_id, group_name, position, part_number, part_name, quantity, rotation, supplier, location, model, date, time) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                $insertStmt = mysqli_prepare($conn, $insertSql);
                                
                                $successCount = 0;
                                $errorCount = 0;
                                
                                foreach ($dataToInsert as $data) {
                                    $row = $data['row_data'];
                                    $group = $data['group'];
                                    $position = $data['position'];
                                    $rotation = $data['rotation'];

                                    $partNumber = isset($headerMap['part_number']) && isset($row[$headerMap['part_number']]) ? trim($row[$headerMap['part_number']]) : '';
                                    $supplier = isset($headerMap['supplier']) && isset($row[$headerMap['supplier']]) ? trim($row[$headerMap['supplier']]) : '';
                                    $location = isset($headerMap['location']) && isset($row[$headerMap['location']]) ? trim($row[$headerMap['location']]) : '';
                                    $model = isset($headerMap['model']) && isset($row[$headerMap['model']]) ? trim($row[$headerMap['model']]) : '';
                                    $dateVal = isset($headerMap['date']) && isset($row[$headerMap['date']]) ? trim($row[$headerMap['date']]) : '';
                                    $timeVal = isset($headerMap['time']) && isset($row[$headerMap['time']]) ? trim($row[$headerMap['time']]) : '';
                                    
                                    $partName = isset($headerMap['part_name']) && isset($row[$headerMap['part_name']]) ? trim($row[$headerMap['part_name']]) : $partNumber;
                                    $quantity = isset($headerMap['quantity']) && isset($row[$headerMap['quantity']]) ? (int)trim($row[$headerMap['quantity']]) : 1;
                                    
                                    if (empty($group) || empty($partNumber) || empty($rotation)) {
                                        $errorCount++;
                                        continue;
                                    }
                                    
                                    // ทำการบันทึก - เพิ่ม model,date,time ใน bind_param
                                    mysqli_stmt_bind_param($insertStmt, "isisssisssss", $uploadId, $group, $position, $partNumber, $partName, $quantity, $rotation, $supplier, $location, $model, $dateVal, $timeVal);
                                    
                                    if (mysqli_stmt_execute($insertStmt)) {
                                        $successCount++;
                                    } else {
                                        $errorCount++;
                                    }
                                }
                                
                                // อัปเดตค่า last_position และ max_position (ถ้าเป็นกลุ่มใหม่) ของแต่ละกลุ่ม
                                updateLastPositions($conn, $newLastPositionsToUpdate, $adminUserId, $defaultMaxPositions, $fallbackDefaultMaxPosition);

                                // อัปเดตจำนวนแถวที่สำเร็จและผิดพลาด
                                $updateSql = "UPDATE uploads SET rows_success = ?, rows_error = ? WHERE id = ?";
                                $updateStmt = mysqli_prepare($conn, $updateSql);
                                mysqli_stmt_bind_param($updateStmt, "iii", $successCount, $errorCount, $uploadId);
                                mysqli_stmt_execute($updateStmt);
                                
                                // Commit Transaction
                                mysqli_commit($conn);
                                
                                $alert = "อัปโหลดไฟล์สำเร็จ! บันทึกข้อมูล {$successCount} รายการ, ข้ามข้อมูลซ้ำในไฟล์ {$csvDuplicateResults['duplicate_count']} รายการ, ผิดพลาด {$errorCount} รายการ";
                                $alertType = 'success';
                                
                                // บันทึกประวัติการอัปโหลด
                                $logSql = "INSERT INTO logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)";
                                $logStmt = mysqli_prepare($conn, $logSql);
                                $action = "upload_csv";
                                $details = "อัปโหลดไฟล์ {$fileName} สำเร็จ: {$successCount} รายการ";
                                $ipAddress = $_SERVER['REMOTE_ADDR'];
                                
                                mysqli_stmt_bind_param($logStmt, "isss", $_SESSION['user_id'], $action, $details, $ipAddress);
                                mysqli_stmt_execute($logStmt);
                            } catch (Exception $e) {
                                // Rollback หากเกิดข้อผิดพลาด
                                mysqli_rollback($conn);
                                
                                $alert = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
                                $alertType = 'danger';
                            }
                        } else {
                            // ไม่มีข้อมูลที่จะอัปโหลด
                            $alertType = 'warning';
                            $alert = "ไม่พบข้อมูลที่จะอัปโหลด";
                        }
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปโหลดไฟล์ CSV - Emergency Picking System</title>
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
            background-color: #f5f7fa;
            min-height: 100vh;
        }
        
        /* ปรับปรุง Navbar */
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
        
        .container {
            padding-top: 20px;
            padding-bottom: 40px;
        }
        
        .page-header {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .page-title {
            font-weight: 600;
            color: #1a4f72;
            margin-bottom: 5px;
        }
        
        .page-subtitle {
            color: #6c757d;
            font-size: 1rem;
            font-weight: normal;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: #1a4f72;
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: 600;
            padding: 12px 20px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .btn-primary {
            background-color: #1a4f72;
            border-color: #1a4f72;
        }
        
        .btn-primary:hover {
            background-color: #133e5b;
            border-color: #133e5b;
        }
        
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 30px 20px;
            text-align: center;
            background-color: #f9f9f9;
            transition: all 0.3s;
            cursor: pointer;
            margin-bottom: 20px;
        }
        
        .upload-area:hover {
            border-color: #1a4f72;
            background-color: #f2f7ff;
        }
        
        .upload-icon {
            font-size: 48px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .upload-text {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .upload-hint {
            font-size: 14px;
            color: #6c757d;
        }
        
        .table-container {
            margin-top: 20px;
        }
        
        .table th {
            background-color: #f2f2f2;
            font-weight: 600;
        }
        
        .table td, .table th {
            vertical-align: middle;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(26, 79, 114, 0.05);
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .badge-count {
            font-size: 0.85rem;
            padding: 0.35em 0.65em;
            border-radius: 50rem;
        }
        
        .footer {
            margin-top: 2rem;
            padding: 1rem 0;
            text-align: center;
            font-size: 0.85rem;
            color: #6c757d;
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
    
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-upload"></i> อัปโหลดไฟล์ CSV
            </h1>
            <p class="page-subtitle">อัปโหลดไฟล์ CSV เพื่อนำเข้าข้อมูลแผนงานหยิบฉุกเฉิน</p>
        </div>
        
        <?php if (!empty($alert)): ?>
            <div class="alert alert-<?php echo $alertType; ?>" role="alert">
                <?php echo $alert; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($warningMessage)): ?>
            <div class="alert alert-warning" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $warningMessage; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <!-- แสดงข้อมูลซ้ำในไฟล์ CSV ถ้ามี -->
        <?php if (isset($csvDuplicates) && !empty($csvDuplicates)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-clone"></i> ข้อมูลซ้ำในไฟล์ CSV 
                    <span class="badge badge-light badge-count"><?php echo count($csvDuplicates); ?> รายการ</span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>แถวที่</th>
                                    <th>Rotation</th>
                                    <th>กลุ่ม</th>
                                    <th>Part Number</th>
                                    <th>ตำแหน่ง</th>
                                    <th>Model</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>ซ้ำกับแถวที่</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($csvDuplicates as $duplicate): ?>
                                    <tr>
                                        <td><?php echo $duplicate['row']; ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['rotation']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['group']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['part_number']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['location']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['model']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['date'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['time'] ?? ''); ?></td>
                                        <td><?php echo $duplicate['duplicate_of_row']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i> ข้อมูลซ้ำในไฟล์ CSV จะถูกข้ามโดยอัตโนมัติ ระบบจะใช้เฉพาะข้อมูลแถวแรกที่พบ
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- แสดงข้อมูลที่ไม่ซ้ำ -->
        <?php if (isset($nonDuplicates) && !empty($nonDuplicates)): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-check-circle"></i> ข้อมูลที่ไม่ซ้ำ 
                    <span class="badge badge-light badge-count"><?php echo count($nonDuplicates); ?> รายการ</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> ข้อมูลเหล่านี้ไม่ซ้ำกับฐานข้อมูลที่มีอยู่ คุณสามารถอัปโหลดได้
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                                                       <thead>
                                <tr>
                                    <th>แถวที่</th>
                                    <th>Rotation</th>
                                    <th>กลุ่ม</th>
                                    <th>Part Number</th>
                                    <th>ตำแหน่ง</th>
                                    <th>Model</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nonDuplicates as $nonDuplicate): ?>
                                    <tr>
                                        <td><?php echo $nonDuplicate['row']; ?></td>
                                        <td><?php echo htmlspecialchars($nonDuplicate['rotation']); ?></td>
                                        <td><?php echo htmlspecialchars($nonDuplicate['group']); ?></td>
                                        <td><?php echo htmlspecialchars($nonDuplicate['part_number']); ?></td>
                                        <td><?php echo htmlspecialchars($nonDuplicate['location']); ?></td>
                                        <td><?php echo htmlspecialchars($nonDuplicate['model']); ?></td>
                                        <td><?php echo htmlspecialchars($nonDuplicate['date'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($nonDuplicate['time'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- ฟอร์มยืนยันการอัปโหลดเฉพาะข้อมูลที่ไม่ซ้ำ -->
                    <form method="post" class="mt-3">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="confirmUpload" required>
                            <label class="form-check-label" for="confirmUpload">
                                ยืนยันการอัปโหลดเฉพาะข้อมูลที่ไม่ซ้ำ จำนวน <?php echo count($nonDuplicates); ?> รายการ
                            </label>
                        </div>
                        <button type="submit" name="upload_non_duplicates" class="btn btn-success">
                            <i class="fas fa-upload"></i> อัปโหลดเฉพาะข้อมูลที่ไม่ซ้ำ
                        </button>
                        <a href="upload.php" class="btn btn-secondary ml-2">
                            <i class="fas fa-times"></i> ยกเลิก
                        </a>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- แสดงข้อมูลซ้ำในฐานข้อมูล ถ้ามี -->
        <?php if (isset($dbDuplicates) && !empty($dbDuplicates)): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <i class="fas fa-database"></i> ข้อมูลที่ซ้ำกับฐานข้อมูล
                    <span class="badge badge-dark badge-count"><?php echo count($dbDuplicates); ?> รายการ</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> ข้อมูลเหล่านี้มีอยู่ในฐานข้อมูลแล้ว จะถูกข้ามในการอัปโหลด
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>แถวที่</th>
                                    <th>Rotation</th>
                                    <th>กลุ่ม</th>
                                    <th>Part Number</th>
                                    <th>ตำแหน่ง</th>
                                    <th>Model</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dbDuplicates as $duplicate): ?>
                                    <tr>
                                        <td><?php echo $duplicate['row']; ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['rotation']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['group']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['part_number']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['location']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['model']); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['date'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars($duplicate['time'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- ฟอร์มอัปโหลดไฟล์ -->
        <?php if (!isset($nonDuplicates) || empty($nonDuplicates)): ?>
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-file-upload"></i> อัปโหลดไฟล์ CSV
                        </div>
                        <div class="card-body">
                            <!-- ฟอร์มอัปโหลดปกติ -->
                            <form method="post" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label for="csv_file">เลือกไฟล์ CSV:</label>
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" id="csv_file" name="csv_file" accept=".csv" required>
                                        <label class="custom-file-label" for="csv_file">เลือกไฟล์...</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="auto_dummy" name="auto_dummy" value="1" checked>
                                        <label class="form-check-label" for="auto_dummy">
                                            สร้าง Dummy Rotation สำหรับ GROUP 03, GROUP 05, GROUP 09L, GROUP 09R อัตโนมัติ (หากพบช่องว่าง)
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <button type="submit" name="upload_csv" class="btn btn-primary">
                                        <i class="fas fa-upload"></i> อัปโหลดไฟล์
                                    </button>
                                    <a href="?action=download_template" class="btn btn-info ml-2">
                                        <i class="fas fa-download"></i> ดาวน์โหลด Template CSV
                                    </a>
                                </div>
                            </form>

                            <hr>
                            <h5 class="mt-4 mb-3"><i class="fas fa-info-circle"></i> เกี่ยวกับ Dummy Rotation</h5>
                            <div class="alert alert-info">
                                <p><strong>การสร้าง Dummy Rotation คืออะไร?</strong></p>
                                <p>ระบบจะตรวจสอบ Rotation ที่ขาดหายไปใน GROUP 03, GROUP 05, GROUP 09L, GROUP 09R และสร้างข้อมูล Dummy โดยอัตโนมัติ เช่น:</p>
                                <p>ถ้ามี Rotation 7303, 7309, 7312, 7315 ระบบจะสร้าง Dummy สำหรับ 7304, 7305, 7306, 7307, 7308, 7310, 7311, 7313, 7314</p>
                                <p>โดยจะกำหนดให้ Part Number เป็น "Dummy", Supplier เป็น "HTT" และข้อมูลอื่นๆ เป็น "Dummy"</p>
                            </div>
                            
                            <!-- ตัวอย่างรูปแบบ CSV ที่ถูกต้อง -->
                            <div class="alert alert-info mt-3">
                                <h5><i class="fas fa-info-circle"></i> รูปแบบไฟล์ CSV ที่ถูกต้อง:</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Rotation</th>
                                                <th>Part_Group</th>
                                                <th>Part_Number</th>
                                                <th>Supplier</th>
                                                <th>Location Pick</th>
                                                <th>Model</th>
                                                <th>Date</th>
                                                <th>Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>8574</td>
                                                <td>GROUP 11R</td>
                                                <td>N1WB-16450-CB</td>
                                                <td>ARK</td>
                                                <td>PFA-02-01</td>
                                                <td>U704</td>
                                                <td><?php echo date('d/m/Y'); ?></td>
                                                <td>08:30:00</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mb-0"><small>หมายเหตุ: ระบบสามารถรองรับชื่อคอลัมน์ได้หลายรูปแบบ แต่โครงสร้างข้อมูลควรเป็นไปตามตัวอย่างข้างต้น</small></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- คำแนะนำ -->
                    <div class="card">
                        <div class="card-header">
                                                       <i class="fas fa-info-circle"></i> คำแนะนำการอัปโหลด
                        </div>
                        <div class="card-body">
                            <h5>รูปแบบไฟล์ CSV ที่รองรับ:</h5>
                            <ul>
                                <li>ไฟล์ต้องมีนามสกุล .csv</li>
                                <li>ไฟล์ควรใช้การเข้ารหัส UTF-8 (แนะนำให้ไม่มี BOM)</li>
                                <li>ตัวคั่นควรเป็นเครื่องหมายจุลภาค (,)</li>
                                <li>บรรทัดแรกต้องเป็นชื่อคอลัมน์</li>
                            </ul>
                            
                            <h5>คอลัมน์ที่จำเป็น:</h5>
                            <ul>
                                <li><strong>Rotation</strong> - หมายเลข Rotation</li>
                                <li><strong>Part_Group</strong> - กลุ่มของชิ้นส่วน</li>
                                <li><strong>Part_Number</strong> - หมายเลขชิ้นส่วน</li>
                            </ul>
                            <h5>คอลัมน์ที่แนะนำให้มี:</h5>
                            <ul>
                                <li><strong>Supplier</strong> - ผู้จำหน่าย</li>
                                <li><strong>Location Pick</strong> - ตำแหน่งหยิบ</li>
                                <li><strong>Model</strong> - รุ่นรถ</li>
                                <li><strong>Date</strong> - วันที่ (รูปแบบ dd/mm/YYYY)</li>
                                <li><strong>Time</strong> - เวลา (รูปแบบ HH:MM:SS)</li>
                            </ul>
                            
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle"></i> <strong>ข้อควรระวัง:</strong> ระบบจะไม่อนุญาตให้อัปโหลดข้อมูลที่ซ้ำกับข้อมูลที่มีอยู่แล้วในระบบ (พิจารณาจาก Rotation, Group, Part Number, และ Location)
                            </div>
                            

                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
    
    <!-- Modal ยืนยันการลบข้อมูล -->
    <div class="modal fade" id="clearDataModal" tabindex="-1" role="dialog" aria-labelledby="clearDataModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="clearDataModalLabel"><i class="fas fa-exclamation-triangle"></i> ยืนยันการลบข้อมูลทั้งหมด</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="text-danger font-weight-bold">คำเตือน: การดำเนินการนี้ไม่สามารถย้อนกลับได้!</p>
                    <p>คุณแน่ใจหรือไม่ว่าต้องการลบข้อมูลทั้งหมด?</p>
                    <form id="clearDataForm" method="post">
                        <div class="form-group">
                            <label for="confirmText">พิมพ์คำว่า "ยืนยัน" เพื่อดำเนินการต่อ:</label>
                            <input type="text" class="form-control" id="confirmText" required pattern="ยืนยัน" oninput="validateConfirmation()">
                            <small class="form-text text-muted">พิมพ์คำว่า "ยืนยัน" ให้ตรงตัว</small>
                        </div>
                        <input type="hidden" name="confirm_clear" value="yes">
                        <input type="hidden" name="clear_all_data" value="1">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" form="clearDataForm" class="btn btn-danger" id="confirmClearBtn" disabled>
                        <i class="fas fa-trash-alt"></i> ลบข้อมูลทั้งหมด
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        // ฟังก์ชันตรวจสอบการยืนยันลบข้อมูล
        function validateConfirmation() {
            const confirmText = document.getElementById('confirmText');
            const confirmBtn = document.getElementById('confirmClearBtn');
            
            if (confirmText && confirmBtn) {
                if (confirmText.value === 'ยืนยัน') {
                    confirmBtn.disabled = false;
                } else {
                    confirmBtn.disabled = true;
                }
            }
        }
        
        // ฟังก์ชันแปลงขนาดไฟล์เป็นรูปแบบที่อ่านง่าย
       
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' bytes';
            else if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            else return (bytes / 1048576).toFixed(1) + ' MB';
        }
        
        // รวมโค้ดที่ต้องทำงานเมื่อ DOM โหลดเสร็จ
        document.addEventListener('DOMContentLoaded', function() {
            // จัดการกับ Custom File Input
            const fileInputs = document.querySelectorAll('.custom-file-input');
            if (fileInputs && fileInputs.length > 0) {
                fileInputs.forEach(function(input) {
                    input.addEventListener('change', function() {
                        var fileName = this.value.split('\\').pop();
                        var label = this.nextElementSibling;
                        if (label) {
                            label.textContent = fileName || 'เลือกไฟล์...';
                        }
                        
                        // ตรวจสอบนามสกุลไฟล์
                        if (fileName && !fileName.toLowerCase().endsWith('.csv')) {
                            alert('กรุณาเลือกไฟล์ CSV เท่านั้น (.csv)');
                            this.value = '';
                            label.textContent = 'เลือกไฟล์...';
                            return;
                        }
                    });
                });
            }
            
            // จัดการกับปุ่มยืนยันการลบข้อมูล
            const confirmText = document.getElementById('confirmText');
            const confirmClearBtn = document.getElementById('confirmClearBtn');
            
            if (confirmText && confirmClearBtn) {
                confirmText.addEventListener('input', validateConfirmation);
            }
            
            // ตัวแปรพื้นที่อัปโหลด
            // const uploadArea = document.getElementById('uploadArea'); // ลบการเรียกใช้ uploadArea ถ้าไม่มีใน HTML
            // const fileInput = document.getElementById('csvFileInput'); // ลบการเรียกใช้ fileInput ถ้าไม่มีใน HTML
            // const submitBtn = document.getElementById('submitBtn'); // ลบการเรียกใช้ submitBtn ถ้าไม่มีใน HTML
            // const fileInfo = document.getElementById('fileInfo'); // ลบการเรียกใช้ fileInfo ถ้าไม่มีใน HTML
            // const fileName = document.getElementById('fileName'); // ลบการเรียกใช้ fileName ถ้าไม่มีใน HTML
            
            // ส่วนเพิ่มลิงก์ไปยังเครื่องมือแก้ไขการแมป
            const uploadForm = document.querySelector('form[enctype="multipart/form-data"]');
            if (uploadForm) {
                const uploadBtnGroup = uploadForm.querySelector('.form-group:last-child');
                if (uploadBtnGroup && !uploadBtnGroup.querySelector('a[href="fix_column_mapping.php"]')) {
                    const fixMappingLink = document.createElement('a');
                    fixMappingLink.className = 'btn btn-warning ml-2';
                    // fixMappingLink.href = 'fix_column_mapping.php'; // ลิงก์ไปยังหน้าแก้ไขการแมป
                    // fixMappingLink.textContent = 'แก้ไขการแมปคอลัมน์';
                    // uploadBtnGroup.appendChild(fixMappingLink);
                }
            }
            
            // เพิ่มคำแนะนำเกี่ยวกับรูปแบบไฟล์ CSV
            // ส่วนนี้ถูกย้ายไปอยู่ใน HTML โดยตรงแล้ว
            // const uploadInfoAlert = document.createElement('div');
            // uploadInfoAlert.className = 'alert alert-info mt-3';
            // uploadInfoAlert.innerHTML = `
            //     <h5><i class="fas fa-info-circle"></i> ข้อแนะนำเพิ่มเติม:</h5>
            //     <ul>
            //         <li>ไฟล์ CSV ต้องมีคอลัมน์ <strong>Part_Number</strong> สำหรับรหัสชิ้นส่วน เช่น N1WB-16450-CB</li>
            //         <li>ไฟล์ CSV ต้องมีคอลัมน์ <strong>Part_Group</strong> สำหรับชื่อกลุ่ม เช่น GROUP 11R</li>
            //         <li>ไฟล์ CSV ควรมีคอลัมน์ <strong>Model</strong> สำหรับรุ่นรถ เช่น U704</li>
            //     </ul>
            // `;
            
            // เพิ่มคำแนะนำหลังตัวอย่างรูปแบบ CSV
            // const csvExampleBox = document.querySelector('.alert.alert-info');
            // if (csvExampleBox) {
            //     csvExampleBox.appendChild(uploadInfoAlert);
            // }
            
            // จัดการเมื่อมีการคลิกที่พื้นที่อัปโหลด
            // ลบส่วนนี้ถ้าไม่มี uploadArea ใน HTML
            // if (uploadArea && fileInput) {
            //     uploadArea.addEventListener('click', function() {
            //         fileInput.click();
            //     });
            // }
            
            // จัดการ Drag & Drop
            // ลบส่วนนี้ถ้าไม่มี uploadArea ใน HTML
            // if (uploadArea) {
            //     ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            //         uploadArea.addEventListener(eventName, function(e) {
            //             e.preventDefault();
            //             e.stopPropagation();
            //         }, false);
            //     });
                
            //     ['dragenter', 'dragover'].forEach(eventName => {
            //         uploadArea.addEventListener(eventName, function() {
            //             uploadArea.classList.add('border-primary');
            //             uploadArea.style.backgroundColor = '#e3f2fd';
            //         }, false);
            //     });
                
            //     ['dragleave', 'drop'].forEach(eventName => {
            //         uploadArea.addEventListener(eventName, function() {
            //             uploadArea.classList.remove('border-primary');
            //             uploadArea.style.backgroundColor = '#f9f9f9';
            //         }, false);
            //     });
                
            //     // จัดการการดรอปไฟล์
            //     uploadArea.addEventListener('drop', function(e) {
            //         const dt = e.dataTransfer;
            //         const files = dt.files;
                    
            //         if (files.length > 0 && fileInput && fileName && fileInfo && submitBtn) {
            //             fileInput.files = files;
            //             const file = files[0];
                        
            //             // ตรวจสอบประเภทไฟล์
            //             if (file.type === 'text/csv' || file.name.endsWith('.csv')) {
            //                 fileName.textContent = file.name;
            //                 fileInfo.classList.remove('d-none');
            //                 submitBtn.disabled = false;
                            
            //                 // เปลี่ยนสไตล์พื้นที่อัปโหลดเมื่อมีไฟล์
            //                 uploadArea.innerHTML = `
            //                     <i class="fas fa-check-circle upload-icon" style="color: #28a745;"></i>
            //                     <div class="upload-text">ไฟล์พร้อมอัปโหลด</div>
            //                     <div class="upload-hint">${file.name} (${formatFileSize(file.size)})</div>
            //                 `;
            //             } else {
            //                 alert('กรุณาเลือกไฟล์ CSV เท่านั้น');
            //             }
            //         }
            //     }, false);
            // }
        });
    </script>
</body>
</html>
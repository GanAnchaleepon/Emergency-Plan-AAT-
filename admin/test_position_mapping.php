<?php
session_start();
require_once '../config/database.php';

// ตรวจสอบการเชื่อมต่อ
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// โค้ดทดสอบเฉพาะส่วน
$maxPositionsConfig = [
    'GROUP 01' => 18,
    'GROUP 02' => 18,
    'GROUP 03' => 18,
    'GROUP 04' => 16,
    'GROUP 05' => 18,
    'GROUP 06' => 18,
    'GROUP 07' => 16,
    'GROUP 08L' => 20,
    'GROUP 08R' => 20,
    'GROUP 09' => 8,
    'GROUP 10' => 10,
    'GROUP 11L' => 12,
    'GROUP 11R' => 12,
    'GROUP 12' => 16,
    'GROUP 13' => 16,
    'GROUP 14' => 16
];

// ฟังก์ชันสำหรับคำนวณ Mapping
function calculateMapping($rotations, $startRotation, $startPosition, $maxPosition) {
    if (empty($rotations) || !in_array($startRotation, $rotations)) {
        return [];
    }
    
    // เรียงลำดับ Rotation จากน้อยไปมาก (ถ้ายังไม่ได้เรียง)
    sort($rotations, SORT_NATURAL);
    
    // หาตำแหน่งของ start_rotation ใน array
    $startIndex = array_search($startRotation, $rotations);
    
    // คำนวณ mapping
    $mapping = [];
    $count = count($rotations);
    
    for ($i = 0; $i < $count; $i++) {
        $rotation = $rotations[$i];
        
        // คำนวณตำแหน่งเทียบกับ startIndex
        $relativeIndex = ($i - $startIndex + $count) % $count;
        
        // คำนวณ position ที่สอดคล้อง
        $position = (($relativeIndex + $startPosition - 1) % $maxPosition) + 1;
        
        // เก็บ mapping
        $mapping[$rotation] = $position;
    }
    
    return $mapping;
}

// ตัวอย่างข้อมูลทดสอบ
$rotations = ['1001', '1002', '1003', '1004', '1005'];
$startRotation = '1003';
$startPosition = 2;
$maxPosition = 16;

$mappingResult = calculateMapping($rotations, $startRotation, $startPosition, $maxPosition);

// แสดงผล
echo '<h1>ทดสอบ Position Mapping</h1>';
echo '<h2>ข้อมูลนำเข้า:</h2>';
echo '<ul>';
echo '<li>Rotations: ' . implode(', ', $rotations) . '</li>';
echo '<li>Start Rotation: ' . $startRotation . '</li>';
echo '<li>Start Position: ' . $startPosition . '</li>';
echo '<li>Max Position: ' . $maxPosition . '</li>';
echo '</ul>';

echo '<h2>ผลลัพธ์ Mapping:</h2>';
echo '<table border="1" cellpadding="5">';
echo '<tr><th>Rotation</th><th>Position</th></tr>';
foreach ($mappingResult as $rotation => $position) {
    echo "<tr><td>$rotation</td><td>$position</td></tr>";
}
echo '</table>';

// ทดสอบเชื่อมต่อฐานข้อมูล
echo '<h2>ทดสอบเชื่อมต่อฐานข้อมูล:</h2>';
$sql = "SELECT COUNT(*) as count FROM picking_plans";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    echo '<p style="color:green">เชื่อมต่อฐานข้อมูลสำเร็จ มีข้อมูลใน picking_plans: ' . $row['count'] . ' รายการ</p>';
} else {
    echo '<p style="color:red">เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: ' . $conn->error . '</p>';
}

echo '<a href="position_mapping.php" style="display:inline-block; margin-top:20px; padding:10px; background-color:#4CAF50; color:white; text-decoration:none;">กลับไปหน้า Position Mapping</a>';
?>

<?php
// ไฟล์สำหรับตรวจสอบความคืบหน้าการแปลงไฟล์ SSC
session_start();
header('Content-Type: application/json');

// ตรวจสอบว่ามีข้อมูลการแปลงไฟล์ใน session หรือไม่
if (!isset($_SESSION['convert_ssc'])) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการแปลงไฟล์ กรุณาอัปโหลดไฟล์ใหม่']);
    exit;
}

$convert_data = $_SESSION['convert_ssc'];

// ตรวจสอบสถานะการแปลงไฟล์
switch ($convert_data['status']) {
    case 'uploading':
        echo json_encode([
            'status' => 'processing',
            'progress' => 0,
            'message' => 'กำลังอัปโหลดไฟล์...'
        ]);
        break;
        
    case 'processing':
        $processed = $convert_data['processed_blocks'];
        $total = $convert_data['total_blocks'];
        $progress = ($total > 0) ? ($processed / $total * 100) : 0;
        
        echo json_encode([
            'status' => 'processing',
            'progress' => $progress,
            'processed' => $processed,
            'total' => $total,
            'matches' => $convert_data['found_matches'],
            'message' => "กำลังประมวลผลข้อมูล... ($processed/$total บล็อก)"
        ]);
        break;
        
    case 'completed':
        // เมื่อเสร็จสิ้น สร้าง HTML สำหรับแสดงผลลัพธ์
        $result_html = '';
        $output_rows = $convert_data['output_rows'] ?? [];
        
        if (!empty($output_rows)) {
            $output_csv = $convert_data['output_csv'] ?? 'Rol_8500-8529.csv';
            
            $result_html .= "<div class='alert alert-success' style='border-left: 5px solid #28a745;'>
                <h5><i class='fas fa-check-circle'></i> แปลงไฟล์เสร็จสมบูรณ์:</h5>
                <div>จำนวน <span style='color:green; font-weight:bold; font-size: 18px;'>" . count($output_rows) . "</span> รายการ</div>
                <small class='text-muted'>เวลาที่ใช้: " . number_format(($convert_data['end_time'] - $convert_data['start_time']), 2) . " วินาที</small>
            </div>";
            
            $result_html .= "<div class='mt-3'>
                <a href='$output_csv' download='$output_csv' class='btn btn-primary'>
                    <i class='fas fa-download'></i> ดาวน์โหลดไฟล์ CSV
                </a>
            </div>";
            
            // แสดงตัวอย่างข้อมูล 5 แถวแรก
            if (count($output_rows) > 0) {
                $result_html .= "<div class='mt-3'>
                    <h5>ตัวอย่างข้อมูล 5 รายการแรก:</h5>
                    <div class='table-responsive'>
                        <table class='table table-sm table-bordered'>
                            <thead class='thead-light'>
                                <tr>
                                    <th>Rotation</th>
                                    <th>Part Group</th>
                                    <th>Part Number</th>
                                    <th>Supplier</th>
                                    <th>Location</th>
                                    <th>Model</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>";
                
                $sample_rows = array_slice($output_rows, 0, 5);
                foreach ($sample_rows as $row) {
                    $result_html .= "<tr>";
                    foreach ($row as $cell) {
                        $result_html .= "<td>" . htmlspecialchars($cell) . "</td>";
                    }
                    $result_html .= "</tr>";
                }
                
                $result_html .= "</tbody></table></div>";
                
                if (count($output_rows) > 5) {
                    $result_html .= "<div class='text-center text-muted'>+ " . (count($output_rows) - 5) . " รายการเพิ่มเติม</div>";
                }
            }
        } else {
            $result_html .= "<div class='alert alert-warning' style='border-left: 5px solid #ffc107;'>
                <h5><i class='fas fa-exclamation-triangle'></i> ไม่พบข้อมูลที่ตรงกัน:</h5>
                <div>ไม่พบข้อมูลที่ตรงกับฐานข้อมูล Master</div>
                <small class='text-muted'>โปรดตรวจสอบว่าได้ Import Master Data แล้ว และข้อมูลในไฟล์ SSC ถูกต้อง</small>
            </div>";
        }
        
        // คืนค่าผลลัพธ์
        echo json_encode([
            'status' => 'completed',
            'progress' => 100,
            'result' => $result_html,
            'matches' => count($output_rows),
            'processed_blocks' => $convert_data['processed_blocks'],
            'total_blocks' => $convert_data['total_blocks']
        ]);
        break;
        
    case 'error':
        echo json_encode([
            'status' => 'error',
            'message' => isset($convert_data['error_message']) ? $convert_data['error_message'] : 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'
        ]);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'สถานะไม่ถูกต้อง: ' . $convert_data['status']
        ]);
}
?>

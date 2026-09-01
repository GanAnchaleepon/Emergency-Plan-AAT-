<?php
// ไฟล์สำหรับตรวจสอบความคืบหน้าการนำเข้าข้อมูล
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

// ตรวจสอบว่ามีข้อมูลการนำเข้าใน session หรือไม่
if (!isset($_SESSION['import_file'])) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการนำเข้า กรุณาอัปโหลดไฟล์ใหม่']);
    exit;
}

$import_data = $_SESSION['import_file'];

// ตรวจสอบสถานะการนำเข้า
switch ($import_data['status']) {
    case 'waiting':
    case 'ready':
        echo json_encode([
            'status' => 'processing',
            'progress' => 0,
            'current' => 0,
            'total' => $import_data['total_lines'],
            'message' => 'กำลังเตรียมนำเข้าข้อมูล...'
        ]);
        break;
        
    case 'processing':
        $current = $import_data['current_line'];
        $total = $import_data['total_lines'];
        $progress = ($total > 0) ? ($current / $total * 100) : 0;
        
        echo json_encode([
            'status' => 'processing',
            'progress' => $progress,
            'current' => $current,
            'total' => $total,
            'imported' => $import_data['imported'],
            'updated' => $import_data['updated'],
            'duplicate' => $import_data['duplicate'],
            'message' => "กำลังนำเข้าข้อมูล... ($current/$total)"
        ]);
        break;
        
    case 'completed':
        // เมื่อเสร็จสิ้น สร้าง HTML สำหรับแสดงผลลัพธ์
        $imported = $import_data['imported'];
        $updated = $import_data['updated'];
        $duplicate = $import_data['duplicate'];
        $duplicate_msgs = $import_data['duplicate_msgs'];
        $total_records = $imported + $updated + $duplicate;
        
        // สร้าง HTML สำหรับแสดงผลลัพธ์คล้ายกับใน index.php
        $result_html = '';
        
        // ข้อมูลไฟล์ที่อัปโหลด
        $result_html .= "<div class='alert alert-info'>ไฟล์ที่อัปโหลด: <strong>{$import_data['name']}</strong><br>วันที่ไฟล์: <strong>" . date('d/m/Y H:i:s', $import_data['time']) . "</strong></div>";
        
        // ดึงข้อมูลการอัปโหลดล่าสุดจาก master_uploads
        $latest_master_upload = '';
        $sql = "SELECT filename, filedate, uploaded_at FROM master_uploads ORDER BY uploaded_at DESC LIMIT 1";
        $result = $conn->query($sql);
        if ($result && $row = $result->fetch_assoc()) {
            $latest_master_upload = "<div class='alert alert-secondary'>ไฟล์ล่าสุด: <strong>{$row['filename']}</strong><br>วันที่ไฟล์: <strong>" . date('d/m/Y H:i:s', strtotime($row['filedate'])) . "</strong><br>อัปโหลดเมื่อ: <strong>" . date('d/m/Y H:i:s', strtotime($row['uploaded_at'])) . "</strong></div>";
        }
        $result_html .= $latest_master_upload;
        
        // แสดงสรุปภาพรวมข้อมูล
        if ($total_records > 0) {
            $result_html .= "<div class='alert alert-primary' style='border-left: 5px solid #007bff;'>
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
            $result_html .= "<div class='alert alert-success' style='border-left: 5px solid #28a745;'>
                <h5><i class='fas fa-plus-circle'></i> นำเข้าข้อมูลใหม่สำเร็จ:</h5>
                <div>จำนวน <span style='color:green; font-weight:bold; font-size: 18px;'>$imported</span> รายการ</div>
                <small class='text-muted'>ข้อมูลที่ไม่เคยมีในระบบ (Part Group, Part Number, Supplier ไม่ซ้ำกับข้อมูลเดิม)</small>
            </div>";
        }

        // รายละเอียดการอัปเดตข้อมูล
        if ($updated > 0) {
            $result_html .= "<div class='alert alert-info' style='border-left: 5px solid #17a2b8;'>
                <h5><i class='fas fa-sync-alt'></i> อัปเดตข้อมูล Location Pick:</h5>
                <div>จำนวน <span style='color:blue; font-weight:bold; font-size: 18px;'>$updated</span> รายการ</div>
                <small class='text-muted'>ข้อมูลที่มี Part Group, Part Number, Supplier ตรงกับข้อมูลเดิม แต่ Location Pick แตกต่าง</small>
            </div>";
        }

        // รายละเอียดข้อมูลซ้ำ
        if ($duplicate > 0) {
            $result_html .= "<div class='alert alert-warning' style='border-left: 5px solid #ffc107;'>
                <h5><i class='fas fa-exclamation-triangle'></i> พบข้อมูลซ้ำ (ไม่นำเข้า):</h5>
                <div>จำนวน <span style='color:orange; font-weight:bold; font-size: 18px;'>$duplicate</span> รายการ</div>
                <small class='text-muted'>ข้อมูลที่มี Part Group, Part Number, Supplier และ Location Pick ตรงกับข้อมูลเดิมทั้งหมด</small>";
            
            // แสดงตัวอย่างข้อมูลซ้ำไม่เกิน 5 รายการทันที
            if (!empty($duplicate_msgs)) {
                $sample_duplicates = array_slice($duplicate_msgs, 0, 5);
                $result_html .= "<div class='mt-2'>
                    <h6>ตัวอย่างข้อมูลซ้ำ:</h6>
                    <ul style='font-size: 12px;'>";
                foreach ($sample_duplicates as $msg) {
                    $result_html .= "<li>$msg</li>";
                }
                $result_html .= "</ul></div>";
            }
            
            // ถ้ามีรายการซ้ำมากกว่า 5 รายการ แสดงปุ่มให้ดูทั้งหมด
            if (count($duplicate_msgs) > 5) {
                $result_html .= "<div class='mt-2'>
                    <button class='btn btn-sm btn-outline-warning' type='button' data-toggle='collapse' data-target='#collapseDuplicates'>
                        แสดงรายการซ้ำทั้งหมด (" . count($duplicate_msgs) . " รายการ)
                    </button>
                    <div class='collapse mt-2' id='collapseDuplicates'>
                        <div class='card card-body' style='max-height: 300px; overflow-y: auto;'>
                            <ul style='font-size: 12px; margin-bottom: 0;'>";
                foreach ($duplicate_msgs as $msg) {
                    $result_html .= "<li>$msg</li>";
                }
                $result_html .= "</ul>
                        </div>
                    </div>
                </div>";
            }
            
            $result_html .= "</div>";
        }

        // ถ้าไม่มีข้อมูลนำเข้าเลย
        if ($imported == 0 && $updated == 0 && $duplicate == 0) {
            $result_html .= "<div class='alert alert-danger' style='border-left: 5px solid #dc3545;'>
                <h5><i class='fas fa-times-circle'></i> ไม่พบข้อมูลนำเข้า</h5>
                <div>ไม่พบข้อมูลใหม่ที่สามารถนำเข้าได้ กรุณาตรวจสอบไฟล์ของคุณ</div>
                <small class='text-muted'>อาจเกิดจากไฟล์ว่างเปล่า หรือรูปแบบข้อมูลไม่ถูกต้อง</small>
            </div>";
        }
        
        // ล้าง session
        // $_SESSION['import_file'] = null;
        
        echo json_encode([
            'status' => 'completed',
            'progress' => 100,
            'result' => $result_html,
            'imported' => $imported,
            'updated' => $updated, 
            'duplicate' => $duplicate,
            'total' => $total_records
        ]);
        break;
        
    case 'error':
        echo json_encode([
            'status' => 'error',
            'message' => isset($import_data['error_message']) ? $import_data['error_message'] : 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'
        ]);
        break;
        
    default:
        echo json_encode([
            'status' => 'error',
            'message' => 'สถานะไม่ถูกต้อง: ' . $import_data['status']
        ]);
}
?>

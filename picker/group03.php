<?php
session_start();
require_once '../config/database.php'; // ปรับ path ตามโครงสร้างโฟลเดอร์ของคุณ

// --- Configuration ---
$current_group_name = "GROUP 03"; // กำหนดกลุ่มสำหรับหน้านี้ // เปลี่ยนจาก "GROUP 02" เป็น "GROUP 03"
$group_session_key = 'current_step_' . str_replace(' ', '_', $current_group_name);
$plan_id_session_key = 'current_plan_id_' . str_replace(' ', '_', $current_group_name);

// --- Helper Functions (ควรย้ายไปไฟล์แยกถ้าซับซับซ้อนขึ้น) ---

/**
 * หา Rotation ปัจจุบันที่กำลังทำอยู่
 * @param mysqli $conn Connection object
 * @param string $groupName ชื่อกลุ่ม
 * @return string|null หมายเลข Rotation หรือ null ถ้าไม่พบ
 */
function getCurrentRotation($conn, $groupName) {
    // หา Rotation ที่มีงาน inprogress
    $stmt = $conn->prepare("SELECT DISTINCT rotation FROM picking_plans WHERE group_name = ? AND status = 'inprogress' LIMIT 1");
    $stmt->bind_param("s", $groupName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['rotation'];
    }
    
    // ถ้าไม่มี inprogress หา Rotation แรกที่ยังมีงาน pending
    $stmt = $conn->prepare("SELECT DISTINCT rotation FROM picking_plans WHERE group_name = ? AND status = 'pending' ORDER BY STR_TO_DATE(date, '%d/%c/%Y') ASC, STR_TO_DATE(time, '%H:%i:%s') ASC, CAST(rotation AS UNSIGNED) ASC LIMIT 1");
    $stmt->bind_param("s", $groupName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['rotation'];
    }
    
    return null;
}

/**
 * ดึงงานปัจจุบันที่ต้องทำสำหรับกลุ่มที่กำหนด
 * @param mysqli $conn Connection object
 * @param string $groupName ชื่อกลุ่ม
 * @param string $planIdSessionKey คีย์ session สำหรับเก็บ plan_id ปัจจุบัน
 * @return array|null ข้อมูลงาน หรือ null ถ้าไม่พบ
 */
function getCurrentTask($conn, $groupName, $planIdSessionKey) {
    // หา plan_id ล่าสุดที่ยังไม่เสร็จ (pending หรือ inprogress) หรือกำลังทำอยู่จาก session
    if (isset($_SESSION[$planIdSessionKey])) {
        $planId = $_SESSION[$planIdSessionKey];
        $stmt = $conn->prepare("SELECT * FROM picking_plans WHERE id = ? AND group_name = ? AND status != 'completed'");
        $stmt->bind_param("is", $planId, $groupName);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($task = $result->fetch_assoc()) {
            return $task;
        }
        // ถ้างานใน session เสร็จแล้ว หรือหาไม่เจอ ให้หาใหม่
        unset($_SESSION[$planIdSessionKey]);
    }

    // หางานแรกที่ยังไม่เสร็จ (pending) หรือกำลังทำ (inprogress)
    // เรียงตาม Rotation แบบธรรมชาติ (ตัวเลข) แล้วตาม Position
    $stmt = $conn->prepare("SELECT * FROM picking_plans WHERE group_name = ? AND status IN ('pending', 'inprogress') ORDER BY STR_TO_DATE(date, '%d/%c/%Y') ASC, STR_TO_DATE(time, '%H:%i:%s') ASC, CAST(rotation AS UNSIGNED), position ASC LIMIT 1");
    $stmt->bind_param("s", $groupName);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($task = $result->fetch_assoc()) {
        $_SESSION[$planIdSessionKey] = $task['id'];
        // อัปเดตสถานะเป็น inprogress ถ้ายังเป็น pending
        if ($task['status'] === 'pending') {
            updatePickingPlanStatus($conn, $task['id'], 'inprogress');
            $task['status'] = 'inprogress'; // อัปเดตค่าในตัวแปร task ด้วย
        }
        return $task;
    }
    return null;
}

/**
 * อัปเดตสถานะของ picking_plan
 * @param mysqli $conn
 * @param int $planId
 * @param string $status
 * @return bool
 */
function updatePickingPlanStatus($conn, $planId, $status) {
    $stmt = $conn->prepare("UPDATE picking_plans SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $planId);
    return $stmt->execute();
}

/**
 * บันทึก item_lot
 * @param mysqli $conn
 * @param int $planId
 * @param string $itemLot
 * @return bool
 */
function updateItemLot($conn, $planId, $itemLot) {
    $stmt = $conn->prepare("UPDATE picking_plans SET item_lot = ? WHERE id = ?");
    $stmt->bind_param("si", $itemLot, $planId);
    return $stmt->execute();
}

/**
 * บันทึกกิจกรรมการหยิบ
 * @param mysqli $conn
 * @param int $planId
 * @param string $action
 * @param string|null $scanData
 * @param string $status 'success' or 'error'
 * @return bool
 */
function logActivity($conn, $planId, $action, $scanData, $status) {
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $stmt = $conn->prepare("INSERT INTO picking_activities (plan_id, action, scan_data, status, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $planId, $action, $scanData, $status, $ip_address);
    return $stmt->execute();
}

/**
 * สร้างตัวย่อชื่อกลุ่มสำหรับ QR Code
 * @param string $groupName ชื่อกลุ่มเต็ม
 * @return string ตัวย่อชื่อกลุ่ม
 */
function getGroupAbbreviationForQR($groupName) {
    // ลบคำว่า "GROUP" และเว้นวรรค แล้วเอาเฉพาะหมายเลขและ L/R ถ้ามี
    $groupNumber = preg_replace('/[^0-9LR]/', '', $groupName);
    
    // ถ้าไม่มีหมายเลข ให้ใช้ค่าเริ่มต้น
    if (empty($groupNumber)) {
        $groupNumber = '01';
    }
    
    // ไม่ต้องเติม 0 ด้านหน้าแล้ว เพราะบางกลุ่มมี L/R ต่อท้าย
    return 'G' . $groupNumber;
}

/**
 * สร้างข้อมูลสำหรับ QR Code
 * @param string $groupName ชื่อกลุ่ม
 * @param string $rotation หมายเลข Rotation
 * @return string ข้อมูลสำหรับ QR Code
 */
function generateExpectedQRData($groupName, $rotation) {
    $groupAbbr = getGroupAbbreviationForQR($groupName);
    $currentDate = date('Ymd'); // รูปแบบ YYYYMMDD
    return $groupAbbr . '_' . $currentDate . formatRotation($rotation);
}

/**
 * จัดรูปแบบ Rotation ให้เป็น 4 หลัก (เช่น 1 -> 0001)
 * @param string|int $rotation
 * @return string
 */
function formatRotation($rotation) {
    return str_pad((string)intval($rotation), 4, '0', STR_PAD_LEFT);
}

/**
 * ตรวจสอบว่าเป็น Dummy Part หรือไม่
 * @param string $partNumber หมายเลข Part
 * @return bool
 */
function isDummyPart($partNumber) {
    return (stripos($partNumber, 'dummy') !== false);
}


// --- Main Logic ---
// ดึงงานปัจจุบัน
$task = getCurrentTask($conn, $current_group_name, $plan_id_session_key);

// ตรวจสอบว่างานปัจจุบันเป็น Dummy Part หรือไม่
$is_dummy_part = ($task && isDummyPart($task['part_number']));

// กำหนดขั้นตอนปัจจุบัน (ถ้าไม่มีงาน ให้ตั้งเป็น 'no_task')
// ปรับขั้นตอนเริ่มต้นให้ข้าม scan_rotation_label
$current_step = $task ? ($_SESSION[$group_session_key] ?? 'scan_part_number') : 'no_task';

// ข้อความแจ้งเตือน
$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';

// ล้างข้อความแจ้งเตือนใน session หลังจากอ่านแล้ว
unset($_SESSION['error_message']);
unset($_SESSION['success_message']);

// Blacklist lot numbers
$item_lot_blacklist = [
    '14B060', '14290', '14A005', '14401', '14405',
    '14630', '14631', '14632', '14633', '14A006'
];

// ประมวลผลการสแกนจาก POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $task) {
    $plan_id = $task['id'];
    $submitted_step = $_POST['submitted_step'] ?? ''; // Hidden field set by JS
    $scanned_data = $_POST['scanned_data'] ?? ''; // Scanned data from JS (ไม่ trim เพื่อเก็บ space)

    // ตรวจสอบว่าข้อมูลที่ส่งมาตรงกับขั้นตอนปัจจุบันหรือไม่
    if ($submitted_step === $current_step) {
        $is_success = false;
        $new_step = $current_step; // ขั้นตอนถัดไป (ค่าเริ่มต้นคือขั้นตอนเดิม)
        $log_action = $current_step;
        $log_status = 'error'; // ค่าเริ่มต้นสถานะ log

        switch ($current_step) {
            case 'scan_part_number':
                // ลบขีดออกจาก Part Number ของระบบและแปลงเป็นตัวพิมพ์ใหญ่เพื่อเปรียบเทียบ
                $system_part_number_cleaned = strtoupper(str_replace('-', '', $task['part_number']));
                // ลบขีดออกจากข้อมูลที่สแกนและแปลงเป็นตัวพิมพ์ใหญ่เพื่อเปรียบเทียบ
                $scanned_part_number_cleaned = strtoupper(str_replace('-', '', $scanned_data));

                // เปรียบเทียบแบบไม่มีขีดและตัวพิมพ์ใหญ่เท่านั้น
                if ($scanned_part_number_cleaned === $system_part_number_cleaned) {
                    logActivity($conn, $plan_id, 'scan_part_number', $scanned_data, 'success');
                    $is_success = true;
                    // ข้าม scan_rotation_label ไป scan_item_lot เลย
                    $new_step = 'scan_item_lot';
                    $log_status = 'success';
                    $_SESSION['success_message'] = 'สแกน Part Number ถูกต้อง: ' . htmlspecialchars($scanned_data);
                } else {
                    logActivity($conn, $plan_id, 'scan_part_number', $scanned_data, 'error');
                    $_SESSION['error_message'] = 'Part Number ไม่ถูกต้อง! สแกนได้: ' . htmlspecialchars($scanned_data) . '';
                }
                break;

            case 'scan_item_lot':
                $is_blacklisted = false;
                // ตรวจสอบว่ามีคำใน blacklist อยู่ในข้อมูลที่สแกนหรือไม่
                foreach ($item_lot_blacklist as $blacklisted_string) {
                    if (strpos($scanned_data, $blacklisted_string) !== false) {
                        $is_blacklisted = true;
                        break;
                    }
                }

                // ตรวจสอบว่าสแกน Part Number ซ้ำหรือไม่
                $system_part_number_cleaned = strtoupper(str_replace('-', '', $task['part_number']));
                $scanned_data_cleaned = strtoupper(str_replace('-', '', $scanned_data));
                if ($scanned_data_cleaned === $system_part_number_cleaned) {
                    $_SESSION['error_message'] = 'ข้อมูลที่สแกนเป็น Part Number กรุณาสแกน Lot สินค้า';
                    break;
                }

                // ตรวจสอบว่าสแกน Rotation Label หรือไม่ (รูปแบบ GXX_YYYYMMDD...)
                if (preg_match('/^G\d{2,3}[LR]?_\d{8}/', $scanned_data)) {
                    $_SESSION['error_message'] = 'ข้อมูลที่สแกนเป็น Rotation Label กรุณาสแกน Lot สินค้า';
                    break;
                }
                
                // ตรวจสอบว่ามีภาษาไทยหรือไม่
                if (preg_match('/[ก-๙]/u', $scanned_data)) {
                    $_SESSION['error_message'] = 'Lot สินค้าต้องไม่มีภาษาไทย';
                }
                // *** ยกเลิกการตรวจสอบรูปแบบอักขระพิเศษ ***
                // elseif (!preg_match('/^[A-Za-z0-9\/\-\.\s\+\*\$\%\_]+$/', $scanned_data)) {
                //     $_SESSION['error_message'] = 'Lot สินค้าต้องเป็นภาษาอังกฤษ ตัวเลข หรืออักขระพิเศษที่อนุญาตเท่านั้น (เช่น / - . + * $ % _)';
                // } 
                // ตรวจสอบ blacklist
                elseif ($is_blacklisted) {
                    $_SESSION['error_message'] = 'Lot สินค้านี้ (' . htmlspecialchars($scanned_data) . ') มีข้อมูลที่ไม่ได้รับอนุญาต';
                } 
                // เพิ่มการตรวจสอบความยาว 12 ตัวอักษร
                elseif (strlen($scanned_data) !== 12) {
                    $_SESSION['error_message'] = 'Lot สินค้าต้องมี 12 ตัวอักษรเท่านั้น';
                }
                else {
                    if (updateItemLot($conn, $plan_id, $scanned_data)) {
                        $is_success = true;
                        // ข้าม scan_rotation_label ไป scan_position เลย
                        $new_step = 'scan_position';
                        $log_status = 'success';
                        $_SESSION['success_message'] = 'บันทึก Lot สินค้าสำเร็จ: ' . htmlspecialchars($scanned_data);
                        $task['item_lot'] = $scanned_data; // Update task data for display
                    } else {
                        $_SESSION['error_message'] = 'เกิดข้อผิดพลาดในการบันทึก Lot สินค้า';
                    }
                }
                break;

            // ลบ case 'scan_rotation_label' ออกทั้งหมด
            // case 'scan_rotation_label':
            //     $scanned_rotation_label_original = $scanned_data;
            //     $expected_qr_data = generateExpectedQRData($task['group_name'], $task['rotation']);

            //     // ลบ 'R' ตัวเดียวถัดจาก 'G' ที่ขึ้นต้น (เช่น GR01_... -> G01_..., GR08R_... -> G08R_...)
            //     $scanned_rotation_label_to_compare = preg_replace('/^GR/', 'G', $scanned_rotation_label_original, 1);
            //     $expected_qr_data_to_compare = preg_replace('/^GR/', 'G', $expected_qr_data, 1);

            //     if ($scanned_rotation_label_to_compare === $expected_qr_data_to_compare) {
            //         logActivity($conn, $plan_id, 'scan_rotation_label', $scanned_data, 'success');
            //         $is_success = true;
            //         $new_step = 'scan_position';
            //         $log_status = 'success';
            //         $_SESSION['success_message'] = 'สแกน Rotation Label ถูกต้อง';
            //     } else {
            //         logActivity($conn, $plan_id, 'scan_rotation_label', $scanned_data, 'error');
            //         $_SESSION['error_message'] = 'Rotation Label ไม่ถูกต้อง! : ' . htmlspecialchars($scanned_rotation_label_original);
            //     }
            //     break;

            case 'scan_position':
                // สมมติว่า Position ที่สแกนคือหมายเลข Position
                if ($scanned_data == $task['position']) { // ใช้ == เพราะอาจเป็น string vs int
                    $is_success = true;
                    $log_status = 'success';
                    $_SESSION['success_message'] = 'สแกนตำแหน่งถูกต้อง งานสำหรับ Position นี้เสร็จสมบูรณ์!';
                    
                    // อัปเดตสถานะเป็น completed
                    updatePickingPlanStatus($conn, $plan_id, 'completed');

                    // ตรวจสอบว่ามีงานเหลือใน Rotation นี้หรือไม่
                    $stmt_check_next = $conn->prepare("SELECT id FROM picking_plans WHERE group_name = ? AND rotation = ? AND status != 'completed' LIMIT 1");
                    $stmt_check_next->bind_param("ss", $current_group_name, $task['rotation']);
                    $stmt_check_next->execute();
                    $result_check_next = $stmt_check_next->get_result();

                    if ($result_check_next->num_rows == 0) { // ไม่มีงานเหลือใน rotation นี้
                        $new_step = 'display_summary';
                        $_SESSION['current_rotation_summary'] = $task['rotation'];
                    } else {
                        // ไปยังงานถัดไป (Position ถัดไป)
                        unset($_SESSION[$plan_id_session_key]); // ล้าง session plan_id เพื่อให้ getCurrentTask หาใหม่
                        $new_step = 'scan_part_number'; // เริ่มขั้นตอนใหม่สำหรับ Position ถัดไป
                    }
                } else {
                    $_SESSION['error_message'] = 'ตำแหน่งที่สแกนไม่ถูกต้อง! สแกนได้: ' . htmlspecialchars($scanned_data);
                }
                break;
        }

        // บันทึกกิจกรรม
        logActivity($conn, $plan_id, $log_action, $scanned_data, $log_status);

        // อัปเดตขั้นตอนใน session
        $_SESSION[$group_session_key] = $new_step;

        // Redirect เพื่อป้องกันการ resubmit form และอัปเดตสถานะหน้า
        header("Location: group03.php?group=" . urlencode($current_group_name)); // เปลี่ยนจาก group02.php เป็น group03.php
        exit;
    } else {
         // กรณีที่ submitted_step ไม่ตรงกับ current_step (อาจเกิดจากการกด back หรือ refresh)
         // ไม่ต้องทำอะไร ปล่อยให้หน้าโหลดใหม่ตาม session state ปัจจุบัน
         // หรืออาจจะ log error ถ้าต้องการ
         // logActivity($conn, $task['id'], 'unexpected_submit', "Submitted step: $submitted_step, Current step: $current_step", 'warning');
    }
}

// ประมวลผลการข้าม Dummy Part
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_dummy']) && $task) {
    $plan_id = $task['id'];
    
    // ตรวจสอบว่าเป็น Dummy Part จริงหรือไม่
    if (isDummyPart($task['part_number'])) {
        // อัปเดตสถานะเป็น completed
        updatePickingPlanStatus($conn, $plan_id, 'completed');
        
        // บันทึกกิจกรรมการข้าม Dummy
        logActivity($conn, $plan_id, 'skip_dummy', $task['part_number'], 'success');
        
        // ตรวจสอบว่ามีงานเหลือใน Rotation นี้หรือไม่
        $stmt_check_next = $conn->prepare("SELECT id FROM picking_plans WHERE group_name = ? AND rotation = ? AND status != 'completed' LIMIT 1");
        $stmt_check_next->bind_param("ss", $current_group_name, $task['rotation']);
        $stmt_check_next->execute();
        $result_check_next = $stmt_check_next->get_result();

        if ($result_check_next->num_rows == 0) { // ไม่มีงานเหลือใน rotation นี้
            $new_step = 'display_summary';
            $_SESSION[$group_session_key] = $new_step;
            $_SESSION['current_rotation_summary'] = $task['rotation'];
        } else {
            // ไปยังงานถัดไป (Position ถัดไป)
            unset($_SESSION[$plan_id_session_key]); // ล้าง session plan_id เพื่อให้ getCurrentTask หาใหม่
            $_SESSION[$group_session_key] = 'scan_part_number'; // เริ่มขั้นตอนใหม่สำหรับ Position ถัดไป
        }
        
        $_SESSION['success_message'] = 'ข้าม Dummy Part สำเร็จ';
        
        // Redirect เพื่อป้องกันการ resubmit form และอัปเดตสถานะหน้า
        header("Location: group03.php?group=" . urlencode($current_group_name));
        exit;
    }
}

// ดึงข้อมูลสรุปสำหรับ Rotation (ถ้ามี)
$summary_items = [];
if ($current_step === 'display_summary' && isset($_SESSION['current_rotation_summary'])) {
    $rotation_to_summarize = $_SESSION['current_rotation_summary'];
    $stmt_summary = $conn->prepare("SELECT part_number, item_lot, quantity FROM picking_plans WHERE group_name = ? AND rotation = ? AND status = 'completed' ORDER BY position ASC");
    $stmt_summary->bind_param("ss", $current_group_name, $rotation_to_summarize);
    $stmt_summary->execute();
    $result_summary = $stmt_summary->get_result();
    while($row = $result_summary->fetch_assoc()){
        $summary_items[] = $row;
    }
}

// หากอยู่ในขั้นตอน display_summary และผู้ใช้กดยืนยันสรุป
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_summary']) && $current_step === 'display_summary') {
    // ไปยัง Rotation ถัดไป
    unset($_SESSION[$plan_id_session_key]); // ล้าง session plan_id เพื่อให้ getCurrentTask หาใหม่
    unset($_SESSION['current_rotation_summary']); // ล้าง session summary rotation
    
    // ดึงงานถัดไป
    $task = getCurrentTask($conn, $current_group_name, $plan_id_session_key);

    if ($task) {
        $_SESSION[$group_session_key] = 'scan_part_number'; // เริ่มขั้นตอนใหม่สำหรับงานถัดไป
    } else {
        $_SESSION[$group_session_key] = 'group_complete'; // ไม่มีงานเหลือแล้ว
    }

    // Redirect เพื่ออัปเดตหน้า
    header("Location: group03.php?group=" . urlencode($current_group_name)); // เปลี่ยนจาก group02.php เป็น group03.php
    exit;
}


// --- ดึงข้อมูลแผนการหยิบทั้งหมดสำหรับกลุ่มนี้ ---
$all_plans_for_group = [];
// หา Rotation ปัจจุบัน
$current_rotation = getCurrentRotation($conn, $current_group_name);
// ดึงเฉพาะงานที่ยังไม่เสร็จ (ซ่อนงานที่ completed)
$stmt_all_plans = $conn->prepare("SELECT rotation, position, part_number, supplier, quantity, location, status FROM picking_plans WHERE group_name = ? AND status != 'completed' ORDER BY STR_TO_DATE(date, '%d/%c/%Y') ASC, STR_TO_DATE(time, '%H:%i:%s') ASC, CAST(rotation AS UNSIGNED), position ASC");
$stmt_all_plans->bind_param("s", $current_group_name);
$stmt_all_plans->execute();
$result_all_plans = $stmt_all_plans->get_result();
while($row = $result_all_plans->fetch_assoc()){
    $all_plans_for_group[] = $row;
}
$stmt_all_plans->close();


$conn->close();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หยิบงาน <?php echo htmlspecialchars($current_group_name); ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background-color: #f4f6f9; }
        .container { max-width: 1200px; margin-top: 20px; }
        .task-info-card { background-color: #fff; border-radius: .25rem; box-shadow: 0 0 1px rgba(0,0,0,.125),0 1px 3px rgba(0,0,0,.2); padding: 20px; margin-bottom: 20px;}
        .scan-input { font-size: 1.2rem; text-align: center; height: 50px; } /* ปรับขนาด input */
        .form-group label h4 { margin-bottom: 0.5rem; font-size: 1.1rem; } /* ปรับขนาด label */
        .locked-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(192, 57, 43, 0.9); z-index: 9999; display: flex; align-items: center; justify-content: center; color: white; text-align: center; padding: 20px; }
        .locked-overlay h2 { font-size: 1.8rem; margin-bottom: 1rem; }
        .locked-overlay p { font-size: 1.1rem; }
        .summary-table th, .summary-table td { vertical-align: middle !important; }
        
        /* Modern Step Card Design */
        .step-card {
            position: relative;
            border: 2px solid #e0e6ed;
            border-radius: 16px;
            padding: 25px 20px;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        .step-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #e0e6ed 0%, #e0e6ed 100%);
            transition: all 0.3s ease;
        }

        .step-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .step-card.active {
            border-color: #007bff;
            background: linear-gradient(135deg, #e7f3ff 0%, #cfe7ff 100%);
            box-shadow: 0 8px 25px rgba(0, 123, 255, 0.25);
            transform: scale(1.02);
        }

        .step-card.active::before {
            background: linear-gradient(90deg, #007bff 0%, #0056b3 100%);
            height: 5px;
        }

        .step-card.completed {
            border-color: #28a745;
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.15);
        }

        .step-card.completed::before {
            background: linear-gradient(90deg, #28a745 0%, #218838 100%);
        }

        .step-card.error {
            border-color: #dc3545;
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.15);
        }

        .step-card.error::before {
            background: linear-gradient(90deg, #dc3545 0%, #c82333 100%);
        }

        .step-card h4 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .step-card h4 i {
            margin-right: 10px;
            font-size: 1.3rem;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .step-card.active h4 i {
            background: #007bff;
            color: #fff;
            animation: pulse 2s infinite;
        }

        .step-card.completed h4 i {
            background: #28a745;
            color: #fff;
        }

        .step-card.error h4 i {
            background: #dc3545;
            color: #fff;
        }

        .step-card.completed h4 { color: #28a745; }
        .step-card.error h4 { color: #dc3545; }
        .step-card.active h4 { color: #007bff; }

        .step-card .scan-input {
            border: 2px solid #dee2e6;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: #ffffff;
        }

        .step-card.active .scan-input {
            border-color: #007bff;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.1);
        }

        .step-card .scan-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.2);
        }

        .step-card .scan-input:disabled {
            background: #f8f9fa;
            cursor: not-allowed;
            opacity: 0.6;
        }

        /* Pulse Animation */
        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(0, 123, 255, 0.7);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(0, 123, 255, 0);
            }
        }

        /* Step Number Badge */
        .step-card::after {
            content: attr(data-step);
            position: absolute;
            top: 15px;
            right: 15px;
            width: 30px;
            height: 30px;
            background: #e0e6ed;
            color: #6c757d;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .step-card.active::after {
            background: #007bff;
            color: #fff;
            transform: scale(1.1);
        }

        .step-card.completed::after {
            content: '✓';
            background: #28a745;
            color: #fff;
        }

        /* Task Info Card - Enhanced Design */
        .task-info-card {
            background: #ffffff;
            color: #333;
            padding: 30px;
            border-radius: 12px;
            border-left: 5px solid #007bff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .task-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }

        .task-info-item {
            background: #f8f9fa;
            padding: 12px 10px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .task-info-item .label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            opacity: 0.7;
            margin-bottom: 5px;
            display: block;
            color: #495057;
        }

        .task-info-item .value {
            font-size: 1.2rem;
            font-weight: 700;
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
            color: #1a4f72;
        }

        .task-info-item.rotation .value,
        .task-info-item.position .value {
            font-size: 1.4rem;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
            color: #007bff;
        }

        .task-info-item.part-number .value {
            font-size: 1rem;
            text-align: center;
        }

        .task-info-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #dee2e6;
        }

        .task-info-header h2 {
            font-size: 1.25rem;
            margin: 0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #1a4f72;
        }

        /* Responsive adjustments */
        @media (min-width: 768px) {
            .scan-input { font-size: 1.5rem; } /* ขนาดใหญ่ขึ้นบนจอใหญ่ */
            .form-group label h4 { font-size: 1.2rem; }
        }

        /* Modern Table styles */
        .plans-table-container {
            margin-top: 40px;
            background: #ffffff;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e0e6ed;
        }
        
        .plans-table-container h3 {
            margin-bottom: 25px;
            font-size: 1.6rem;
            font-weight: 700;
            color: #1a4f72;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            border-bottom: 3px solid #007bff;
            padding-bottom: 15px;
        }
        
        .plans-table-container h3::before {
            content: '📋';
            margin-right: 12px;
            font-size: 1.8rem;
        }
        
        .plans-table-container .table-responsive {
            max-height: 500px;
            overflow-y: auto;
            border-radius: 12px;
            box-shadow: inset 0 0 10px rgba(0, 0, 0, 0.03);
        }
        
        .plans-table-container .table-responsive::-webkit-scrollbar {
            width: 10px;
        }
        
        .plans-table-container .table-responsive::-webkit-scrollbar-track {
            background: #f1f3f5;
            border-radius: 10px;
        }
        
        .plans-table-container .table-responsive::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #007bff, #0056b3);
            border-radius: 10px;
        }
        
        .plans-table-container .table-responsive::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #0056b3, #004085);
        }
        
        .plans-table-container .table {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .plans-table-container .table thead th {
            background: linear-gradient(135deg, #1a4f72 0%, #2c5f8d 100%);
            color: #ffffff;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            padding: 16px 12px;
            border: none;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
            vertical-align: middle;
        }
        
        .plans-table-container .table thead th:first-child {
            border-top-left-radius: 8px;
        }
        
        .plans-table-container .table thead th:last-child {
            border-top-right-radius: 8px;
        }
        
        .plans-table-container .table td {
            vertical-align: middle;
            padding: 14px 12px;
            border-bottom: 1px solid #e9ecef;
            border-left: none;
            border-right: none;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            text-align: center;
        }
        
        .plans-table-container .table tbody tr {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .plans-table-container .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Badge Status - Modern Design */
        .badge-status {
            font-weight: 600;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .badge-status::before {
            content: '●';
            margin-right: 6px;
            font-size: 0.6rem;
            animation: pulse-dot 2s infinite;
        }
        
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .badge-pending {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }
        
        .badge-pending:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.3);
        }
        
        .badge-pickable {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }
        
        .badge-pickable:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.3);
        }
        
        .badge-inprogress {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: #212529;
        }
        
        .badge-inprogress::before {
            animation: pulse-dot 1s infinite;
        }
        
        .badge-inprogress:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.4);
        }
        
        .badge-completed {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
        }
        
        .badge-completed:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }

        /* Rotation alternating row colors - Enhanced */
        .rotation-group-0 {
            background-color: #ffffff !important;
        }
        
        .rotation-group-1 {
            background: linear-gradient(90deg, #f8fbff 0%, #e3f2fd 100%) !important;
        }
        
        .rotation-group-0:hover,
        .rotation-group-1:hover {
            background: linear-gradient(90deg, #fff8dc 0%, #fff3cd 100%) !important;
            box-shadow: 0 2px 8px rgba(0, 123, 255, 0.15);
        }
        
        /* Rotation Column Highlight */
        .plans-table-container .table td:first-child {
            font-weight: 700;
            font-family: 'Courier New', monospace;
            color: #007bff;
            font-size: 1.05rem;
        }
        
        /* Position Column Highlight */
        .plans-table-container .table td:nth-child(2) {
            font-weight: 600;
            color: #1a4f72;
        }
        
        /* Part Number Column */
        .plans-table-container .table td:nth-child(3) {
            font-family: 'Sarabun', sans-serif;
            font-size: 1.05rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            color: #2c3e50;
        }

        /* เพิ่ม style สำหรับปุ่มข้าม Dummy */
        .dummy-badge {
            background-color: #FF9800;
            color: white;
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 5px;
            margin-left: 10px;
        }
        
        .skip-dummy-btn {
            background-color: #FF9800;
            border-color: #E65100;
            font-weight: bold;
            margin-top: 15px;
            padding: 10px 20px;
            width: 100%;
        }
        
        .skip-dummy-btn:hover {
            background-color: #E65100;
        }
    </style>
</head>
<body>
    <!-- Locked Overlay for Part Number Error -->
    <div class="locked-overlay" id="lockedOverlay" style="<?php echo ($current_step === 'scan_part_number' && !empty($error_message)) ? 'display: flex;' : 'display: none;'; ?>">
        <div>
            <i class="fas fa-lock fa-3x mb-3"></i>
            <h2><?php echo $error_message; ?></h2>
            <p>กรุณาสแกน Part Number ให้ถูกต้องเพื่อดำเนินการต่อ</p>
            <button class="btn btn-light mt-3" onclick="location.reload();"><i class="fas fa-redo"></i> ลองอีกครั้ง</button>
        </div>
    </div>

    <div class="container">
        <div class="text-center mb-4">
            <h1 class="display-4 font-weight-bold" style="color: #1a4f72; letter-spacing: 2px;">
            <i class="fas fa-people-carry mr-2" style="color: #007bff;"></i>
            หยิบงาน: <span style="color: #007bff;"><?php echo htmlspecialchars($current_group_name); ?></span>
            </h1>
            <hr style="border-top: 2px solid #007bff; width: 60px; margin: 20px auto 0;">
        </div>

        <?php if ($error_message && $current_step !== 'scan_part_number'): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if ($task && $current_step !== 'group_complete' && $current_step !== 'display_summary'): ?>
            <div class="task-info-card">
                <div class="task-info-header">
                    <h2>📦 ข้อมูลงานปัจจุบัน</h2>
                </div>
                <div class="task-info-grid">
                    <!-- Rotation -->
                    <div class="task-info-item rotation">
                        <span class="label">Rotation<br><small style="font-size: 0.65rem; font-weight: 500;">(รอบการหยิบ)</small></span>
                        <div class="value"><?php echo htmlspecialchars(formatRotation($task['rotation'])); ?></div>
                    </div>

                    <!-- Part Number (spans 2 columns) -->
                    <div class="task-info-item part-number">
                        <span class="label">Part Number<br><small style="font-size: 0.65rem; font-weight: 500;">(หมายเลขสินค้า)</small></span>
                        <div class="value"><?php echo htmlspecialchars($task['part_number']); ?></div>
                    </div>

                    <!-- Location -->
                    <div class="task-info-item location">
                        <span class="label">📍 Location<br><small style="font-size: 0.65rem; font-weight: 500;">(ตำแหน่งจัดเก็บ)</small></span>
                        <div class="value"><?php echo htmlspecialchars($task['location'] ?? '-'); ?></div>
                    </div>

                    <!-- Position -->
                    <div class="task-info-item position">
                        <span class="label">Position<br><small style="font-size: 0.65rem; font-weight: 500;">(ช่อง Dolly)</small></span>
                        <div class="value"><?php echo htmlspecialchars($task['position']); ?></div>
                    </div>
                </div>

                <!-- Additional Info (Part Name, Quantity, Item Lot) -->
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #dee2e6;">
                    <div class="row" style="color: #333;">
                        <div class="col-md-6 mb-2">
                            <small style="opacity: 0.7; text-transform: uppercase; color: #495057;">Part Name (ชื่อสินค้า)</small>
                            <p style="margin: 0; font-size: 1rem; font-weight: 600; color: #1a4f72;"><?php echo htmlspecialchars($task['part_name']); ?></p>
                        </div>
                        <div class="col-md-3 mb-2">
                            <small style="opacity: 0.7; text-transform: uppercase; color: #495057;">Quantity (จำนวน)</small>
                            <p style="margin: 0; font-size: 1rem; font-weight: 600; color: #1a4f72;"><?php echo htmlspecialchars($task['quantity']); ?></p>
                        </div>
                        <?php if(!empty($task['item_lot'])): ?>
                        <div class="col-md-3 mb-2">
                            <small style="opacity: 0.7; text-transform: uppercase; color: #495057;">Lot</small>
                            <p style="margin: 0; font-size: 1rem; font-weight: 600; color: #1a4f72;"><?php echo htmlspecialchars($task['item_lot']); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($is_dummy_part): ?>
                    <div class="alert alert-warning">
                        <strong><i class="fas fa-exclamation-triangle"></i> ส่วนนี้เป็น Dummy Part</strong> คุณสามารถข้ามได้โดยกดปุ่มด้านล่าง
                    </div>
                    
                    <form method="POST" action="">
                        <button type="submit" name="skip_dummy" class="btn skip-dummy-btn">
                            <i class="fas fa-forward"></i> ข้าม Dummy Part
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            
            <!-- Combined Scan Form - แสดงเฉพาะเมื่อไม่ใช่ Dummy Part หรือผู้ใช้เลือกที่จะไม่ข้าม -->
            <?php if (!$is_dummy_part): ?>
                <!-- Modern Scan Form with Step Progress -->
                <div class="scan-form-container">
                    <div class="step-progress-bar mb-4">
                        <div class="progress" style="height: 8px; border-radius: 10px; background: #e9ecef;">
                            <div class="progress-bar bg-primary" role="progressbar" 
                                 style="width: <?php 
                                    if ($current_step === 'scan_part_number') echo '33';
                                    elseif ($current_step === 'scan_item_lot') echo '66';
                                    elseif ($current_step === 'scan_position') echo '99';
                                    else echo '100';
                                 ?>%; transition: width 0.6s ease;"
                                 aria-valuenow="<?php echo $current_step === 'scan_part_number' ? 33 : ($current_step === 'scan_item_lot' ? 66 : ($current_step === 'scan_position' ? 99 : 100)); ?>" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">
                            </div>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted font-weight-bold">
                                <?php 
                                    if ($current_step === 'scan_part_number') echo 'ขั้นตอน 1 จาก 3';
                                    elseif ($current_step === 'scan_item_lot') echo 'ขั้นตอน 2 จาก 3';
                                    elseif ($current_step === 'scan_position') echo 'ขั้นตอน 3 จาก 3';
                                    else echo 'เสร็จสมบูรณ์';
                                ?>
                            </small>
                        </div>
                    </div>

                    <form method="POST" action="" id="scanForm">
                        <input type="hidden" name="submitted_step" id="submittedStep">
                        <input type="hidden" name="scanned_data" id="scannedData">

                        <div class="row">
                            <!-- Step 1: Scan Part Number -->
                            <div class="col-lg-4 col-md-12 mb-4">
                                <div class="step-card <?php echo $current_step === 'scan_part_number' ? 'active' : ($current_step !== 'scan_part_number' && $current_step !== 'no_task' && $current_step !== 'display_summary' && $current_step !== 'group_complete' ? 'completed' : ''); ?>" 
                                     data-step="1">
                                    <h4>
                                        <i class="fas fa-barcode"></i> 
                                        <span>สแกน Part Number</span>
                                    </h4>
                                    <div class="form-group mb-0">
                                        <input type="text" 
                                               class="form-control scan-input" 
                                               id="scan_part_number_input" 
                                               placeholder="สแกนบาร์โค้ด Part Number..."
                                               <?php echo $current_step !== 'scan_part_number' ? 'disabled' : ''; ?> 
                                               required>
                                    </div>
                                    <?php if ($current_step === 'scan_part_number'): ?>
                                    <div class="mt-2">
                                        <small class="text-primary">
                                            <i class="fas fa-info-circle"></i> กรุณานำบาร์โค้ดมาสแกน
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Step 2: Scan Item Lot -->
                            <div class="col-lg-4 col-md-12 mb-4">
                                <div class="step-card <?php echo $current_step === 'scan_item_lot' ? 'active' : ($current_step !== 'scan_item_lot' && $current_step !== 'scan_part_number' && $current_step !== 'no_task' && $current_step !== 'display_summary' && $current_step !== 'group_complete' ? 'completed' : ''); ?>" 
                                     data-step="2">
                                    <h4>
                                        <i class="fas fa-tags"></i> 
                                        <span>สแกน Lot สินค้า</span>
                                    </h4>
                                    <div class="form-group mb-0">
                                        <input type="text" 
                                               class="form-control scan-input" 
                                               id="scan_item_lot_input" 
                                               placeholder="สแกนบาร์โค้ด Lot..."
                                               <?php echo $current_step !== 'scan_item_lot' ? 'disabled' : ''; ?> 
                                               required>
                                    </div>
                                    <?php if ($current_step === 'scan_item_lot'): ?>
                                    <div class="mt-2">
                                        <small class="text-primary">
                                            <i class="fas fa-info-circle"></i> Lot ต้องมี 12 ตัวอักษร
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Step 3: Scan Position -->
                            <div class="col-lg-4 col-md-12 mb-4">
                                <div class="step-card <?php echo $current_step === 'scan_position' ? 'active' : ($current_step === 'display_summary' || $current_step === 'group_complete' ? 'completed' : ''); ?>" 
                                     data-step="3">
                                    <h4>
                                        <i class="fas fa-map-marker-alt"></i> 
                                        <span>สแกนตำแหน่ง</span>
                                    </h4>
                                    <div class="form-group mb-0">
                                        <input type="text" 
                                               class="form-control scan-input" 
                                               id="scan_position_input" 
                                               placeholder="สแกนตำแหน่ง Dolly..."
                                               <?php echo $current_step !== 'scan_position' ? 'disabled' : ''; ?> 
                                               required>
                                    </div>
                                    <?php if ($current_step === 'scan_position'): ?>
                                    <div class="mt-2">
                                        <small class="text-primary">
                                            <i class="fas fa-info-circle"></i> สแกนตำแหน่งบน Dolly
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
        <?php elseif ($current_step === 'display_summary'): ?>
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h4><i class="fas fa-clipboard-check"></i> สรุป Rotation: <?php echo str_pad(htmlspecialchars($_SESSION['current_rotation_summary']), 4, '0', STR_PAD_LEFT); ?> (Dolly Summary)</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($summary_items)): ?>
                    <table class="table table-bordered table-striped summary-table">
                        <thead class="thead-light">
                            <tr>
                                <th>Part Number</th>
                                <th>Lot สินค้า</th>
                                <th>จำนวน</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($summary_items as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['part_number']); ?></td>
                                <td><?php echo htmlspecialchars($item['item_lot'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($item['quantity']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="text-muted">ไม่พบรายการสำหรับสรุป</p>
                    <?php endif; ?>
                    <!-- Removed manual confirm button, auto-advance below -->
                    <div class="alert alert-info text-center mt-3">
                        กำลังไปยังงานถัดไป...
                    </div>
                </div>
            </div>
            <script>
                setTimeout(function() {
                    // Submit a hidden form to trigger the PHP logic for next rotation
                    var f = document.createElement('form');
                    f.method = 'POST';
                    f.action = '';
                    var i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = 'confirm_summary';
                    i.value = '1';
                    f.appendChild(i);
                    document.body.appendChild(f);
                    f.submit();
                }, 2000); // 2 seconds delay
            </script>
        <?php elseif ($current_step === 'group_complete'): ?>
            <div class="alert alert-success text-center" role="alert">
                <h4 class="alert-heading"><i class="fas fa-check-circle"></i> สำเร็จ!</h4>
                <p>งานสำหรับ <?php echo htmlspecialchars($current_group_name); ?> เสร็จสมบูรณ์แล้ว</p>
                <hr>
                <a href="index.php" class="btn btn-primary"><i class="fas fa-home"></i> กลับไปหน้าเลือกกลุ่มงาน</a>
            </div>
        <?php else: ?>
            <div class="alert alert-warning text-center" role="alert">
                <h4 class="alert-heading"><i class="fas fa-info-circle"></i> ไม่พบงาน</h4>
                <p>ไม่พบงานที่ต้องทำสำหรับ <?php echo htmlspecialchars($current_group_name); ?> ในขณะนี้</p>
                <hr>
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> กลับไปหน้าเลือกกลุ่มงาน</a>
            </div>
        <?php endif; ?>

        <!-- ตารางรายการแผนการหยิบทั้งหมดสำหรับกลุ่มนี้ -->
        <div class="plans-table-container">
            <h3>รายการแผนการหยิบทั้งหมด (<?php echo htmlspecialchars($current_group_name); ?>)</h3>
            <?php if (empty($all_plans_for_group)): ?>
                <div class="alert alert-info text-center">
                    ไม่พบรายการแผนการหยิบสำหรับกลุ่มนี้
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-bordered table-hover">
                        <thead>
                            <tr>
                                <th>Rotation</th>
                                <th>Position</th>
                                <th>Part Number</th>
                                <th>Supplier</th>
                                <th>Location</th>
                                <th>Quantity</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $previous_rotation = null;
                            $rotation_class_index = 0;
                            foreach ($all_plans_for_group as $plan): 
                                // เช็คว่า Rotation เปลี่ยนหรือไม่
                                if ($previous_rotation !== $plan['rotation']) {
                                    $rotation_class_index = 1 - $rotation_class_index; // Toggle 0/1
                                    $previous_rotation = $plan['rotation'];
                                }
                                
                                // กำหนด badge status ตามเงื่อนไข
                                $display_status = $plan['status'];
                                $badge_class = 'badge-' . $plan['status'];
                                $status_text = '';
                                
                                if ($plan['status'] === 'completed') {
                                    $status_text = 'เสร็จสมบูรณ์';
                                    $badge_class = 'badge-completed';
                                } elseif ($plan['rotation'] == $current_rotation) {
                                    // งานที่อยู่ใน Rotation ปัจจุบัน และยังไม่เสร็จ
                                    $status_text = 'สามารถหยิบได้';
                                    $badge_class = 'badge-pickable';
                                } elseif ($plan['status'] === 'pending') {
                                    // งานที่ยังไม่ถึงคิว (Rotation ถัดไป)
                                    $status_text = 'รอดำเนินการ';
                                    $badge_class = 'badge-pending';
                                } else {
                                    $status_text = htmlspecialchars($plan['status']);
                                }
                                
                                // แสดงแถวสำหรับทุกงาน
                            ?>
                                <tr class="rotation-group-<?php echo $rotation_class_index; ?>">
                                    <td><?php echo htmlspecialchars(str_pad((string)intval($plan['rotation']), 4, '0', STR_PAD_LEFT)); ?></td>
                                    <td><?php echo htmlspecialchars($plan['position']); ?></td>
                                    <td><?php echo htmlspecialchars($plan['part_number']); ?></td>
                                    <td><?php echo htmlspecialchars($plan['supplier'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['location'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($plan['quantity']); ?></td>
                                    <td>
                                        <span class="badge badge-status <?php echo $badge_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const currentStep = "<?php echo $current_step; ?>";
            const scanForm = document.getElementById('scanForm');
            const submittedStepInput = document.getElementById('submittedStep');
            const scannedDataInput = document.getElementById('scannedData');
            const lockedOverlay = document.getElementById('lockedOverlay');

            // Map steps to input IDs
            const stepInputMap = {
                'scan_part_number': 'scan_part_number_input',
                'scan_item_lot': 'scan_item_lot_input',
                // 'scan_rotation_label': 'scan_rotation_label_input', // ลบขั้นตอนนี้ออก
                'scan_position': 'scan_position_input'
            };

            // Function to set focus
            function setFocusOnCurrentInput() {
                if (stepInputMap[currentStep]) {
                    const currentInputId = stepInputMap[currentStep];
                    const currentInputElement = document.getElementById(currentInputId);
                    if (currentInputElement) {
                        currentInputElement.focus();
                        // Select text for easy re-scan
                        currentInputElement.select();
                    }
                }
            }

            // Set focus on page load
            setFocusOnCurrentInput();

            // Add event listeners to all scan inputs
            const scanInputs = document.querySelectorAll('.scan-input');
            scanInputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Only process if this input is the one for the current step
                    if (this.id === stepInputMap[currentStep]) {
                        const scannedValue = this.value; // ไม่ trim เพื่อเก็บ space
                        if (scannedValue) {
                            // Populate hidden fields and submit the form
                            submittedStepInput.value = currentStep;
                            scannedDataInput.value = scannedValue;
                            scanForm.submit();
                        }
                    } else {
                         // If user types in a disabled input, clear it and refocus the correct one
                         this.value = '';
                         setFocusOnCurrentInput();
                    }
                });

                 // Prevent manual typing in disabled fields (optional, browser handles disabled)
                 if (input.disabled) {
                     input.addEventListener('keydown', function(event) {
                         event.preventDefault();
                     });
                 }
            });

            // Handle the locked overlay visibility based on PHP error message
            // This is already handled by the PHP inline style, but adding JS check for robustness
            if (currentStep === 'scan_part_number' && "<?php echo !empty($error_message); ?>" === '1') {
                 if (lockedOverlay) {
                     lockedOverlay.style.display = 'flex';
                 }
            } else {
                 if (lockedOverlay) {
                     lockedOverlay.style.display = 'none';
                 }
            }
            
            // เพิ่มการตรวจสอบว่าเป็น Dummy Part หรือไม่
            const isDummy = <?php echo $is_dummy_part ? 'true' : 'false'; ?>;
            
            // ถ้าเป็น Dummy Part ให้โฟกัสที่ปุ่มข้าม
            if (isDummy) {
                const skipButton = document.querySelector('button[name="skip_dummy"]');
                if (skipButton) {
                    skipButton.focus();
                }
            } else {
                // ...existing code for focus on scan inputs...
            }
        });
    </script>
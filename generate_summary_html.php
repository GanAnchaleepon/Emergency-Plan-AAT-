<?php
// 1. เรียกใช้ไฟล์เชื่อมต่อฐานข้อมูล
require_once 'config/database.php';

// 2. ตรวจสอบว่ามีการส่ง Group และช่วงเวลามาหรือไม่
if (!isset($_GET['group']) || !isset($_GET['start_time']) || !isset($_GET['end_time']) || !isset($_GET['round_no']) || !isset($_GET['rack_sequence']) || !isset($_GET['ttv_round_no'])) {
    die('Error: กรุณาระบุ Group, ช่วงเวลาของรอบ, หมายเลขรอบ, RACK SEQUENCE และ TTV Round No.');
}

$group = $_GET['group'];
$start_time = $_GET['start_time'];
$end_time = $_GET['end_time'];
$round_no = (int)$_GET['round_no'];
$rack_sequence = $_GET['rack_sequence'];
$ttv_round_no = $_GET['ttv_round_no'];

// --- เพิ่มส่วนบันทึกข้อมูลลง completed_rounds ---
$stmt_save_round = $conn->prepare("
    INSERT INTO completed_rounds (group_name, start_time, end_time, rack_sequence, ttv_round_no)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
        rack_sequence = VALUES(rack_sequence),
        ttv_round_no = VALUES(ttv_round_no),
        updated_at = NOW()
");
if ($stmt_save_round) {
    $stmt_save_round->bind_param("sssss", $group, $start_time, $end_time, $rack_sequence, $ttv_round_no);
    $stmt_save_round->execute();
    $stmt_save_round->close();
}
// --- สิ้นสุดส่วนบันทึกข้อมูล ---

// 3. ดึงข้อมูลจากฐานข้อมูล
$items_sql = "SELECT 
                position AS item,
                rotation,
                part_number AS part_no,
                part_name, -- เพิ่ม part_name เพื่อดึง Part Base
                supplier,
                model -- เพิ่มการดึงคอลัมน์ model
              FROM picking_plans
              WHERE group_name = ? 
                AND status = 'completed'
                AND updated_at > ?
                AND updated_at <= ?
              ORDER BY CAST(position AS UNSIGNED) ASC";

$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("sss", $group, $start_time, $end_time);
$items_stmt->execute();
$items_result = $items_stmt->get_result();

$picking_items = [];
while ($row = $items_result->fetch_assoc()) {
    $picking_items[] = $row;
}
$items_stmt->close();

if (empty($picking_items)) {
    die('Error: ไม่พบข้อมูล Dolly สำหรับ Group และช่วงเวลาที่ระบุ');
}

// 4. ประมวลผลข้อมูลเพื่อสร้าง Summary Data
$all_rotations = array_unique(array_column($picking_items, 'rotation'));
natsort($all_rotations);

$summary_data = [
    'group_name' => $group,
    'total_items' => count($picking_items),
    'min_position' => min(array_column($picking_items, 'item')),
    'max_position' => max(array_column($picking_items, 'item')),
    'min_rotation' => !empty($all_rotations) ? reset($all_rotations) : 'N/A',
    'max_rotation' => !empty($all_rotations) ? end($all_rotations) : 'N/A',
];

$base_part_no = '';
if ($group === 'GROUP 08L' || $group === 'GROUP 08R' || $group === 'GROUP 10') {
    $base_parts = [];
    foreach ($picking_items as $item) {
        $part_no = $item['part_no'];
        $parts = preg_split('/[-\s]/', $part_no);
        $current_base = '';
        if (count($parts) >= 2) {
            foreach($parts as $part) {
                if (is_numeric($part) && strlen($part) >= 4) {
                    $current_base = $part;
                    break;
                }
            }
            if (empty($current_base) && isset($parts[1])) {
                $current_base = $parts[1];
            }
        }
        if (!empty($current_base)) {
            $base_parts[] = $current_base;
        }
    }
    if (!empty($base_parts)) {
        $unique_base_parts = array_unique($base_parts);
        sort($unique_base_parts);
        $base_part_no = implode('/', $unique_base_parts);
    }
} elseif ($group === 'GROUP 10' || $group === 'GROUP 11') { // เพิ่ม GROUP 11 ที่นี่
     // สำหรับ GROUP 10 และ GROUP 11 ให้ดึง Part Base จาก Part Name
     $base_parts = [];
     foreach ($picking_items as $item) {
         $part_name = $item['part_name'];
         $parts = explode('-', $part_name);
         if (isset($parts[1])) {
             $base_parts[] = $parts[1];
         }
     }
     if (!empty($base_parts)) {
         $unique_base_parts = array_unique($base_parts);
         sort($unique_base_parts);
         $base_part_no = implode('/', $unique_base_parts); // อาจจะแสดง E044D70/E04652 หรือ E045B58/E046A34
     }
} elseif ($group === 'GROUP 01') {
    // สำหรับ GROUP 01, Part Base จะแสดงในตารางแยกตามคอลัมน์
    // ไม่ต้องกำหนด base_part_no ที่นี่
    $base_part_no = 'N/A'; // หรือค่าว่างตามความเหมาะสม
}
else {
    if (!empty($picking_items)) {
        $first_part = $picking_items[0]['part_no'];
        $parts = preg_split('/[-\s]/', $first_part);
        if (count($parts) >= 2) {
            foreach($parts as $part) {
                if (is_numeric($part) && strlen($part) >= 4) {
                    $base_part_no = $part;
                    break;
                }
            }
            if (empty($base_part_no) && isset($parts[1])) {
                $base_part_no = $parts[1];
            }
        }
    }
}


// ฟังก์ชันสำหรับสร้างตัวย่อของกลุ่มสำหรับ Serial No
function getGroupAbbrForSerial($groupName) {
    $groupName = strtoupper($groupName);
    $groupName = str_replace('GROUP', '', $groupName);
    $groupName = str_replace(' ', '', $groupName);
    // ลบ 0 นำหน้าออกจากตัวเลข เช่น 01 -> 1, 08L -> 8L
    if (preg_match('/^0(\d.*)/', $groupName, $matches)) {
        $groupName = $matches[1];
    }
    return 'G' . $groupName;
}

$summary_data['from_company'] = 'SUMITOMO ELECTRIC WIRING SYSTEMS (THAILAND) LIMITED';
$summary_data['from_gsdb'] = 'EHE7C';
$summary_data['to_company'] = 'Ford Motor Company';
$summary_data['to_gsdb'] = 'GBL9A';
$summary_data['doc_date'] = date('d/m/Y');
$summary_data['base_part_no'] = $base_part_no; // ใช้ค่าที่คำนวณได้ (หรือ N/A สำหรับ G01)
$summary_data['dolly_number'] = 'TTV-' . $group . '-' . date('His', strtotime($end_time));

// สร้าง Serial No (S) รูปแบบใหม่
$group_abbr = getGroupAbbrForSerial($group);
$running_no_padded = sprintf('%03d', $round_no);
$new_serial_no = 'DOL' . date('ymd') . 'E' . $group_abbr . $running_no_padded;
$summary_data['serial_no_s'] = $new_serial_no;

$summary_data['delivery_date'] = date('d/m/y H:i', strtotime($end_time));
$summary_data['part_group'] = $summary_data['group_name'] . '  ';

$summary_data['rack_sequence'] = $rack_sequence;

$conn->close();

// เพิ่มฟังก์ชันสำหรับแปลงคำว่า DUMMY เป็นตัวหนังสือสีขาว
function formatPartNumber($partNumber) {
    // ใช้ regular expression เพื่อแทนที่คำว่า DUMMY ด้วย span ที่มี class dummy-text
    return preg_replace('/DUMMY|Dummy|dummy/i', '<span class="dummy-text">$0</span>', htmlspecialchars($partNumber));
}

// เพิ่มฟังก์ชันสำหรับแปลง Model เป็นตัวหนังสือสีขาว
function formatModel($model) {
    // ตรวจสอบว่า model มีคำว่า DUMMY หรือไม่
    if (preg_match('/DUMMY|Dummy|dummy/i', $model)) {
        // ถ้ามีคำว่า DUMMY ให้แทนที่ด้วย span ที่มี class dummy-text
        return preg_replace('/DUMMY|Dummy|dummy/i', '<span class="dummy-text">$0</span>', htmlspecialchars($model));
    } else {
        // ถ้าไม่มีคำว่า DUMMY ให้แสดงตามปกติ
        return htmlspecialchars($model);
    }
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dolly Summary Sheet - <?php echo htmlspecialchars($summary_data['dolly_number']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700&display=swap" rel="stylesheet">
    <style>
        @page {
            size: A4;
            margin: 0; /* จัดการ margin ผ่าน padding ของ .page แทน */
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f0f0f0;
            display: flex;
            justify-content: center;
            padding: 20px;
        }
        .page {
            background: white;
            width: 210mm;
            min-height: 297mm;
            padding: 10mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            box-sizing: border-box;
        }
        .info-table-container, .table-container, .footer {
            width: 100%;
            margin-bottom: 5px; /* Reduced margin */
        }
        /* Added style for the new top table */
        .top-ttv-round-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px; /* Space below this table */
        }
        .top-ttv-round-table td {
            border: 1px solid white; /* White border */
            padding: 5px;
            text-align: center;
            font-size: 1500%; /* Increased font size to 1500% */
            font-weight: bold;
            background-color: white; /* White background */
            color: #333; /* Dark text color */
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid black;
            font-size: 10pt;
        }
        .info-table td {
            border: 1px solid black;
            padding: 2px; /* Reduced padding */
            vertical-align: top;
        }
        .info-table .dock-cell {
            width: 120px; /* กำหนดความกว้างคงที่สำหรับคอลัมน์ DOCK */
            text-align: center;
            vertical-align: middle;
            border-left: 1px solid black; /* ปรับความหนาของเส้นให้เท่ากับเส้นอื่น */
        }
        .info-table .label {
            font-weight: bold;
        }
        .dock-value {
            font-size: 20pt; /* Reduced font size */
            font-weight: bold;
        }
        .title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-top: 15px;
        }
        .summary-table th, .summary-table td {
            border: 1px solid black;
            padding: 5px;
            text-align: center;
            vertical-align: middle;
        }
        .summary-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        /* Adjusted style for part number column to prevent wrapping */
        .summary-table .part-no {
            text-align: center; /* Keep center alignment */
            white-space: nowrap; /* Prevent text wrapping */
        }
        .footer {
            position: relative;
            margin-top: 10px; /* Reduced margin */
            font-size: 10pt;
        }
        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            text-align: center;
            gap: 10px; /* Reduced gap */
        }
        .signature-line {
            margin-top: 20px; /* Reduced margin */
            border-bottom: 1px dotted black;
        }
        .note {
            font-size: 8pt;
            margin-top: 20px;
        }
        .controls {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #fff;
            padding: 10px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .btn {
            padding: 8px 15px;
            border: none;
            background-color: #007bff;
            color: white;
            cursor: pointer;
            border-radius: 3px;
            font-family: 'Sarabun', sans-serif;
        }

        /* เพิ่ม CSS สำหรับซ่อนคำว่า DUMMY */
        .dummy-text {
            color: white;
            font-weight: normal;
        }
        
        /* สำหรับการพิมพ์ - แน่ใจว่าจะเป็นสีขาวเมื่อพิมพ์ด้วย */
        @media print {
            .dummy-text {
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media print {
            body {
                background-color: #fff;
                padding: 0;
                margin: 0;
            }
            .page {
                box-shadow: none;
                margin: 0;
                width: 210mm;
                height: 297mm; /* Fixed height for A4 */
                padding: 10mm; /* รักษาระยะขอบสำหรับการพิมพ์ */
                overflow: hidden; /* Hide any content that overflows */
                page-break-after: always; /* Ensure it's treated as a single page */
            }
            .controls {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="controls">
        <button class="btn" onclick="window.print()">🖨️ พิมพ์ใบสรุป</button>
    </div>

    <div class="page">
        <!-- Added new table here -->
        <table class="top-ttv-round-table">
            <tr>
                <td>
                    <?php echo htmlspecialchars($ttv_round_no); ?>
                </td>
            </tr>
        </table>
        <!-- End new table -->

        <div class="info-table-container">
            <table class="info-table">
                <colgroup>
                    <col style="width: 50%;">
                    <col style="width: 50%;">
                </colgroup>
                <!-- Removed FROM/TO row -->
                <!-- Removed DESCRIPTION/BASE PART/LOWEST ROTATION row -->
                <!-- Removed LINEFEED/HIGHEST ROTATION row -->
                <!-- Removed RACK SEQUENCE/SERIAL NO (S) row -->
            </table>
        </div>

        <div class="title" style="text-align: right; font-size: 150%;">Page : 1/1</div>

        <div class="info-table-container">
            <table class="info-table" style="border-color: transparent;">
            <tr>
            <td style="border-color: transparent;">
            <span class="label" style="font-size: 150%;">Rotation No:</span>
            <span style="font-size: 150%; font-weight: bold;">
            <?php echo htmlspecialchars($summary_data['min_rotation']); ?> - <?php echo htmlspecialchars($summary_data['max_rotation']); ?>
            </span>
            </td>
            <td style="border-color: transparent; text-align: right;">
            <span class="label" style="font-size: 150%;">TTV Round No:</span>
            <span style="font-size: 150%; font-weight: bold;">
            <span style="display: inline-block; padding: 2px 12px; border: 2px solid #FFD600; border-radius: 6px; background: #FFFDE7; color: #333; font-weight: bold;">
                <?php echo htmlspecialchars($ttv_round_no); ?>
            </span>
            </span>
            </td>
            </tr>
            <tr>
            <td style="border-color: transparent;">
            <span class="label">Delivery Date:</span>
            <?php echo htmlspecialchars($summary_data['delivery_date']); ?>
            </td>
            <td style="border-color: transparent; text-align: right;">
            <span class="label">Summary Sheet No:</span>
            <span class="barcode39" style="font-size: 22px; margin-left: 10px; vertical-align: middle;">
            *<?php echo htmlspecialchars($summary_data['serial_no_s']); ?>*
            </span>
            <!-- Google Fonts: Libre Barcode 39 as fallback for Free 3 of 9 -->
            <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+39&display=swap" rel="stylesheet">
            <style>
            @media screen, print {
                .barcode39 {
                font-family: 'Free 3 of 9', 'Libre Barcode 39', 'C39HrP24DhTt', 'C39HrP24DlTt', 'C39HrP36DlTt', 'C39HrP36DhTt', monospace;
                letter-spacing: 2px;
                }
            }
            </style>
            </td>
            </tr>
            <tr>
            <td style="border-color: transparent;">
            <span class="label">Part Group:</span>
            <?php echo htmlspecialchars($summary_data['part_group']); ?>
            </td>
            <td style="border-color: transparent;">&nbsp;</td>
            </tr>
            </table>
        </div>

        <div class="table-container">
            <?php if ($group === 'GROUP 01'): ?>
                <?php
                    // Group items by position for GROUP 01
                    $grouped_items_g01 = [];
                    foreach ($picking_items as $item) {
                        $item_key = $item['item'];
                        if (!isset($grouped_items_g01[$item_key])) {
                            $grouped_items_g01[$item_key] = [
                                'item' => $item['item'],
                                'rotation' => $item['rotation'],
                                'supplier' => $item['supplier'],
                                'model' => $item['model'], // เพิ่ม model ที่นี่
                                'col14290_parts' => [],
                                'col14630_parts' => [],
                                'col14631_parts' => [],
                                'col14632_parts' => [],
                                'col14633_parts' => [],
                            ];
                        }
                        
                        // Categorize part numbers based on the specified bases
                        $part_no = $item['part_no'];
                        if (strpos($part_no, '14290') !== false) {
                            $grouped_items_g01[$item_key]['col14290_parts'][] = $part_no;
                        } elseif (strpos($part_no, '14630') !== false) {
                            $grouped_items_g01[$item_key]['col14630_parts'][] = $part_no;
                        } elseif (strpos($part_no, '14631') !== false) {
                            $grouped_items_g01[$item_key]['col14631_parts'][] = $part_no;
                        } elseif (strpos($part_no, '14632') !== false) {
                            $grouped_items_g01[$item_key]['col14632_parts'][] = $part_no;
                        } elseif (strpos($part_no, '14633') !== false) {
                            $grouped_items_g01[$item_key]['col14633_parts'][] = $part_no;
                        }
                    }
                ?>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">Item.</th>
                            <th style="width: 10%;">Rotation</th>
                            <th style="width: 10%;">Model</th>
                            <th style="width: 10%;">Supplier</th>
                            <th style="width: 13%;">PART BASE: 14290</th>
                            <th style="width: 13%;">PART BASE: 14630</th>
                            <th style="width: 13%;">PART BASE: 14631</th>
                            <th style="width: 13%;">PART BASE: 14632</th>
                            <th style="width: 13%;">PART BASE: 14633</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped_items_g01 as $item_data): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item_data['item']); ?></td>
                            <td><?php echo htmlspecialchars($item_data['rotation']); ?></td>
                            <td><?php echo formatModel($item_data['model'] ?: 'N/A'); ?></td> <?php // แก้ไขให้ใช้ formatModel ?>
                            <td><?php echo htmlspecialchars($item_data['supplier'] ?: 'SEWT'); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col14290_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col14630_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col14631_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col14632_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col14633_parts'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($group === 'GROUP 02'): ?> <?php // เพิ่มเงื่อนไขสำหรับ GROUP 02 ?>
                <?php
                    // Group items by position for GROUP 02
                    $grouped_items_g02 = [];
                    foreach ($picking_items as $item) {
                        $item_key = $item['item'];
                        if (!isset($grouped_items_g02[$item_key])) {
                            $grouped_items_g02[$item_key] = [
                                'item' => $item['item'],
                                'rotation' => $item['rotation'],
                                'supplier' => $item['supplier'],
                                'model' => $item['model'],
                                'col14405_parts' => [],
                                'col13A576_parts' => [],
                                'col2B572_parts' => [],
                                'col13B578_parts' => [],
                            ];
                        }
                        
                        // Categorize part numbers based on the specified bases for GROUP 02
                        $part_no = $item['part_no'];
                        if (strpos($part_no, '14405') !== false) {
                            $grouped_items_g02[$item_key]['col14405_parts'][] = $part_no;
                        } elseif (strpos($part_no, '13A576') !== false) {
                            $grouped_items_g02[$item_key]['col13A576_parts'][] = $part_no;
                        } elseif (strpos($part_no, '2B572') !== false) {
                            $grouped_items_g02[$item_key]['col2B572_parts'][] = $part_no;
                        } elseif (strpos($part_no, '13B578') !== false) {
                            $grouped_items_g02[$item_key]['col13B578_parts'][] = $part_no;
                        }
                    }
                ?>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">Item.</th>
                            <th style="width: 10%;">Rotation</th>
                            <th style="width: 10%;">Model</th>
                            <th style="width: 10%;">Supplier</th>
                            <th style="width: 16.25%;">PART BASE: 14405</th>
                            <th style="width: 16.25%;">PART BASE: 13A576</th>
                            <th style="width: 16.25%;">PART BASE: 2B572</th>
                            <th style="width: 16.25%;">PART BASE: 13B578</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped_items_g02 as $item_data): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item_data['item']); ?></td>
                            <td><?php echo htmlspecialchars($item_data['rotation']); ?></td>
                            <td><?php echo formatModel($item_data['model'] ?: 'N/A'); ?></td> <?php // แก้ไขให้ใช้ formatModel ?>
                            <td><?php echo htmlspecialchars($item_data['supplier'] ?: 'SEWT'); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col14405_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col13A576_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col2B572_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col13B578_parts'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($group === 'GROUP 04'): ?> <?php // เพิ่มเงื่อนไขสำหรับ GROUP 04 ?>
                <?php
                    // Group items by position for GROUP 04
                    $grouped_items_g04 = [];
                    foreach ($picking_items as $item) {
                        $item_key = $item['item'];
                        if (!isset($grouped_items_g04[$item_key])) {
                            $grouped_items_g04[$item_key] = [
                                'item' => $item['item'],
                                'rotation' => $item['rotation'],
                                'supplier' => $item['supplier'],
                                'model' => $item['model'],
                                'col14401_parts' => [],
                                'col2104545_parts' => [],
                            ];
                        }
                        
                        // Categorize part numbers based on the specified bases for GROUP 04
                        $part_no = $item['part_no'];
                        if (strpos($part_no, '14401') !== false) {
                            $grouped_items_g04[$item_key]['col14401_parts'][] = $part_no;
                        } elseif (strpos($part_no, '2104545') !== false) {
                            $grouped_items_g04[$item_key]['col2104545_parts'][] = $part_no;
                        }
                    }
                ?>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 5%;">Item.</th>
                            <th style="width: 10%;">Rotation</th>
                            <th style="width: 10%;">Model</th>
                            <th style="width: 10%;">Supplier</th>
                            <th style="width: 27.5%;">PART BASE: 14401</th>
                            <th style="width: 27.5%;">PART BASE: 2104545</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grouped_items_g04 as $item_data): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item_data['item']); ?></td>
                            <td><?php echo htmlspecialchars($item_data['rotation']); ?></td>
                            <td><?php echo formatModel($item_data['model'] ?: 'N/A'); ?></td> <?php // แก้ไขให้ใช้ formatModel ?>
                            <td><?php echo htmlspecialchars($item_data['supplier'] ?: 'SEWT'); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col14401_parts'])); ?></td>
                            <td class="part-no"><?php echo formatPartNumber(implode(' ', $item_data['col2104545_parts'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif ($group === 'GROUP 10' || $group === 'GROUP 11'): ?><?php
                    // 1. ประมวลผลข้อมูลเพื่อรวมรายการที่มี 'item' (position) เดียวกัน
                    $merged_items = [];
                    foreach ($picking_items as $item) {
                        $item_key = $item['item'];
                        if (!isset($merged_items[$item_key])) {
                            // เริ่มต้นกลุ่มใหม่ถ้ายังไม่มี
                            $merged_items[$item_key] = [
                                'item' => $item['item'],
                                'rotation' => $item['rotation'],
                                'supplier' => $item['supplier'],
                                'model' => $item['model'], // เพิ่ม model ที่นี่
                                'col5_parts' => [], // สำหรับ part คอลัมน์ที่ 5
                                'col6_parts' => [], // สำหรับ part คอลัมน์ที่ 6
                            ];
                        }
                        // 2. จัดหมวดหมู่ Part Number ตามเงื่อนไขของแต่ละกลุ่ม
                        $part_no = $item['part_no'];
                        $part_name = $item['part_name']; // ใช้ part_name สำหรับ GROUP 10 และ 11
                        
                        if ($group === 'GROUP 10') {
                            // สำหรับ GROUP 10 แยกตาม Part Base จาก Part Name
                            $part_name_parts = explode('-', $part_name);
                            $part_base = $part_name_parts[1] ?? '';

                            // ตรวจสอบ Part Base ที่ต้องการสำหรับ GROUP 10
                            if ($part_base === 'E044D70') {
                                $merged_items[$item_key]['col5_parts'][] = $part_no;
                            } elseif ($part_base === 'E04652') {
                                $merged_items[$item_key]['col6_parts'][] = $part_no;
                            }
                        } elseif ($group === 'GROUP 11') {
                            // สำหรับ GROUP 11 แยกตาม Part Base จาก Part Name
                            $part_name_parts = explode('-', $part_name);
                            $part_base = $part_name_parts[1] ?? '';

                            // ตรวจสอบ Part Base ที่ต้องการสำหรับ GROUP 11
                            if ($part_base === 'E045B58') {
                                $merged_items[$item_key]['col5_parts'][] = $part_no;
                            } elseif ($part_base === 'E046A34') {
                                $merged_items[$item_key]['col6_parts'][] = $part_no;
                            }
                        }
                    }
                ?>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Item.</th>
                            <th style="width: 15%;">Rotation</th>
                            <th style="width: 15%;">Model</th>
                            <th style="width: 15%;">Supplier</th>
                            <?php if ($group === 'GROUP 10'): ?>
                                <th style="width: 22.5%;">PART BASE: E044D70</th>
                                <th style="width: 22.5%;">PART BASE: E04652</th>
                            <?php elseif ($group === 'GROUP 11'): ?>
                                <th style="width: 22.5%;">PART BASE: E045B58</th>
                                <th style="width: 22.5%;">PART BASE: E046A34</th>
                            <?php endif; ?>
                        </tr></thead>
                    <tbody>
                        <?php foreach ($merged_items as $merged_item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($merged_item['item']); ?></td>
                            <td><?php echo htmlspecialchars($merged_item['rotation']); ?></td>
                            <td><?php echo formatModel($merged_item['model'] ?: 'N/A'); ?></td> <?php // แก้ไขให้ใช้ formatModel ?>
                            <td><?php echo htmlspecialchars($merged_item['supplier'] ?: 'SEWT'); ?></td>
                            <td class="part-no" style="text-align: center;"><?php echo formatPartNumber(implode(' ', $merged_item['col5_parts'])); ?></td>
                            <td class="part-no" style="text-align: center;"><?php echo formatPartNumber(implode(' ', $merged_item['col6_parts'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
            <table class="summary-table">
                <thead>
                    <tr>
                        <th style="width: 10%;">Item.</th>
                        <th style="width: 15%;">Rotation</th>
                        <th style="width: 15%;">Model</th>
                        <th style="width: 15%;">Supplier</th>
                        <th style="width: 45%;">PART BASE: <?php echo htmlspecialchars($summary_data['base_part_no']); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($picking_items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['item']); ?></td>
                        <td><?php echo htmlspecialchars($item['rotation']); ?></td>
                        <td><?php echo formatModel($item['model'] ?: 'N/A'); ?></td> <?php // แก้ไขให้ใช้ formatModel ?>
                        <td><?php echo htmlspecialchars($item['supplier'] ?: 'SEWT'); ?></td>
                        <td class="part-no" style="text-align: center;"><?php echo formatPartNumber($item['part_no']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="footer" style="font-size: 8pt;">
            <div class="signature-grid" style="gap: 16px;">
            <div>
                <div class="signature-line" style="margin-top: 24px;"></div>
                <div style="font-size: 8pt;">Picker (TTV)</div>
            </div>
            <div>
            </div>
            <div>
                <div class="signature-line" style="margin-top: 24px;"></div>
                <div style="font-size: 8pt;">Check By (TTV)</div>
            </div>
            </div>
        </div>
    </div>
</body>
</html>
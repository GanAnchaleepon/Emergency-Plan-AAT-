<?php
session_start();
require_once '../config/database.php';
require_once '../lib/tcpdf/tcpdf.php'; // --- เพิ่มบรรทัดนี้ --- (ตรวจสอบ path ให้ถูกต้อง)

// ตรวจสอบว่ามีการล็อกอินเป็นแอดมิน
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// ดึงรายชื่อกลุ่มงานทั้งหมด
$groups = [];
$sql = "SELECT group_name FROM picking_plans GROUP BY group_name ORDER BY CAST(SUBSTRING(group_name, 7, 2) AS UNSIGNED) ASC, RIGHT(group_name, 1)";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row['group_name'];
    }
}

$message = '';
$messageType = '';

// --- ฟังก์ชันช่วย ---
function getGroupAbbreviation($groupName) {
    // ลบคำว่า "GROUP" และเว้นวรรค แล้วเอาเฉพาะหมายเลขและ L/R ถ้ามี
    $groupNumber = preg_replace('/[^0-9LR]/', '', $groupName); // เก็บ L และ R ด้วย
    if (empty($groupNumber)) {
        $groupNumber = '01';
    }
    // ไม่ต้องเติม 0 ด้านหน้าแล้ว เพราะบางกลุ่มมี L/R ต่อท้าย
    return 'G' . $groupNumber;
}

// สร้าง QR Code ตามรูปแบบใหม่: G01_202506176918
function generateQRData($group, $rotation, $round = null, $dateYmd = null) {
    $groupAbbr = getGroupAbbreviation($group); // e.g., "G01", "G08R"
    if ($dateYmd === null) {
        $dateYmd = date('Ymd'); // fallback to today if not provided
    }
    $qrDataBase = $groupAbbr . '_' . $dateYmd . $rotation;

    // เพิ่มการตรวจสอบและลบ 'R' ตัวเดียวถัดจาก 'G' ที่ขึ้นต้น ถ้าตามด้วยตัวเลข
    // เช่น GR01_... -> G01_... แต่ GR08R_... ยังคงเป็น G08R_...
    $cleanedQrData = preg_replace('/^GR(\d)/', 'G$1', $qrDataBase, 1);

    if ($group === 'GROUP 12' && in_array($round, [1, 2])) {
        $cleanedQrData .= '_' . $round;
    }

    return $cleanedQrData; // คืนค่าข้อมูล QR Code ที่ผ่านการตรวจสอบแล้ว
}

// --- ฟังก์ชันใหม่สำหรับสร้างเนื้อหาสติกเกอร์ขนาด 2.5x1 นิ้ว ---
function generateStickerContent_2_5x1($pdf, $data, $fontname) {
    $rotation = str_pad($data['rotation'], 4, '0', STR_PAD_LEFT);
    $partNumber = $data['part_number'];
    $groupName = $data['group_name'];
    $position = $data['position'];
    $round = $data['round'] ?? null;

    // Parse date/time from picking plan row
    $rawDate = $data['date'] ?? '';
    $rawTime = $data['time'] ?? '';
    $dt = DateTime::createFromFormat('d/m/Y H:i:s', $rawDate . ' ' . $rawTime);
    if (!$dt) { // fallback try without seconds
        $dt = DateTime::createFromFormat('d/m/Y H:i', $rawDate . ' ' . substr($rawTime,0,5));
    }
    if (!$dt) { // fallback date only
        $dt = DateTime::createFromFormat('d/m/Y', $rawDate);
        if ($dt && $rawTime) {
            // append time manually
            $timeParts = explode(':', $rawTime);
            if (count($timeParts) >= 2) {
                $dt->setTime((int)$timeParts[0], (int)$timeParts[1], isset($timeParts[2])?(int)$timeParts[2]:0);
            }
        }
    }
    $dateYmd = $dt ? $dt->format('Ymd') : date('Ymd');
    $printedDate = $dt ? $dt->format('d/m/y H:i') : date('d/m/y H:i');

    $barcode1DData = $dateYmd . $rotation; // ECSS barcode uses plan date
    $qrData = generateQRData($groupName, $rotation, $round, $dateYmd);

    $label_page_width = 63.5; // 2.5 inches
    $label_page_height = 25.4; // 1 inch
    $usable_page_width = $label_page_width - 4;

    $pdf->SetY(1.5); 
    $initialY_barcode_section = $pdf->GetY();

    // Rotation Info (ซ้ายบน)
    $pdf->SetX(2);
    $pdf->SetFont('helvetica', '', 5); 
    $pdf->MultiCell($usable_page_width * 0.30, 6, "Rotation #:\n<strong style=\"font-size:9px;\">" . htmlspecialchars($rotation) . "</strong>\n<span style=\"font-size:4px;\">" . htmlspecialchars($barcode1DData) . "</span>", 0, 'C', false, 1, $pdf->GetX(), $initialY_barcode_section, true, 0, true, true, 0, 'T', false);
    $yAfterRotationInfo = $pdf->GetY();

    // ECSS Barcode (กลางบน)
    $x_for_ecss_block_start = 2 + ($usable_page_width * 0.30) + 0.5;
    $pdf->SetXY($x_for_ecss_block_start, $initialY_barcode_section);
    $style1D_ECSS_small = [
        'position' => '', 'align' => 'C', 'stretch' => false, 'fitwidth' => true, 
        'cellfitalign' => '', 'border' => false, 'hpadding' => 'auto', 'vpadding' => 'auto', 
        'fgcolor' => [0,0,0], 'bgcolor' => false, 'text' => false, 'font' => $fontname,
        'fontsize' => 14, 
        'stretchtext' => 0
    ];
    $ecss_barcode_width_small = $usable_page_width * 0.38;
    $ecss_barcode_height_small = 5; 
    $pdf->write1DBarcode($barcode1DData, 'C128', $pdf->GetX(), $initialY_barcode_section, $ecss_barcode_width_small, $ecss_barcode_height_small, 0.3, $style1D_ECSS_small, 'N');
    
    $pdf->SetXY($x_for_ecss_block_start, $initialY_barcode_section + $ecss_barcode_height_small); 
    $pdf->SetFont('helvetica', '', 4); 
    $pdf->Cell($ecss_barcode_width_small, 1.5, "ECSS Date & Rotation", 0, 1, 'C'); 
    $yAfterECSSBarcode = $pdf->GetY();

    // QR Code (ขวาบน)
    $qr_size_small = 8; 
    $pdf->SetXY($usable_page_width - $qr_size_small + 1.5, $initialY_barcode_section); 
    $styleQR_small = ['border' => false, 'padding' => 0, 'fgcolor' => [0,0,0], 'bgcolor' => false];
    $pdf->write2DBarcode($qrData, 'QRCODE,M', $pdf->GetX(), $pdf->GetY(), $qr_size_small, $qr_size_small, $styleQR_small, 'N');
    
    if ($groupName === 'GROUP 12' && $round !== null) {
        $pdf->SetFont('helvetica', 'B', 6);
        $pdf->SetXY($pdf->GetX(), $pdf->GetY() - 1);
        $pdf->Cell(0, 0, '_' . $round, 0, 0, 'L');
    }
    
    $yAfterQR = $initialY_barcode_section + $qr_size_small;
    
    $pdf->SetY(max($yAfterRotationInfo, $yAfterECSSBarcode, $yAfterQR));
    $pdf->Ln(0.3); 

    // HR Line 1
    $pdf->Line(2, $pdf->GetY(), $label_page_width - 2, $pdf->GetY());
    $pdf->Ln(0.3); 

    // Part Number Barcode & Text (ซ้ายกลาง)
    $initialY_part_section = $pdf->GetY();
    $pdf->SetX(2);
    $partNo_barcode_width_small = $usable_page_width * 0.60;
    $partNo_barcode_height_small = 5; 
    $style1D_PartNo_small = [
        'position' => '', 'align' => 'L', 'stretch' => false, 'fitwidth' => true,
        'cellfitalign' => '', 'border' => false, 'hpadding' => 'auto', 'vpadding' => 'auto',
        'fgcolor' => [0,0,0], 'bgcolor' => false, 'text' => false, 'font' => $fontname,
        'fontsize' => 16, 
        'stretchtext' => 0
    ];
    $pdf->write1DBarcode($partNumber, 'C128', $pdf->GetX(), $initialY_part_section, $partNo_barcode_width_small, $partNo_barcode_height_small, 0.3, $style1D_PartNo_small, 'N');
    $pdf->SetX(2);
    $pdf->SetFont('helvetica', '', 5); 
    $pdf->Cell($partNo_barcode_width_small, 1.5, htmlspecialchars($partNumber), 0, 1, 'L'); 
    $yAfterPartNoText = $pdf->GetY();

    // Group Name (ขวากลาง)
    $group_name_y_pos_small = $initialY_part_section + $partNo_barcode_height_small - 1.5; 
    $pdf->SetXY(2 + $partNo_barcode_width_small + 0.5, $group_name_y_pos_small); 
    $pdf->SetFont('helvetica', '', 5); 
    $pdf->Cell($usable_page_width * 0.35, 1.5, htmlspecialchars($groupName), 0, 1, 'R'); 
    $yAfterGroupName = $group_name_y_pos_small + 1.5;
    
    $pdf->SetY(max($yAfterPartNoText, $yAfterGroupName));
    $pdf->Ln(0.3); 

    // HR Line 2
    $pdf->Line(2, $pdf->GetY(), $label_page_width - 2, $pdf->GetY());
    $pdf->Ln(0.1); 

    // Footer Section
    $footerY = $label_page_height - 2 - 1.5; 
    $pdf->SetY($footerY);
    $pdf->SetFont('helvetica', '', 4); 
    $footer_cell_width = $usable_page_width / 2;
    $pdf->Cell($footer_cell_width, 1.5, "Printed: " . $printedDate, 0, 0, 'L'); 
    $pdf->SetXY(2 + $footer_cell_width, $footerY);
    $pdf->Cell($footer_cell_width, 1.5, "Pos: " . htmlspecialchars($position), 0, 1, 'R'); 
}

// --- ฟังก์ชันใหม่สำหรับสร้างเนื้อหาสติกเกอร์ขนาด 3x1.5 นิ้ว ---
function generateStickerContent_3x1_5($pdf, $data, $fontname) {
    $rotation = str_pad($data['rotation'], 4, '0', STR_PAD_LEFT);
    $partNumber = $data['part_number'];
    $groupName = $data['group_name'];
    $position = $data['position'];
    $round = $data['round'] ?? null;

    $rawDate = $data['date'] ?? '';
    $rawTime = $data['time'] ?? '';
    $dt = DateTime::createFromFormat('d/m/Y H:i:s', $rawDate . ' ' . $rawTime);
    if (!$dt) { $dt = DateTime::createFromFormat('d/m/Y H:i', $rawDate . ' ' . substr($rawTime,0,5)); }
    if (!$dt) { $dt = DateTime::createFromFormat('d/m/Y', $rawDate); }
    if ($dt && $rawTime && strlen($rawTime) >= 5) {
        $tp = explode(':', $rawTime);
        if (count($tp) >= 2) {
            $dt->setTime((int)$tp[0], (int)$tp[1], isset($tp[2])?(int)$tp[2]:0);
        }
    }
    $dateYmd = $dt ? $dt->format('Ymd') : date('Ymd');
    $printedDate = $dt ? $dt->format('d/m/y H:i') : date('d/m/y H:i');

    $barcode1DData = $dateYmd . $rotation; // Use plan date
    $qrData = generateQRData($groupName, $rotation, $round, $dateYmd);

    $label_page_width = 76.2;
    $label_page_height = 38.1;

    // --- เนื้อหาสติกเกอร์ตาตามตัวอย่าง HTML ---
    $usable_page_width = $label_page_width - 4; // ลบ margin ซ้ายขวา
    $usable_page_height = $label_page_height - 4; // ลบ margin บนล่าง

    // --- Barcode Section (Top) ---
    $pdf->SetY(2); // เริ่มจาก Y = 2 (หลัง margin บน)
    $initialY_barcode_section = $pdf->GetY();

    // Left part of barcode-section (Rotation Info)
    $pdf->SetX(2);
    $pdf->SetFont('helvetica', '', 7); // font-size: 8px in HTML approx 7 in TCPDF
    $currentX = $pdf->GetX();
    $currentY = $pdf->GetY();
    $pdf->MultiCell($usable_page_width * 0.35, 10, "Trim Rotation #:\n<strong style=\"font-size:14px;\">" . htmlspecialchars($rotation) . "</strong>\n<span style=\"font-size:6px;\">" . htmlspecialchars($barcode1DData) . "</span>", 0, 'C', false, 1, $currentX, $currentY, true, 0, true, true, 0, 'T', false);
    
    $yAfterRotationInfo = $pdf->GetY();

    // Middle part of barcode-section (ECSS Barcode)
    $x_for_ecss_block_start = 2 + ($usable_page_width * 0.35) + 1;
    $pdf->SetXY($x_for_ecss_block_start , $initialY_barcode_section); // +1 for small gap
    $style1D_ECSS = [
        'position' => '', 'align' => 'C', 'stretch' => false, 
        'fitwidth' => true, 'cellfitalign' => '', 'border' => false, 
        'hpadding' => 'auto', 'vpadding' => 'auto', 'fgcolor' => [0,0,0], 
        'bgcolor' => false, 'text' => false, 'font' => $fontname, // ใช้ฟอนต์ที่โหลด
        'fontsize' => 22, // ปรับขนาดฟอนต์บาร์โค้ดตามต้องการ
        'stretchtext' => 0 // ปิดการยืดตัวอักษรเพื่อให้ฟอนต์บาร์โค้ดแสดงผลถูกต้อง
    ];
    $ecss_barcode_width = $usable_page_width * 0.40;
    $ecss_barcode_height = 10; //mm
    // เพิ่ม * ที่หัวและท้ายของข้อมูลสำหรับ Code 39
    $currentX_barcode = $pdf->GetX();
    $currentY_barcode = $pdf->GetY();
    $pdf->write1DBarcode($barcode1DData, 'C128', $currentX_barcode, $currentY_barcode, $ecss_barcode_width, $ecss_barcode_height, 0.4, $style1D_ECSS, 'N');
    
    // --- ปรับตำแหน่งข้อความ "ECSS Date and rotation" ---
    // กำหนด Y ให้อยู่ใต้บาร์โค้ด ECSS
    // กำหนด X ให้เริ่มที่จุดเดียวกับบาร์โค้ด ECSS เพื่อให้ Cell มีความกว้างเท่ากันและจัดกลางได้ถูกต้อง
    $pdf->SetXY($x_for_ecss_block_start, $currentY_barcode + $ecss_barcode_height); 
    $pdf->SetFont('helvetica', '', 5); // font-size: 6px in HTML
    $pdf->Cell($ecss_barcode_width, 3, "ECSS Date and rotation", 0, 1, 'C');

    $yAfterECSSBarcode = $pdf->GetY(); // Y position after the text cell

    // Right part of barcode-section (QR Code)
    $qr_size = 12; //mm
    $pdf->SetXY($usable_page_width - $qr_size + 2 - 1 , $initialY_barcode_section); // -1 for small adjustment
    $styleQR = ['border' => false, 'padding' => 0, 'fgcolor' => [0,0,0], 'bgcolor' => false];
    $currentX_qr = $pdf->GetX();
    $currentY_qr = $pdf->GetY();
    $pdf->write2DBarcode($qrData, 'QRCODE,M', $currentX_qr, $currentY_qr, $qr_size, $qr_size, $styleQR, 'N');
    
    if ($groupName === 'GROUP 12' && $round !== null) {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetXY($currentX_qr + $qr_size, $currentY_qr);
        $pdf->Cell(0, 0, '_' . $round, 0, 0, 'L');
    }
    
    $pdf->SetY(max($yAfterRotationInfo, $yAfterECSSBarcode, $initialY_barcode_section + $qr_size)); // Set Y to below the tallest element in this row
    $pdf->Ln(1); // Small space before HR

    // --- HR Line 1 ---
    $pdf->Line(2, $pdf->GetY(), $label_page_width - 2, $pdf->GetY());
    $pdf->Ln(1);

    // --- Part Section (Middle) ---
    $initialY_part_section = $pdf->GetY();

    // Left part of part-section (Part Number Barcode & Text)
    $pdf->SetX(2);
    $partNo_barcode_width = $usable_page_width * 0.65;
    $partNo_barcode_height = 10; //mm
    $style1D_PartNo = [
        'position' => '', 'align' => 'L', 'stretch' => false, 
        'fitwidth' => true, 'cellfitalign' => '', 'border' => false, 
        'hpadding' => 'auto', 'vpadding' => 'auto', 'fgcolor' => [0,0,0], 
        'bgcolor' => false, 'text' => false, 'font' => $fontname, // ใช้ฟอนต์ที่โหลด
        'fontsize' => 25, // ปรับขนาดฟอนต์บาร์โค้ดตามต้องการ
        'stretchtext' => 0 // ปิดการยืดตัวอักษร
    ];
    // เพิ่ม * ที่หัวและท้ายของข้อมูลสำหรับ Code 39
    $currentX_part_barcode = $pdf->GetX();
    $currentY_part_barcode = $pdf->GetY();
    $pdf->write1DBarcode($partNumber, 'C128', $currentX_part_barcode, $currentY_part_barcode, $partNo_barcode_width, $partNo_barcode_height, 0.4, $style1D_PartNo, 'N');
    $pdf->SetX(2); // Reset X for text
    $pdf->SetFont('helvetica', '', 7); // font-size: 8px in HTML
    $pdf->Cell($partNo_barcode_width, 3, htmlspecialchars($partNumber), 0, 1, 'L');
    
    $yAfterPartNo = $pdf->GetY();

    // Right part of part-section (Group Name)
    $pdf->SetXY(2 + $partNo_barcode_width + 1, $initialY_part_section + $partNo_barcode_height - 1); // Align with bottom of barcode text
    $pdf->SetFont('helvetica', '', 7); // font-size: 8px in HTML
    $pdf->Cell($usable_page_width * 0.30, 3, htmlspecialchars($groupName), 0, 1, 'R');
    
    $pdf->SetY(max($yAfterPartNo, $initialY_part_section + $partNo_barcode_height + 3));
    $pdf->Ln(1);

    // --- HR Line 2 ---
    $pdf->Line(2, $pdf->GetY(), $label_page_width - 2, $pdf->GetY());
    $pdf->Ln(0.5);

    // --- Footer Section ---
    $pdf->SetFont('helvetica', '', 6); // font-size: 8px in HTML for footer
    $footer_cell_width = $usable_page_width / 2;
    $pdf->Cell($footer_cell_width, 3, "Printed: " . $printedDate, 0, 0, 'L');
    $pdf->Cell($footer_cell_width, 3, "Position: " . htmlspecialchars($position), 0, 1, 'R');
}


// ส่วนประมวลผลฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_stickers'])) {
    $selected_groups = $_POST['groups'] ?? [];
    $label_size_str = $_POST['label_size'] ?? '';

    if (empty($selected_groups)) {
        $message = "กรุณาเลือกกลุ่มงานอย่างน้อยหนึ่งกลุ่ม";
        $messageType = "danger";
    } elseif (empty($label_size_str)) {
        $message = "กรุณาเลือกขนาดสติกเกอร์";
        $messageType = "danger";
    } else {
        // ดึงข้อมูลจากฐานข้อมูล
        $placeholders = implode(',', array_fill(0, count($selected_groups), '?'));
    $stmt = $conn->prepare("SELECT group_name, position, part_number, rotation, date, time 
                FROM picking_plans 
                WHERE group_name IN ($placeholders)
                ORDER BY group_name ASC,
                         STR_TO_DATE(date, '%d/%m/%Y') ASC,
                         COALESCE(time, '00:00:00') ASC,
                         CAST(rotation AS UNSIGNED) ASC,
                         position ASC");
        
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($selected_groups)), ...$selected_groups);
            $stmt->execute();
            $data_result = $stmt->get_result();
            $stickers_data = [];
            while ($row = $data_result->fetch_assoc()) {
                $stickers_data[] = $row;
            }
            $stmt->close();

            if (empty($stickers_data)) {
                $message = "ไม่พบข้อมูลสำหรับกลุ่มที่เลือก";
                $messageType = "warning";
            } else {
                // กำหนดขนาดสติกเกอร์ (mm) และการตั้งค่าหน้า PDF
                $label_width_mm = 0;
                $label_height_mm = 0;
                $page_orientation = 'L'; // Landscape -- เปลี่ยนเป็นแนวนอน

                if ($label_size_str === '3x1.5') { // 3 x 1.5 inches
                    $label_page_width = 76.2; // ความกว้างของหน้า PDF (ด้านยาวของสติกเกอร์)
                    $label_page_height = 38.1; // ความสูงของหน้า PDF (ด้านสั้นของสติกเกอร์)
                } elseif ($label_size_str === '2.5x1') { // 2.5 x 1 inches
                    $label_page_width = 63.5;
                    $label_page_height = 25.4;
                } else {
                    $message = "ขนาดสติกเกอร์ไม่ถูกต้อง";
                    $messageType = "danger";
                    goto display_page;
                }

                // สร้าง PDF
                $pdf = new TCPDF($page_orientation, 'mm', [$label_page_width, $label_page_height], true, 'UTF-8', false);
                $pdf->SetCreator(PDF_CREATOR);
                $pdf->SetAuthor('Emergency Picking System');
                $pdf->SetTitle('Bulk Stickers');
                $pdf->SetSubject('Sticker Labels');
                
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                $pdf->SetMargins(2, 2, 2); // Margin เล็กน้อยรอบสติกเกอร์
                $pdf->SetAutoPageBreak(false); // ปิดการขึ้นหน้าใหม่อัตโนมัติ เราจะจัดการเอง

                // --- เพิ่มการโหลดฟอนต์ Free 3 of 9 Regular ---
                $fontname = TCPDF_FONTS::addTTFfont('../lib/tcpdf/fonts/LibreBarcode128-Regular.ttf', 'TrueTypeUnicode', '', 32);
                // หากไม่แน่ใจเรื่อง path หรือต้องการให้ TCPDF หาจาก default path ของมัน อาจลองใช้ชื่อฟอนต์โดยตรง
                // $fontname = 'free3of9'; // TCPDF จะพยายามหาจาก K_PATH_FONTS

                // Font
                // $pdf->SetFont('helvetica', '', 8); // Set default font if needed, specific fonts set per element

                foreach ($stickers_data as $data) {
                    if ($data['group_name'] === 'GROUP 12') {
                        // Round 1
                        $data['round'] = 1;
                        $pdf->AddPage();
                        if ($label_size_str === '3x1.5') {
                            generateStickerContent_3x1_5($pdf, $data, $fontname);
                        } elseif ($label_size_str === '2.5x1') {
                            generateStickerContent_2_5x1($pdf, $data, $fontname);
                        }

                        // Round 2
                        $data['round'] = 2;
                        $pdf->AddPage();
                        if ($label_size_str === '3x1.5') {
                            generateStickerContent_3x1_5($pdf, $data, $fontname);
                        } elseif ($label_size_str === '2.5x1') {
                            generateStickerContent_2_5x1($pdf, $data, $fontname);
                        }
                    } else {
                        $data['round'] = null;
                        $pdf->AddPage();
                        if ($label_size_str === '3x1.5') {
                            generateStickerContent_3x1_5($pdf, $data, $fontname);
                        } elseif ($label_size_str === '2.5x1') {
                            generateStickerContent_2_5x1($pdf, $data, $fontname);
                        }
                    }
                }

                ob_end_clean(); // ล้าง output buffer ก่อนส่ง PDF
                $pdf->Output('bulk_stickers_'.date('YmdHis').'.pdf', 'D'); // D = Download
                exit;
            }
        } else {
            $message = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง SQL: " . $conn->error;
            $messageType = "danger";
        }
    }
}

display_page: // Label สำหรับ goto
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิมพ์สติกเกอร์ลาเบลตามกลุ่มงาน - ระบบ Emergency Picking</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a4f72; 
            --secondary-color: #2980b9;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background-color: #f5f5f5;
        }
        .navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            padding: 0; white-space: nowrap; height: 60px;
        }
        .navbar-brand {
            color: white !important; font-weight: 600; font-size: 1.2rem; padding: 0.5rem 1rem;
            display: flex; align-items: center; background: rgba(0,0,0,0.1); height: 60px; margin-right: 0;
        }
        .navbar-brand i { margin-right: 0.5rem; font-size: 1.5rem; color: #ffcc00; }
        .navbar-nav { display: flex; align-items: center; }
        .navbar-dark .navbar-nav .nav-link {
            color: rgba(255,255,255,0.85); padding: 0 0.9rem; font-weight: 500; position: relative;
            transition: all 0.3s; height: 60px; line-height: 60px;
        }
        .navbar-dark .navbar-nav .nav-link:hover { background-color: rgba(255,255,255,0.1); color: white; }
        .navbar-dark .navbar-nav .nav-link.active {
            color: white; font-weight: 600; background-color: rgba(0,0,0,0.2);
            box-shadow: inset 0 -3px 0 #ffcc00;
        }
        .navbar-dark .navbar-nav .nav-link i { margin-right: 0.3rem; font-size: 1rem; vertical-align: middle; }
        .main-content { padding: 2rem; max-width: 900px; margin: 20px auto; }
        .card { border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border-radius: 10px; }
        .card-header {
            background-color: var(--primary-color); color: white; font-weight: 600;
            border-top-left-radius: 10px; border-top-right-radius: 10px;
        }
        .card-header i { margin-right: 0.5rem; }
        .form-check-label { margin-left: 0.25rem; }
        .btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); }
        .btn-primary:hover { background-color: #16405d; border-color: #16405d; }
        .footer { text-align: center; margin-top: 2rem; padding: 1rem; color: #6c757d; font-size: 0.9rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="../">
                <i class="fas fa-warehouse"></i> Emergency Picking (Operation TTV AAT-Wiring)
            </a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php"><i class="fas fa-tachometer-alt"></i> แดชบอร์ด</a></li>
                    <li class="nav-item"><a class="nav-link" href="../Convert_BC/index.php"><i class="fas fa-exchange-alt"></i> Convert BC</a>                 </li>
                    <li class="nav-item"><a class="nav-link" href="upload.php"><i class="fas fa-upload"></i> อัปโหลด</a></li>
                    <li class="nav-item"><a class="nav-link active" href="print_bulk_stickers.php"><i class="fas fa-print"></i> พิมพ์สติกเกอร์</a></li>
                    <li class="nav-item"><a class="nav-link" href="print_summary.php"><i class="fas fa-file-pdf"></i> พิมพ์ใบสรุป</a></li>
                    <li class="nav-item"><a class="nav-link" href="history.php"><i class="fas fa-history"></i> ประวัติ</a></li>
                    <li class="nav-item"><a class="nav-link" href="reports.php"><i class="fas fa-chart-bar"></i> รายงาน</a></li>
                    <li class="nav-item">
                        <a class="nav-link" href="position_mapping.php">
                            <i class="fas fa-map-marker-alt"></i> กำหนดจุดเริ่มต้น Position
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="logout.php" style="background-color: rgba(224,79,79,0.2);"><i class="fas fa-sign-out-alt"></i> ออกจากระบบ</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-print"></i> พิมพ์สติกเกอร์ลาเบลทั้งหมด (ตามกลุ่มงาน)
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?>" role="alert">
                        <?php echo htmlspecialchars($message); // Ensure message is escaped ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="groups"><strong>1. เลือกกลุ่มงาน (สามารถเลือกได้มากกว่า 1 กลุ่ม):</strong></label>
                        <select multiple class="form-control" id="groups" name="groups[]" size="10" required>
                            <?php foreach ($groups as $group_name): ?>
                                <option value="<?php echo htmlspecialchars($group_name); ?>" 
                                    <?php echo (isset($selected_groups) && in_array($group_name, $selected_groups)) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($group_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">กด Ctrl (หรือ Cmd บน Mac) ค้างไว้เพื่อเลือกหลายรายการ</small>
                    </div>

                    <div class="form-group">
                        <label><strong>2. เลือกลำดับข้อมูล:</strong></label>
                        <p class="form-control-static">ระบบจะเรียงข้อมูลตาม <strong>กลุ่ม, Date (เก่าสุดก่อน), Time (เก่าสุดก่อน), Rotation (น้อยไปมาก), และ Position</strong> โดยอัตโนมัติ</p>
                    </div>

                    <div class="form-group">
                        <label><strong>3. เลือกขนาดสติกเกอร์:</strong></label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="label_size" id="size_3x1_5" value="3x1.5" 
                                <?php echo (isset($label_size_str) && $label_size_str == '3x1.5') ? 'checked' : (!isset($label_size_str) ? 'checked' : ''); ?> required>
                            <label class="form-check-label" for="size_3x1_5">
                                ขนาด 3 x 1.5 นิ้ว
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="label_size" id="size_2_5x1" value="2.5x1" 
                                <?php echo (isset($label_size_str) && $label_size_str == '2.5x1') ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="size_2_5x1">
                                ขนาด 2.5 x 1 นิ้ว
                            </label>
                        </div>
                    </div>

                    <button type="submit" name="generate_stickers" class="btn btn-primary btn-lg">
                        <i class="fas fa-cogs"></i> สร้างไฟล์สำหรับพิมพ์
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>

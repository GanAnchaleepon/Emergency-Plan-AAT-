<?php
/**
 * ระบบสร้างฉลาก Rotation สำหรับ Emergency Picking
 * รองรับการใช้ Web Fonts จากโฟลเดอร์ Free_3_of_9_Regular
 * พร้อมใช้งานบน Hostinger โดยไม่ต้องติดตั้ง Font เพิ่มเติม
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// รับข้อมูลจาก URL Parameters หรือ POST
$rotation = $_GET['rotation'] ?? $_POST['rotation'] ?? "8606";
$partNumber = $_GET['part_number'] ?? $_POST['part_number'] ?? "RB3T14290ABC";
$group = $_GET['group'] ?? $_POST['group'] ?? "GROUP 01";
$position = $_GET['position'] ?? $_POST['position'] ?? "12";
$barcodeData = $_GET['barcode_data'] ?? $_POST['barcode_data'] ?? date('Ymd') . $rotation;
$layout = $_GET['layout'] ?? $_POST['layout'] ?? "standard"; // เปลี่ยนจาก "receipt" เป็น "standard"
$round = $_GET['round'] ?? $_POST['round'] ?? null;

// ข้อมูลเพิ่มเติม
$printedDate = date("d/m/y H:i");

// ฟังก์ชันแปลงชื่อกลุ่มเป็นตัวย่อ
function getGroupAbbreviation($groupName) {
    // ลบคำว่า "GROUP" และเว้นวรรค แล้วเอาเฉพาะหมายเลข
    $groupNumber = preg_replace('/[^0-9]/', '', $groupName);
    
    // ถ้าไม่มีหมายเลข ให้ใช้ค่าเริ่มต้น
    if (empty($groupNumber)) {
        $groupNumber = '01';
    }
    
    // เติม 0 ด้านหน้าให้เป็น 2 หลัก
    $groupNumber = str_pad($groupNumber, 2, '0', STR_PAD_LEFT);
    
    return 'G' . $groupNumber;
}

// สร้าง QR Code ตามรูปแบบใหม่: G01_202506176918
function generateQRData($group, $rotation, $round = null) {
    $groupAbbr = getGroupAbbreviation($group);
    $currentDate = date('Ymd'); // YYYYMMDD
    $qrDataBase = $groupAbbr . '_' . $currentDate . $rotation;

    if ($group === 'GROUP 12' && in_array($round, [1, 2])) {
        $qrDataBase .= '_' . $round;
    }

    return $qrDataBase;
}

$qrData = generateQRData($group, $rotation, $round);

// ฟังก์ชันตรวจสอบว่ามี Font Files อยู่หรือไม่
function checkFontFiles() {
    $fontPath = 'Free_3_of_9_Regular/';
    $fonts = [
        'FREE3OF9.ttf' => false,
        'FREE3OF9.eot' => false,
        'FREE3OF9.woff' => false,
        'FREE3OF9.svg' => false,
        'stylesheet.css' => false
    ];
    
    foreach ($fonts as $fontFile => $exists) {
        if (file_exists($fontPath . $fontFile)) {
            $fonts[$fontFile] = true;
        }
    }
    
    return $fonts;
}

// ฟังก์ชันสร้าง QR Code
function generateQRCode($data, $size = 100) {
    $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($data);
    return "<img src='{$qrUrl}' alt='QR Code' style='width:{$size}px; height:{$size}px;'>";
}

// ตรวจสอบ Font Files
$fontFiles = checkFontFiles();
$hasFontFiles = $fontFiles['FREE3OF9.ttf'];

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rotation Label - <?php echo htmlspecialchars($rotation); ?></title>
    <style>
        <?php if ($fontFiles['stylesheet.css']): ?>
        /* Import stylesheet จาก Free_3_of_9_Regular */
        @import url('Free_3_of_9_Regular/stylesheet.css');
        <?php elseif ($hasFontFiles): ?>
        /* Web Fonts - โหลด Font จากโฟลเดอร์ Free_3_of_9_Regular */
        @font-face {
            font-family: 'Free 3 of 9 Regular';
            src: url('Free_3_of_9_Regular/FREE3OF9.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }
        <?php endif; ?>
        
        /* Fallback Fonts */
        @import url('https://fonts.googleapis.com/css2?family=Libre+Barcode+39+Text&display=swap');
        
        @page {
            size: 3in 1.5in;
            margin: 0.1in;
        }
        
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 10px;
            background: #f8f9fa;
        }
        
        .label-container {
            width: 2.8in;
            height: 1.3in;
            border: 2px solid #000;
            padding: 0.05in;
            box-sizing: border-box;
            background: white;
            page-break-after: always;
            margin: 0 auto;
        }
        
        /* Barcode Font Classes */
        .barcode-free3of9 {
            font-family: 'Free 3 of 9 Regular', 'Libre Barcode 39 Text', monospace;
            font-size: 18px;
            letter-spacing: 1px;
            text-align: center;
            line-height: 1;
        }
        
        .barcode-text {
            font-family: Arial, sans-serif;
            font-size: 8px;
            text-align: center;
            margin-top: 2px;
        }
        
        /* Font Status */
        .font-status {
            position: fixed;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 1000;
        }
        
        .font-status.loaded {
            background: rgba(40, 167, 69, 0.8);
        }
        
        .font-status.fallback {
            background: rgba(255, 193, 7, 0.8);
        }
        
        /* Layout Standard */
        .layout-standard .header {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 2px;
            text-align: center;
        }
        
        .layout-standard .barcode-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2px 0;
            height: 40px;
        }
        
        .layout-standard .part-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2px 0;
            font-weight: bold;
            font-size: 11px;
        }
        
        .layout-standard .footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 2px;
            font-size: 8px;
        }
        
        /* Layout Compact */
        .layout-compact .header {
            font-weight: bold;
            font-size: 10px;
            text-align: center;
            margin-bottom: 1px;
        }
        
        .layout-compact .main-info {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 5px;
            margin: 2px 0;
        }
        
        .layout-compact .left-section {
            font-size: 9px;
        }
        
        .layout-compact .right-section {
            text-align: center;
        }
        
        /* Layout Minimal */
        .layout-minimal {
            text-align: center;
            padding: 0.1in;
        }
        
        .layout-minimal .rotation-large {
            font-size: 24px;
            font-weight: bold;
            color: #d63384;
            margin-bottom: 5px;
        }
        
        .layout-minimal .part-info {
            font-size: 10px;
            margin: 3px 0;
        }
        
        /* Layout Industrial */
        .layout-industrial {
            background: #f8f9fa;
            border: 3px solid #000;
        }
        
        .layout-industrial .warning-header {
            background: #ffcc00;
            color: #000;
            font-weight: bold;
            text-align: center;
            padding: 2px;
            font-size: 8px;
        }
        
        .layout-industrial .rotation-box {
            background: #000;
            color: #fff;
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            padding: 5px;
            margin: 2px 0;
        }
        
        /* Layout Modern */
        .layout-modern {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            border: none;
        }
        
        .layout-modern .top-bar {
            background: rgba(255,255,255,0.2);
            text-align: center;
            padding: 3px;
            font-size: 8px;
            border-radius: 4px 4px 0 0;
        }
        
        .layout-modern .rotation-highlight {
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
            margin: 5px 0;
        }
        
        /* Print Styles */
        @media print {
            body { 
                margin: 0; 
                background: white; 
            }
            .no-print { 
                display: none; 
            }
            .font-status { 
                display: none; 
            }
            .label-container {
                margin: 0;
            }
        }
        
        /* Control Buttons */
        .btn {
            display: inline-block;
            padding: 6px 12px;
            margin: 2px;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.42857143;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            cursor: pointer;
            border: 1px solid transparent;
            border-radius: 4px;
            text-decoration: none;
        }
        
        .btn-primary {
            color: #fff;
            background-color: #007bff;
            border-color: #007bff;
        }
        
        .btn-outline-primary {
            color: #007bff;
            background-color: transparent;
            border-color: #007bff;
        }
        
        .btn-warning {
            color: #212529;
            background-color: #ffc107;
            border-color: #ffc107;
        }
        
        .btn-outline-warning {
            color: #ffc107;
            background-color: transparent;
            border-color: #ffc107;
        }
        
        .btn-secondary {
            color: #fff;
            background-color: #6c757d;
            border-color: #6c757d;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        
        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        .alert-warning {
            color: #856404;
            background-color: #fff3cd;
            border-color: #ffeaa7;
        }
    </style>
</head>
<body>
    
    <?php if ($layout === "receipt"): ?>
    <!-- Layout แบบใบเสร็จเหมือนในภาพ 100% -->
    <div class="label-container" style="width: 3.0in; height: 1.5in; border: 2px solid #000; padding: 0.05in; position: relative; font-family: Arial, sans-serif; background: white;">
        <!-- ส่วนหัว -->
        <div style="font-size: 12px; font-weight: bold; margin-bottom: 3px;">
            Trim Rotation #: <?php echo htmlspecialchars($rotation); ?>
        </div>
        
        <!-- แถวกลาง - บาร์โค้ดหลักและ QR Code -->
        <div style="display: flex; margin-bottom: 5px; justify-content: space-between;">
            <div style="flex: 3;">
                <!-- บาร์โค้ด 1D หลัก -->
                <div class="barcode-free3of9" style="font-size: 28px; line-height: 1.2;">
                    <?php if ($hasFontFiles): ?>
                    *<?php echo strtoupper($barcodeData); ?>*
                    <?php else: ?>
                    <?php echo strtoupper($barcodeData); ?>
                    <?php endif; ?>
                </div>
                <div style="text-align: center; font-size: 7px;">ECSS Date and rotation</div>
            </div>
            <div style="flex: 1; text-align: right;">
                <!-- QR Code -->
                <div style="width: 32px; height: 32px;">
                    <?php echo generateQRCode($qrData, 32); ?>
                    <div style="font-size: 6px; text-align: center;">QR TTV</div>
                </div>
            </div>
        </div>
        
        <!-- บาร์โค้ด Part Number -->
        <div style="margin-bottom: 5px;">
            <div class="barcode-free3of9" style="font-size: 22px; line-height: 1.2;">
                <?php if ($hasFontFiles): ?>
                *<?php echo strtoupper($partNumber); ?>*
                <?php else: ?>
                <?php echo strtoupper($partNumber); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- ส่วนท้าย -->
        <div style="display: flex; justify-content: space-between; font-size: 7px; position: absolute; bottom: 0.05in; width: 95%;">
            <div>
                Printed <?php echo date("d/m/y H:i"); ?>
            </div>
            <div>
                <?php echo htmlspecialchars($group); ?>
            </div>
            <div>
                Position <?php echo $position; ?>
            </div>
        </div>
    </div>
    
    <?php elseif ($layout === "standard"): ?>
    <!-- Layout Standard with Free 3 of 9 Regular -->
     
    <div class="label-container layout-standard">
        <div class="barcode-section" style="margin: 2px 0; height: 30px; display: flex; justify-content: space-between; align-items: center; padding-top: 0.05in; padding-bottom: 0.05in;">
            <div class="header" style="font-size: 8px; font-weight: normal; text-align: center; width: auto;">
            Trim Rotation # <br>
            <div style="font-size: 16px; font-weight: bold;"><?php echo htmlspecialchars($rotation); ?></div>
            <div class="barcode-text" style="font-size: 7px; text-align: center;"><?php echo htmlspecialchars($barcodeData); ?></div>
            </div>
            
            <div class="barcode" style="flex: 1; text-align: center;">
            <div class="barcode-free3of9" style="font-size: 22px; line-height: 1;">
            <?php if ($hasFontFiles): ?>
            *<?php echo strtoupper($barcodeData); ?>*
            <?php else: ?>
            <?php echo strtoupper($barcodeData); ?>
            <?php endif; ?>
            </div>
            <div style="font-size: 6px; text-align: center; margin-top: 1px;">ECSS Date and rotation</div>
            </div>
            
            <div class="qr-code" style="width: 40px; text-align: right;">
            <?php echo generateQRCode($qrData, 40); ?>
            </div>
        </div>


        <!-- เส้นแบ่ง -->
        <hr style="border: none; border-top: 1px solid #000; margin: 3px 0;">
               
        <div class="part-section" style="display: flex; justify-content: space-between; align-items: flex-end; margin: 2px 0; padding-top: 0.005in; padding-bottom: 0.005in;">
            <div style="flex: 3;">
            <div class="barcode-free3of9" style="font-size: 25px; text-align: left; font-weight: normal;">
            <?php if ($hasFontFiles): ?>
            *<?php echo strtoupper($partNumber); ?>*
            <?php else: ?>
            <?php echo strtoupper($partNumber); ?>
            <?php endif; ?>
            </div>
            <div class="barcode-text" style="text-align: left; font-size: 8px; font-weight: normal;"><?php echo htmlspecialchars($partNumber); ?></div>
            </div>
            <div style="flex: 1; text-align: right; font-size: 8px; font-weight: normal;">
            <?php echo htmlspecialchars($group); ?>
            </div>
        </div>
        
        <div class="footer" style>
            <div></div>
            <div></div>
            <div></div>
        </div>
        <!-- เส้นแบ่ง -->
        <hr style="border: none; border-top: 1px solid #000; margin: 3px 0;">
        
        <div class="footer">
            <div>Printed: <?php echo $printedDate; ?></div>
            <div>Position: <?php echo htmlspecialchars($position); ?></div>
        </div>
    </div>
    
    <?php elseif ($layout === "compact"): ?>
    <!-- Layout Compact with Free 3 of 9 Regular -->
    <div class="label-container layout-compact">
        <div class="header">ROTATION: <?php echo htmlspecialchars($rotation); ?></div>
        
        <div class="main-info">
            <div class="left-section">
                <div><strong>Part:</strong> <?php echo htmlspecialchars($partNumber); ?></div>
                <div><strong>Group:</strong> <?php echo htmlspecialchars($group); ?> | <strong>Pos:</strong> <?php echo $position; ?></div>
                <div class="barcode-free3of9" style="font-size: 12px;">
                    <?php if ($hasFontFiles): ?>
                    *<?php echo strtoupper($barcodeData); ?>*
                    <?php else: ?>
                    <?php echo strtoupper($barcodeData); ?>
                    <?php endif; ?>
                </div>
                <div class="barcode-text"><?php echo htmlspecialchars($barcodeData); ?></div>
            </div>
            <div class="right-section">
                <?php echo generateQRCode($qrData, 40); ?>
            </div>
        </div>
        
        <div class="footer">
            <div><?php echo $printedDate; ?></div>
            <div>Emergency Picking</div>
        </div>
    </div>
    <?php elseif ($layout === "minimal"): ?>
    <!-- Layout Minimal with Free 3 of 9 Regular -->
    <div class="label-container layout-minimal">
        <div class="rotation-large"><?php echo htmlspecialchars($rotation); ?></div>
        <div class="part-info"><?php echo htmlspecialchars($partNumber); ?></div>
        <div class="part-info"><?php echo htmlspecialchars($group); ?> - Position <?php echo $position; ?></div>
        <div class="barcode-free3of9" style="font-size: 16px;">
            <?php if ($hasFontFiles): ?>
            *<?php echo strtoupper($barcodeData); ?>*
            <?php else: ?>
            <?php echo strtoupper($barcodeData); ?>
            <?php endif; ?>
        </div>
        <div class="barcode-text"><?php echo htmlspecialchars($barcodeData); ?></div>
        <div style="font-size: 8px; margin-top: 5px;"><?php echo $printedDate; ?></div>
    </div>
    
    <?php elseif ($layout === "industrial"): ?>
    <!-- Layout Industrial with Free 3 of 9 Regular -->
    <div class="label-container layout-industrial">
        <div class="warning-header">⚠ EMERGENCY PICKING ⚠</div>
        <div class="rotation-box">ROTATION: <?php echo htmlspecialchars($rotation); ?></div>
        
        <div style="display: flex; justify-content: space-between; margin: 2px 0;">
            <div style="font-size: 9px;">
                <div><strong>PART:</strong> <?php echo htmlspecialchars($partNumber); ?></div>
                <div><strong>GROUP:</strong> <?php echo htmlspecialchars($group); ?></div>
                <div><strong>POS:</strong> <?php echo $position; ?></div>
            </div>
            <div><?php echo generateQRCode($qrData, 30); ?></div>
        </div>
        
        <div style="text-align: center; margin: 2px 0;">
            <div class="barcode-free3of9" style="font-size: 14px;">
                <?php if ($hasFontFiles): ?>
                *<?php echo strtoupper($barcodeData); ?>*
                <?php else: ?>
                <?php echo strtoupper($barcodeData); ?>
                <?php endif; ?>
            </div>
            <div class="barcode-text"><?php echo htmlspecialchars($barcodeData); ?></div>
        </div>
        
        <div style="display: flex; justify-content: space-between; font-size: 8px; margin-top: 2px;">
            <div><?php echo $printedDate; ?></div>
            <div>TTV SYSTEM</div>
        </div>
    </div>
    
    <?php elseif ($layout === "modern"): ?>
    <!-- Layout Modern with Free 3 of 9 Regular -->
    <div class="label-container layout-modern">
        <div class="top-bar">Emergency Picking System</div>
        <div class="rotation-highlight">ROT: <?php echo htmlspecialchars($rotation); ?></div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin: 3px 0;">
            <div style="font-size: 9px;">
                <div><?php echo htmlspecialchars($partNumber); ?></div>
                <div><?php echo htmlspecialchars($group); ?> | Pos: <?php echo $position; ?></div>
            </div>
            <div><?php echo generateQRCode($qrData, 30); ?></div>
        </div>
        
        <div style="background: rgba(255,255,255,0.9); color: #000; padding: 2px; border-radius: 3px; margin: 2px 0;">
            <div class="barcode-free3of9" style="font-size: 12px; color: #000;">
                <?php if ($hasFontFiles): ?>
                *<?php echo strtoupper($barcodeData); ?>*
                <?php else: ?>
                <?php echo strtoupper($barcodeData); ?>
                <?php endif; ?>
            </div>
            <div class="barcode-text" style="color: #000;"><?php echo htmlspecialchars($barcodeData); ?></div>
        </div>
        
        <div style="display: flex; justify-content: space-between; color: rgba(255,255,255,0.8); font-size: 8px; margin-top: 2px;">
            <div><?php echo $printedDate; ?></div>
            <div>TTV</div>
        </div>
    </div>
    
    <?php elseif ($layout === "font-test"): ?>
    <!-- Layout Test Fonts -->
    <div style="width: 4in; min-height: 3in; border: 1px solid #ccc; padding: 0.2in; background: white; margin: 0 auto;">
        <h3>Font Test - Free 3 of 9 Regular</h3>
        
        <div style="margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <h5>ข้อมูลที่ใช้ในการทดสอบ:</h5>
            <table style="width: 100%; font-size: 12px;">
                <tr><td><strong>Group:</strong></td><td><?php echo htmlspecialchars($group); ?></td></tr>
                <tr><td><strong>Group Abbreviation:</strong></td><td><?php echo getGroupAbbreviation($group); ?></td></tr>
                <tr><td><strong>Rotation:</strong></td><td><?php echo htmlspecialchars($rotation); ?></td></tr>
                <tr><td><strong>Date:</strong></td><td><?php echo date('Y-m-d'); ?> (<?php echo date('Ymd'); ?>)</td></tr>
                <tr><td><strong>QR Data:</strong></td><td><?php echo htmlspecialchars($qrData); ?></td></tr>
                <tr><td><strong>Barcode Data:</strong></td><td><?php echo htmlspecialchars($barcodeData); ?></td></tr>
            </table>
        </div>
        
        <div style="margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <h5>ตัวอย่าง QR Code:</h5>
            <div style="text-align: center; margin: 10px 0;">
                <?php echo generateQRCode($qrData, 100); ?>
            </div>
            <p style="text-align: center; font-size: 12px; margin: 5px 0;">
                <strong>QR Data:</strong> <?php echo htmlspecialchars($qrData); ?>
            </p>
        </div>
        
        <div style="margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <h5>สถานะ Font Files ใน Free_3_of_9_Regular/</h5>
            <?php foreach ($fontFiles as $fontFile => $exists): ?>
            <div style="margin: 5px 0;">
                <?php echo $exists ? '✅' : '❌'; ?> 
                <strong><?php echo $fontFile; ?></strong>
                <?php if (!$exists): ?>
                <span style="color: red;">(ไม่พบ)</span>
                <?php else: ?>
                <span style="color: green;">(พบแล้ว)</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div style="margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <h5>ทดสอบ Font</h5>
            
            <?php if ($hasFontFiles): ?>
            <div style="margin: 5px 0;">
                <strong>Free 3 of 9 Regular:</strong><br>
                <div class="barcode-free3of9" style="font-size: 20px;">*<?php echo strtoupper($barcodeData); ?>*</div>
                <div class="barcode-text"><?php echo htmlspecialchars($barcodeData); ?></div>
            </div>
            <?php endif; ?>
            
            <div style="margin: 5px 0;">
                <strong>Google Font Fallback:</strong><br>
                <div style="font-family: 'Libre Barcode 39 Text', monospace; font-size: 20px; text-align: center;"><?php echo strtoupper($barcodeData); ?></div>
                <div class="barcode-text"><?php echo htmlspecialchars($barcodeData); ?></div>
            </div>
            
            <div style="margin: 5px 0;">
                <strong>Monospace Fallback:</strong><br>
                <div style="font-family: monospace; font-size: 12px; text-align: center;">||| ||| ||| ||| |||<br><?php echo htmlspecialchars($barcodeData); ?></div>
            </div>
        </div>
        
        <div style="margin: 10px 0; padding: 10px; border: 1px solid #ccc; border-radius: 5px;">
            <h5>รูปแบบข้อมูล QR Code ใหม่:</h5>
            <table style="width: 100%; font-size: 12px;">
                <tr><td><strong>รูปแบบ:</strong></td><td>G[XX]_YYYYMMDD[Rotation]</td></tr>
                <tr><td><strong>ตัวอย่าง GROUP 01:</strong></td><td>G01_202506176918</td></tr>
                <tr><td><strong>ตัวอย่าง GROUP 02:</strong></td><td>G02_202506176918</td></tr>
                <tr><td><strong>ตัวอย่าง GROUP 10:</strong></td><td>G10_202506176918</td></tr>
            </table>
            <p style="font-size: 11px; color: #666;">
                - G[XX] = Group abbreviation (G + หมายเลขกลุ่ม 2 หลัก)<br>
                - YYYY = ปี (4 หลัก)<br>
                - MM = เดือน (2 หลัก)<br>
                - DD = วัน (2 หลัก)<br>
                - Rotation = หมายเลข Rotation
            </p>
        </div>
        
        <?php if (!$hasFontFiles): ?>
        <div style="margin: 10px 0; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px;">
            <h5 style="color: #856404;">⚠️ ไม่พบ Font Files</h5>
            <p style="font-size: 12px;">
                โฟลเดอร์ <code>Free_3_of_9_Regular/</code> พร้อมใช้งานแล้ว แต่ระบบจะใช้ Google Fonts เป็น fallback
            </p>
        </div>
        <?php else: ?>
        <div style="margin: 10px 0; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;">
            <h5 style="color: #155724;">✅ Font Setup สมบูรณ์!</h5>
            <p style="font-size: 12px; margin: 0;">
                ระบบใช้ Font จากโฟลเดอร์ Free_3_of_9_Regular แล้ว บาร์โค้ดจะแสดงถูกต้องในทุกเครื่อง
            </p>
        </div>
        <?php endif; ?>
    </div>
    
    <?php else: ?>
    <!-- Default/Unknown Layout -->
    <div class="label-container layout-standard">
        <div style="text-align: center; padding: 20px;">
            <h4>Layout: <?php echo htmlspecialchars($layout); ?></h4>
            <div class="barcode-free3of9" style="margin: 20px 0;">
                <?php if ($hasFontFiles): ?>*<?php echo strtoupper($barcodeData); ?>*<?php else: ?><?php echo strtoupper($barcodeData); ?><?php endif; ?>
            </div>
            <div class="barcode-text"><?php echo htmlspecialchars($barcodeData); ?></div>
            <div style="margin-top: 10px; font-size: 10px;">
                <strong><?php echo htmlspecialchars($partNumber); ?></strong> | <?php echo htmlspecialchars($group); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- ส่วนควบคุม (ไม่แสดงตอนพิมพ์) -->
    <div class="no-print" style="margin-top: 20px; text-align: center;">
        <button onclick="window.print()" class="btn btn-primary">
            🖨️ พิมพ์ฉลาก
        </button>
        <button onclick="history.back()" class="btn btn-secondary">
            ← กลับ
        </button>
    </div>
    
    <!-- เลือก Layout -->
    <div class="no-print" style="margin-top: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; max-width: 800px; margin-left: auto; margin-right: auto;">
        <h3>เลือกรูปแบบฉลาก:</h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px; justify-content: center;">
            <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'standard'])); ?>" 
               class="btn btn-<?php echo $layout === 'standard' ? 'primary' : 'outline-primary'; ?>">Standard</a>
            
            <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'compact'])); ?>" 
               class="btn btn-<?php echo $layout === 'compact' ? 'primary' : 'outline-primary'; ?>">Compact</a>
            
            <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'minimal'])); ?>" 
               class="btn btn-<?php echo $layout === 'minimal' ? 'primary' : 'outline-primary'; ?>">Minimal</a>
            
            <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'industrial'])); ?>" 
               class="btn btn-<?php echo $layout === 'industrial' ? 'primary' : 'outline-primary'; ?>">Industrial</a>
            
            <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'modern'])); ?>" 
               class="btn btn-<?php echo $layout === 'modern' ? 'primary' : 'outline-primary'; ?>">Modern</a>
            
            <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'font-test'])); ?>" 
               class="btn btn-<?php echo $layout === 'font-test' ? 'warning' : 'outline-warning'; ?>">Font Test</a>
        </div>
        
        <div class="alert alert-success">
            <h5>✅ ใช้ Font จากโฟลเดอร์ Free_3_of_9_Regular</h5>
            <p><strong>ข้อดี:</strong> Font พร้อมใช้งาน ไม่ต้องอัปโหลดเพิ่ม บาร์โค้ดแสดงถูกต้องทุกอุปกรณ์</p>
            <p><strong>ที่ตั้ง:</strong> <code>Free_3_of_9_Regular/FREE3OF9.ttf</code></p>
            <p><strong>การใช้งาน:</strong> เรียกผ่าน URL พร้อมพารามิเตอร์ที่ต้องการ</p>
        </div>
        
        <h4>ตัวอย่างการใช้งาน:</h4>
        <div style="background: #f1f3f4; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px;">
            generate-label.php?rotation=6918&part_number=RB3T14290ABC&group=GROUP%2001&position=12&layout=standard
        </div>
        
        <h4>รูปแบบ QR Code ใหม่:</h4>
        <div style="background: #e8f5e8; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px;">
            <strong>รูปแบบ:</strong> G[XX]_YYYYMMDD[Rotation]<br>
            <strong>ตัวอย่าง:</strong> <?php echo htmlspecialchars($qrData); ?><br>
            <strong>อธิบาย:</strong><br>
            - <?php echo getGroupAbbreviation($group); ?> = <?php echo htmlspecialchars($group); ?><br>
            - <?php echo date('Ymd'); ?> = วันที่ <?php echo date('d/m/Y'); ?><br>
            - <?php echo htmlspecialchars($rotation); ?> = Rotation Number
        </div>
        
        <h4>พารามิเตอร์ที่รองรับ:</h4>
        <ul style="font-size: 12px;">
            <li><strong>rotation</strong> - หมายเลข Rotation</li>
            <li><strong>part_number</strong> - หมายเลขชิ้นส่วน</li>
            <li><strong>group</strong> - กลุ่มงาน (เช่น GROUP 01)</li>
            <li><strong>position</strong> - ตำแหน่งบน Dolly</li>
            <li><strong>barcode_data</strong> - ข้อมูลบาร์โค้ด (ถ้าไม่ระบุจะใช้ YYYYMMDD + Rotation)</li>
            <li><strong>layout</strong> - รูปแบบฉลาก (standard, compact, minimal, industrial, modern, font-test)</li>
            <li><strong>round</strong> - รอบการทำงาน (1 หรือ 2) สำหรับ GROUP 12</li>
            <li><strong>auto_print</strong> - ถ้าใส่ 1 จะพิมพ์อัตโนมัติ</li>
        </ul>
    </div>
    
    <script>
        // ตรวจสอบการโหลด Font และอัปเดตสถานะ
        document.addEventListener('DOMContentLoaded', function() {
            // const fontStatus = document.getElementById('fontStatus'); // ลบการเรียกใช้ fontStatus
            
            // ตรวจสอบว่า Font โหลดสำเร็จหรือไม่
            // if (document.fonts && fontStatus) { // ลบการตรวจสอบ fontStatus
            //     document.fonts.ready.then(function() {
            //         const fontFace = [...document.fonts].find(font => 
            //             font.family.includes('Free 3 of 9') || font.family.includes('Free3of9')
            //         );
                    
            //         if (fontFace && fontStatus) { 
            //             fontStatus.className = 'font-status loaded no-print';
            //             fontStatus.innerHTML = '✅ Free 3 of 9 โหลดสำเร็จ';
            //         } else if (fontStatus) { 
            //             fontStatus.className = 'font-status fallback no-print';
            //             fontStatus.innerHTML = '⚠️ ใช้ Fallback Font';
            //         }
            //     });
            // }
            
            // Auto-print
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('auto_print') === '1') {
                setTimeout(function() {
                    window.print();
                }, 1000);
            }
        });
        
        // ป้องกันการคลิกซ้ำ
        let isPrinting = false;
        window.addEventListener('beforeprint', function() {
            if (isPrinting) return false;
            isPrinting = true;
        });
        
        window.addEventListener('afterprint', function() {
            isPrinting = false;
        });
    </script>
</body>
</html>
    </script>
</body>
</html>

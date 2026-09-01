<?php
/**
 * Navbar กลางของระบบ Emergency Picking
 *
 * วิธีใช้:
 *   1. ใน <head>  ->  <link rel="stylesheet" href="../includes/navbar.css">
 *   2. ต้นๆ <body> ->  <?php include __DIR__ . '/../includes/navbar.php'; ?>
 *
 * เพิ่ม/ลบ/สลับเมนู แก้ที่ $navbarMenu ด้านล่างที่เดียว มีผลทุกหน้า
 * ถ้าหน้าที่เรียกใช้ไม่ได้อยู่ลึก 1 ระดับจาก root ให้กำหนด $navbarBase ก่อน include
 */

$navbarBase = isset($navbarBase) ? $navbarBase : '../';

// 'show' => false คือซ่อนจากเมนูแต่ยังเก็บไฟล์ไว้
$navbarMenu = [
    ['label' => 'แดชบอร์ด',                  'href' => 'admin/index.php',               'icon' => 'fa-tachometer-alt'],
    ['label' => 'Convert BC',                'href' => 'Convert_BC/index.php',          'icon' => 'fa-exchange-alt'],
    ['label' => 'พิมพ์สติกเกอร์',            'href' => 'admin/print_bulk_stickers.php', 'icon' => 'fa-print'],
    ['label' => 'พิมพ์ใบสรุป',               'href' => 'admin/print_summary.php',       'icon' => 'fa-file-pdf'],
    ['label' => 'ประวัติ',                   'href' => 'admin/history.php',             'icon' => 'fa-history'],
    ['label' => 'รายงาน',                    'href' => 'admin/reports.php',             'icon' => 'fa-chart-bar'],
    ['label' => 'กำหนดจุดเริ่มต้น Position', 'href' => 'admin/position_mapping.php',    'icon' => 'fa-map-marker-alt'],
    ['label' => 'ผู้ใช้งาน',                 'href' => 'admin/manage_users.php',        'icon' => 'fa-users'],
    ['label' => 'อัปโหลด',                   'href' => 'admin/upload.php',              'icon' => 'fa-upload', 'show' => false],
];

$navbarScript  = strtolower(basename($_SERVER['SCRIPT_NAME']));
$navbarDir     = strtolower(basename(dirname($_SERVER['SCRIPT_NAME'])));
$navbarCurrent = $navbarDir . '/' . $navbarScript;
?>
<nav class="navbar navbar-expand-lg navbar-dark sticky-top app-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo $navbarBase; ?>">
            <i class="fas fa-warehouse"></i>
            Emergency Picking (Operation TTV AAT-Wiring)
        </a>
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="สลับเมนู">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
<?php foreach ($navbarMenu as $item):
    if (isset($item['show']) && $item['show'] === false) {
        continue;
    }
    $isActive = (strtolower($item['href']) === $navbarCurrent); ?>
                <li class="nav-item">
                    <a class="nav-link<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo $navbarBase . $item['href']; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                        <i class="fas <?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                    </a>
                </li>
<?php endforeach; ?>
                <li class="nav-item">
                    <a class="nav-link logout-link" href="<?php echo $navbarBase; ?>admin/logout.php">
                        <i class="fas fa-sign-out-alt"></i> ออกจากระบบ
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

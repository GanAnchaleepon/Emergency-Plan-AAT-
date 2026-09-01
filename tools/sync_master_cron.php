<?php
/**
 * ดึง Master Data จาก FTM SEQ อัตโนมัติ — สำหรับ cron / Task Scheduler เท่านั้น
 *
 * Linux (Hostinger) — ตั้ง 2 รอบตามเวลาไทย:
 *   30 7 * * *  /usr/bin/php /home/xxx/public_html/tools/sync_master_cron.php
 *   0 22 * * *  /usr/bin/php /home/xxx/public_html/tools/sync_master_cron.php
 *
 * Windows (XAMPP) — Task Scheduler เรียก:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Emergency-Plan-AAT-\tools\sync_master_cron.php
 *
 * ใส่ --force เพื่อสั่งดึงทันทีโดยไม่สนตารางเวลา
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("สคริปต์นี้รันได้จาก command line เท่านั้น\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/MasterDataSync.php';

$config = require __DIR__ . '/../config/ftmseq.php';
$sync = new MasterDataSync($conn, $config);
$force = in_array('--force', $argv, true);

$stamp = date('Y-m-d H:i:s');

if (!$force) {
    $slot = $sync->dueSlot();
    if ($slot === null) {
        echo "[$stamp] ยังไม่ถึงรอบดึงข้อมูล ข้ามไป\n";
        exit(0);
    }
    echo "[$stamp] ถึงรอบ $slot น. เริ่มดึงข้อมูล\n";
}

try {
    $result = $sync->run($force ? 'manual-cli' : 'cron');
    echo "[$stamp] " . $result['message'] . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[$stamp] ผิดพลาด: " . $e->getMessage() . "\n");
    exit(1);
}

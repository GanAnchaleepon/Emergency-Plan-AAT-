<?php
/**
 * ตั้งค่าการเชื่อมต่อ FTM SEQ API (ส่วนที่ไม่เป็นความลับ — ขึ้น git ได้)
 * รหัสผ่านอยู่ใน config/ftmseq.local.php เท่านั้น
 */

$config = [
    'base_url'   => 'https://aatsecloud.ttv-supplychain.com/aatseq/',
    'login_path' => 'chPass.php',

    // ใบรับรอง: โหลดจาก https://curl.se/ca/cacert.pem มาวางไว้ที่ path นี้
    // ถ้าไม่มีไฟล์ ระบบจะใช้ CA ของเครื่องแทน (บน XAMPP มักไม่ผ่าน ให้โหลดมาวาง)
    'ca_bundle'  => __DIR__ . '/../storage/certs/cacert.pem',

    'cookie_jar' => __DIR__ . '/../storage/ftmseq.cookies',
    'state_file' => __DIR__ . '/../storage/ftmseq_master.state.json',
    'lock_file'  => __DIR__ . '/../storage/ftmseq_master.lock',

    'master' => [
        'endpoint' => 'MasterData/PartMaster_data.php',
        'fields'   => ['type' => 1],

        // จับคู่คอลัมน์ในตาราง master_data กับชื่อฟิลด์ที่ API ส่งมา
        // ใส่ได้หลายชื่อ ระบบจะไล่หาตัวแรกที่เจอ (เผื่อ API เปลี่ยนชื่อฟิลด์)
        'map' => [
            'part_number'   => ['Part_Number'],
            'part_group'    => ['Part_Group'],
            'supplier'      => ['Supplier_Code', 'Supplier'],
            // ตรงกับคอลัมน์ "Location" ในไฟล์ Master.csv เดิม
            'location_pick' => ['Location', 'Pick_Location'],
        ],

        // รอบดึงอัตโนมัติ (เวลาไทย) — ใช้โดย tools/sync_master_cron.php
        'schedule' => ['07:30', '22:00'],

        // ถ้าเลยเวลารอบมานานกว่านี้ (นาที) ให้ข้ามรอบนั้นไป ไม่ต้องดึงย้อนหลัง
        'schedule_grace_minutes' => 180,
    ],
];

$localConfig = __DIR__ . '/ftmseq.local.php';
if (is_file($localConfig)) {
    $config = array_replace_recursive($config, require $localConfig);
}

return $config;

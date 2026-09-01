<?php
/**
 * คัดลอกไฟล์นี้เป็น config/ftmseq.local.php แล้วกรอกค่าจริง
 * ไฟล์ ftmseq.local.php ถูก .gitignore ไว้แล้ว ห้าม commit ขึ้น git
 */

return [
    'user' => '',   // ชื่อผู้ใช้ FTM SEQ (ควรใช้ service account ไม่ใช่บัญชีส่วนตัว)
    'pass' => '',   // รหัสผ่าน

    // ถ้า endpoint จริงไม่ตรงกับค่า default ใน config/ftmseq.php ให้ override ตรงนี้
    // 'master' => [
    //     'endpoint' => 'shipping/xxx_data.php',
    //     'map' => [
    //         'part_number'   => 'Part_Number',
    //         'part_group'    => 'Part_Group',
    //         'supplier'      => 'Supplier_Code',
    //         'location_pick' => 'Location',
    //     ],
    // ],
];

-- เพิ่มคอลัมน์ last_position ในตาราง group_settings (สำหรับการอัปเกรด)

-- ตรวจสอบว่ามีคอลัมน์ last_position หรือยัง
SELECT COUNT(*) INTO @column_exists
FROM information_schema.columns 
WHERE table_schema = DATABASE()
AND table_name = 'group_settings' 
AND column_name = 'last_position';

-- ถ้ายังไม่มีคอลัมน์ last_position ให้เพิ่ม
SET @query = IF(@column_exists = 0, 
                'ALTER TABLE `group_settings` ADD COLUMN `last_position` int(11) DEFAULT 0 AFTER `max_position`', 
                'SELECT \'Column last_position already exists\' AS message');

PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- อัปเดตค่า last_position สำหรับแต่ละกลุ่มโดยดูจากตำแหน่งสุดท้ายที่มีการใช้งาน
UPDATE group_settings gs
SET gs.last_position = (
    SELECT IFNULL(MAX(pp.position), 0)
    FROM picking_plans pp
    WHERE pp.group_name = gs.group_name
);

-- แสดงค่า last_position ที่อัปเดตแล้ว
SELECT group_name, max_position, last_position FROM group_settings ORDER BY group_name;

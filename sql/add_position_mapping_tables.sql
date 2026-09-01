-- ไฟล์ SQL สำหรับเพิ่มตาราง position_mappings และ group_settings

-- สร้างตาราง position_mappings
CREATE TABLE IF NOT EXISTS `position_mappings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `group_name` varchar(20) NOT NULL,
    `start_rotation` varchar(50) NOT NULL,
    `start_position` int(11) NOT NULL,
    `max_position` int(11) NOT NULL,
    `created_by` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- สร้างตาราง group_settings
CREATE TABLE IF NOT EXISTS `group_settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `group_name` varchar(20) NOT NULL,
    `max_position` int(11) NOT NULL DEFAULT 18,
    `last_position` int(11) DEFAULT 0,
    `created_by` int(11) NOT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เพิ่มข้อมูลตัวอย่างสำหรับ group_settings
INSERT INTO `group_settings` (group_name, max_position, last_position, created_by) VALUES
('GROUP 01', 18, 0, 1),
('GROUP 02', 18, 0, 1),
('GROUP 03', 18, 0, 1),
('GROUP 04', 18, 0, 1),
('GROUP 05', 18, 0, 1),
('GROUP 06', 18, 0, 1),
('GROUP 07', 18, 0, 1),
('GROUP 08L', 18, 0, 1),
('GROUP 08R', 18, 0, 1),
('GROUP 09', 18, 0, 1),
('GROUP 10', 18, 0, 1),
('GROUP 11L', 18, 0, 1),
('GROUP 11R', 18, 0, 1),
('GROUP 12', 18, 0, 1),
('GROUP 13', 18, 0, 1),
('GROUP 14', 18, 0, 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();

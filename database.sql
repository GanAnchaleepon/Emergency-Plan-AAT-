-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 01, 2025 at 06:16 AM
-- Server version: 10.11.10-MariaDB
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u829165346_AAT_Emergency`
--

-- --------------------------------------------------------

--
-- Table structure for table `completed_rounds`
--

CREATE TABLE `completed_rounds` (
  `id` int(11) NOT NULL,
  `group_name` varchar(20) NOT NULL,
  `start_time` timestamp NOT NULL,
  `end_time` timestamp NOT NULL,
  `rack_sequence` varchar(3) DEFAULT NULL,
  `ttv_round_no` varchar(2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_settings`
--

CREATE TABLE `group_settings` (
  `id` int(11) NOT NULL,
  `group_name` varchar(20) NOT NULL,
  `max_position` int(11) NOT NULL DEFAULT 18,
  `last_position` int(11) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `master_data`
--

CREATE TABLE `master_data` (
  `id` int(11) NOT NULL,
  `part_group` varchar(50) DEFAULT NULL,
  `part_number` varchar(50) DEFAULT NULL,
  `supplier` varchar(50) DEFAULT NULL,
  `location_pick` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for table `master_uploads`
--

CREATE TABLE `master_uploads` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `filedate` datetime NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `picking_activities`
--

CREATE TABLE `picking_activities` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `scan_data` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `picking_plans`
--

CREATE TABLE `picking_plans` (
  `id` int(11) NOT NULL,
  `upload_id` int(11) NOT NULL DEFAULT 0,
  `group_name` varchar(10) NOT NULL DEFAULT '',
  `position` int(11) NOT NULL DEFAULT 1,
  `part_number` varchar(50) NOT NULL DEFAULT '',
  `part_name` varchar(255) NOT NULL DEFAULT '',
  `item_lot` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `rotation` varchar(50) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `supplier` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `date` varchar(20) DEFAULT NULL,      -- เพิ่มจาก FTM
  `time` varchar(20) DEFAULT NULL       -- เพิ่มจาก FTM
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `position_mappings`
--

CREATE TABLE `position_mappings` (
  `id` int(11) NOT NULL,
  `group_name` varchar(20) NOT NULL,
  `start_rotation` varchar(50) NOT NULL,
  `start_position` int(11) NOT NULL,
  `max_position` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `uploads`
--

CREATE TABLE `uploads` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `rows_total` int(11) NOT NULL DEFAULT 0,
  `rows_success` int(11) NOT NULL DEFAULT 0,
  `rows_duplicate` int(11) NOT NULL DEFAULT 0,
  `rows_error` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `name`, `role`, `created_at`) VALUES
(1, 'admin', '$2y$10$yLOicTuu5.gzMSvtzqiEZub9OyMXRkfuu7Ip2tUO9MD18UHgNpN7C', 'ผู้ดูแลระบบ', 'admin', '2025-06-19 06:03:53');

--
-- Dumping data for table `group_settings`
--

INSERT INTO `group_settings` (`id`, `group_name`, `max_position`, `last_position`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'GROUP 01', 16, 0, 1, '2025-08-19 10:33:14', '2025-08-20 08:44:04'),
(2, 'GROUP 02', 16, 0, 1, '2025-08-19 10:33:14', '2025-08-20 07:16:33'),
(3, 'GROUP 03', 16, 0, 1, '2025-08-19 10:33:14', '2025-08-20 08:44:09'),
(4, 'GROUP 04', 8, 0, 1, '2025-08-19 10:33:14', '2025-08-20 08:44:17'),
(5, 'GROUP 05', 20, 0, 1, '2025-08-19 10:33:14', '2025-08-20 08:44:14'),
(6, 'GROUP 06L', 20, 0, 1, '2025-08-19 10:33:14', '2025-08-20 08:44:21'),
(7, 'GROUP 06R', 20, 0, 1, '2025-08-19 10:33:14', '2025-08-20 08:44:24'),
(8, 'GROUP 07', 16, 0, 1, '2025-08-19 10:33:14', '2025-08-20 07:17:03'),
(9, 'GROUP 09L', 12, 0, 1, '2025-08-19 10:33:14', '2025-08-20 07:17:49'),
(10, 'GROUP 09R', 12, 0, 1, '2025-08-19 10:33:14', '2025-08-20 07:17:54'),
(11, 'GROUP 10', 16, 0, 1, '2025-08-19 10:33:14', '2025-08-20 07:18:05'),
(12, 'GROUP 11', 16, 0, 1, '2025-08-19 10:33:14', '2025-08-20 07:17:32');

-- --------------------------------------------------------
-- Indexes for dumped tables
--

--
-- Indexes for table `completed_rounds`
--
ALTER TABLE `completed_rounds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_round` (`group_name`,`end_time`);

--
-- Indexes for table `group_settings`
--
ALTER TABLE `group_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_group` (`group_name`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `master_data`
--
ALTER TABLE `master_data`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `master_uploads`
--
ALTER TABLE `master_uploads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `picking_activities`
--
ALTER TABLE `picking_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`);

--
-- Indexes for table `picking_plans`
--
ALTER TABLE `picking_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `upload_id` (`upload_id`),
  ADD KEY `idx_group` (`group_name`),
  ADD KEY `idx_position` (`position`),
  ADD KEY `idx_supplier` (`supplier`),
  ADD KEY `idx_location` (`location`);

--
-- Indexes for table `position_mappings`
--
ALTER TABLE `position_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_group` (`group_name`);

--
-- Indexes for table `uploads`
--
ALTER TABLE `uploads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `completed_rounds`
--
ALTER TABLE `completed_rounds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_settings`
--
ALTER TABLE `group_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `master_data`
--
ALTER TABLE `master_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `master_uploads`
--
ALTER TABLE `master_uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `picking_activities`
--
ALTER TABLE `picking_activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `picking_plans`
--
ALTER TABLE `picking_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `position_mappings`
--
ALTER TABLE `position_mappings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `uploads`
--
ALTER TABLE `uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `logs`
--
ALTER TABLE `logs`
  ADD CONSTRAINT `logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `picking_activities`
--
ALTER TABLE `picking_activities`
  ADD CONSTRAINT `picking_activities_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `picking_plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `picking_plans`
--
ALTER TABLE `picking_plans`
  ADD CONSTRAINT `picking_plans_ibfk_1` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `uploads`
--
ALTER TABLE `uploads`
  ADD CONSTRAINT `uploads_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

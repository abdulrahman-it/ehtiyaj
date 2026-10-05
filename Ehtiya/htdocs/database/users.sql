-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql207.infinityfree.com
-- Generation Time: Oct 05, 2026 at 04:12 PM
-- Server version: 11.4.13-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_43072358_ehtiyaj`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `major` varchar(120) NOT NULL,
  `role` enum('student','admin') NOT NULL DEFAULT 'student',
  `status` enum('active','suspended','deactivated') NOT NULL DEFAULT 'active',
  `email_visibility` enum('private','accepted_only') NOT NULL DEFAULT 'private',
  `phone_visibility` enum('private','accepted_only') NOT NULL DEFAULT 'private',
  `deactivation_reason` text DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password_hash`, `phone`, `major`, `role`, `status`, `email_visibility`, `phone_visibility`, `deactivation_reason`, `deactivated_at`, `created_at`, `updated_at`) VALUES
(1, 'Abdurhman lahem Ali alasbahie', 'abdulrahman.asbahie@gmail.com', '$2y$12$ZImrkgiAey51CpvkGypf/.9CnTBM9XVsNA4GudOHUqGlUULoU4376', '777777777', 'تقنية المعلومات', 'admin', 'active', 'private', 'private', NULL, NULL, '2026-10-02 21:11:32', '2026-10-02 21:13:45'),
(2, 'نجم', 'asbahy_it@scc.edu.ye', '$2y$12$cUSV/lLQYh1dw2upbIyInO1l4lUQ82bVyfWejpT9RJtjT/erSsUBe', '777777555', 'نظم المعلومات', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-02 21:21:11', '2026-10-02 21:21:41'),
(3, 'Hanadi.M Jabir', 'hn777610534@gmail.com', '$2y$12$Kpj.k5QhNBRN7fTUK9s90uhs0soXL.zjb7W7GTgBU.zajmtrdb6ca', '779004687', 'إدارة الأعمال', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-02 21:28:46', '2026-10-02 21:28:46'),
(4, 'najm', 'nn@gmail.com', '$2y$12$TliHO9ERqIOsos/a3TCRhO4LgP5T.kbJ0KQBmEJ/oF6dOLkiRqdwS', '777777777', 'نظم المعلومات', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-03 18:23:56', '2026-10-03 18:37:31'),
(5, 'مهند الخضر١٢٣', 'mohened@gmail.com', '$2y$12$6Yw/HF9mzYwHp0ANcjodneQxMedeTYaX8.vVjom9gGR0S4WQKmttq', '+967770423005اىاةنخن', 'تقنية المعلومات', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-03 18:25:00', '2026-10-03 18:28:02'),
(6, 'احمد', 'ahmed@gmail.com', '$2y$12$gH/iIek4hixhwWOxBRRQXOEV0.sKPFEVXZwW43tQ4RvuvBO9/iob2', '777777777', 'علوم الحاسوب', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-03 21:15:08', '2026-10-03 21:15:08'),
(7, 'عبد الكريم', 'abdulkareemalasbhi4@gmail.com', '$2y$12$HPQvt3SqVhyndhPbUF6b/.zV1EpSpzrKC5WrbIsTH1jl/fp/gR4ZC', '7831177901', 'هندسة الحاسوب', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-03 21:31:59', '2026-10-03 21:39:25'),
(8, 'Ahmed', 'ahmed.test.ehtiyaj.2026@example.com', '$2y$12$XdB9/oJ2TQGnGAoT5gf29OuDTlo7m93x9dFac6WNX2NANTwRIKLUy', NULL, 'إدارة الأعمال', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-05 18:35:54', '2026-10-05 18:35:54'),
(9, 'Sara', 'sara.test.ehtiyaj.2026@example.com', '$2y$12$ye.FWnfCdIPvJCpY330iP.TPiAzNlBwKkW66sGxa7hJbJrxmFpdgy', NULL, 'تقنية المعلومات', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-05 18:41:52', '2026-10-05 18:41:52'),
(10, 'عمارعبدالحكيم', 'wwwammar@gmail.com', '$2y$12$3iuYKgkCdOmAaUtSqUxgxu1S9vIcwMNctYCGWWPb18K4NymG.kRny', '783656033', 'تقنية المعلومات', 'student', 'active', 'private', 'private', NULL, NULL, '2026-10-05 19:07:51', '2026-10-05 19:07:51'),
(11, 'eman', 'eman@gmail.com', '$2y$12$AYdtRX2GZvc4BgfdFoKjpubtkAr8pqpebb5CKSP/jhzlOfnkBRVMW', '777777777', 'نظم المعلومات', 'admin', 'active', 'private', 'private', NULL, NULL, '2026-10-05 19:27:07', '2026-10-05 19:28:32');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_major` (`major`),
  ADD KEY `idx_users_role_status` (`role`,`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- phpMyAdmin SQL Dump
-- version 5.0.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 19, 2020 at 10:29 PM
-- Server version: 10.4.11-MariaDB
-- PHP Version: 7.4.2

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tasksdb`
--

-- --------------------------------------------------------

--
-- Table structure for table `tblsessions`
--

CREATE TABLE `tblsessions` (
  `id` bigint(20) NOT NULL COMMENT 'Session ID',
  `userid` bigint(20) NOT NULL COMMENT 'User ID',
  `accesstoken` varchar(100) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL COMMENT 'Access Token',
  `accesstokenexpiry` datetime NOT NULL COMMENT 'Access Toke Expire time',
  `refreshtoken` varchar(100) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL COMMENT 'Refresh Token',
  `refreshtokenexpiry` datetime NOT NULL COMMENT 'Refresh Token Expire time'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Session table';

--
-- Dumping data for table `tblsessions`
--

INSERT INTO `tblsessions` (`id`, `userid`, `accesstoken`, `accesstokenexpiry`, `refreshtoken`, `refreshtokenexpiry`) VALUES
(6, 1, 'ZjI5MGQwNzkyNTAyOTIzYjViOWY5ZDA0ZDY3MzgwODM2MmNhODUzMGEwNmJlNWMwMzEzNTM4MzkzOTMxMzgzMTMzMzI=', '2020-05-19 22:15:32', 'MTg3MDFkYjVhYmU4NWEwMzRhMWE1ZGY2NGIzNDk2ZmMyYTk3MDI1ODRhY2I0ODdlMzEzNTM4MzkzOTMxMzgzMTMzMzI=', '2020-06-02 21:55:32'),
(7, 1, 'OTBjYmY5NTZmYzU1ZmMyZjA3NTViNjYwMGRkMGM2YzIzMzVmZGFiNDEyNjRlM2NmMzEzNTM4MzkzOTMxMzkzNjMxMzY=', '2020-05-19 22:40:16', 'MGUxMzc3MDE1ZDEzMjU2NWJmZTg4NDg0MDVlZTg4MzI0NDJjM2E3NjFmYzJiNGVlMzEzNTM4MzkzOTMxMzkzNjMxMzY=', '2020-06-02 22:20:16');

-- --------------------------------------------------------

--
-- Table structure for table `tbltasks`
--

CREATE TABLE `tbltasks` (
  `id` bigint(20) NOT NULL COMMENT 'Task ID - Primary key',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Task Title',
  `description` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Task Description',
  `deadline` datetime DEFAULT NULL COMMENT 'Task Deadline date',
  `completed` enum('Y','N') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'N' COMMENT 'Task Completition Status',
  `userid` bigint(20) NOT NULL COMMENT 'User ID of owner of task'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tasks table';

--
-- Dumping data for table `tbltasks`
--

INSERT INTO `tbltasks` (`id`, `title`, `description`, `deadline`, `completed`, `userid`) VALUES
(13, 'title 1', 'desc 1', '2020-05-27 22:00:17', 'N', 1);

-- --------------------------------------------------------

--
-- Table structure for table `tblusers`
--

CREATE TABLE `tblusers` (
  `id` bigint(20) NOT NULL COMMENT 'User id',
  `fullname` varchar(255) NOT NULL COMMENT 'Users Full Name',
  `username` varchar(255) NOT NULL COMMENT 'Users Username',
  `password` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL COMMENT 'Users Password',
  `useractive` enum('Y','N') NOT NULL DEFAULT 'Y' COMMENT 'Is User Active',
  `loginattempts` int(1) NOT NULL DEFAULT 0 COMMENT 'Attempts to login'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Users table';

--
-- Dumping data for table `tblusers`
--

INSERT INTO `tblusers` (`id`, `fullname`, `username`, `password`, `useractive`, `loginattempts`) VALUES
(1, 'Jane Tasevski', 'jane', '$2y$10$YyT7w.3FMMmgHCK4F.SaSe4s2.B2r.coIBAak5jbjV8OlX5ildF1G', 'Y', 0),
(3, 'Jon Doe', 'jon', '$2y$10$axysHNosdzVLKPZVPVuJpeAYPC8fHwjjH/ZCkZCSGsyjReh/a5Yd2', 'Y', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tblsessions`
--
ALTER TABLE `tblsessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `accesstoken` (`accesstoken`),
  ADD UNIQUE KEY `refreshtoken` (`refreshtoken`),
  ADD KEY `sessionuserid_fk` (`userid`);

--
-- Indexes for table `tbltasks`
--
ALTER TABLE `tbltasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `taskuserid_fk` (`userid`);

--
-- Indexes for table `tblusers`
--
ALTER TABLE `tblusers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tblsessions`
--
ALTER TABLE `tblsessions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'Session ID', AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tbltasks`
--
ALTER TABLE `tbltasks`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'Task ID - Primary key', AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `tblusers`
--
ALTER TABLE `tblusers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'User id', AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tblsessions`
--
ALTER TABLE `tblsessions`
  ADD CONSTRAINT `sessionuserid_fk` FOREIGN KEY (`userid`) REFERENCES `tblusers` (`id`);

--
-- Constraints for table `tbltasks`
--
ALTER TABLE `tbltasks`
  ADD CONSTRAINT `taskuserid_fk` FOREIGN KEY (`userid`) REFERENCES `tblusers` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

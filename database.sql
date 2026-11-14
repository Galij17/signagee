CREATE DATABASE IF NOT EXISTS `digital_signage` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `digital_signage`;

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `spesialis` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nama_spesialis` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dokter` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `spesialis_id` INT NOT NULL,
  `nama_dokter` VARCHAR(100) NOT NULL,
  FOREIGN KEY (`spesialis_id`) REFERENCES `spesialis`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `jadwal_praktek` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dokter_id` INT NOT NULL,
  `hari` VARCHAR(20) NOT NULL DEFAULT 'Senin',
  `status` ENUM('praktek', 'mundur', 'cuti', 'diganti') NOT NULL DEFAULT 'praktek',
  `jam_praktek` VARCHAR(50) NULL,
  `jam_mundur` VARCHAR(50) NULL,
  `cuti_sampai` DATE NULL,
  `dokter_pengganti` VARCHAR(100) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`dokter_id`) REFERENCES `dokter`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_jadwal_hari` (`dokter_id`, `hari`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `video_promosi` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `judul` VARCHAR(150) NOT NULL,
  `url_video` TEXT NOT NULL,
  `putar_per_sesi` INT NOT NULL DEFAULT 1,
  `tipe` ENUM('video', 'image') NOT NULL DEFAULT 'video',
  `durasi` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pengaturan` (
  `kunci` VARCHAR(100) PRIMARY KEY,
  `nilai` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `pengaturan` (`kunci`, `nilai`) VALUES
('nama_rs', 'RSUI BANYUBENING'),
('nama_size', '35'),
('logo_path', ''),
('logo_size', '70'),
('kecepatan_jadwal', '45'),
('lat', '-7.5360639'),
('lng', '110.5927848'),
('metode_sholat', '11');

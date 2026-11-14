<?php
/**
 * DATABASE AUTO-MIGRATION SCRIPT
 * Jalankan via browser (hanya dari localhost) atau CLI: php migrate.php
 */

if (php_sapi_name() !== 'cli' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
    http_response_code(403);
    die('Akses migrasi hanya diizinkan dari localhost atau CLI.');
}

require_once 'config.php';

try {
    echo php_sapi_name() === 'cli' ? "--- Memulai Migrasi Database ---\n" : "<h3>--- Memulai Migrasi Database ---</h3>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `spesialis` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nama_spesialis` VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `dokter` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `spesialis_id` INT NOT NULL,
        `nama_dokter` VARCHAR(100) NOT NULL,
        FOREIGN KEY (`spesialis_id`) REFERENCES `spesialis`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `jadwal_praktek` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Pastikan kolom hari dan unique key ada pada database versi lama
    $cols = $pdo->query("SHOW COLUMNS FROM `jadwal_praktek`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('hari', $cols, true)) {
        $pdo->exec("ALTER TABLE `jadwal_praktek` ADD COLUMN `hari` VARCHAR(20) NOT NULL DEFAULT 'Senin' AFTER `dokter_id`");
    }

    // Bersihkan duplikat (dokter_id, hari) sebelum memasang unique key
    $pdo->exec("DELETE jp1 FROM `jadwal_praktek` jp1
        INNER JOIN `jadwal_praktek` jp2
        ON jp1.dokter_id = jp2.dokter_id AND jp1.hari = jp2.hari AND jp1.id < jp2.id");

    $indexes = $pdo->query("SHOW INDEX FROM `jadwal_praktek` WHERE Key_name = 'unique_jadwal_hari'")->fetchAll();
    if (empty($indexes)) {
        $pdo->exec("ALTER TABLE `jadwal_praktek` ADD UNIQUE KEY `unique_jadwal_hari` (`dokter_id`, `hari`)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `video_promosi` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `judul` VARCHAR(150) NOT NULL,
        `url_video` TEXT NOT NULL,
        `putar_per_sesi` INT NOT NULL DEFAULT 1,
        `tipe` ENUM('video', 'image') NOT NULL DEFAULT 'video',
        `durasi` INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $vcols = $pdo->query("SHOW COLUMNS FROM `video_promosi`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tipe', $vcols, true)) {
        $pdo->exec("ALTER TABLE `video_promosi` ADD COLUMN `tipe` ENUM('video', 'image') NOT NULL DEFAULT 'video' AFTER `putar_per_sesi`");
    }
    if (!in_array('durasi', $vcols, true)) {
        $pdo->exec("ALTER TABLE `video_promosi` ADD COLUMN `durasi` INT NOT NULL DEFAULT 0 AFTER `tipe`");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `pengaturan` (
        `kunci` VARCHAR(100) PRIMARY KEY,
        `nilai` TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO `pengaturan` (`kunci`, `nilai`) VALUES
        ('nama_rs', 'RSUI BANYUBENING'),
        ('nama_size', '35'),
        ('logo_path', ''),
        ('logo_size', '70'),
        ('kecepatan_jadwal', '45'),
        ('lat', '-7.5360639'),
        ('lng', '110.5927848'),
        ('metode_sholat', '11')");

    // Pastikan user admin default ada di admin_users
    $check = $pdo->prepare("SELECT id FROM `admin_users` WHERE username = ?");
    $check->execute(['admin']);
    if (!$check->fetch()) {
        $hashed = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO `admin_users` (username, password) VALUES (?, ?)")->execute(['admin', $hashed]);
    }

    $msg = "MIGRASI SELESAI! Semua tabel dan indeks sinkron.";
    echo php_sapi_name() === 'cli' ? "$msg\n" : "<p style='color:green;font-weight:bold;'>$msg</p><p><a href='login.php'>Ke Halaman Login</a></p>";
} catch (PDOException $e) {
    $err = "Gagal Migrasi: " . $e->getMessage();
    echo php_sapi_name() === 'cli' ? "$err\n" : "<p style='color:red;'>" . htmlspecialchars($err) . "</p>";
    exit(1);
}

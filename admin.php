<?php
session_start();
$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || isset($_POST['is_ajax'])
    || isset($_GET['is_ajax']);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    if ($is_ajax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sesi login berakhir. Silakan muat ulang dan login kembali.']);
        exit;
    }
    header("Location: login.php");
    exit;
}

require_once 'config.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Auto-migration aman untuk memastikan struktur tabel sinkron
try {
    $pdo->query("SELECT kunci FROM pengaturan LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("DROP TABLE IF EXISTS pengaturan");
    $pdo->exec("CREATE TABLE `pengaturan` (`kunci` VARCHAR(100) PRIMARY KEY, `nilai` TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
$pdo->exec("INSERT IGNORE INTO `pengaturan` (`kunci`, `nilai`) VALUES
    ('kecepatan_jadwal', '45'),
    ('nama_rs', 'RSUI BANYUBENING'),
    ('logo_path', ''),
    ('logo_size', '70'),
    ('nama_size', '35'),
    ('lat', '-7.5360639'),
    ('lng', '110.5927848'),
    ('metode_sholat', '11')");

try {
    $cols = $pdo->query("SHOW COLUMNS FROM jadwal_praktek")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('hari', $cols, true)) {
        $pdo->exec("ALTER TABLE jadwal_praktek ADD COLUMN hari VARCHAR(20) NOT NULL DEFAULT 'Senin' AFTER dokter_id");
    }
    $idx = $pdo->query("SHOW INDEX FROM jadwal_praktek WHERE Key_name = 'unique_jadwal_hari'")->fetchAll();
    if (empty($idx)) {
        $pdo->exec("DELETE jp1 FROM jadwal_praktek jp1 INNER JOIN jadwal_praktek jp2 ON jp1.dokter_id = jp2.dokter_id AND jp1.hari = jp2.hari AND jp1.id < jp2.id");
        $pdo->exec("ALTER TABLE jadwal_praktek ADD UNIQUE KEY unique_jadwal_hari (dokter_id, hari)");
    }
} catch (PDOException $e) {}

$hari_list = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$hari_map = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$hari_ini = $hari_map[(int)date('N')];

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

function getUploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Ukuran file melebihi batas upload server (cek upload_max_filesize di php.ini).';
        case UPLOAD_ERR_PARTIAL:
            return 'File hanya terunggah sebagian. Silakan coba lagi.';
        case UPLOAD_ERR_NO_FILE:
            return 'Belum ada file yang dipilih.';
        default:
            return 'Terjadi kesalahan saat mengunggah file (kode: ' . $code . ').';
    }
}

// Handle POST & AJAX mutations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => false, 'message' => 'Aksi tidak dikenal.'];

    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Ukuran file melebihi batas post_max_size server PHP (' . ini_get('post_max_size') . '). Naikkan upload_max_filesize dan post_max_size di php.ini.'
        ]);
        exit;
    }

    if (!verifyCsrf()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid. Muat ulang halaman dan coba lagi.']);
        exit;
    }

    try {
        // 1. MASTER POLI
        if (isset($_POST['action_add_spesialis'])) {
            $nama = trim($_POST['nama_spesialis'] ?? '');
            if ($nama === '') {
                $response = ['success' => false, 'message' => 'Nama Poli tidak boleh kosong.'];
            } else {
                $pdo->prepare("INSERT INTO spesialis (nama_spesialis) VALUES (?)")->execute([$nama]);
                $response = ['success' => true, 'message' => 'Poliklinik baru berhasil ditambahkan.'];
            }
        }
        elseif (isset($_POST['action_edit_spesialis'])) {
            $id = (int)($_POST['id'] ?? 0);
            $nama = trim($_POST['nama_spesialis'] ?? '');
            if ($id <= 0 || $nama === '') {
                $response = ['success' => false, 'message' => 'Data Poli tidak valid.'];
            } else {
                $pdo->prepare("UPDATE spesialis SET nama_spesialis = ? WHERE id = ?")->execute([$nama, $id]);
                $response = ['success' => true, 'message' => 'Nama Poliklinik berhasil diperbarui.'];
            }
        }
        elseif (isset($_POST['action_del_spesialis'])) {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM spesialis WHERE id = ?")->execute([$id]);
            $response = ['success' => true, 'message' => 'Poliklinik beserta data terkait berhasil dihapus.'];
        }

        // 2. MASTER DOKTER
        elseif (isset($_POST['action_add_dokter'])) {
            $spesialis_id = (int)($_POST['spesialis_id'] ?? 0);
            $nama_dokter = trim($_POST['nama_dokter'] ?? '');
            if ($spesialis_id <= 0 || $nama_dokter === '') {
                $response = ['success' => false, 'message' => 'Pilih Poli dan isi nama dokter terlebih dahulu.'];
            } else {
                $pdo->prepare("INSERT INTO dokter (spesialis_id, nama_dokter) VALUES (?, ?)")->execute([$spesialis_id, $nama_dokter]);
                $response = ['success' => true, 'message' => 'Data dokter berhasil ditambahkan.'];
            }
        }
        elseif (isset($_POST['action_edit_dokter'])) {
            $id = (int)($_POST['id'] ?? 0);
            $spesialis_id = (int)($_POST['spesialis_id'] ?? 0);
            $nama_dokter = trim($_POST['nama_dokter'] ?? '');
            if ($id <= 0 || $spesialis_id <= 0 || $nama_dokter === '') {
                $response = ['success' => false, 'message' => 'Data dokter tidak valid.'];
            } else {
                $pdo->prepare("UPDATE dokter SET spesialis_id = ?, nama_dokter = ? WHERE id = ?")->execute([$spesialis_id, $nama_dokter, $id]);
                $response = ['success' => true, 'message' => 'Data dokter berhasil diperbarui.'];
            }
        }
        elseif (isset($_POST['action_del_dokter'])) {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM dokter WHERE id = ?")->execute([$id]);
            $response = ['success' => true, 'message' => 'Data dokter berhasil dihapus.'];
        }

        // 3. JADWAL PRAKTEK
        elseif (isset($_POST['action_add_jadwal'])) {
            $d_id = (int)($_POST['dokter_id'] ?? 0);
            $hari = $_POST['hari'] ?? '';
            $st   = $_POST['status'] ?? 'praktek';

            if ($d_id <= 0 || !in_array($hari, $hari_list, true) || !in_array($st, ['praktek', 'mundur', 'cuti', 'diganti'], true)) {
                $response = ['success' => false, 'message' => 'Pilih poli, dokter, hari, dan status dengan benar.'];
            } else {
                $jp = null; $jm = null; $cs = null; $dp = null;
                $valid = true;

                if ($st === 'praktek') {
                    $mulai = trim($_POST['jam_mulai'] ?? '');
                    $selesai = trim($_POST['jam_selesai'] ?? '');
                    if ($mulai === '' || $selesai === '') {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Isi Jam Mulai dan Jam Selesai praktek.'];
                    } else {
                        $jp = $mulai . ' - ' . $selesai;
                    }
                } elseif ($st === 'mundur') {
                    $jm = trim($_POST['jam_mundur'] ?? '');
                    if ($jm === '') {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Isi jam mundur praktek.'];
                    }
                } elseif ($st === 'cuti') {
                    $cs = trim($_POST['cuti_sampai'] ?? '');
                    if ($cs === '') {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Pilih tanggal akhir cuti dokter.'];
                    }
                } elseif ($st === 'diganti') {
                    if (($_POST['tipe_pengganti'] ?? 'internal') === 'internal') {
                        $pid = (int)($_POST['dokter_pengganti_internal_id'] ?? 0);
                        if ($pid > 0) {
                            $stmt_d = $pdo->prepare("SELECT nama_dokter FROM dokter WHERE id = ?");
                            $stmt_d->execute([$pid]);
                            $dp = $stmt_d->fetchColumn() ?: null;
                        }
                    } else {
                        $dp = trim($_POST['dokter_pengganti_manual'] ?? '') ?: null;
                    }
                    if ($dp === null) {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Pilih atau ketik nama dokter pengganti.'];
                    }
                }

                if ($valid) {
                    $stmt = $pdo->prepare("INSERT INTO jadwal_praktek (dokter_id, hari, status, jam_praktek, jam_mundur, cuti_sampai, dokter_pengganti)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        jam_praktek = VALUES(jam_praktek),
                        jam_mundur = VALUES(jam_mundur),
                        cuti_sampai = VALUES(cuti_sampai),
                        dokter_pengganti = VALUES(dokter_pengganti)");
                    $stmt->execute([$d_id, $hari, $st, $jp, $jm, $cs, $dp]);
                    $response = ['success' => true, 'message' => "Jadwal praktek hari $hari berhasil disimpan."];
                }
            }
        }
        elseif (isset($_POST['action_edit_jadwal_modal'])) {
            $id = (int)($_POST['edit_id'] ?? 0);
            $st = $_POST['status'] ?? 'praktek';

            if ($id <= 0 || !in_array($st, ['praktek', 'mundur', 'cuti', 'diganti'], true)) {
                $response = ['success' => false, 'message' => 'Data jadwal tidak valid.'];
            } else {
                $jp = null; $jm = null; $cs = null; $dp = null;
                $valid = true;

                if ($st === 'praktek') {
                    $mulai = trim($_POST['edit_jam_mulai'] ?? '');
                    $selesai = trim($_POST['edit_jam_selesai'] ?? '');
                    if ($mulai === '' || $selesai === '') {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Isi Jam Mulai dan Jam Selesai praktek.'];
                    } else {
                        $jp = $mulai . ' - ' . $selesai;
                    }
                } elseif ($st === 'mundur') {
                    $jm = trim($_POST['edit_jam_mundur'] ?? '');
                    if ($jm === '') {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Isi jam mundur praktek.'];
                    }
                } elseif ($st === 'cuti') {
                    $cs = trim($_POST['edit_cuti_sampai'] ?? '');
                    if ($cs === '') {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Pilih tanggal akhir cuti.'];
                    }
                } elseif ($st === 'diganti') {
                    if (($_POST['tipe_pengganti_modal'] ?? 'internal') === 'internal') {
                        $pid = (int)($_POST['dokter_pengganti_internal_modal'] ?? 0);
                        if ($pid > 0) {
                            $stmt_d = $pdo->prepare("SELECT nama_dokter FROM dokter WHERE id = ?");
                            $stmt_d->execute([$pid]);
                            $dp = $stmt_d->fetchColumn() ?: null;
                        }
                    } else {
                        $dp = trim($_POST['edit_dokter_pengganti_manual'] ?? '') ?: null;
                    }
                    if ($dp === null) {
                        $valid = false;
                        $response = ['success' => false, 'message' => 'Pilih atau ketik nama dokter pengganti.'];
                    }
                }

                if ($valid) {
                    $stmt = $pdo->prepare("UPDATE jadwal_praktek SET status=?, jam_praktek=?, jam_mundur=?, cuti_sampai=?, dokter_pengganti=? WHERE id=?");
                    $stmt->execute([$st, $jp, $jm, $cs, $dp, $id]);
                    $response = ['success' => true, 'message' => 'Jadwal harian berhasil diperbarui.'];
                }
            }
        }
        elseif (isset($_POST['action_del_jadwal'])) {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM jadwal_praktek WHERE id = ?")->execute([$id]);
            $response = ['success' => true, 'message' => 'Jadwal praktek berhasil dihapus.'];
        }

        // 4. PLAYLIST MEDIA
        elseif (isset($_POST['action_add_video'])) {
            $judul = trim($_POST['judul_video'] ?? '');
            $putar = max(1, (int)($_POST['putar_per_sesi'] ?? 1));
            $durasi = max(1, (int)($_POST['durasi_gambar'] ?? 15));

            if ($judul === '') {
                $response = ['success' => false, 'message' => 'Judul media wajib diisi.'];
            } elseif (!isset($_FILES['file_media']) || $_FILES['file_media']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['file_media']['error'] ?? UPLOAD_ERR_NO_FILE;
                $response = ['success' => false, 'message' => getUploadErrorMessage((int)$errCode)];
            } else {
                $origName = basename($_FILES['file_media']['name']);
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $videoExts = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];

                if (!in_array($ext, array_merge($imageExts, $videoExts), true)) {
                    $response = ['success' => false, 'message' => 'Format file tidak didukung. Gunakan MP4, WEBM, MOV, JPG, PNG, atau WEBP.'];
                } else {
                    $upload_dir = 'uploads/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                    $safeName = time() . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace("/[^a-zA-Z0-9._-]/", "_", $origName);
                    $target_file = $upload_dir . $safeName;
                    $tipe_media = in_array($ext, $imageExts, true) ? 'image' : 'video';

                    if (move_uploaded_file($_FILES['file_media']['tmp_name'], $target_file)) {
                        $pdo->prepare("INSERT INTO video_promosi (judul, url_video, putar_per_sesi, tipe, durasi) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$judul, $target_file, $putar, $tipe_media, $tipe_media === 'image' ? $durasi : 0]);
                        $response = ['success' => true, 'message' => 'Media berhasil diunggah ke Playlist.'];
                    } else {
                        $response = ['success' => false, 'message' => 'Gagal menyimpan file ke folder uploads/.'];
                    }
                }
            }
        }
        elseif (isset($_POST['action_edit_media'])) {
            $id = (int)($_POST['media_id'] ?? 0);
            $judul = trim($_POST['edit_judul'] ?? '');
            $putar = max(1, (int)($_POST['edit_putar'] ?? 1));
            $durasi = max(1, (int)($_POST['edit_durasi'] ?? 15));

            if ($id <= 0 || $judul === '') {
                $response = ['success' => false, 'message' => 'Judul media tidak boleh kosong.'];
            } else {
                $pdo->prepare("UPDATE video_promosi SET judul=?, putar_per_sesi=?, durasi=? WHERE id=?")->execute([$judul, $putar, $durasi, $id]);
                $response = ['success' => true, 'message' => 'Pengaturan media berhasil diperbarui.'];
            }
        }
        elseif (isset($_POST['action_move_media'])) {
            $id = (int)($_POST['id'] ?? 0);
            $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
            $stmt = $pdo->prepare("SELECT * FROM video_promosi WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch();
            if ($current) {
                $op = $direction === 'up' ? '<' : '>';
                $ord = $direction === 'up' ? 'DESC' : 'ASC';
                $stmt = $pdo->prepare("SELECT * FROM video_promosi WHERE id $op ? ORDER BY id $ord LIMIT 1");
                $stmt->execute([$id]);
                $neighbor = $stmt->fetch();
                if ($neighbor) {
                    $pdo->beginTransaction();
                    $upd = $pdo->prepare("UPDATE video_promosi SET judul=?, url_video=?, putar_per_sesi=?, tipe=?, durasi=? WHERE id=?");
                    $upd->execute([$neighbor['judul'], $neighbor['url_video'], $neighbor['putar_per_sesi'], $neighbor['tipe'], $neighbor['durasi'], $current['id']]);
                    $upd->execute([$current['judul'], $current['url_video'], $current['putar_per_sesi'], $current['tipe'], $current['durasi'], $neighbor['id']]);
                    $pdo->commit();
                }
            }
            $response = ['success' => true, 'message' => 'Urutan playlist diperbarui.'];
        }
        elseif (isset($_POST['action_del_video'])) {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT url_video FROM video_promosi WHERE id = ?");
            $stmt->execute([$id]);
            $v = $stmt->fetch();
            if ($v && !empty($v['url_video']) && str_starts_with($v['url_video'], 'uploads/') && file_exists($v['url_video'])) {
                @unlink($v['url_video']);
            }
            $pdo->prepare("DELETE FROM video_promosi WHERE id = ?")->execute([$id]);
            $response = ['success' => true, 'message' => 'Media berhasil dihapus dari Playlist.'];
        }

        // 5. PENGATURAN GLOBAL
        elseif (isset($_POST['action_save_pengaturan'])) {
            $oldLogo = trim($_POST['old_logo'] ?? '');
            $removeLogo = ($_POST['remove_logo'] ?? '0') === '1';

            $set_data = [
                'nama_rs'          => trim($_POST['nama_rs'] ?? 'RSUI BANYUBENING') ?: 'RSUI BANYUBENING',
                'logo_size'        => max(24, min(200, (int)($_POST['logo_size'] ?? 70))),
                'nama_size'        => max(18, min(72, (int)($_POST['nama_size'] ?? 35))),
                'lat'              => trim($_POST['lat'] ?? '-7.5360639'),
                'lng'              => trim($_POST['lng'] ?? '110.5927848'),
                'metode_sholat'    => trim($_POST['metode_sholat'] ?? '11'),
                'kecepatan_jadwal' => max(10, min(300, (int)($_POST['kecepatan_jadwal'] ?? 45))),
                'logo_path'        => $removeLogo ? '' : $oldLogo
            ];

            if ($removeLogo && $oldLogo !== '' && str_starts_with($oldLogo, 'uploads/') && file_exists($oldLogo)) {
                @unlink($oldLogo);
            }

            $uploadValid = true;
            if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                    $uploadValid = false;
                    $response = ['success' => false, 'message' => getUploadErrorMessage((int)$_FILES['logo_file']['error'])];
                } else {
                    $origLogo = basename($_FILES['logo_file']['name']);
                    $ext = strtolower(pathinfo($origLogo, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                        $uploadValid = false;
                        $response = ['success' => false, 'message' => 'Format logo harus PNG, JPG, WEBP, atau GIF.'];
                    } else {
                        $upload_dir = 'uploads/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $target_file = $upload_dir . 'logo_' . time() . '_' . preg_replace("/[^a-zA-Z0-9._-]/", "_", $origLogo);
                        if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $target_file)) {
                            if ($oldLogo !== '' && $oldLogo !== $target_file && str_starts_with($oldLogo, 'uploads/') && file_exists($oldLogo)) {
                                @unlink($oldLogo);
                            }
                            $set_data['logo_path'] = $target_file;
                        }
                    }
                }
            }

            if ($uploadValid) {
                $stmtSett = $pdo->prepare("INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai = ?");
                foreach ($set_data as $k => $v) {
                    $stmtSett->execute([$k, (string)$v, (string)$v]);
                }
                $response = [
                    'success'   => true,
                    'message'   => 'Pengaturan global berhasil disimpan.',
                    'logo_path' => $set_data['logo_path']
                ];
            }
        }

        // 6. GANTI PASSWORD
        elseif (isset($_POST['action_change_password'])) {
            $old_pass = $_POST['old_password'] ?? '';
            $new_pass = $_POST['new_password'] ?? '';
            $confirm_pass = $_POST['confirm_password'] ?? '';
            $admin_id = (int)($_SESSION['admin_id'] ?? 0);

            if (strlen($new_pass) < 6) {
                $response = ['success' => false, 'message' => 'Password baru minimal 6 karakter.'];
            } elseif ($new_pass !== $confirm_pass) {
                $response = ['success' => false, 'message' => 'Konfirmasi password baru tidak cocok.'];
            } else {
                $stmt = $pdo->prepare("SELECT password FROM admin_users WHERE id = ?");
                $stmt->execute([$admin_id]);
                $hash = $stmt->fetchColumn();

                if ($hash && password_verify($old_pass, $hash)) {
                    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE admin_users SET password = ? WHERE id = ?")->execute([$new_hash, $admin_id]);
                    $response = ['success' => true, 'message' => 'Password admin berhasil diperbarui.'];
                } else {
                    $response = ['success' => false, 'message' => 'Password lama tidak sesuai.'];
                }
            }
        }
    } catch (\Exception $e) {
        $response = ['success' => false, 'message' => 'Kesalahan server: ' . $e->getMessage()];
    }

    if ($is_ajax) {
        echo json_encode($response);
        exit;
    }
    header("Location: admin.php");
    exit;
}

// Ambil data untuk render halaman
$active_hari = (isset($_GET['hari']) && in_array($_GET['hari'], $hari_list, true)) ? $_GET['hari'] : $hari_ini;
$active_tab = (isset($_GET['tab']) && in_array($_GET['tab'], ['tab_jadwal', 'tab_master', 'tab_media', 'tab_pengaturan'], true))
    ? $_GET['tab']
    : 'tab_jadwal';

$list_spesialis = $pdo->query("
    SELECT s.*, COUNT(d.id) AS total_dokter
    FROM spesialis s
    LEFT JOIN dokter d ON d.spesialis_id = s.id
    GROUP BY s.id
    ORDER BY s.nama_spesialis ASC
")->fetchAll();

$list_dokter = $pdo->query("
    SELECT d.*, s.nama_spesialis
    FROM dokter d
    JOIN spesialis s ON d.spesialis_id = s.id
    ORDER BY s.nama_spesialis ASC, d.nama_dokter ASC
")->fetchAll();

$list_video = $pdo->query("SELECT * FROM video_promosi ORDER BY id ASC")->fetchAll();

$sett_res = $pdo->query("SELECT kunci, nilai FROM pengaturan")->fetchAll();
$pengaturan = [];
foreach ($sett_res as $r) {
    $pengaturan[$r['kunci']] = $r['nilai'];
}

$semua_jadwal = [];
$stmt_all = $pdo->query("
    SELECT jp.*, d.nama_dokter, s.nama_spesialis, d.spesialis_id
    FROM jadwal_praktek jp
    JOIN dokter d ON jp.dokter_id = d.id
    JOIN spesialis s ON d.spesialis_id = s.id
    ORDER BY s.nama_spesialis ASC, d.nama_dokter ASC
");
foreach ($stmt_all->fetchAll() as $row) {
    $semua_jadwal[$row['hari']][] = $row;
}

$nama_rs_panel = $pengaturan['nama_rs'] ?? 'RSUI BANYUBENING';
$logo_rs_panel = $pengaturan['logo_path'] ?? '';
$count_today_jadwal = count($semua_jadwal[$hari_ini] ?? []);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Admin Signage — <?= htmlspecialchars($nama_rs_panel) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: #f5f1ec;
            color: #1c283d;
            margin: 0;
        }
        button, a[href], select, input[type="file"], input[type="radio"], input[type="checkbox"], label[for] {
            cursor: pointer;
        }
        button:disabled {
            cursor: not-allowed;
            opacity: 0.55;
        }
        :focus-visible {
            outline: 2px solid #473d35;
            outline-offset: 2px;
        }
        .tabular-nums { font-variant-numeric: tabular-nums; }

        .sidebar-shell {
            background-color: #1c283d;
            color: #f8fafc;
            border-right: 1px solid rgba(140, 123, 109, 0.28);
        }
        .panel-card {
            background-color: #ffffff;
            border: 1px solid #e4dbd2;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(28, 40, 61, 0.05);
        }
        .input-field {
            width: 100%;
            background-color: #ffffff;
            border: 1px solid #d6cbc1;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: #1c283d;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .input-field:focus {
            outline: none;
            border-color: #473d35;
            box-shadow: 0 0 0 3px rgba(71, 61, 53, 0.14);
        }
        .input-field::placeholder { color: #8c7b6d; }

        .select-field {
            width: 100%;
            background-color: #ffffff;
            border: 1px solid #d6cbc1;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #1c283d;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .select-field:focus {
            outline: none;
            border-color: #473d35;
            box-shadow: 0 0 0 3px rgba(71, 61, 53, 0.14);
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-height: 44px;
            background-color: #1c283d;
            color: #ffffff;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 700;
            border: 1px solid #1c283d;
            transition: background-color 0.15s ease;
            white-space: nowrap;
        }
        .btn-primary:hover { background-color: #2a3a57; border-color: #2a3a57; }
        .btn-primary:active { background-color: #141d2e; }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-height: 40px;
            background-color: #ffffff;
            color: #473d35;
            padding: 0.5rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.8125rem;
            font-weight: 700;
            border: 1px solid #d6cbc1;
            transition: background-color 0.15s ease, border-color 0.15s ease;
            white-space: nowrap;
        }
        .btn-secondary:hover {
            background-color: #faf7f4;
            border-color: #8c7b6d;
        }

        .badge-praktek { background-color: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
        .badge-mundur  { background-color: #fffbeb; color: #92400e; border-color: #fde68a; }
        .badge-cuti    { background-color: #fff1f2; color: #9f1239; border-color: #fecdd3; }
        .badge-diganti { background-color: #eef2ff; color: #3730a3; border-color: #c7d2fe; }

        #map_sholat { height: 280px; border-radius: 0.75rem; z-index: 1; border: 1px solid #cbd5e1; }
    </style>
</head>
<body class="min-h-screen flex flex-col lg:flex-row">

    <!-- Toast Container -->
    <div id="toast-container" aria-live="polite" class="fixed top-5 right-5 z-[70] flex flex-col gap-2.5 max-w-sm w-full pointer-events-none"></div>

    <!-- SIDEBAR DESKTOP -->
    <aside class="sidebar-shell hidden lg:flex lg:w-64 lg:flex-col lg:fixed lg:inset-y-0 lg:left-0 z-30">
        <div class="p-6 border-b border-[#8c7b6d]/25 flex items-center gap-3">
            <?php if ($logo_rs_panel !== '' && file_exists($logo_rs_panel)): ?>
                <img id="sidebar_logo_img" src="<?= htmlspecialchars($logo_rs_panel) ?>" alt="Logo <?= htmlspecialchars($nama_rs_panel) ?>" width="42" height="42" class="w-10 h-10 object-contain rounded-xl bg-white p-1 shrink-0">
            <?php else: ?>
                <div id="sidebar_logo_fallback" class="w-10 h-10 rounded-xl bg-[#473d35] border border-[#c7ab97]/50 text-[#c7ab97] flex items-center justify-center shrink-0" aria-hidden="true">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <p class="text-[11px] font-bold uppercase tracking-widest text-[#c7ab97]">Digital Signage</p>
                <p id="header_nama_rs" class="text-sm font-extrabold text-white truncate"><?= htmlspecialchars($nama_rs_panel) ?></p>
            </div>
        </div>

        <nav class="flex-1 p-4 space-y-1.5" role="tablist" aria-label="Menu navigasi panel admin">
            <?php
            $navItems = [
                'tab_jadwal'     => ['label' => 'Jadwal Harian',    'badge_id' => 'nav_badge_jadwal', 'badge' => $count_today_jadwal],
                'tab_master'     => ['label' => 'Poli & Dokter',    'badge_id' => 'nav_badge_dokter', 'badge' => count($list_dokter)],
                'tab_media'      => ['label' => 'Playlist Media',   'badge_id' => 'nav_badge_media',  'badge' => count($list_video)],
                'tab_pengaturan' => ['label' => 'Pengaturan & Akun','badge_id' => '',                 'badge' => null],
            ];
            foreach ($navItems as $tId => $item):
                $isActive = ($active_tab === $tId);
            ?>
                <button
                    type="button"
                    role="tab"
                    id="btn_<?= $tId ?>"
                    aria-selected="<?= $isActive ? 'true' : 'false' ?>"
                    aria-controls="<?= $tId ?>"
                    onclick="switchMainTab('<?= $tId ?>')"
                    class="main-tab-btn w-full min-h-[44px] px-3.5 py-2.5 rounded-xl text-sm font-bold flex items-center justify-between gap-2 transition-colors <?= $isActive ? 'bg-[#473d35] text-white border border-[#c7ab97]/50 shadow-sm' : 'text-[#e4dbd2] hover:bg-[#473d35]/55 hover:text-white border border-transparent' ?>"
                >
                    <span><?= $item['label'] ?></span>
                    <?php if ($item['badge'] !== null): ?>
                        <span id="<?= $item['badge_id'] ?>" class="tabular-nums text-xs px-2 py-0.5 rounded-md bg-[#141d2e] text-[#c7ab97]">
                            <?= (int)$item['badge'] ?>
                        </span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="p-4 border-t border-[#8c7b6d]/25 space-y-2.5">
            <a href="display.php" target="_blank" rel="noopener" class="w-full min-h-[42px] px-3.5 py-2 rounded-xl bg-[#473d35]/75 hover:bg-[#473d35] text-[#f5efe9] text-xs font-bold flex items-center justify-center gap-2 transition-colors border border-[#8c7b6d]/50">
                <svg class="w-4 h-4 shrink-0 text-[#c7ab97]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                </svg>
                <span>Buka Layar TV</span>
            </a>
            <div class="flex items-center justify-between px-1 pt-1 text-xs text-[#c7ab97]">
                <span class="truncate">Akun: <strong class="text-white"><?= htmlspecialchars($_SESSION['admin_username']) ?></strong></span>
                <a href="logout.php" onclick="return confirm('Keluar dari Panel Admin?')" class="font-bold text-rose-300 hover:text-rose-200 underline underline-offset-2">
                    Keluar
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT SHELL -->
    <div class="flex-1 lg:pl-64 flex flex-col min-w-0">

        <!-- HEADER MOBILE & WORKSPACE BAR -->
        <header class="bg-white border-b border-[#e4dbd2] sticky top-0 z-20">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 id="workspace_title" class="text-lg sm:text-xl font-extrabold text-[#1c283d] leading-tight">
                        <?php
                        $titles = [
                            'tab_jadwal'     => 'Jadwal Praktek Harian',
                            'tab_master'     => 'Master Poliklinik & Dokter',
                            'tab_media'      => 'Manajemen Playlist Media',
                            'tab_pengaturan' => 'Pengaturan Global & Akun'
                        ];
                        echo $titles[$active_tab];
                        ?>
                    </h1>
                    <p class="text-xs text-[#6b5d52] mt-0.5">
                        Hari ini: <strong class="text-[#473d35]"><?= htmlspecialchars($hari_ini) ?></strong>
                        &middot; Sinkronisasi layar TV otomatis setiap 3 detik
                    </p>
                </div>

                <!-- Tombol Aksi Mobile -->
                <div class="flex lg:hidden items-center gap-2">
                    <a href="display.php" target="_blank" rel="noopener" class="btn-secondary text-xs">Layar TV</a>
                    <a href="logout.php" onclick="return confirm('Keluar dari Panel Admin?')" class="btn-secondary text-xs text-rose-700 border-rose-200">Keluar</a>
                </div>
            </div>

            <!-- Mobile Tab Bar -->
            <nav class="lg:hidden px-4 pb-3 flex gap-1.5 overflow-x-auto" aria-label="Menu panel admin mobile">
                <?php foreach ($navItems as $tId => $item):
                    $isActive = ($active_tab === $tId);
                ?>
                    <button
                        type="button"
                        data-mobile-tab="<?= $tId ?>"
                        onclick="switchMainTab('<?= $tId ?>')"
                        class="mobile-tab-btn min-h-[40px] px-3.5 py-2 rounded-xl text-xs font-bold whitespace-nowrap border transition-colors <?= $isActive ? 'bg-[#1c283d] text-white border-[#1c283d]' : 'bg-[#faf7f4] text-[#473d35] border-[#e4dbd2]' ?>"
                    >
                        <?= $item['label'] ?>
                    </button>
                <?php endforeach; ?>
            </nav>
        </header>

        <main class="flex-1 max-w-6xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8 space-y-6">

            <!-- ==================== TAB 1: JADWAL HARIAN ==================== -->
            <section id="tab_jadwal" role="tabpanel" aria-labelledby="btn_tab_jadwal" class="main-tab-content <?= $active_tab === 'tab_jadwal' ? '' : 'hidden' ?> space-y-6">

                <!-- Ringkasan Data Asli Database -->
                <div id="kpi_summary_row" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="panel-card p-5 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-[#6b5d52]">Jadwal Hari <?= htmlspecialchars($hari_ini) ?></p>
                            <p class="text-2xl font-extrabold text-[#1c283d] tabular-nums mt-1"><?= $count_today_jadwal ?> <span class="text-sm font-semibold text-[#8c7b6d]">dokter</span></p>
                        </div>
                        <span class="rounded-xl bg-[#f5efe9] border border-[#c7ab97] px-3 py-1.5 text-xs font-bold text-[#473d35]">Tayang di TV</span>
                    </div>
                    <div class="panel-card p-5 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-[#6b5d52]">Total Master Dokter</p>
                            <p class="text-2xl font-extrabold text-[#1c283d] tabular-nums mt-1"><?= count($list_dokter) ?> <span class="text-sm font-semibold text-[#8c7b6d]">/ <?= count($list_spesialis) ?> poli</span></p>
                        </div>
                        <button type="button" onclick="switchMainTab('tab_master')" class="text-xs font-bold text-[#473d35] hover:text-[#1c283d] hover:underline">Kelola</button>
                    </div>
                    <div class="panel-card p-5 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-[#6b5d52]">Playlist Media Aktif</p>
                            <p class="text-2xl font-extrabold text-[#1c283d] tabular-nums mt-1"><?= count($list_video) ?> <span class="text-sm font-semibold text-[#8c7b6d]">item</span></p>
                        </div>
                        <button type="button" onclick="switchMainTab('tab_media')" class="text-xs font-bold text-[#473d35] hover:text-[#1c283d] hover:underline">Kelola</button>
                    </div>
                </div>

                <!-- Filter Hari & Tabel Jadwal -->
                <div class="panel-card overflow-hidden">
                    <div class="p-5 sm:p-6 border-b border-[#e4dbd2] flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
                        <div>
                            <h2 class="text-base font-extrabold text-[#1c283d]">Daftar Jadwal Per Hari</h2>
                            <p class="text-xs text-[#6b5d52] mt-0.5">Klik tab hari untuk meninjau atau mengubah status praktek dokter.</p>
                        </div>
                        <div id="hari_tabs_bar" class="flex flex-wrap gap-1.5 bg-[#efe8e0] p-1 rounded-xl border border-[#e4dbd2]" role="tablist" aria-label="Pilih hari jadwal">
                            <?php foreach ($hari_list as $h):
                                $cnt = count($semua_jadwal[$h] ?? []);
                                $isDayActive = ($active_hari === $h);
                                $isToday = ($h === $hari_ini);
                            ?>
                                <button
                                    type="button"
                                    role="tab"
                                    id="tab_<?= $h ?>"
                                    aria-selected="<?= $isDayActive ? 'true' : 'false' ?>"
                                    onclick="switchTabHari('<?= $h ?>')"
                                    class="tab-btn min-h-[38px] px-3.5 py-1.5 rounded-lg text-xs font-bold transition-colors flex items-center gap-1.5 <?= $isDayActive ? 'bg-[#1c283d] text-white shadow-sm' : 'text-[#473d35] hover:bg-white' ?>"
                                >
                                    <span><?= $h ?></span>
                                    <?php if ($isToday): ?>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-[#c7ab97] text-[#1c283d] font-extrabold">Hari ini</span>
                                    <?php endif; ?>
                                    <span class="tabular-nums text-[11px] opacity-80">(<?= $cnt ?>)</span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <?php foreach ($hari_list as $h): ?>
                            <div id="panel_<?= $h ?>" class="jadwal-panel <?= ($active_hari === $h) ? '' : 'hidden' ?>">
                                <table class="w-full text-left text-sm border-collapse">
                                    <thead>
                                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-bold uppercase tracking-wider text-slate-600">
                                            <th class="px-6 py-3.5">Poliklinik</th>
                                            <th class="px-6 py-3.5">Nama Dokter</th>
                                            <th class="px-6 py-3.5">Status</th>
                                            <th class="px-6 py-3.5">Jam / Keterangan</th>
                                            <th class="px-6 py-3.5 text-right">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php if (empty($semua_jadwal[$h])): ?>
                                            <tr>
                                                <td colspan="5" class="px-6 py-12 text-center">
                                                    <p class="text-sm font-bold text-slate-700">Belum ada jadwal praktek untuk hari <?= htmlspecialchars($h) ?>.</p>
                                                    <p class="text-xs text-slate-500 mt-1">Gunakan formulir di bawah untuk menambahkan jadwal dokter pada hari <?= htmlspecialchars($h) ?>.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($semua_jadwal[$h] as $j):
                                                $st = $j['status'];
                                                $badgeClass = $st === 'praktek' ? 'badge-praktek' : ($st === 'mundur' ? 'badge-mundur' : ($st === 'cuti' ? 'badge-cuti' : 'badge-diganti'));
                                            ?>
                                                <tr class="hover:bg-slate-50 transition-colors">
                                                    <td class="px-6 py-4 text-xs font-bold text-slate-600 uppercase tracking-wide"><?= htmlspecialchars($j['nama_spesialis']) ?></td>
                                                    <td class="px-6 py-4 font-bold text-slate-900"><?= htmlspecialchars($j['nama_dokter']) ?></td>
                                                    <td class="px-6 py-4">
                                                        <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold uppercase border <?= $badgeClass ?>">
                                                            <?= htmlspecialchars($st) ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 font-semibold text-slate-800 tabular-nums">
                                                        <?php
                                                        if ($st === 'praktek') {
                                                            echo htmlspecialchars($j['jam_praktek'] ?: 'Sesuai jadwal');
                                                        } elseif ($st === 'mundur') {
                                                            echo '<span class="text-amber-800">Mundur ke jam ' . htmlspecialchars($j['jam_mundur'] ?: '-') . '</span>';
                                                        } elseif ($st === 'cuti') {
                                                            $tgl = !empty($j['cuti_sampai']) ? date('d-m-Y', strtotime($j['cuti_sampai'])) : '-';
                                                            echo '<span class="text-rose-800">Cuti s/d ' . htmlspecialchars($tgl) . '</span>';
                                                        } else {
                                                            echo '<span class="text-indigo-800">Digantikan: ' . htmlspecialchars($j['dokter_pengganti'] ?: '-') . '</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-right whitespace-nowrap">
                                                        <div class="inline-flex items-center gap-2">
                                                            <button
                                                                type="button"
                                                                onclick='openEditModal(<?= json_encode($j, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                                class="min-h-[36px] px-3 py-1.5 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold transition-colors"
                                                            >
                                                                Ubah
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onclick="confirmDeleteAction('action_del_jadwal', <?= (int)$j['id'] ?>, 'Hapus jadwal dokter <?= htmlspecialchars(addslashes($j['nama_dokter'])) ?> pada hari <?= htmlspecialchars($h) ?>?')"
                                                                class="min-h-[36px] px-3 py-1.5 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold transition-colors"
                                                            >
                                                                Hapus
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Form Tambah / Perbarui Jadwal -->
                <div class="panel-card p-6 sm:p-8">
                    <div class="pb-4 mb-6 border-b border-slate-200">
                        <h3 class="text-base font-extrabold text-[#1c283d]">
                            Tambah / Perbarui Jadwal Hari <span id="label_form_hari" class="text-[#473d35] underline decoration-[#c7ab97] decoration-2 underline-offset-4"><?= htmlspecialchars($active_hari) ?></span>
                        </h3>
                        <p class="text-xs text-slate-600 mt-0.5">Jika dokter sudah terdaftar di hari tersebut, jadwalnya akan diperbarui otomatis.</p>
                    </div>

                    <form action="" method="POST" class="ajax-form space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action_add_jadwal" value="1">
                        <input type="hidden" name="hari" id="input_form_hari" value="<?= htmlspecialchars($active_hari) ?>">

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="sel_poli" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Poliklinik</label>
                                <select id="sel_poli" class="select-field" required>
                                    <option value="">Pilih Poliklinik...</option>
                                    <?php foreach ($list_spesialis as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nama_spesialis']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="sel_dok" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Dokter</label>
                                <select id="sel_dok" name="dokter_id" class="select-field" required>
                                    <option value="">Pilih Poliklinik dahulu...</option>
                                </select>
                            </div>
                            <div>
                                <label for="sel_stat" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Status Praktek</label>
                                <select id="sel_stat" name="status" class="select-field" required>
                                    <option value="praktek">Praktek Normal</option>
                                    <option value="mundur">Mundur Jam</option>
                                    <option value="cuti">Sedang Cuti</option>
                                    <option value="diganti">Diganti Dokter Lain</option>
                                </select>
                            </div>
                        </div>

                        <div class="rounded-xl bg-slate-50 border border-slate-200 p-5">
                            <div id="box_praktek" class="cond-input-add grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="add_jam_mulai" class="block text-xs font-bold text-slate-700 mb-1.5">Jam Mulai</label>
                                    <input id="add_jam_mulai" type="time" name="jam_mulai" class="input-field tabular-nums">
                                </div>
                                <div>
                                    <label for="add_jam_selesai" class="block text-xs font-bold text-slate-700 mb-1.5">Jam Selesai</label>
                                    <input id="add_jam_selesai" type="time" name="jam_selesai" class="input-field tabular-nums">
                                </div>
                            </div>

                            <div id="box_mundur" class="cond-input-add hidden max-w-xs">
                                <label for="add_jam_mundur" class="block text-xs font-bold text-amber-800 mb-1.5">Mundur Ke Jam</label>
                                <input id="add_jam_mundur" type="time" name="jam_mundur" class="input-field tabular-nums">
                            </div>

                            <div id="box_cuti" class="cond-input-add hidden max-w-xs">
                                <label for="add_cuti_sampai" class="block text-xs font-bold text-rose-800 mb-1.5">Cuti Sampai Tanggal</label>
                                <input id="add_cuti_sampai" type="date" name="cuti_sampai" class="input-field tabular-nums">
                            </div>

                            <div id="box_diganti" class="cond-input-add hidden space-y-4">
                                <div class="flex flex-wrap gap-5 text-sm font-semibold text-slate-700">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="radio" name="tipe_pengganti" value="internal" checked class="w-4 h-4 accent-[#473d35]">
                                        <span>Dokter Se-Poliklinik (Internal)</span>
                                    </label>
                                    <label class="inline-flex items-center gap-2">
                                        <input type="radio" name="tipe_pengganti" value="manual" class="w-4 h-4 accent-[#473d35]">
                                        <span>Input Nama Manual</span>
                                    </label>
                                </div>
                                <div id="wrapper_pengganti_internal">
                                    <label for="sel_dok_pengganti_internal" class="block text-xs font-bold text-slate-700 mb-1.5">Pilih Dokter Pengganti</label>
                                    <select id="sel_dok_pengganti_internal" name="dokter_pengganti_internal_id" class="select-field">
                                        <option value="">Pilih Poliklinik dahulu...</option>
                                    </select>
                                </div>
                                <div id="wrapper_pengganti_manual" class="hidden">
                                    <label for="add_pengganti_manual" class="block text-xs font-bold text-slate-700 mb-1.5">Nama Dokter Pengganti</label>
                                    <input id="add_pengganti_manual" type="text" name="dokter_pengganti_manual" placeholder="Contoh: dr. Budi Santoso, Sp.PD" class="input-field">
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="btn-primary px-6">Simpan Jadwal Dokter</button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- ==================== TAB 2: MASTER POLI & DOKTER ==================== -->
            <section id="tab_master" role="tabpanel" aria-labelledby="btn_tab_master" class="main-tab-content <?= $active_tab === 'tab_master' ? '' : 'hidden' ?>">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    <!-- Master Poli -->
                    <div class="panel-card flex flex-col overflow-hidden">
                        <div class="p-6 border-b border-slate-200 space-y-4">
                            <div>
                                <h2 class="text-base font-extrabold text-slate-900">Master Poliklinik / Spesialis</h2>
                                <p class="text-xs text-slate-600 mt-0.5">Total: <span id="count_poli" class="font-bold tabular-nums"><?= count($list_spesialis) ?></span> poliklinik</p>
                            </div>
                            <form action="" method="POST" class="ajax-form flex gap-2.5">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action_add_spesialis" value="1">
                                <label for="input_nama_poli" class="sr-only">Nama Poliklinik</label>
                                <input id="input_nama_poli" type="text" name="nama_spesialis" placeholder="Contoh: POLI ANAK" class="input-field" required>
                                <button type="submit" class="btn-primary">Tambah</button>
                            </form>
                            <div>
                                <label for="search_poli" class="sr-only">Cari Poliklinik</label>
                                <input id="search_poli" type="search" oninput="filterPoliTable(this.value)" placeholder="Cari nama poliklinik..." class="input-field text-xs">
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto max-h-[460px]">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-slate-50 border-b border-slate-200 text-xs font-bold uppercase tracking-wider text-slate-600 sticky top-0">
                                    <tr>
                                        <th class="px-5 py-3">Nama Poliklinik</th>
                                        <th class="px-5 py-3 text-center">Dokter</th>
                                        <th class="px-5 py-3 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody_poli" class="divide-y divide-slate-100">
                                    <?php if (empty($list_spesialis)): ?>
                                        <tr><td colspan="3" class="px-5 py-10 text-center text-slate-500 text-sm">Belum ada poliklinik.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($list_spesialis as $s): ?>
                                            <tr class="row-poli hover:bg-slate-50 transition-colors" data-name="<?= htmlspecialchars(strtolower($s['nama_spesialis'])) ?>">
                                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= htmlspecialchars($s['nama_spesialis']) ?></td>
                                                <td class="px-5 py-3.5 text-center tabular-nums text-xs font-bold text-slate-600"><?= (int)$s['total_dokter'] ?></td>
                                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-1.5">
                                                        <button type="button" onclick="filterDokter(<?= (int)$s['id'] ?>, '<?= htmlspecialchars(addslashes($s['nama_spesialis'])) ?>')" class="min-h-[34px] px-2.5 py-1 rounded-lg border border-[#c7ab97] bg-[#f5efe9] hover:bg-[#ebe1d6] text-[#1c283d] text-xs font-bold">Filter</button>
                                                        <button type="button" onclick='bukaEditPoli(<?= json_encode($s, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="min-h-[34px] px-2.5 py-1 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold">Ubah</button>
                                                        <button type="button" onclick="confirmDeleteAction('action_del_spesialis', <?= (int)$s['id'] ?>, 'Hapus poliklinik <?= htmlspecialchars(addslashes($s['nama_spesialis'])) ?>? Semua dokter dan jadwal di poli ini ikut terhapus.')" class="min-h-[34px] px-2.5 py-1 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold">Hapus</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Master Dokter -->
                    <div class="panel-card flex flex-col overflow-hidden">
                        <div class="p-6 border-b border-slate-200 space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h2 class="text-base font-extrabold text-slate-900">Master Dokter</h2>
                                    <p class="text-xs text-slate-600 mt-0.5">Total: <span id="count_dokter" class="font-bold tabular-nums"><?= count($list_dokter) ?></span> dokter</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span id="label_filter_dokter" class="hidden rounded-lg bg-[#f5efe9] border border-[#c7ab97] text-[#1c283d] px-2.5 py-1 text-xs font-bold"></span>
                                    <button type="button" id="btn_reset_filter" onclick="filterDokter('all', '')" class="hidden min-h-[32px] px-2.5 py-1 rounded-lg border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold">Tampilkan Semua</button>
                                </div>
                            </div>

                            <form action="" method="POST" class="ajax-form space-y-2.5">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action_add_dokter" value="1">
                                <div>
                                    <label for="sel_poli_tambah" class="sr-only">Pilih Poliklinik</label>
                                    <select name="spesialis_id" id="sel_poli_tambah" class="select-field" required>
                                        <option value="">Pilih Poliklinik...</option>
                                        <?php foreach ($list_spesialis as $s): ?>
                                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nama_spesialis']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="flex gap-2.5">
                                    <label for="input_nama_dokter" class="sr-only">Nama Dokter</label>
                                    <input id="input_nama_dokter" type="text" name="nama_dokter" placeholder="Contoh: dr. Ahmad Fauzi, Sp.A" class="input-field" required>
                                    <button type="submit" class="btn-primary">Tambah</button>
                                </div>
                            </form>

                            <div>
                                <label for="search_dokter" class="sr-only">Cari Dokter</label>
                                <input id="search_dokter" type="search" oninput="applyDokterFilters()" placeholder="Cari nama dokter..." class="input-field text-xs">
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto max-h-[460px]">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-slate-50 border-b border-slate-200 text-xs font-bold uppercase tracking-wider text-slate-600 sticky top-0">
                                    <tr>
                                        <th class="px-5 py-3">Dokter &amp; Poliklinik</th>
                                        <th class="px-5 py-3 text-right">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="tbody_dokter" class="divide-y divide-slate-100">
                                    <?php if (empty($list_dokter)): ?>
                                        <tr><td colspan="2" class="px-5 py-10 text-center text-slate-500 text-sm">Belum ada dokter.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($list_dokter as $d): ?>
                                            <tr class="row-dokter hover:bg-slate-50 transition-colors" data-poli="<?= (int)$d['spesialis_id'] ?>" data-name="<?= htmlspecialchars(strtolower($d['nama_dokter'])) ?>">
                                                <td class="px-5 py-3.5">
                                                    <div class="font-bold text-[#1c283d]"><?= htmlspecialchars($d['nama_dokter']) ?></div>
                                                    <div class="text-xs font-semibold text-[#6b5d52] mt-0.5"><?= htmlspecialchars($d['nama_spesialis']) ?></div>
                                                </td>
                                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                                    <div class="inline-flex items-center gap-1.5">
                                                        <button type="button" onclick='bukaEditDokter(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="min-h-[34px] px-2.5 py-1 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold">Ubah</button>
                                                        <button type="button" onclick="confirmDeleteAction('action_del_dokter', <?= (int)$d['id'] ?>, 'Hapus dokter <?= htmlspecialchars(addslashes($d['nama_dokter'])) ?>? Jadwal dokter ini juga akan terhapus.')" class="min-h-[34px] px-2.5 py-1 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold">Hapus</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </section>

            <!-- ==================== TAB 3: PLAYLIST MEDIA ==================== -->
            <section id="tab_media" role="tabpanel" aria-labelledby="btn_tab_media" class="main-tab-content <?= $active_tab === 'tab_media' ? '' : 'hidden' ?> space-y-6">
                <div class="panel-card p-6 sm:p-8">
                    <div class="pb-4 mb-6 border-b border-slate-200">
                        <h2 class="text-base font-extrabold text-slate-900">Unggah Media Promosi</h2>
                        <p class="text-xs text-slate-600 mt-0.5">Format didukung: MP4, WEBM, MOV, JPG, PNG, WEBP. Batas upload PHP: <strong class="tabular-nums"><?= htmlspecialchars(ini_get('upload_max_filesize')) ?></strong>.</p>
                    </div>

                    <form id="form_upload_media" action="" method="POST" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action_add_video" value="1">

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                            <div>
                                <label for="judul_video" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Judul Media</label>
                                <input id="judul_video" type="text" name="judul_video" required placeholder="Contoh: Profil Layanan IGD" class="input-field">
                            </div>
                            <div>
                                <label for="file_media" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">File Video / Gambar</label>
                                <input id="file_media" type="file" name="file_media" accept="video/mp4,video/webm,video/quicktime,image/*" required onchange="cekTipeFile(this)" class="input-field py-2 text-xs">
                            </div>
                            <div id="box_loop">
                                <label for="putar_per_sesi" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Jumlah Putar per Sesi</label>
                                <input id="putar_per_sesi" type="number" name="putar_per_sesi" value="1" min="1" class="input-field tabular-nums">
                            </div>
                            <div id="box_durasi" class="hidden">
                                <label for="durasi_gambar" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Durasi Tampil (Detik)</label>
                                <input id="durasi_gambar" type="number" name="durasi_gambar" value="15" min="3" class="input-field tabular-nums">
                            </div>
                            <div>
                                <button type="submit" id="btn_upload_media" class="btn-primary w-full">Unggah ke Playlist</button>
                            </div>
                        </div>

                        <div id="upload_progress_wrap" class="hidden space-y-1.5 pt-1">
                            <div class="flex justify-between text-xs font-bold text-slate-700">
                                <span id="upload_progress_label">Mengunggah media...</span>
                                <span id="upload_progress_pct" class="tabular-nums">0%</span>
                            </div>
                            <div class="w-full h-2.5 bg-[#efe8e0] rounded-full overflow-hidden">
                                <div id="upload_progress_bar" class="h-full bg-[#473d35] transition-all duration-150" style="width: 0%"></div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="panel-card overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="text-sm font-extrabold text-slate-900">Urutan Tayang Playlist (<span id="count_media" class="tabular-nums"><?= count($list_video) ?></span> item)</h3>
                        <span class="text-xs text-slate-500">Perubahan otomatis tampil di layar TV dalam 3 detik.</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 border-b border-slate-200 text-xs font-bold uppercase tracking-wider text-slate-600">
                                <tr>
                                    <th class="px-5 py-3.5 w-28 text-center">Urutan</th>
                                    <th class="px-5 py-3.5 w-28">Pratinjau</th>
                                    <th class="px-5 py-3.5">Judul &amp; Tipe</th>
                                    <th class="px-5 py-3.5 text-center">Durasi / Putar</th>
                                    <th class="px-5 py-3.5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tbody_media" class="divide-y divide-slate-100">
                                <?php if (empty($list_video)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-12 text-center">
                                            <p class="text-sm font-bold text-slate-700">Playlist media masih kosong.</p>
                                            <p class="text-xs text-slate-500 mt-1">Unggah video atau gambar di atas agar tampil di layar TV.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($list_video as $index => $v):
                                        $fileExists = !empty($v['url_video']) && file_exists($v['url_video']);
                                    ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-5 py-3.5 text-center">
                                                <div class="inline-flex items-center gap-1">
                                                    <button type="button" <?= $index === 0 ? 'disabled' : '' ?> onclick="moveMedia(<?= (int)$v['id'] ?>, 'up')" aria-label="Naikkan urutan <?= htmlspecialchars($v['judul']) ?>" class="min-h-[34px] min-w-[34px] rounded-lg border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 font-bold text-xs">&uarr;</button>
                                                    <button type="button" <?= $index === count($list_video) - 1 ? 'disabled' : '' ?> onclick="moveMedia(<?= (int)$v['id'] ?>, 'down')" aria-label="Turunkan urutan <?= htmlspecialchars($v['judul']) ?>" class="min-h-[34px] min-w-[34px] rounded-lg border border-slate-300 bg-white hover:bg-slate-100 text-slate-700 font-bold text-xs">&darr;</button>
                                                </div>
                                            </td>
                                            <td class="px-5 py-3.5">
                                                <?php if ($v['tipe'] === 'image' && $fileExists): ?>
                                                    <img src="<?= htmlspecialchars($v['url_video']) ?>" alt="<?= htmlspecialchars($v['judul']) ?>" width="80" height="45" class="w-20 h-11 object-cover rounded-lg border border-slate-200 bg-slate-100">
                                                <?php elseif ($v['tipe'] === 'video' && $fileExists): ?>
                                                    <div class="w-20 h-11 rounded-lg bg-slate-900 text-white flex items-center justify-center text-[11px] font-bold">VIDEO</div>
                                                <?php else: ?>
                                                    <div class="w-20 h-11 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 flex items-center justify-center text-[10px] font-bold text-center px-1">File Hilang</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-3.5">
                                                <div class="font-bold text-slate-900"><?= htmlspecialchars($v['judul']) ?></div>
                                                <div class="mt-1 flex items-center gap-2">
                                                    <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-bold uppercase <?= $v['tipe'] === 'image' ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-indigo-50 text-indigo-800 border border-indigo-200' ?>">
                                                        <?= $v['tipe'] === 'image' ? 'Gambar' : 'Video' ?>
                                                    </span>
                                                    <span class="text-xs text-slate-500 truncate max-w-[240px]"><?= htmlspecialchars(basename($v['url_video'])) ?></span>
                                                </div>
                                            </td>
                                            <td class="px-5 py-3.5 text-center font-bold text-slate-700 tabular-nums">
                                                <?= $v['tipe'] === 'image' ? (int)$v['durasi'] . ' Detik' : (int)$v['putar_per_sesi'] . 'x Putar' ?>
                                            </td>
                                            <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                                <div class="inline-flex items-center gap-2">
                                                    <button type="button" onclick='bukaEditMedia(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="min-h-[36px] px-3 py-1.5 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-xs font-bold">Ubah</button>
                                                    <button type="button" onclick="confirmDeleteAction('action_del_video', <?= (int)$v['id'] ?>, 'Hapus media <?= htmlspecialchars(addslashes($v['judul'])) ?> dari playlist?')" class="min-h-[36px] px-3 py-1.5 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold">Hapus</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ==================== TAB 4: PENGATURAN & AKUN ==================== -->
            <section id="tab_pengaturan" role="tabpanel" aria-labelledby="btn_tab_pengaturan" class="main-tab-content <?= $active_tab === 'tab_pengaturan' ? '' : 'hidden' ?> space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                    <form id="form_pengaturan" action="" method="POST" enctype="multipart/form-data" class="ajax-form lg:col-span-8 space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action_save_pengaturan" value="1">
                        <input type="hidden" name="old_logo" id="input_old_logo" value="<?= htmlspecialchars($pengaturan['logo_path'] ?? '') ?>">
                        <input type="hidden" name="remove_logo" id="input_remove_logo" value="0">

                        <!-- Pratinjau Header TV -->
                        <div class="panel-card overflow-hidden">
                            <div class="px-6 py-3 bg-[#faf7f4] border-b border-[#e4dbd2] text-xs font-bold uppercase tracking-wider text-[#6b5d52]">
                                Pratinjau Header Layar TV
                            </div>
                            <div class="bg-[#1c283d] px-6 py-4 flex items-center justify-between gap-4 border-b-2 border-[#c7ab97]">
                                <div class="flex items-center gap-3 min-w-0">
                                    <img id="live_tv_logo" src="<?= htmlspecialchars($pengaturan['logo_path'] ?? '') ?>" alt="Pratinjau logo" width="48" height="48" class="<?= empty($pengaturan['logo_path']) ? 'hidden' : '' ?> object-contain bg-white p-1 rounded-xl shrink-0" style="width: <?= min(64, max(32, (int)($pengaturan['logo_size'] ?? 56))) ?>px; height: <?= min(64, max(32, (int)($pengaturan['logo_size'] ?? 56))) ?>px;">
                                    <div id="live_tv_default_logo" class="<?= empty($pengaturan['logo_path']) ? '' : 'hidden' ?> w-10 h-10 bg-[#473d35] border border-[#c7ab97]/60 rounded-xl flex items-center justify-center text-[#c7ab97] shrink-0" aria-hidden="true">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p id="live_tv_nama" class="font-extrabold text-white truncate" style="font-size: <?= min(36, max(18, (int)($pengaturan['nama_size'] ?? 28))) ?>px;"><?= htmlspecialchars($nama_rs_panel) ?></p>
                                        <p class="text-[11px] font-bold text-[#c7ab97] uppercase tracking-wider">Jadwal Praktek: Hari <?= htmlspecialchars($hari_ini) ?></p>
                                    </div>
                                </div>
                                <div class="text-right shrink-0 text-white">
                                    <p class="text-xl font-extrabold tabular-nums">08:30</p>
                                    <p class="text-[11px] text-[#e4dbd2]"><?= htmlspecialchars($hari_ini) ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="panel-card p-6 space-y-4">
                                <h2 class="text-base font-extrabold text-slate-900 pb-3 border-b border-slate-200">Identitas &amp; Ukuran</h2>

                                <div>
                                    <label for="set_nama_rs" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Nama Rumah Sakit</label>
                                    <input id="set_nama_rs" type="text" name="nama_rs" value="<?= htmlspecialchars($pengaturan['nama_rs'] ?? 'RSUI BANYUBENING') ?>" oninput="updateLiveTvPreview()" class="input-field font-bold" required>
                                </div>

                                <div>
                                    <label for="set_logo_file" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Logo Rumah Sakit</label>
                                    <div id="sett_logo_preview_box" class="mb-2.5 flex items-center gap-3 p-2.5 rounded-xl border border-slate-200 bg-slate-50 <?= empty($pengaturan['logo_path']) ? 'hidden' : '' ?>">
                                        <img id="sett_logo_img" src="<?= htmlspecialchars($pengaturan['logo_path'] ?? '') ?>" alt="Logo saat ini" width="40" height="40" class="w-10 h-10 object-contain rounded-lg bg-white border border-slate-200 p-1">
                                        <span class="text-xs text-slate-600 flex-1 truncate" id="sett_logo_name"><?= htmlspecialchars(basename($pengaturan['logo_path'] ?? '')) ?></span>
                                        <button type="button" onclick="markRemoveLogo()" class="text-xs font-bold text-rose-700 hover:underline px-2 py-1">Hapus Logo</button>
                                    </div>
                                    <input id="set_logo_file" type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif" class="input-field py-2 text-xs">
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="set_nama_size" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Font Nama (px)</label>
                                        <input id="set_nama_size" type="number" name="nama_size" min="18" max="72" value="<?= (int)($pengaturan['nama_size'] ?? 35) ?>" oninput="updateLiveTvPreview()" class="input-field tabular-nums text-center">
                                    </div>
                                    <div>
                                        <label for="set_logo_size" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Lebar Logo (px)</label>
                                        <input id="set_logo_size" type="number" name="logo_size" min="24" max="200" value="<?= (int)($pengaturan['logo_size'] ?? 70) ?>" oninput="updateLiveTvPreview()" class="input-field tabular-nums text-center">
                                    </div>
                                </div>

                                <div>
                                    <label for="set_kecepatan" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Kecepatan Running Text (Detik)</label>
                                    <input id="set_kecepatan" type="number" name="kecepatan_jadwal" min="10" max="300" value="<?= (int)($pengaturan['kecepatan_jadwal'] ?? 45) ?>" class="input-field tabular-nums">
                                    <p class="text-[11px] text-slate-500 mt-1">Semakin kecil nilainya, semakin cepat teks berjalan.</p>
                                </div>
                            </div>

                            <div class="panel-card p-6 space-y-4">
                                <h2 class="text-base font-extrabold text-slate-900 pb-3 border-b border-slate-200">Lokasi Jadwal Sholat</h2>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Klik Peta atau Geser Pin Lokasi RS</p>
                                    <div id="map_sholat" class="w-full bg-slate-100"></div>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="input_lat" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Latitude</label>
                                        <input type="text" name="lat" id="input_lat" value="<?= htmlspecialchars($pengaturan['lat'] ?? '-7.5360639') ?>" class="input-field tabular-nums text-xs bg-slate-50" readonly>
                                    </div>
                                    <div>
                                        <label for="input_lng" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Longitude</label>
                                        <input type="text" name="lng" id="input_lng" value="<?= htmlspecialchars($pengaturan['lng'] ?? '110.5927848') ?>" class="input-field tabular-nums text-xs bg-slate-50" readonly>
                                    </div>
                                </div>

                                <div>
                                    <label for="set_metode_sholat" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Metode Perhitungan Sholat</label>
                                    <select id="set_metode_sholat" name="metode_sholat" class="select-field">
                                        <option value="11" <?= ($pengaturan['metode_sholat'] ?? '11') == '11' ? 'selected' : '' ?>>Kementerian Agama RI</option>
                                        <option value="1" <?= ($pengaturan['metode_sholat'] ?? '') == '1' ? 'selected' : '' ?>>Univ. Islamic Sciences, Karachi</option>
                                        <option value="2" <?= ($pengaturan['metode_sholat'] ?? '') == '2' ? 'selected' : '' ?>>ISNA (Amerika Utara)</option>
                                        <option value="3" <?= ($pengaturan['metode_sholat'] ?? '') == '3' ? 'selected' : '' ?>>Muslim World League</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary w-full">Simpan Pengaturan Global</button>
                    </form>

                    <div class="lg:col-span-4 panel-card p-6">
                        <h2 class="text-base font-extrabold text-slate-900 pb-3 mb-5 border-b border-slate-200">Keamanan Akun Admin</h2>
                        <form action="" method="POST" class="ajax-form space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action_change_password" value="1">

                            <div>
                                <label for="old_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Password Lama</label>
                                <input id="old_password" type="password" name="old_password" required autocomplete="current-password" placeholder="Masukkan password saat ini" class="input-field">
                            </div>
                            <div>
                                <label for="new_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Password Baru</label>
                                <input id="new_password" type="password" name="new_password" required minlength="6" autocomplete="new-password" placeholder="Minimal 6 karakter" class="input-field">
                            </div>
                            <div>
                                <label for="confirm_password" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Konfirmasi Password Baru</label>
                                <input id="confirm_password" type="password" name="confirm_password" required minlength="6" autocomplete="new-password" placeholder="Ulangi password baru" class="input-field">
                            </div>
                            <button type="submit" class="w-full min-h-[44px] rounded-xl bg-[#473d35] hover:bg-[#3a312b] text-white text-sm font-bold transition-colors mt-2">
                                Perbarui Password
                            </button>
                        </form>
                    </div>
                </div>
            </section>

        </main>
    </div>

    <!-- MODALS -->
    <!-- Modal Edit Media -->
    <div id="modalEditMedia" role="dialog" aria-modal="true" aria-labelledby="modalEditMediaTitle" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50">
        <div class="bg-white w-full max-w-md rounded-2xl border border-slate-200 shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                <h3 id="modalEditMediaTitle" class="text-base font-extrabold text-slate-900">Ubah Pengaturan Media</h3>
                <button type="button" onclick="closeAllModals()" aria-label="Tutup dialog" class="w-9 h-9 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 font-bold text-xl">&times;</button>
            </div>
            <form action="" method="POST" class="ajax-form p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action_edit_media" value="1">
                <input type="hidden" name="media_id" id="edit_media_id">

                <div>
                    <label for="edit_media_judul" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Judul Media</label>
                    <input type="text" name="edit_judul" id="edit_media_judul" required class="input-field">
                </div>
                <div id="edit_box_video">
                    <label for="edit_media_putar" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Jumlah Putar per Sesi</label>
                    <input type="number" name="edit_putar" id="edit_media_putar" min="1" class="input-field tabular-nums">
                </div>
                <div id="edit_box_image">
                    <label for="edit_media_durasi" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Durasi Tampil (Detik)</label>
                    <input type="number" name="edit_durasi" id="edit_media_durasi" min="3" class="input-field tabular-nums">
                </div>

                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" onclick="closeAllModals()" class="btn-secondary">Batal</button>
                    <button type="submit" class="btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Poli -->
    <div id="modalEditPoli" role="dialog" aria-modal="true" aria-labelledby="modalEditPoliTitle" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50">
        <div class="bg-white w-full max-w-md rounded-2xl border border-slate-200 shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                <h3 id="modalEditPoliTitle" class="text-base font-extrabold text-slate-900">Ubah Poliklinik</h3>
                <button type="button" onclick="closeAllModals()" aria-label="Tutup dialog" class="w-9 h-9 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 font-bold text-xl">&times;</button>
            </div>
            <form action="" method="POST" class="ajax-form p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action_edit_spesialis" value="1">
                <input type="hidden" name="id" id="edit_poli_id">

                <div>
                    <label for="edit_poli_nama" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Nama Poliklinik</label>
                    <input type="text" name="nama_spesialis" id="edit_poli_nama" class="input-field" required>
                </div>
                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" onclick="closeAllModals()" class="btn-secondary">Batal</button>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Dokter -->
    <div id="modalEditDokter" role="dialog" aria-modal="true" aria-labelledby="modalEditDokterTitle" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50">
        <div class="bg-white w-full max-w-md rounded-2xl border border-slate-200 shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                <h3 id="modalEditDokterTitle" class="text-base font-extrabold text-slate-900">Ubah Data Dokter</h3>
                <button type="button" onclick="closeAllModals()" aria-label="Tutup dialog" class="w-9 h-9 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 font-bold text-xl">&times;</button>
            </div>
            <form action="" method="POST" class="ajax-form p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action_edit_dokter" value="1">
                <input type="hidden" name="id" id="edit_dok_id">

                <div>
                    <label for="edit_dok_spesialis" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Poliklinik</label>
                    <select name="spesialis_id" id="edit_dok_spesialis" class="select-field" required>
                        <?php foreach ($list_spesialis as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nama_spesialis']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="edit_dok_nama" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Nama Dokter</label>
                    <input type="text" name="nama_dokter" id="edit_dok_nama" class="input-field" required>
                </div>
                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" onclick="closeAllModals()" class="btn-secondary">Batal</button>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Edit Jadwal -->
    <div id="modalEdit" role="dialog" aria-modal="true" aria-labelledby="modalEditJadwalTitle" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/50">
        <div class="bg-white w-full max-w-md rounded-2xl border border-slate-200 shadow-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                <h3 id="modalEditJadwalTitle" class="text-base font-extrabold text-slate-900">Ubah Jadwal Harian</h3>
                <button type="button" onclick="closeAllModals()" aria-label="Tutup dialog" class="w-9 h-9 rounded-lg text-slate-500 hover:bg-slate-200 hover:text-slate-900 font-bold text-xl">&times;</button>
            </div>
            <form action="" method="POST" class="ajax-form p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action_edit_jadwal_modal" value="1">
                <input type="hidden" name="edit_id" id="edit_id">

                <div class="rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Dokter Terpilih</p>
                    <p class="text-sm font-extrabold text-slate-900 mt-0.5" id="edit_nama_dokter"></p>
                </div>

                <div>
                    <label for="edit_status" class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1.5">Status Praktek</label>
                    <select name="status" id="edit_status" class="select-field" onchange="toggleModalEditInputs()">
                        <option value="praktek">Praktek Normal</option>
                        <option value="mundur">Mundur Jam</option>
                        <option value="cuti">Sedang Cuti</option>
                        <option value="diganti">Diganti Dokter Lain</option>
                    </select>
                </div>

                <div id="edit_box_praktek" class="modal-cond-input grid grid-cols-2 gap-4">
                    <div>
                        <label for="edit_jam_mulai" class="block text-xs font-bold text-slate-700 mb-1.5">Jam Mulai</label>
                        <input type="time" id="edit_jam_mulai" name="edit_jam_mulai" class="input-field tabular-nums">
                    </div>
                    <div>
                        <label for="edit_jam_selesai" class="block text-xs font-bold text-slate-700 mb-1.5">Jam Selesai</label>
                        <input type="time" id="edit_jam_selesai" name="edit_jam_selesai" class="input-field tabular-nums">
                    </div>
                </div>

                <div id="edit_box_mundur" class="modal-cond-input hidden">
                    <label for="edit_jam_mundur" class="block text-xs font-bold text-amber-800 mb-1.5">Mundur Ke Jam</label>
                    <input type="time" id="edit_jam_mundur" name="edit_jam_mundur" class="input-field tabular-nums">
                </div>

                <div id="edit_box_cuti" class="modal-cond-input hidden">
                    <label for="edit_cuti_sampai" class="block text-xs font-bold text-rose-800 mb-1.5">Cuti Sampai Tanggal</label>
                    <input type="date" id="edit_cuti_sampai" name="edit_cuti_sampai" class="input-field tabular-nums">
                </div>

                <div id="edit_box_diganti" class="modal-cond-input hidden space-y-3">
                    <div class="flex gap-5 text-xs font-bold text-slate-700">
                        <label class="inline-flex gap-2 items-center">
                            <input type="radio" name="tipe_pengganti_modal" value="internal" checked class="w-4 h-4 accent-[#473d35]">
                            <span>Internal Poli</span>
                        </label>
                        <label class="inline-flex gap-2 items-center">
                            <input type="radio" name="tipe_pengganti_modal" value="manual" class="w-4 h-4 accent-[#473d35]">
                            <span>Ketik Manual</span>
                        </label>
                    </div>
                    <div id="edit_wrapper_internal">
                        <label for="edit_dok_pengganti_internal" class="sr-only">Pilih Dokter Pengganti</label>
                        <select id="edit_dok_pengganti_internal" name="dokter_pengganti_internal_modal" class="select-field">
                            <option value="">Pilih Pengganti...</option>
                        </select>
                    </div>
                    <div id="edit_wrapper_manual" class="hidden">
                        <label for="edit_dokter_pengganti_manual" class="sr-only">Nama Dokter Pengganti</label>
                        <input type="text" id="edit_dokter_pengganti_manual" name="edit_dokter_pengganti_manual" placeholder="Ketik nama dokter pengganti..." class="input-field">
                    </div>
                </div>

                <div class="flex justify-end gap-2.5 pt-2">
                    <button type="button" onclick="closeAllModals()" class="btn-secondary">Batal</button>
                    <button type="submit" class="btn-primary">Simpan Jadwal</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
        const TAB_TITLES = {
            tab_jadwal: 'Jadwal Praktek Harian',
            tab_master: 'Master Poliklinik & Dokter',
            tab_media: 'Manajemen Playlist Media',
            tab_pengaturan: 'Pengaturan Global & Akun'
        };
        let currentTab = <?= json_encode($active_tab) ?>;
        let currentHari = <?= json_encode($active_hari) ?>;

        function escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            const isOk = type === 'success';
            toast.className = `pointer-events-auto flex items-start gap-3 px-4 py-3.5 rounded-xl border shadow-lg text-sm font-semibold transition-all duration-200 ${
                isOk ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : 'bg-rose-50 border-rose-200 text-rose-900'
            }`;
            toast.innerHTML = `
                <svg class="w-5 h-5 shrink-0 mt-0.5 ${isOk ? 'text-emerald-600' : 'text-rose-600'}" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
                    ${isOk
                        ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />'
                        : '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />'}
                </svg>
                <span class="flex-1">${escapeHtml(msg)}</span>
            `;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-x-4');
                setTimeout(() => toast.remove(), 220);
            }, 4000);
        }

        function syncUrlState() {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', currentTab);
            url.searchParams.set('hari', currentHari);
            window.history.replaceState({}, '', url);
        }

        function switchMainTab(id) {
            currentTab = id;
            syncUrlState();

            document.querySelectorAll('.main-tab-btn').forEach(b => {
                b.setAttribute('aria-selected', 'false');
                b.className = 'main-tab-btn w-full min-h-[44px] px-3.5 py-2.5 rounded-xl text-sm font-bold flex items-center justify-between gap-2 transition-colors text-[#e4dbd2] hover:bg-[#473d35]/55 hover:text-white border border-transparent';
            });
            const activeBtn = document.getElementById('btn_' + id);
            if (activeBtn) {
                activeBtn.setAttribute('aria-selected', 'true');
                activeBtn.className = 'main-tab-btn w-full min-h-[44px] px-3.5 py-2.5 rounded-xl text-sm font-bold flex items-center justify-between gap-2 transition-colors bg-[#473d35] text-white border border-[#c7ab97]/50 shadow-sm';
            }

            document.querySelectorAll('.mobile-tab-btn').forEach(b => {
                const match = b.getAttribute('data-mobile-tab') === id;
                b.className = `mobile-tab-btn min-h-[40px] px-3.5 py-2 rounded-xl text-xs font-bold whitespace-nowrap border transition-colors ${
                    match ? 'bg-[#1c283d] text-white border-[#1c283d]' : 'bg-[#faf7f4] text-[#473d35] border-[#e4dbd2]'
                }`;
            });

            document.querySelectorAll('.main-tab-content').forEach(c => c.classList.add('hidden'));
            document.getElementById(id).classList.remove('hidden');
            document.getElementById('workspace_title').textContent = TAB_TITLES[id] || 'Panel Admin';

            if (id === 'tab_pengaturan') initMap();
        }

        function switchTabHari(hari) {
            currentHari = hari;
            syncUrlState();
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.setAttribute('aria-selected', 'false');
                b.classList.remove('bg-[#1c283d]', 'text-white', 'shadow-sm');
                b.classList.add('text-[#473d35]', 'hover:bg-white');
            });
            const activeBtn = document.getElementById('tab_' + hari);
            if (activeBtn) {
                activeBtn.setAttribute('aria-selected', 'true');
                activeBtn.classList.remove('text-[#473d35]', 'hover:bg-white');
                activeBtn.classList.add('bg-[#1c283d]', 'text-white', 'shadow-sm');
            }
            document.querySelectorAll('.jadwal-panel').forEach(p => p.classList.add('hidden'));
            document.getElementById('panel_' + hari).classList.remove('hidden');
            document.getElementById('label_form_hari').textContent = hari;
            document.getElementById('input_form_hari').value = hari;
        }

        // LEAFLET MAP
        let map = null;
        function initMap() {
            if (map) {
                setTimeout(() => map.invalidateSize(), 150);
                return;
            }
            setTimeout(() => {
                const lat = parseFloat(document.getElementById('input_lat').value) || -7.5360639;
                const lng = parseFloat(document.getElementById('input_lng').value) || 110.5927848;
                map = L.map('map_sholat').setView([lat, lng], 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap'
                }).addTo(map);
                const marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                marker.on('dragend', () => {
                    const pos = marker.getLatLng();
                    document.getElementById('input_lat').value = pos.lat.toFixed(7);
                    document.getElementById('input_lng').value = pos.lng.toFixed(7);
                });
                map.on('click', (e) => {
                    marker.setLatLng(e.latlng);
                    document.getElementById('input_lat').value = e.latlng.lat.toFixed(7);
                    document.getElementById('input_lng').value = e.latlng.lng.toFixed(7);
                });
            }, 150);
        }
        if (currentTab === 'tab_pengaturan') initMap();

        // LIVE TV PREVIEW IN PENGATURAN
        function updateLiveTvPreview() {
            const nama = (document.getElementById('set_nama_rs')?.value || '').trim() || 'RSUI BANYUBENING';
            const namaSize = Math.min(36, Math.max(18, parseInt(document.getElementById('set_nama_size')?.value, 10) || 28));
            const logoSize = Math.min(64, Math.max(32, parseInt(document.getElementById('set_logo_size')?.value, 10) || 56));
            const namaEl = document.getElementById('live_tv_nama');
            const logoEl = document.getElementById('live_tv_logo');
            if (namaEl) {
                namaEl.textContent = nama;
                namaEl.style.fontSize = namaSize + 'px';
            }
            if (logoEl) {
                logoEl.style.width = logoSize + 'px';
                logoEl.style.height = logoSize + 'px';
            }
        }

        // FILTER POLI & DOKTER
        let activeFilterPoli = 'all';
        let activeFilterName = '';

        function filterPoliTable(query) {
            const q = (query || '').trim().toLowerCase();
            document.querySelectorAll('.row-poli').forEach(r => {
                const name = r.getAttribute('data-name') || '';
                r.style.display = name.includes(q) ? '' : 'none';
            });
        }

        function filterDokter(idPoli, namaPoli) {
            activeFilterPoli = idPoli;
            activeFilterName = namaPoli;
            applyDokterFilters();
        }

        function applyDokterFilters() {
            const q = (document.getElementById('search_dokter')?.value || '').trim().toLowerCase();
            const label = document.getElementById('label_filter_dokter');
            const btnReset = document.getElementById('btn_reset_filter');

            document.querySelectorAll('.row-dokter').forEach(r => {
                const matchPoli = (activeFilterPoli === 'all') || (r.getAttribute('data-poli') == activeFilterPoli);
                const matchText = (r.getAttribute('data-name') || '').includes(q);
                r.style.display = (matchPoli && matchText) ? '' : 'none';
            });

            if (activeFilterPoli === 'all') {
                label.classList.add('hidden');
                btnReset.classList.add('hidden');
            } else {
                label.textContent = 'Poli: ' + activeFilterName;
                label.classList.remove('hidden');
                btnReset.classList.remove('hidden');
            }
        }

        // TAMBAH JADWAL: LOAD DOKTER SESUAI POLI
        document.getElementById('sel_poli').addEventListener('change', function () {
            const dd = document.getElementById('sel_dok');
            const ddPengganti = document.getElementById('sel_dok_pengganti_internal');
            if (!this.value) {
                dd.innerHTML = '<option value="">Pilih Poliklinik dahulu...</option>';
                ddPengganti.innerHTML = '<option value="">Pilih Poliklinik dahulu...</option>';
                return;
            }
            dd.innerHTML = '<option value="">Memuat dokter...</option>';
            fetch('api.php?action=get_dokter_by_spesialis&spesialis_id=' + encodeURIComponent(this.value))
                .then(r => r.json())
                .then(data => {
                    dd.innerHTML = '<option value="">Pilih Dokter...</option>';
                    ddPengganti.innerHTML = '<option value="">Pilih Dokter Pengganti...</option>';
                    data.forEach(d => {
                        dd.innerHTML += `<option value="${d.id}">${escapeHtml(d.nama_dokter)}</option>`;
                        ddPengganti.innerHTML += `<option value="${d.id}">${escapeHtml(d.nama_dokter)}</option>`;
                    });
                })
                .catch(() => {
                    dd.innerHTML = '<option value="">Gagal memuat dokter</option>';
                });
        });

        document.getElementById('sel_stat').addEventListener('change', function () {
            document.querySelectorAll('.cond-input-add').forEach(el => el.classList.add('hidden'));
            document.getElementById('box_' + this.value).classList.remove('hidden');
        });

        document.querySelectorAll('input[name="tipe_pengganti"]').forEach(r => {
            r.addEventListener('change', function () {
                document.getElementById('wrapper_pengganti_internal').classList.toggle('hidden', this.value !== 'internal');
                document.getElementById('wrapper_pengganti_manual').classList.toggle('hidden', this.value !== 'manual');
            });
        });

        // MEDIA FORM
        function cekTipeFile(input) {
            const file = input.files[0];
            const isImg = file && file.type.startsWith('image/');
            document.getElementById('box_loop').classList.toggle('hidden', !!isImg);
            document.getElementById('box_durasi').classList.toggle('hidden', !isImg);
        }

        // MODALS
        function closeAllModals() {
            ['modalEditMedia', 'modalEditPoli', 'modalEditDokter', 'modalEdit'].forEach(id => {
                document.getElementById(id).classList.add('hidden');
            });
        }
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeAllModals();
        });
        ['modalEditMedia', 'modalEditPoli', 'modalEditDokter', 'modalEdit'].forEach(id => {
            document.getElementById(id).addEventListener('click', (e) => {
                if (e.target.id === id) closeAllModals();
            });
        });

        function bukaEditPoli(data) {
            document.getElementById('edit_poli_id').value = data.id;
            document.getElementById('edit_poli_nama').value = data.nama_spesialis;
            document.getElementById('modalEditPoli').classList.remove('hidden');
        }

        function bukaEditDokter(data) {
            document.getElementById('edit_dok_id').value = data.id;
            document.getElementById('edit_dok_nama').value = data.nama_dokter;
            document.getElementById('edit_dok_spesialis').value = data.spesialis_id;
            document.getElementById('modalEditDokter').classList.remove('hidden');
        }

        function bukaEditMedia(data) {
            document.getElementById('edit_media_id').value = data.id;
            document.getElementById('edit_media_judul').value = data.judul || '';
            document.getElementById('edit_media_putar').value = data.putar_per_sesi || 1;
            document.getElementById('edit_media_durasi').value = data.durasi || 15;
            const isImg = data.tipe === 'image';
            document.getElementById('edit_box_image').classList.toggle('hidden', !isImg);
            document.getElementById('edit_box_video').classList.toggle('hidden', isImg);
            document.getElementById('modalEditMedia').classList.remove('hidden');
        }

        function toggleModalEditInputs() {
            const s = document.getElementById('edit_status').value;
            document.querySelectorAll('#modalEdit .modal-cond-input').forEach(e => e.classList.add('hidden'));
            document.getElementById('edit_box_' + s).classList.remove('hidden');
        }

        function openEditModal(data) {
            document.getElementById('edit_jam_mulai').value = '';
            document.getElementById('edit_jam_selesai').value = '';
            document.getElementById('edit_jam_mundur').value = '';
            document.getElementById('edit_cuti_sampai').value = '';
            document.getElementById('edit_dokter_pengganti_manual').value = '';

            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_nama_dokter').textContent = data.nama_dokter;
            document.getElementById('edit_status').value = data.status;

            if (data.jam_praktek) {
                const p = data.jam_praktek.split('-');
                document.getElementById('edit_jam_mulai').value = (p[0] || '').trim();
                document.getElementById('edit_jam_selesai').value = (p[1] || '').trim();
            }
            if (data.jam_mundur) document.getElementById('edit_jam_mundur').value = data.jam_mundur;
            if (data.cuti_sampai) document.getElementById('edit_cuti_sampai').value = data.cuti_sampai;

            toggleModalEditInputs();

            const ddPenggantiModal = document.getElementById('edit_dok_pengganti_internal');
            ddPenggantiModal.innerHTML = '<option value="">Memuat dokter...</option>';
            if (data.spesialis_id) {
                fetch('api.php?action=get_dokter_by_spesialis&spesialis_id=' + encodeURIComponent(data.spesialis_id))
                    .then(r => r.json())
                    .then(dokters => {
                        ddPenggantiModal.innerHTML = '<option value="">Pilih Pengganti...</option>';
                        let matchedInternal = false;
                        dokters.forEach(d => {
                            if (d.id != data.dokter_id) {
                                const isMatch = data.dokter_pengganti === d.nama_dokter;
                                if (isMatch) matchedInternal = true;
                                ddPenggantiModal.innerHTML += `<option value="${d.id}" ${isMatch ? 'selected' : ''}>${escapeHtml(d.nama_dokter)}</option>`;
                            }
                        });

                        if (data.status === 'diganti') {
                            const radioInternal = document.querySelector('input[name="tipe_pengganti_modal"][value="internal"]');
                            const radioManual = document.querySelector('input[name="tipe_pengganti_modal"][value="manual"]');
                            if (matchedInternal) {
                                radioInternal.checked = true;
                                radioInternal.dispatchEvent(new Event('change'));
                            } else {
                                radioManual.checked = true;
                                document.getElementById('edit_dokter_pengganti_manual').value = data.dokter_pengganti || '';
                                radioManual.dispatchEvent(new Event('change'));
                            }
                        }
                    });
            }
            document.getElementById('modalEdit').classList.remove('hidden');
        }

        document.querySelectorAll('input[name="tipe_pengganti_modal"]').forEach(r => {
            r.addEventListener('change', function () {
                document.getElementById('edit_wrapper_internal').classList.toggle('hidden', this.value !== 'internal');
                document.getElementById('edit_wrapper_manual').classList.toggle('hidden', this.value !== 'manual');
            });
        });

        function markRemoveLogo() {
            document.getElementById('input_remove_logo').value = '1';
            document.getElementById('sett_logo_preview_box').classList.add('hidden');
            document.getElementById('live_tv_logo').classList.add('hidden');
            document.getElementById('live_tv_default_logo').classList.remove('hidden');
            showToast('Logo ditandai untuk dihapus. Klik "Simpan Pengaturan Global" untuk menerapkan.', 'success');
        }

        // PARTIAL DOM REFRESH
        function refreshDataUpdate() {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', currentTab);
            url.searchParams.set('hari', currentHari);

            fetch(url.toString(), { cache: 'no-store' })
                .then(res => res.text())
                .then(html => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const idsToUpdate = [
                        'tbody_poli', 'tbody_dokter', 'tbody_media',
                        'count_poli', 'count_dokter', 'count_media',
                        'nav_badge_jadwal', 'nav_badge_dokter', 'nav_badge_media',
                        'kpi_summary_row', 'sel_poli', 'sel_poli_tambah', 'edit_dok_spesialis',
                        'hari_tabs_bar', 'header_nama_rs'
                    ];
                    ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'].forEach(h => idsToUpdate.push('panel_' + h));

                    idsToUpdate.forEach(id => {
                        const el = document.getElementById(id);
                        const newEl = doc.getElementById(id);
                        if (el && newEl) el.innerHTML = newEl.innerHTML;
                    });

                    applyDokterFilters();
                    filterPoliTable(document.getElementById('search_poli')?.value || '');
                })
                .catch(err => console.error('Gagal menyinkronkan data:', err));
        }

        function postAction(params) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('is_ajax', '1');
            Object.entries(params).forEach(([k, v]) => fd.append(k, v));

            return fetch(window.location.pathname, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(r => r.json());
        }

        function confirmDeleteAction(actionKey, id, message) {
            if (!confirm(message)) return;
            postAction({ [actionKey]: '1', id: String(id) })
                .then(d => {
                    showToast(d.message, d.success ? 'success' : 'error');
                    if (d.success) refreshDataUpdate();
                })
                .catch(() => showToast('Gagal menghubungi server.', 'error'));
        }

        function moveMedia(id, direction) {
            postAction({ action_move_media: '1', id: String(id), direction })
                .then(d => {
                    showToast(d.message, d.success ? 'success' : 'error');
                    if (d.success) refreshDataUpdate();
                })
                .catch(() => showToast('Gagal mengubah urutan media.', 'error'));
        }

        // AJAX FORM HANDLERS
        document.querySelectorAll('form.ajax-form').forEach(f => {
            f.addEventListener('submit', e => {
                e.preventDefault();
                const btn = f.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.innerHTML = 'Menyimpan...';
                btn.disabled = true;

                const fd = new FormData(f);
                fd.append('is_ajax', '1');

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(r => r.json())
                    .then(d => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        showToast(d.message, d.success ? 'success' : 'error');

                        if (d.success) {
                            closeAllModals();

                            if (f.id === 'form_pengaturan' && typeof d.logo_path === 'string') {
                                document.getElementById('input_old_logo').value = d.logo_path;
                                document.getElementById('input_remove_logo').value = '0';
                                document.getElementById('set_logo_file').value = '';
                                const box = document.getElementById('sett_logo_preview_box');
                                const tvLogo = document.getElementById('live_tv_logo');
                                const tvDef = document.getElementById('live_tv_default_logo');
                                if (d.logo_path !== '') {
                                    const srcBust = d.logo_path + '?t=' + Date.now();
                                    document.getElementById('sett_logo_img').src = srcBust;
                                    document.getElementById('sett_logo_name').textContent = d.logo_path.split('/').pop();
                                    box.classList.remove('hidden');
                                    tvLogo.src = srcBust;
                                    tvLogo.classList.remove('hidden');
                                    tvDef.classList.add('hidden');
                                } else {
                                    box.classList.add('hidden');
                                    tvLogo.classList.add('hidden');
                                    tvDef.classList.remove('hidden');
                                }
                                updateLiveTvPreview();
                            } else if (!f.closest('[role="dialog"]') && !f.querySelector('input[name="action_change_password"]')) {
                                f.reset();
                                const selStat = f.querySelector('select[name="status"]');
                                if (selStat) selStat.dispatchEvent(new Event('change'));
                            }

                            if (f.querySelector('input[name="action_change_password"]')) {
                                f.reset();
                            }

                            refreshDataUpdate();
                        }
                    })
                    .catch(() => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        showToast('Gagal menghubungi server.', 'error');
                    });
            });
        });

        // UPLOAD MEDIA DENGAN PROGRESS BAR (XHR)
        document.getElementById('form_upload_media').addEventListener('submit', function (e) {
            e.preventDefault();
            const form = this;
            const btn = document.getElementById('btn_upload_media');
            const wrap = document.getElementById('upload_progress_wrap');
            const bar = document.getElementById('upload_progress_bar');
            const pctEl = document.getElementById('upload_progress_pct');
            const labelEl = document.getElementById('upload_progress_label');

            const fd = new FormData(form);
            fd.append('is_ajax', '1');

            btn.disabled = true;
            btn.textContent = 'Mengunggah...';
            wrap.classList.remove('hidden');
            bar.style.width = '0%';
            pctEl.textContent = '0%';
            labelEl.textContent = 'Mengunggah media...';

            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.pathname, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = function (evt) {
                if (evt.lengthComputable) {
                    const pct = Math.round((evt.loaded / evt.total) * 100);
                    bar.style.width = pct + '%';
                    pctEl.textContent = pct + '%';
                    if (pct === 100) labelEl.textContent = 'Memproses file di server...';
                }
            };

            xhr.onload = function () {
                btn.disabled = false;
                btn.textContent = 'Unggah ke Playlist';
                wrap.classList.add('hidden');
                try {
                    const d = JSON.parse(xhr.responseText);
                    showToast(d.message, d.success ? 'success' : 'error');
                    if (d.success) {
                        form.reset();
                        document.getElementById('box_loop').classList.remove('hidden');
                        document.getElementById('box_durasi').classList.add('hidden');
                        refreshDataUpdate();
                    }
                } catch {
                    showToast('Respons server tidak valid saat mengunggah media.', 'error');
                }
            };

            xhr.onerror = function () {
                btn.disabled = false;
                btn.textContent = 'Unggah ke Playlist';
                wrap.classList.add('hidden');
                showToast('Koneksi terputus saat mengunggah file.', 'error');
            };

            xhr.send(fd);
        });
    </script>
</body>
</html>

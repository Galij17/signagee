<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once 'config.php';

$action = $_GET['action'] ?? 'get_display_signage';

if ($action === 'get_dokter_by_spesialis') {
    $spesialis_id = isset($_GET['spesialis_id']) ? (int)$_GET['spesialis_id'] : 0;
    $stmt = $pdo->prepare("SELECT id, nama_dokter FROM dokter WHERE spesialis_id = ? ORDER BY nama_dokter ASC");
    $stmt->execute([$spesialis_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($action === 'get_display_signage') {
    try {
        $hari_map = [
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            7 => 'Minggu'
        ];
        $hari_ini = $hari_map[(int)date('N')];

        $query = "
            SELECT jp.id, jp.status, jp.jam_praktek, jp.jam_mundur, jp.cuti_sampai, jp.dokter_pengganti,
                   d.nama_dokter, s.nama_spesialis
            FROM jadwal_praktek jp
            JOIN dokter d ON jp.dokter_id = d.id
            JOIN spesialis s ON d.spesialis_id = s.id
            WHERE jp.hari = :hari
            ORDER BY s.nama_spesialis ASC, d.nama_dokter ASC
        ";
        $stmtJadwal = $pdo->prepare($query);
        $stmtJadwal->execute(['hari' => $hari_ini]);
        $rawJadwal = $stmtJadwal->fetchAll();

        $jadwal_grouped = [];
        foreach ($rawJadwal as $row) {
            $poli = $row['nama_spesialis'];
            $detail = '';
            $status = $row['status'];

            if ($status === 'praktek') {
                $jam = trim((string)($row['jam_praktek'] ?? ''));
                $detail = $jam !== '' ? "PRAKTEK: " . $jam : "PRAKTEK SESUAI JADWAL";
            } elseif ($status === 'mundur') {
                $jam = trim((string)($row['jam_mundur'] ?? ''));
                $detail = $jam !== '' ? "MUNDUR KE JAM " . $jam : "JADWAL MUNDUR";
            } elseif ($status === 'cuti') {
                $cuti = trim((string)($row['cuti_sampai'] ?? ''));
                $detail = ($cuti !== '' && strtotime($cuti) !== false)
                    ? "CUTI S/D " . date('d-m-Y', strtotime($cuti))
                    : "SEDANG CUTI";
            } elseif ($status === 'diganti') {
                $pengganti = trim((string)($row['dokter_pengganti'] ?? ''));
                $detail = $pengganti !== '' ? "DIGANTIKAN: " . $pengganti : "DIGANTIKAN DOKTER LAIN";
            }

            $jadwal_grouped[$poli][] = [
                'nama_dokter'    => $row['nama_dokter'],
                'status'         => $status,
                'detail_kondisi' => $detail
            ];
        }

        $stmtVideo = $pdo->query("SELECT id, judul, url_video, putar_per_sesi, tipe, durasi FROM video_promosi ORDER BY id ASC");
        $videos = $stmtVideo->fetchAll();

        $stmtSettings = $pdo->query("SELECT kunci, nilai FROM pengaturan");
        $pengaturan = [
            'nama_rs'          => 'RSUI BANYUBENING',
            'nama_size'        => '35',
            'logo_path'        => '',
            'logo_size'        => '70',
            'kecepatan_jadwal' => '45',
            'lat'              => '-7.5360639',
            'lng'              => '110.5927848',
            'metode_sholat'    => '11',
        ];
        foreach ($stmtSettings->fetchAll() as $sett) {
            $pengaturan[$sett['kunci']] = $sett['nilai'];
        }

        echo json_encode([
            'status'      => 'success',
            'hari_ini'    => $hari_ini,
            'server_time' => date('c'),
            'jadwal'      => $jadwal_grouped,
            'videos'      => $videos,
            'pengaturan'  => $pengaturan
        ]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Action tidak dikenal.']);

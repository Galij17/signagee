# Digital Signage RSUI Banyubening

Aplikasi Digital Signage berbasis PHP & MySQL untuk menampilkan playlist video/gambar promosi rumah sakit, jadwal praktek dokter harian, serta jadwal sholat pada layar TV ruang tunggu.

## Fitur Utama
1. **Layar TV Display (`display.php`)**:
   - Pemutaran playlist video (`MP4`, `WEBM`, `MOV`) dan gambar (`JPG`, `PNG`, `WEBP`) otomatis dengan pengaturan jumlah putar/durasi per item.
   - Running text jadwal praktek dokter otomatis difilter sesuai hari berjalan (WIB) beserta status (`Praktek`, `Mundur`, `Cuti`, `Diganti`).
   - Integrasi jadwal sholat harian otomatis berdasarkan titik koordinat peta rumah sakit (Aladhan API, tetap berjalan normal bila offline).
2. **Panel Kontrol Admin (`admin.php`)**:
   - **Jadwal Harian**: Kelola jadwal dokter per hari (`Senin` - `Minggu`) dengan status Praktek Normal, Mundur Jam, Cuti, atau Diganti Dokter Lain.
   - **Poli & Dokter**: Kelola master poliklinik dan data dokter beserta pencarian dan filter per poli.
   - **Playlist Media**: Unggah video/gambar langsung ke server dengan indikator progres, pratinjau media, pengurutan atas/bawah, dan pengaturan durasi/loop.
   - **Pengaturan & Akun**: Atur nama RS, logo, ukuran tampilan, kecepatan running text, pin peta lokasi RS, serta ganti password admin.

## Persiapan Server (Laragon / XAMPP)
Karena mendukung upload video promosi berukuran besar, sesuaikan pengaturan pada `php.ini`:
- `upload_max_filesize = 100M`
- `post_max_size = 100M`

Setelah mengubah `php.ini`, restart Apache/web server.

## Instalasi
1. Letakkan folder projek di root web server (mis. `C:\laragon\www\signage\`).
2. Pastikan MySQL/MariaDB menyala, lalu import `database.sql` atau jalankan `php migrate.php` dari terminal (atau akses `http://localhost/signage/migrate.php` dari localhost).
3. Buka aplikasi:
   - Login Admin: `http://localhost/signage/login.php` (akun awal default: `admin` / `admin123` — segera ganti password setelah login)
   - Layar TV: `http://localhost/signage/display.php`

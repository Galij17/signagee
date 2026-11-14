<?php
session_start();
require_once 'config.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: admin.php");
    exit;
}

try {
    $pdo->query("SELECT 1 FROM admin_users LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_users` (`id` INT AUTO_INCREMENT PRIMARY KEY, `username` VARCHAR(50) NOT NULL UNIQUE, `password` VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$countUsers = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
if ($countUsers === 0) {
    $default_pass = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT IGNORE INTO `admin_users` (`username`, `password`) VALUES (?, ?)")->execute(['admin', $default_pass]);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$nama_rs = 'RSUI BANYUBENING';
$logo_path = '';
try {
    $sett = $pdo->query("SELECT kunci, nilai FROM pengaturan WHERE kunci IN ('nama_rs', 'logo_path')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($sett['nama_rs'])) $nama_rs = $sett['nama_rs'];
    if (!empty($sett['logo_path']) && file_exists($sett['logo_path'])) $logo_path = $sett['logo_path'];
} catch (PDOException $e) {}

$error = '';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $username_val = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Sesi formulir kedaluwarsa. Silakan muat ulang halaman dan coba lagi.';
    } elseif ($username_val === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password FROM admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$username_val]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: admin.php");
            exit;
        } else {
            $error = 'Username atau password tidak sesuai.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk Admin — <?= htmlspecialchars($nama_rs) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: #f5f1ec;
            color: #1c283d;
            margin: 0;
        }
        button, a[href], input[type="submit"], label[for] { cursor: pointer; }
        :focus-visible { outline: 2px solid #473d35; outline-offset: 2px; }
        .brand-panel { background-color: #1c283d; color: #ffffff; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 sm:p-6">
    <main class="w-full max-w-4xl bg-white rounded-3xl border border-[#e4dbd2] shadow-lg overflow-hidden grid grid-cols-1 md:grid-cols-12">

        <!-- Panel Identitas Rumah Sakit -->
        <section class="brand-panel md:col-span-5 p-8 sm:p-10 flex flex-col justify-between gap-8 border-b-4 md:border-b-0 md:border-r-4 border-[#c7ab97]">
            <div class="space-y-6">
                <div class="flex items-center gap-3.5">
                    <?php if ($logo_path !== ''): ?>
                        <img src="<?= htmlspecialchars($logo_path) ?>" alt="Logo <?= htmlspecialchars($nama_rs) ?>" width="52" height="52" class="w-13 h-13 object-contain rounded-xl bg-white p-1.5 shrink-0">
                    <?php else: ?>
                        <div class="w-12 h-12 rounded-xl bg-[#473d35] border border-[#c7ab97]/50 text-[#c7ab97] flex items-center justify-center shrink-0" aria-hidden="true">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-widest text-[#c7ab97]">Digital Signage</p>
                        <p class="text-base font-extrabold text-white truncate"><?= htmlspecialchars($nama_rs) ?></p>
                    </div>
                </div>

                <div class="space-y-3">
                    <h2 class="text-2xl font-extrabold leading-snug text-white">
                        Kendali Layar Informasi &amp; Jadwal Poliklinik
                    </h2>
                    <p class="text-sm text-[#e4dbd2] leading-relaxed">
                        Kelola jadwal praktek dokter harian, materi video atau poster ruang tunggu, serta koordinat jadwal sholat dalam satu panel terpusat.
                    </p>
                </div>
            </div>

            <div class="pt-6 border-t border-[#8c7b6d]/35">
                <a href="display.php" class="inline-flex items-center justify-center gap-2 w-full min-h-[44px] rounded-xl bg-[#473d35] hover:bg-[#574b41] text-[#f5efe9] text-sm font-bold px-4 py-2.5 transition-colors border border-[#8c7b6d]/50">
                    <svg class="w-4 h-4 shrink-0 text-[#c7ab97]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z" />
                    </svg>
                    <span>Buka Layar TV Display</span>
                </a>
            </div>
        </section>

        <!-- Form Login Operator -->
        <section class="md:col-span-7 p-8 sm:p-10 flex flex-col justify-center">
            <div class="mb-7">
                <h1 class="text-2xl font-extrabold text-[#1c283d]">Masuk ke Panel Admin</h1>
                <p class="text-sm text-[#5c5047] mt-1">Gunakan akun operator untuk memperbarui jadwal dan playlist media.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div role="alert" class="mb-6 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">
                    <svg class="w-5 h-5 text-rose-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <div>
                    <label for="username" class="block text-xs font-bold uppercase tracking-wider text-[#473d35] mb-1.5">Username</label>
                    <input
                        id="username"
                        type="text"
                        name="username"
                        value="<?= htmlspecialchars($username_val) ?>"
                        autocomplete="username"
                        required
                        autofocus
                        placeholder="Masukkan username"
                        class="w-full rounded-xl border border-[#d6cbc1] bg-white px-4 py-3 text-sm font-semibold text-[#1c283d] placeholder:text-[#8c7b6d] focus:border-[#473d35] focus:ring-2 focus:ring-[#473d35]/15 focus:outline-none transition-colors"
                    >
                </div>

                <div>
                    <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#473d35] mb-1.5">Password</label>
                    <div class="relative">
                        <input
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            placeholder="Masukkan password"
                            class="w-full rounded-xl border border-[#d6cbc1] bg-white pl-4 pr-12 py-3 text-sm font-semibold text-[#1c283d] placeholder:text-[#8c7b6d] focus:border-[#473d35] focus:ring-2 focus:ring-[#473d35]/15 focus:outline-none transition-colors"
                        >
                        <button
                            type="button"
                            id="toggle_pass"
                            aria-label="Tampilkan password"
                            aria-pressed="false"
                            class="absolute inset-y-0 right-0 px-3.5 flex items-center text-[#6b5d52] hover:text-[#1c283d]"
                        >
                            <svg id="icon_eye_open" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <svg id="icon_eye_closed" class="w-5 h-5 hidden" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button
                    type="submit"
                    class="w-full min-h-[46px] rounded-xl bg-[#1c283d] hover:bg-[#2a3a57] active:bg-[#141d2e] text-white text-sm font-bold tracking-wide transition-colors shadow-sm"
                >
                    Masuk ke Dashboard
                </button>
            </form>
        </section>
    </main>

    <script>
        const toggleBtn = document.getElementById('toggle_pass');
        const passInput = document.getElementById('password');
        const eyeOpen = document.getElementById('icon_eye_open');
        const eyeClosed = document.getElementById('icon_eye_closed');

        toggleBtn.addEventListener('click', () => {
            const isHidden = passInput.type === 'password';
            passInput.type = isHidden ? 'text' : 'password';
            toggleBtn.setAttribute('aria-pressed', String(isHidden));
            toggleBtn.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Tampilkan password');
            eyeOpen.classList.toggle('hidden', isHidden);
            eyeClosed.classList.toggle('hidden', !isHidden);
        });
    </script>
</body>
</html>

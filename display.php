<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Display Signage — RSUI Banyubening</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        html, body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: #121a28;
            color: #f8fafc;
            margin: 0;
        }
        body.cursor-hidden { cursor: none; }
        button { cursor: pointer; }
        .tabular-nums { font-variant-numeric: tabular-nums; }

        .topbar-bg {
            background-color: #1c283d;
            border-bottom: 2px solid #c7ab97;
        }
        .sidebar-bg {
            background-color: #172133;
            border-left: 1px solid rgba(199, 171, 151, 0.25);
        }

        .marquee-wrapper {
            overflow: hidden;
            white-space: nowrap;
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
        }
        .marquee-content {
            display: inline-flex;
            align-items: center;
            padding-left: 100%;
            animation: marquee-scroll 45s linear infinite;
            will-change: transform;
        }
        @keyframes marquee-scroll {
            0%   { transform: translate3d(0, 0, 0); }
            100% { transform: translate3d(-100%, 0, 0); }
        }

        #player_promosi_video,
        #player_promosi_image {
            transition: opacity 0.45s ease-in-out;
        }

        /* Scrollbar halus untuk panel jadwal */
        #schedule_cards_wrap::-webkit-scrollbar { width: 4px; }
        #schedule_cards_wrap::-webkit-scrollbar-track { background: transparent; }
        #schedule_cards_wrap::-webkit-scrollbar-thumb { background: rgba(199, 171, 151, 0.35); border-radius: 4px; }
    </style>
</head>
<body class="h-screen w-screen flex flex-col overflow-hidden select-none" onclick="enableAudio()">

    <!-- Banner Koneksi API -->
    <div
        id="api-error-banner"
        role="status"
        class="hidden fixed top-4 left-1/2 -translate-x-1/2 z-50 rounded-lg bg-amber-950 border border-amber-500 text-amber-200 px-4 py-2 text-xs font-bold shadow-lg"
    >
        Koneksi ke server terganggu. Mencoba menyambung ulang...
    </div>

    <!-- TOP BAR SOLID (Tidak menutupi video/poster) -->
    <header class="topbar-bg px-5 sm:px-8 py-3.5 flex flex-wrap items-center justify-between gap-4 shrink-0 z-20">
        <div class="flex items-center gap-4 min-w-0">
            <img
                id="logo_rs"
                src=""
                width="56"
                height="56"
                class="hidden object-contain bg-white p-1.5 rounded-xl shrink-0"
                alt="Logo Rumah Sakit"
                onerror="handleLogoError()"
            >
            <div
                id="default_logo"
                class="w-12 h-12 bg-[#473d35] border border-[#c7ab97]/60 rounded-xl flex items-center justify-center text-[#c7ab97] shrink-0"
                aria-hidden="true"
            >
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
            </div>
            <div class="min-w-0">
                <h1 id="nama_rs" class="font-extrabold text-white tracking-wide leading-tight truncate" style="font-size: 30px;">
                    RSUI BANYUBENING
                </h1>
                <p id="label_hari_ini" class="text-xs sm:text-sm font-bold text-[#c7ab97] tracking-wider uppercase mt-0.5">
                    Memuat Jadwal...
                </p>
            </div>
        </div>

        <!-- Jadwal Sholat Ringkas di Header -->
        <div id="sholat_bar" class="hidden xl:flex items-center gap-4 rounded-xl bg-[#473d35]/65 border border-[#8c7b6d]/50 px-4 py-2">
            <span class="text-[11px] font-extrabold uppercase tracking-wider text-[#c7ab97] shrink-0">Waktu Sholat</span>
            <div id="sholat_times" class="flex items-center gap-4 text-xs sm:text-sm text-slate-100 font-semibold tabular-nums whitespace-nowrap"></div>
        </div>

        <!-- Jam & Tanggal -->
        <div class="text-right shrink-0">
            <div class="flex items-baseline justify-end gap-1">
                <span id="txt-clock" class="text-3xl sm:text-4xl font-extrabold text-white tabular-nums tracking-tight">00:00</span>
                <span id="txt-seconds" class="text-base sm:text-lg font-bold text-[#c7ab97] tabular-nums">:00</span>
            </div>
            <div id="txt-date" class="text-xs sm:text-sm font-semibold text-[#e4dbd2] mt-0.5">
                Memuat tanggal...
            </div>
        </div>
    </header>

    <!-- MAIN BROADCAST AREA: STAGE MEDIA + PANEL JADWAL DOKTER -->
    <div class="flex-1 min-h-0 flex flex-col lg:flex-row overflow-hidden">

        <!-- Kiri: Area Video & Poster Promosi -->
        <main class="flex-1 bg-slate-950 relative flex items-center justify-center overflow-hidden min-h-[260px]">
            <video
                id="player_promosi_video"
                class="absolute inset-0 w-full h-full object-contain opacity-0 hidden"
                autoplay
                playsinline
                width="1920"
                height="1080"
            ></video>
            <img
                id="player_promosi_image"
                src=""
                alt="Media Informasi Rumah Sakit"
                class="absolute inset-0 w-full h-full object-contain opacity-0 hidden"
                width="1920"
                height="1080"
            />

            <!-- Tombol Aktivasi Suara -->
            <button
                type="button"
                id="unmute-overlay"
                onclick="event.stopPropagation(); enableAudio();"
                class="fixed bottom-24 left-6 z-40 flex items-center gap-2 rounded-xl bg-rose-700 hover:bg-rose-800 text-white px-4 py-2.5 text-xs sm:text-sm font-bold shadow-lg border border-rose-400/40 transition-colors"
                aria-label="Aktifkan suara video"
            >
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 010 12.728M16.463 8.288a5.25 5.25 0 010 7.424M6.75 8.25l4.72-4.72a.75.75 0 011.28.53v15.88a.75.75 0 01-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.01 9.01 0 012.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75z" />
                </svg>
                <span>Klik Layar untuk Aktifkan Suara</span>
            </button>

            <!-- Loading State -->
            <div id="loading_media_msg" class="flex flex-col items-center justify-center text-[#e4dbd2] px-6 text-center">
                <div class="w-9 h-9 border-4 border-[#c7ab97] border-t-transparent rounded-full animate-spin mb-3" aria-hidden="true"></div>
                <p class="text-sm font-semibold tracking-wide text-[#e4dbd2]">Menghubungkan ke server informasi...</p>
            </div>

            <!-- Empty State -->
            <div id="no_video_msg" class="hidden flex-col items-center justify-center text-[#e4dbd2] px-6 text-center max-w-md">
                <div class="w-14 h-14 rounded-2xl bg-[#1c283d] border border-[#8c7b6d]/40 flex items-center justify-center mb-3 text-[#c7ab97]" aria-hidden="true">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <p class="text-base font-bold text-white">Belum Ada Media Tayang</p>
                <p class="text-xs text-[#c7ab97]/90 mt-1">Unggah video atau gambar promosi di halaman Admin agar tampil pada layar ini.</p>
            </div>
        </main>

        <!-- Kanan: Panel Live Jadwal Praktek Hari Ini -->
        <aside class="sidebar-bg w-full lg:w-[360px] xl:w-[410px] shrink-0 flex flex-col min-h-0 max-h-[38vh] lg:max-h-none">
            <div class="px-5 py-3.5 border-b border-[#8c7b6d]/30 flex items-center justify-between bg-[#473d35]/35 shrink-0">
                <h2 class="text-sm font-extrabold uppercase tracking-wider text-white">Praktek Dokter Hari Ini</h2>
                <span id="aside_hari_badge" class="rounded-md bg-[#c7ab97] border border-[#c7ab97] px-2.5 py-0.5 text-xs font-extrabold text-[#1c283d] uppercase">...</span>
            </div>

            <div id="schedule_cards_wrap" class="flex-1 overflow-y-auto p-4 space-y-3">
                <div class="rounded-xl border border-[#8c7b6d]/30 bg-[#1c283d] p-4 text-sm text-[#e4dbd2]">
                    Memuat daftar dokter...
                </div>
            </div>
        </aside>
    </div>

    <!-- FOOTER RUNNING TEXT -->
    <footer class="bg-[#141d2e] border-t-2 border-[#c7ab97] h-16 flex items-stretch shrink-0 z-20">
        <div class="bg-[#473d35] px-5 sm:px-7 flex items-center justify-center shrink-0 border-r border-[#c7ab97]/35">
            <span class="text-[#f5efe9] text-sm sm:text-base font-extrabold uppercase tracking-wider">Info Poliklinik</span>
        </div>
        <div class="marquee-wrapper flex-1">
            <div id="teks_marquee_gabungan" class="marquee-content text-xl sm:text-2xl font-bold text-slate-100">
                Menyinkronkan jadwal dokter...
            </div>
        </div>
    </footer>

    <script>
        const videoPlayer = document.getElementById('player_promosi_video');
        const imagePlayer = document.getElementById('player_promosi_image');
        const loadingMsg  = document.getElementById('loading_media_msg');
        const emptyMsg    = document.getElementById('no_video_msg');
        const apiErrorEl  = document.getElementById('api-error-banner');
        const unmuteBtn   = document.getElementById('unmute-overlay');

        let playlist = [];
        let currentVidIndex = 0;
        let playCount = 0;
        let imageTimer = null;
        let errorSkipTimer = null;
        let audioUnlocked = false;
        let lastFetchDate = '';
        let mapSett = { lat: '', lng: '', method: '' };
        let stringDokter = '';
        let cacheMarquee = '';
        let cacheAside = '';
        let hasLoadedOnce = false;
        let cursorTimer = null;

        function escapeHtml(str) {
            return String(str ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function resetCursorIdle() {
            document.body.classList.remove('cursor-hidden');
            clearTimeout(cursorTimer);
            cursorTimer = setTimeout(() => {
                document.body.classList.add('cursor-hidden');
            }, 3000);
        }
        document.addEventListener('mousemove', resetCursorIdle);
        resetCursorIdle();

        function enableAudio() {
            if (audioUnlocked) return;
            audioUnlocked = true;
            videoPlayer.muted = false;
            unmuteBtn.classList.add('hidden');
            if (!videoPlayer.classList.contains('hidden')) {
                videoPlayer.play().catch(() => {});
            }
        }

        function handleLogoError() {
            document.getElementById('logo_rs').classList.add('hidden');
            document.getElementById('default_logo').classList.remove('hidden');
        }

        function updateClock() {
            const now = new Date();
            const hh = String(now.getHours()).padStart(2, '0');
            const mm = String(now.getMinutes()).padStart(2, '0');
            const ss = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('txt-clock').textContent = `${hh}:${mm}`;
            document.getElementById('txt-seconds').textContent = `:${ss}`;
            document.getElementById('txt-date').textContent = now.toLocaleDateString('id-ID', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
        }
        setInterval(updateClock, 1000);
        updateClock();

        function showEmptyMedia() {
            loadingMsg.classList.add('hidden');
            videoPlayer.pause();
            videoPlayer.removeAttribute('src');
            videoPlayer.classList.add('opacity-0', 'hidden');
            imagePlayer.classList.add('opacity-0', 'hidden');
            emptyMsg.classList.remove('hidden');
            emptyMsg.classList.add('flex');
        }

        function advanceMediaIndex() {
            playCount = 0;
            currentVidIndex++;
            if (currentVidIndex >= playlist.length) currentVidIndex = 0;
        }

        function playNextMedia() {
            clearTimeout(imageTimer);
            clearTimeout(errorSkipTimer);
            imageTimer = null;

            if (!Array.isArray(playlist) || playlist.length === 0) {
                showEmptyMedia();
                return;
            }

            loadingMsg.classList.add('hidden');
            emptyMsg.classList.add('hidden');
            emptyMsg.classList.remove('flex');

            if (currentVidIndex >= playlist.length) {
                currentVidIndex = 0;
                playCount = 0;
            }

            let targetMedia = playlist[currentVidIndex];
            const maxPlays = Math.max(1, parseInt(targetMedia.putar_per_sesi, 10) || 1);

            if (targetMedia.tipe === 'video' && playCount >= maxPlays) {
                advanceMediaIndex();
                targetMedia = playlist[currentVidIndex];
            } else if (targetMedia.tipe === 'image' && playCount > 0) {
                advanceMediaIndex();
                targetMedia = playlist[currentVidIndex];
            }

            if (targetMedia.tipe === 'image') {
                videoPlayer.pause();
                videoPlayer.classList.add('opacity-0');
                setTimeout(() => {
                    if (playlist[currentVidIndex]?.tipe === 'image') videoPlayer.classList.add('hidden');
                }, 450);

                imagePlayer.src = targetMedia.url_video;
                imagePlayer.alt = targetMedia.judul || 'Media Informasi Rumah Sakit';
                imagePlayer.classList.remove('hidden');
                requestAnimationFrame(() => imagePlayer.classList.remove('opacity-0'));

                const dur = Math.max(3, parseInt(targetMedia.durasi, 10) || 15);
                playCount = 1;
                imageTimer = setTimeout(playNextMedia, dur * 1000);
            } else {
                imagePlayer.classList.add('opacity-0');
                setTimeout(() => {
                    if (playlist[currentVidIndex]?.tipe === 'video') imagePlayer.classList.add('hidden');
                }, 450);

                const curSrc = videoPlayer.getAttribute('src') || '';
                if (curSrc !== targetMedia.url_video) {
                    videoPlayer.src = targetMedia.url_video;
                }
                videoPlayer.classList.remove('hidden');
                requestAnimationFrame(() => videoPlayer.classList.remove('opacity-0'));

                videoPlayer.muted = !audioUnlocked;
                const playPromise = videoPlayer.play();
                if (playPromise !== undefined) {
                    playPromise.catch(() => {
                        videoPlayer.muted = true;
                        videoPlayer.play().catch(() => {
                            if (playlist.length > 1) {
                                errorSkipTimer = setTimeout(() => {
                                    advanceMediaIndex();
                                    playNextMedia();
                                }, 2000);
                            }
                        });
                    });
                }
                playCount++;
            }
        }

        videoPlayer.addEventListener('ended', playNextMedia);
        videoPlayer.addEventListener('error', () => {
            if (playlist.length > 1) {
                errorSkipTimer = setTimeout(() => {
                    advanceMediaIndex();
                    playNextMedia();
                }, 2000);
            }
        });
        imagePlayer.addEventListener('error', () => {
            if (playlist.length > 1) {
                clearTimeout(imageTimer);
                errorSkipTimer = setTimeout(() => {
                    advanceMediaIndex();
                    playNextMedia();
                }, 2000);
            }
        });

        function updateMarquee() {
            if (stringDokter !== cacheMarquee && stringDokter !== '') {
                cacheMarquee = stringDokter;
                document.getElementById('teks_marquee_gabungan').innerHTML = stringDokter;
            }
        }

        function fetchSholat(lat, lng, method) {
            const today = new Date().toLocaleDateString('id-ID');
            if (lastFetchDate === today && mapSett.lat == lat && mapSett.lng == lng && mapSett.method == method) return;
            if (!lat || !lng) return;

            fetch(`https://api.aladhan.com/v1/timings/today?latitude=${encodeURIComponent(lat)}&longitude=${encodeURIComponent(lng)}&method=${encodeURIComponent(method || 11)}`)
                .then(r => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(d => {
                    if (!d || !d.data || !d.data.timings) return;
                    const t = d.data.timings;
                    const items = [
                        ['Subuh', t.Fajr],
                        ['Dzuhur', t.Dhuhr],
                        ['Ashar', t.Asr],
                        ['Maghrib', t.Maghrib],
                        ['Isya', t.Isha]
                    ];
                    document.getElementById('sholat_times').innerHTML = items.map(([label, time]) =>
                        `<span class="inline-flex items-center gap-1"><span class="text-[#e4dbd2] font-medium">${label}</span> <span class="text-white font-bold">${escapeHtml(time)}</span></span>`
                    ).join('');
                    document.getElementById('sholat_bar').classList.remove('hidden');
                    lastFetchDate = today;
                    mapSett = { lat, lng, method };
                })
                .catch(() => {});
        }

        function getStatusChipClass(status) {
            if (status === 'praktek') return 'bg-emerald-950/90 text-emerald-300 border-emerald-600/60';
            if (status === 'mundur')  return 'bg-amber-950/90 text-amber-300 border-amber-500/60';
            if (status === 'cuti')    return 'bg-rose-950/90 text-rose-300 border-rose-500/60';
            if (status === 'diganti') return 'bg-indigo-950/90 text-indigo-300 border-indigo-500/60';
            return 'bg-slate-800 text-slate-200 border-slate-600';
        }

        function renderScheduleAside(jadwal, hariIni) {
            const key = JSON.stringify({ jadwal, hariIni });
            if (key === cacheAside) return;
            cacheAside = key;

            const container = document.getElementById('schedule_cards_wrap');
            const entries = Object.entries(jadwal || {});
            if (entries.length === 0) {
                container.innerHTML = `
                    <div class="rounded-xl border border-[#8c7b6d]/30 bg-[#1c283d] p-5 text-center">
                        <p class="text-sm font-bold text-white">Belum ada jadwal praktek</p>
                        <p class="text-xs text-[#e4dbd2] mt-1">Tidak ada jadwal poliklinik aktif untuk hari ${escapeHtml(hariIni || 'ini')}.</p>
                    </div>
                `;
                return;
            }

            container.innerHTML = entries.map(([poli, doks]) => {
                const rows = doks.map(dok => {
                    const chipClass = getStatusChipClass(dok.status);
                    return `
                        <div class="py-2.5 border-t border-[#8c7b6d]/25 first:border-t-0 first:pt-0 last:pb-0">
                            <p class="text-sm font-bold text-white leading-snug">${escapeHtml(dok.nama_dokter)}</p>
                            <span class="mt-1 inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-bold ${chipClass}">
                                ${escapeHtml(dok.detail_kondisi)}
                            </span>
                        </div>
                    `;
                }).join('');
                return `
                    <div class="rounded-xl border border-[#8c7b6d]/35 bg-[#1c283d] p-3.5 space-y-2.5">
                        <div class="text-xs font-extrabold uppercase tracking-wider text-[#c7ab97]">${escapeHtml(poli)}</div>
                        <div>${rows}</div>
                    </div>
                `;
            }).join('');
        }

        // Auto-scroll pelan panel samping jika daftar dokter melebihi tinggi panel
        setInterval(() => {
            const wrap = document.getElementById('schedule_cards_wrap');
            if (!wrap || wrap.scrollHeight <= wrap.clientHeight + 4) return;
            if (wrap.scrollTop + wrap.clientHeight >= wrap.scrollHeight - 2) {
                wrap.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                wrap.scrollBy({ top: 120, behavior: 'smooth' });
            }
        }, 5000);

        function fetchUpdates() {
            fetch('api.php?action=get_display_signage', { cache: 'no-store' })
                .then(r => {
                    if (!r.ok) throw new Error('API error');
                    return r.json();
                })
                .then(data => {
                    apiErrorEl.classList.add('hidden');

                    if (data.pengaturan) {
                        const sett = data.pengaturan;
                        const speed = Math.max(10, parseInt(sett.kecepatan_jadwal, 10) || 45);
                        document.getElementById('teks_marquee_gabungan').style.animationDuration = `${speed}s`;

                        const namaEl = document.getElementById('nama_rs');
                        namaEl.textContent = sett.nama_rs || 'RSUI BANYUBENING';
                        namaEl.style.fontSize = Math.min(44, Math.max(20, parseInt(sett.nama_size, 10) || 30)) + 'px';

                        const logoImg = document.getElementById('logo_rs');
                        const defaultLogo = document.getElementById('default_logo');
                        const logoPath = (sett.logo_path || '').trim();
                        if (logoPath !== '') {
                            if (logoImg.getAttribute('src') !== logoPath) {
                                logoImg.src = logoPath;
                            }
                            const logoSize = Math.min(72, Math.max(36, parseInt(sett.logo_size, 10) || 56));
                            logoImg.style.width = logoSize + 'px';
                            logoImg.style.height = logoSize + 'px';
                            logoImg.classList.remove('hidden');
                            defaultLogo.classList.add('hidden');
                        } else {
                            logoImg.classList.add('hidden');
                            defaultLogo.classList.remove('hidden');
                        }

                        fetchSholat(sett.lat, sett.lng, sett.metode_sholat);
                    }

                    if (data.hari_ini) {
                        document.getElementById('label_hari_ini').textContent = 'Jadwal Praktek: Hari ' + data.hari_ini;
                        document.getElementById('aside_hari_badge').textContent = data.hari_ini;
                    }

                    const newVideos = Array.isArray(data.videos) ? data.videos : [];
                    if (!hasLoadedOnce || JSON.stringify(newVideos) !== JSON.stringify(playlist)) {
                        const wasEmpty = playlist.length === 0;
                        playlist = newVideos;
                        hasLoadedOnce = true;

                        if (playlist.length === 0) {
                            clearTimeout(imageTimer);
                            showEmptyMedia();
                        } else if (wasEmpty || (videoPlayer.paused && !imageTimer)) {
                            playCount = 0;
                            currentVidIndex = 0;
                            playNextMedia();
                        } else if (currentVidIndex >= playlist.length) {
                            currentVidIndex = 0;
                            playCount = 0;
                        }
                    }

                    const jadwal = data.jadwal || {};
                    renderScheduleAside(jadwal, data.hari_ini);

                    const entries = Object.entries(jadwal);
                    if (entries.length === 0) {
                        stringDokter = `<span class="mx-6 text-slate-300 font-semibold">Belum ada jadwal praktek dokter untuk hari ${escapeHtml(data.hari_ini || 'ini')}.</span>`;
                    } else {
                        stringDokter = entries.map(([poli, doks]) => {
                            const poliBadge = `<span class="inline-flex items-center rounded-lg bg-[#473d35] border border-[#c7ab97]/50 text-[#f5efe9] px-3.5 py-0.5 text-base font-extrabold uppercase tracking-wide mr-3">${escapeHtml(poli)}</span>`;
                            const docItems = doks.map(dok => {
                                const chipClass = getStatusChipClass(dok.status);
                                return `<span class="inline-flex items-center gap-2 mr-5"><span class="text-white font-bold">${escapeHtml(dok.nama_dokter)}</span><span class="rounded-md border px-2 py-0.5 text-sm font-bold ${chipClass}">${escapeHtml(dok.detail_kondisi)}</span></span>`;
                            }).join('<span class="text-[#8c7b6d] mr-5">&bull;</span>');
                            return `<span class="inline-flex items-center mr-10">${poliBadge}${docItems}</span>`;
                        }).join('');
                    }
                    updateMarquee();
                })
                .catch(() => {
                    apiErrorEl.classList.remove('hidden');
                    loadingMsg.classList.add('hidden');
                });
        }

        fetchUpdates();
        setInterval(fetchUpdates, 3000);
    </script>
</body>
</html>

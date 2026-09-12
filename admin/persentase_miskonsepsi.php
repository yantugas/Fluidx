<?php

session_start();

include '../config/database.php';


/* =========================================================
   CEK LOGIN ADMIN
========================================================= */

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login_admin.php");
    exit;
}

$nama_admin = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Admin';


/* =========================================================
   KONFIGURASI DIAGNOSIS FOUR-TIER

   Tier 1 (Jawaban)   : benar jika SEMUA pernyataan pada soal
                         dijawab tepat sesuai kunci.
   Tier 2 (Keyakinan Jawaban) : yakin / tidak_yakin
   Tier 3 (Alasan)    : dinilai manual oleh admin (0-100).
                         Dianggap BENAR jika skor >= KKM_TIER3.
   Tier 4 (Keyakinan Alasan)  : yakin / tidak_yakin

   KOMBINASI DIAGNOSIS:
   - Paham Konsep   : Tier1 benar & Tier2 yakin & Tier3 benar & Tier4 yakin
   - Miskonsepsi    : Tier2 yakin & Tier4 yakin, TAPI Tier1 dan/atau
                       Tier3 SALAH (yakin tapi jawaban/alasan keliru)
   - Tidak Paham    : selain dua kondisi di atas (ada ketidakyakinan
                       pada Tier2 dan/atau Tier4 -> menebak / kurang paham)
   - Belum Lengkap  : Tier 1/2/3/4 belum lengkap diisi/dinilai,
                       sehingga belum bisa didiagnosis.
========================================================= */

define('KKM_TIER3', 70);


/* =========================================================
   FUNGSI: HITUNG DIAGNOSIS MISKONSEPSI (REALTIME)

   Dibungkus try/catch supaya kalau ada error SQL (mis. nama kolom
   tabel di database Anda berbeda dari yang diharapkan), halaman
   TIDAK ikut error/blank/macet. Pesan error asli akan dikirim ke
   frontend supaya langsung kelihatan di tabel & console browser
   (F12), jadi gampang dilacak penyebabnya.
========================================================= */

function ambilDataMiskonsepsi(mysqli $conn): array
{
    $default = [
        'updated_at'                => date('H:i:s'),
        'total_siswa_mengerjakan'   => 0,
        'total_konsep'              => 0,
        'siswa_miskonsepsi_manapun' => 0,
        'persentase_keseluruhan'    => 0.0,
        'ringkasan_global'          => [
            'paham' => 0, 'miskonsepsi' => 0, 'tidak_paham' => 0, 'belum_lengkap' => 0,
        ],
        'kkm_tier3'  => KKM_TIER3,
        'per_konsep' => [],
        'error'      => null,
    ];

    try {
        return ambilDataMiskonsepsiInner($conn) + $default;
    } catch (\Throwable $e) {
        // Kembalikan error asli (pesan SQL/PHP) supaya bisa langsung
        // dilihat di tabel & console, bukan cuma "Memuat data..." selamanya.
        $default['error'] = $e->getMessage();
        return $default;
    }
}

/**
 * Wrapper mysqli_query yang melempar error dengan pesan SQL asli
 * kalau query gagal (misal nama tabel/kolom tidak cocok dengan
 * database Anda), supaya pesannya jelas & bisa dilacak.
 */
function q(mysqli $conn, string $sql)
{
    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        throw new \RuntimeException('Query gagal: ' . mysqli_error($conn));
    }
    return $result;
}

function ambilDataMiskonsepsiInner(mysqli $conn): array
{
    // --- 1. Daftar konsep dari Kelola Soal (semua konsep ikut tampil,
    //         walau belum ada data miskonsepsi sama sekali) ---
    $daftar_konsep = [];
    $res_konsep = q($conn, "
        SELECT c.id, c.concept_name,
               (SELECT COUNT(*) FROM questions q
                 WHERE q.concept_id = c.id AND q.is_active = 1) AS jumlah_soal
        FROM concepts c
        ORDER BY c.concept_name ASC
    ");
    if ($res_konsep) {
        while ($row = mysqli_fetch_assoc($res_konsep)) {
            $daftar_konsep[(int) $row['id']] = [
                'concept_id'    => (int) $row['id'],
                'concept_name'  => $row['concept_name'],
                'jumlah_soal'   => (int) $row['jumlah_soal'],
                'siswa_miskonsepsi' => [], // set user_id
                'jumlah_jawaban'    => 0,
                'jumlah_belum_lengkap' => 0,
            ];
        }
    }

    // --- 2. Total siswa yang sudah mengerjakan quiz (N_siswa global) ---
    $total_siswa_mengerjakan = 0;
    $res_total = q($conn, "
        SELECT COUNT(DISTINCT user_id) AS total FROM student_answers
    ");
    if ($res_total) {
        $total_siswa_mengerjakan = (int) (mysqli_fetch_assoc($res_total)['total'] ?? 0);
    }

    // --- 3. Peta Tier 1 (per user + soal): lengkap & benar semua atau tidak ---
    $tier1_map = [];
    $res_tier1 = q($conn, "
        SELECT
            ssa.user_id,
            ssa.question_id,
            COUNT(*) AS dijawab,
            SUM(ssa.is_correct) AS jumlah_benar,
            (SELECT COUNT(*) FROM question_statements qs
              WHERE qs.question_id = ssa.question_id) AS total_pernyataan
        FROM student_statement_answers ssa
        GROUP BY ssa.user_id, ssa.question_id
    ");
    if ($res_tier1) {
        while ($row = mysqli_fetch_assoc($res_tier1)) {
            $key = $row['user_id'] . '-' . $row['question_id'];
            $tier1_map[$key] = [
                'lengkap' => ((int) $row['total_pernyataan'] > 0)
                             && ((int) $row['dijawab'] >= (int) $row['total_pernyataan']),
                'benar'   => ((int) $row['jumlah_benar'] === (int) $row['total_pernyataan'])
                             && ((int) $row['total_pernyataan'] > 0),
            ];
        }
    }

    // --- 4. Data utama: Tier 2, 3, 4 per (user, soal) + konsep soal ---
    $ringkasan_global = [
        'paham'         => 0,
        'miskonsepsi'   => 0,
        'tidak_paham'   => 0,
        'belum_lengkap' => 0,
    ];
    $siswa_miskonsepsi_manapun = []; // set user_id (miskonsepsi di konsep manapun)

    $res_jawaban = q($conn, "
        SELECT
            sa.user_id,
            sa.question_id,
            sa.confidence_answer,
            sa.reason_score,
            sa.confidence_reason,
            q.concept_id,
            c.concept_name
        FROM student_answers sa
        INNER JOIN questions q ON q.id = sa.question_id
        LEFT JOIN concepts c ON c.id = q.concept_id
    ");

    if ($res_jawaban) {
        while ($row = mysqli_fetch_assoc($res_jawaban)) {

            $key = $row['user_id'] . '-' . $row['question_id'];
            $t1  = $tier1_map[$key] ?? ['lengkap' => false, 'benar' => false];

            $tier2 = $row['confidence_answer'];   // yakin / tidak_yakin / null
            $tier4 = $row['confidence_reason'];   // yakin / tidak_yakin / null
            $skor3 = $row['reason_score'];        // 0-100 / null

            $lengkap = $t1['lengkap']
                && $tier2 !== null
                && $tier4 !== null
                && $skor3 !== null;

            if (!$lengkap) {
                $kategori = 'belum_lengkap';
            } else {
                $tier3_benar = ((int) $skor3 >= KKM_TIER3);

                if ($t1['benar'] && $tier2 === 'yakin' && $tier3_benar && $tier4 === 'yakin') {
                    $kategori = 'paham';
                } elseif ($tier2 === 'yakin' && $tier4 === 'yakin') {
                    $kategori = 'miskonsepsi';
                } else {
                    $kategori = 'tidak_paham';
                }
            }

            $ringkasan_global[$kategori]++;

            $cid = $row['concept_id'] !== null ? (int) $row['concept_id'] : 0;

            if (!isset($daftar_konsep[$cid])) {
                $daftar_konsep[$cid] = [
                    'concept_id'   => $cid,
                    'concept_name' => $row['concept_name'] ?? 'Tanpa Konsep',
                    'jumlah_soal'  => 0,
                    'siswa_miskonsepsi'    => [],
                    'jumlah_jawaban'       => 0,
                    'jumlah_belum_lengkap' => 0,
                ];
            }

            $daftar_konsep[$cid]['jumlah_jawaban']++;

            if ($kategori === 'belum_lengkap') {
                $daftar_konsep[$cid]['jumlah_belum_lengkap']++;
            }

            if ($kategori === 'miskonsepsi') {
                $daftar_konsep[$cid]['siswa_miskonsepsi'][$row['user_id']] = true;
                $siswa_miskonsepsi_manapun[$row['user_id']] = true;
            }
        }
    }

    // --- 5. Susun output per konsep + rumus P = N_miskonsepsi / N_siswa x 100% ---
    $per_konsep = [];

    foreach ($daftar_konsep as $cid => $d) {

        $n_miskonsepsi = count($d['siswa_miskonsepsi']);

        $persentase = $total_siswa_mengerjakan > 0
            ? round(($n_miskonsepsi / $total_siswa_mengerjakan) * 100, 1)
            : 0.0;

        $per_konsep[] = [
            'concept_id'           => $cid,
            'concept_name'         => $d['concept_name'],
            'jumlah_soal'          => $d['jumlah_soal'],
            'n_miskonsepsi'        => $n_miskonsepsi,
            'total_siswa'          => $total_siswa_mengerjakan,
            'persentase'           => $persentase,
            'jumlah_jawaban'       => $d['jumlah_jawaban'],
            'jumlah_belum_lengkap' => $d['jumlah_belum_lengkap'],
            'formula'              => "P = {$n_miskonsepsi}/{$total_siswa_mengerjakan} x 100% = {$persentase}%",
        ];
    }

    usort($per_konsep, function ($a, $b) {
        return $b['persentase'] <=> $a['persentase'];
    });

    $persentase_keseluruhan = $total_siswa_mengerjakan > 0
        ? round((count($siswa_miskonsepsi_manapun) / $total_siswa_mengerjakan) * 100, 1)
        : 0.0;

    return [
        'updated_at'              => date('H:i:s'),
        'total_siswa_mengerjakan' => $total_siswa_mengerjakan,
        'total_konsep'            => count($per_konsep),
        'siswa_miskonsepsi_manapun' => count($siswa_miskonsepsi_manapun),
        'persentase_keseluruhan'  => $persentase_keseluruhan,
        'ringkasan_global'        => $ringkasan_global,
        'kkm_tier3'               => KKM_TIER3,
        'per_konsep'              => $per_konsep,
    ];
}


/* =========================================================
   AJAX / REAL-TIME DATA
========================================================= */

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        ambilDataMiskonsepsi($conn),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}


/* =========================================================
   DATA AWAL (render pertama)
========================================================= */

$data_awal = ambilDataMiskonsepsi($conn);

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persentase Miskonsepsi - Quiz Fluida Statis</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-common.css">
    <link rel="stylesheet" href="../assets/css/admin-layout.css">
    <link rel="stylesheet" href="../assets/css/persentase_miskonsepsi.css">

    <!--
      Chart.js TIDAK di-load langsung di sini lagi.
      Sebelumnya hanya 1 sumber (cdnjs) dipakai - kalau sumber itu
      diblokir/lambat/unreachable dari browser admin, chart gagal
      total (itulah pesan error yang muncul di layar).
      Sekarang pemuatannya dipindah ke loader multi-sumber di script
      utama (lihat fungsi muatChartJs), yang mencoba beberapa CDN
      + file lokal secara berurutan sampai salah satu berhasil.
    -->
</head>

<script>
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}
</script>

<body>

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="logo">
            <div class="logo-icon"><i class="fa-solid fa-flask"></i></div>
            <div class="logo-text">
                <h2>Quiz Fluida Statis</h2>
                <p>Panel Admin</p>
            </div>
        </div>

        <div class="menu-title">Menu Utama</div>

        <ul class="menu">
            <li>
                <a href="dashboard_admin.php">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="kelola_soal.php">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Kelola Soal</span>
                </a>
            </li>
            <li>
                <a href="kelola_materi.php">
                    <i class="fa-solid fa-file-arrow-up"></i>
                    <span>Kelola Materi</span>
                </a>
            </li>
            <li>
                <a href="jawaban_siswa.php">
                    <i class="fa-solid fa-users"></i>
                    <span>Jawaban Siswa</span>
                </a>
            </li>
            <li>
                <a href="monitoring_pelanggaran.php">
                    <i class="fa-solid fa-eye"></i>
                    <span>Monitoring Pelanggaran</span>
                </a>
            </li>
            <li>
                <a href="score_siswa.php">
                    <i class="fa-solid fa-trophy"></i>
                    <span>Score Siswa</span>
                </a>
            </li>
            <li>
                <a href="persentase_miskonsepsi.php" class="active">
                    <i class="fa-solid fa-chart-column"></i>
                    <span>Persentase Miskonsepsi</span>
                </a>
            </li>
            <li>
                <a href="miskonsepsi_terbanyak.php">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Miskonsepsi Terbanyak</span>
                </a>
            </li>
        </ul>

        <div class="logout">
            <a href="../logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </div>

    </aside>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>


    <!-- MAIN CONTENT -->
    <main class="main">
        <button class="menu-toggle" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>

        <div class="header">
            <div>
                <h1>Persentase Miskonsepsi</h1>
                <p>Diagnosis four-tier per konsep, mengikuti data Kelola Soal secara realtime.</p>
            </div>

            <div class="admin-profile">
                <i class="fa-solid fa-user"></i>
                <span><?= htmlspecialchars($nama_admin); ?></span>
            </div>
        </div>

        <?php if (!empty($data_awal['error'])): ?>
            <div class="alert-box error" id="mkErrorBanner">
                <strong>Gagal memuat data miskonsepsi:</strong>
                <?= htmlspecialchars($data_awal['error']); ?>
                <br>
                Cek nama tabel/kolom di database (concepts, questions, question_statements,
                student_statement_answers, student_answers) apakah sudah sesuai migrasi.
            </div>
        <?php endif; ?>

        <!-- KARTU RINGKASAN -->
        <div class="mk-cards">

            <div class="mk-card">
                <div class="mk-card-icon teal"><i class="fa-solid fa-users"></i></div>
                <div>
                    <p>Total Siswa Mengerjakan</p>
                    <h3 id="cardTotalSiswa"><?= $data_awal['total_siswa_mengerjakan']; ?></h3>
                </div>
            </div>

            <div class="mk-card">
                <div class="mk-card-icon blue"><i class="fa-solid fa-layer-group"></i></div>
                <div>
                    <p>Total Konsep</p>
                    <h3 id="cardTotalKonsep"><?= $data_awal['total_konsep']; ?></h3>
                </div>
            </div>

            <div class="mk-card">
                <div class="mk-card-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <p>Persentase Miskonsepsi Keseluruhan</p>
                    <h3 id="cardPersentaseKeseluruhan"><?= $data_awal['persentase_keseluruhan']; ?>%</h3>
                </div>
            </div>

            <div class="mk-card">
                <div class="mk-card-icon gray"><i class="fa-solid fa-hourglass-half"></i></div>
                <div>
                    <p>Jawaban Belum Lengkap Dinilai</p>
                    <h3 id="cardBelumLengkap"><?= $data_awal['ringkasan_global']['belum_lengkap']; ?></h3>
                </div>
            </div>

        </div>

        <!-- CHART + TABEL -->
        <div class="mk-layout">

            <!-- PIE CHART -->
            <div class="panel mk-chart-panel">
                <div class="panel-head">
                    <h2>Distribusi Miskonsepsi per Konsep</h2>
                    <span class="live-dot" id="lastUpdate">Terakhir diperbarui: <?= $data_awal['updated_at']; ?></span>
                </div>

                <div class="mk-chart-wrap">
                    <canvas id="chartMiskonsepsi"></canvas>
                </div>

                <p class="mk-note" id="chartEmptyNote" style="display:none;">
                    Belum ada data miskonsepsi untuk ditampilkan.
                </p>
            </div>

            <!-- TABEL DETAIL -->
            <div class="panel mk-table-panel">
                <div class="panel-head">
                    <h2>Rincian per Konsep</h2>
                </div>

                <p class="mk-formula-info">
                    Rumus: <strong>P = N<sub>miskonsepsi</sub> / N<sub>siswa</sub> &times; 100%</strong>
                    &mdash; alasan (Tier 3) dianggap benar jika nilai &ge; <?= KKM_TIER3; ?>.
                </p>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Konsep</th>
                                <th>Jumlah Siswa Miskonsepsi</th>
                                <th>Persentase</th>
                                <th>Rumus</th>
                            </tr>
                        </thead>
                        <tbody id="tabelKonsepBody">
                            <tr><td colspan="4" class="empty-row">Memuat data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>


<script>
/* =========================================================
   DATA AWAL DARI PHP (render pertama, tanpa perlu fetch dulu)
========================================================= */

const dataAwal = <?= json_encode(
    $data_awal,
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
) ?: 'null'; ?>;


/* =========================================================
   WARNA UNTUK TIAP SLICE PIE CHART
========================================================= */

const paletWarna = [
    '#32b9bf', '#ff7676', '#ffb84d', '#5c7cfa',
    '#a78bfa', '#51cf66', '#ff9ff3', '#ffa94d',
    '#4dabf7', '#f783ac', '#69db7c', '#e599f7'
];

function warnaKe(index) {
    return paletWarna[index % paletWarna.length];
}


/* =========================================================
   LOADER CHART.JS - MULTI SUMBER (fallback berantai)

   Sebelumnya Chart.js cuma dicoba dari 1 CDN (cdnjs). Kalau CDN
   itu unreachable dari browser admin (firewall, DNS, region
   blocking, dsb), chart langsung gagal total.

   Sekarang dicoba berurutan:
     1) File lokal di assets/js/chart.umd.min.js
        (kalau sudah di-upload sendiri ke server -> PALING andal,
        tidak butuh internet sama sekali)
     2) jsDelivr
     3) cdnjs
     4) unpkg

   Begitu salah satu berhasil, langsung dipakai. Kalau semuanya
   gagal, chart dilewati dengan pesan yang jelas (tabel & kartu
   ringkasan tetap jalan seperti biasa).
========================================================= */

function muatChartJs() {
    const sumberChartJs = [
        '../assets/js/chart.umd.min.js',
        'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
        'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js',
        'https://unpkg.com/chart.js@4.4.4/dist/chart.umd.min.js'
    ];

    return new Promise((resolve) => {
        let i = 0;

        function cobaSumberBerikutnya() {
            if (typeof Chart !== 'undefined') {
                resolve(true);
                return;
            }
            if (i >= sumberChartJs.length) {
                resolve(false);
                return;
            }

            const url = sumberChartJs[i];
            i++;

            const tag = document.createElement('script');
            tag.src = url;
            tag.onload = () => {
                console.log('Chart.js berhasil dimuat dari: ' + url);
                resolve(true);
            };
            tag.onerror = () => {
                console.warn('Chart.js gagal dimuat dari: ' + url + ' - mencoba sumber berikutnya...');
                cobaSumberBerikutnya();
            };
            document.head.appendChild(tag);
        }

        cobaSumberBerikutnya();
    });
}


/* =========================================================
   INISIALISASI CHART.JS (PIE)

   Dibungkus try/catch: kalau SEMUA sumber Chart.js gagal dimuat
   (mis. tidak ada koneksi internet sama sekali & file lokal
   belum di-upload), tabel & realtime TETAP jalan. Hanya bagian
   chart yang dilewati + diberi pesan.
========================================================= */

let chartMiskonsepsi = null;

async function inisialisasiChart() {
    try {
        const berhasil = await muatChartJs();

        if (!berhasil || typeof Chart === 'undefined') {
            throw new Error(
                'Semua sumber Chart.js gagal dimuat (cek koneksi internet server/browser, ' +
                'atau upload chart.umd.min.js ke folder assets/js/ agar tidak bergantung pada internet).'
            );
        }

        const ctx = document.getElementById('chartMiskonsepsi').getContext('2d');

        chartMiskonsepsi = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: [],
                    borderColor: '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 14, padding: 14 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (item) {
                                const nilai = item.raw;
                                return item.label + ': ' + nilai + '% siswa miskonsepsi';
                            }
                        }
                    }
                }
            }
        });

        // Chart baru siap sekarang -> render ulang pakai data yang sudah ada
        // (data awal dari PHP, atau data terakhir dari polling kalau sudah sempat jalan)
        renderAll(dataTerakhir || dataAwal);

    } catch (error) {
        console.error('Gagal membuat pie chart:', error);
        const note = document.getElementById('chartEmptyNote');
        note.textContent = 'Chart tidak bisa ditampilkan: ' + error.message;
        note.style.display = 'block';
    }
}

let dataTerakhir = null;
inisialisasiChart();


/* =========================================================
   RENDER SEMUA (dipanggil saat load pertama & tiap update realtime)

   Setiap bagian dibungkus supaya satu bagian gagal tidak
   membuat bagian lain (tabel, kartu) ikut macet.
========================================================= */

function renderAll(data) {

    if (!data) {
        console.error('renderAll: data kosong/null.');
        return;
    }

    // Simpan data terakhir supaya begitu Chart.js selesai dimuat
    // (async, bisa belakangan), chart langsung bisa diisi tanpa
    // perlu menunggu polling berikutnya.
    dataTerakhir = data;

    // --- Pesan error dari server (kalau ada) ---
    if (data.error) {
        console.error('Error dari server:', data.error);
    }

    // --- Kartu ringkasan ---
    try {
        document.getElementById('cardTotalSiswa').textContent = data.total_siswa_mengerjakan ?? 0;
        document.getElementById('cardTotalKonsep').textContent = data.total_konsep ?? 0;
        document.getElementById('cardPersentaseKeseluruhan').textContent = (data.persentase_keseluruhan ?? 0) + '%';
        document.getElementById('cardBelumLengkap').textContent = data.ringkasan_global?.belum_lengkap ?? 0;
    } catch (error) {
        console.error('Gagal update kartu ringkasan:', error);
    }

    const perKonsep = data.per_konsep || [];

    // --- Pie chart ---
    if (chartMiskonsepsi) {
        try {
            const adaData = perKonsep.some(k => k.n_miskonsepsi > 0);

            chartMiskonsepsi.data.labels = perKonsep.map(k => k.concept_name);
            chartMiskonsepsi.data.datasets[0].data = perKonsep.map(k => k.persentase);
            chartMiskonsepsi.data.datasets[0].backgroundColor = perKonsep.map((_, i) => warnaKe(i));
            chartMiskonsepsi.update();

            document.getElementById('chartEmptyNote').style.display =
                (perKonsep.length === 0 || !adaData) ? 'block' : 'none';

            if (perKonsep.length === 0 || !adaData) {
                document.getElementById('chartEmptyNote').textContent =
                    'Belum ada data miskonsepsi untuk ditampilkan.';
            }
        } catch (error) {
            console.error('Gagal update pie chart:', error);
        }
    }

    // --- Tabel rincian ---
    try {
        const tbody = document.getElementById('tabelKonsepBody');

        if (data.error) {
            tbody.innerHTML = `<tr><td colspan="4" class="empty-row">
                Gagal memuat data: ${data.error}
            </td></tr>`;
        } else if (perKonsep.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="empty-row">Belum ada konsep di Kelola Soal.</td></tr>';
        } else {
            tbody.innerHTML = perKonsep.map((k, i) => `
                <tr>
                    <td>
                        <span class="mk-dot" style="background:${warnaKe(i)}"></span>
                        ${k.concept_name}
                    </td>
                    <td>${k.n_miskonsepsi} dari ${k.total_siswa} siswa</td>
                    <td><span class="mk-badge">${k.persentase}%</span></td>
                    <td class="mk-formula-cell">${k.formula}</td>
                </tr>
            `).join('');
        }
    } catch (error) {
        console.error('Gagal update tabel:', error);
    }

    // --- Waktu update ---
    try {
        document.getElementById('lastUpdate').textContent =
            (data.error ? 'Update terakhir gagal (' : 'Terakhir diperbarui: ')
            + data.updated_at + (data.error ? ')' : '');
    } catch (error) {
        console.error('Gagal update waktu:', error);
    }
}


/* =========================================================
   REAL-TIME: POLLING SETIAP 5 DETIK

   Mengikuti data terbaru dari Kelola Soal & Jawaban Siswa
   tanpa perlu reload halaman. setInterval didaftarkan lebih
   dulu (sebelum render pertama) supaya polling TETAP jalan
   walau render pertama gagal karena sebab apa pun.
========================================================= */

async function perbaruiMiskonsepsi() {
    try {
        const response = await fetch(
            'persentase_miskonsepsi.php?ajax=1&_=' + Date.now(),
            {
                method: 'GET',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }
        );

        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ' saat mengambil data.');
        }

        const text = await response.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch (parseError) {
            console.error('Respons bukan JSON valid:', text);
            throw new Error('Server tidak mengembalikan JSON yang valid (lihat console untuk isi lengkap).');
        }

        renderAll(data);

    } catch (error) {
        console.error('Real-time error:', error);
        const el = document.getElementById('lastUpdate');
        if (el) {
            el.textContent = 'Gagal memperbarui data: ' + error.message;
        }
    }
}

setInterval(perbaruiMiskonsepsi, 5000);

// Render pertama dari data PHP, lalu langsung tarik data terbaru sekali lagi
try {
    renderAll(dataAwal);
} catch (error) {
    console.error('Gagal render pertama:', error);
}
perbaruiMiskonsepsi();
</script>

</body>
</html>
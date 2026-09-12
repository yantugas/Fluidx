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
   KONFIGURASI DIAGNOSIS FOUR-TIER (SAMA SEPERTI HALAMAN
   "PERSENTASE MISKONSEPSI", supaya kedua halaman konsisten)

   Tier 1 (Jawaban)   : benar jika SEMUA pernyataan pada soal
                         dijawab tepat sesuai kunci.
   Tier 2 (Keyakinan Jawaban) : yakin / tidak_yakin
   Tier 3 (Alasan)    : dinilai manual oleh admin (0-100).
                         Dianggap BENAR jika skor >= KKM_TIER3.
   Tier 4 (Keyakinan Alasan)  : yakin / tidak_yakin

   Miskonsepsi = yakin di Tier 2 & Tier 4, TAPI Tier 1 dan/atau
   Tier 3 salah (yakin, tapi jawaban/alasannya keliru).
========================================================= */

define('KKM_TIER3', 70);


/* =========================================================
   FUNGSI: HITUNG JUMLAH MISKONSEPSI PER NOMOR SOAL (REALTIME)

   Nomor soal mengikuti urutan yang sama persis dengan yang
   dilihat siswa saat mengerjakan quiz, yaitu soal aktif
   (is_active = 1) diurutkan ASC berdasarkan id (lihat
   siswa/quiz.php). Soal ke-1 di halaman ini = "Soal 1" yang
   sama yang dilihat siswa.

   Dibungkus try/catch supaya kalau ada error SQL, halaman
   TIDAK ikut error/blank/macet. Pesan error asli dikirim ke
   frontend supaya gampang dilacak.
========================================================= */

function ambilDataMiskonsepsiSoal(mysqli $conn): array
{
    $default = [
        'updated_at'              => date('H:i:s'),
        'total_siswa_mengerjakan' => 0,
        'total_soal'              => 0,
        'rata_rata_miskonsepsi'   => 0.0,
        'soal_terbanyak'          => null,
        'kkm_tier3'               => KKM_TIER3,
        'per_soal'                => [],
        'error'                   => null,
    ];

    try {
        return ambilDataMiskonsepsiSoalInner($conn) + $default;
    } catch (\Throwable $e) {
        // Kembalikan pesan error asli (SQL/PHP) supaya langsung kelihatan
        // di tabel & console, bukan cuma "Memuat data..." selamanya.
        $default['error'] = $e->getMessage();
        return $default;
    }
}

/**
 * Wrapper mysqli_query yang melempar error dengan pesan SQL asli
 * kalau query gagal, supaya pesannya jelas & bisa dilacak.
 */
function qs(mysqli $conn, string $sql)
{
    $result = mysqli_query($conn, $sql);
    if ($result === false) {
        throw new \RuntimeException('Query gagal: ' . mysqli_error($conn));
    }
    return $result;
}

function ambilDataMiskonsepsiSoalInner(mysqli $conn): array
{
    // --- 1. Daftar soal AKTIF, urut sama seperti tampilan quiz siswa ---
    //         (id ASC) -> index+1 = "Nomor Soal".
    $daftar_soal  = [];   // question_id => data
    $urutan_soal  = [];   // question_id => nomor soal (1,2,3,...)
    $nomor = 0;

    $res_soal = qs($conn, "
        SELECT q.id, q.question_text, q.concept_id, c.concept_name
        FROM questions q
        LEFT JOIN concepts c ON c.id = q.concept_id
        WHERE q.is_active = 1
        ORDER BY q.id ASC
    ");
    if ($res_soal) {
        while ($row = mysqli_fetch_assoc($res_soal)) {
            $nomor++;
            $qid = (int) $row['id'];
            $urutan_soal[$qid] = $nomor;
            $daftar_soal[$qid] = [
                'question_id'          => $qid,
                'nomor_soal'           => $nomor,
                'question_text'        => $row['question_text'],
                'concept_name'         => $row['concept_name'] ?? 'Tanpa Konsep',
                'siswa_miskonsepsi'    => [], // set user_id
                'jumlah_jawaban'       => 0,
                'jumlah_belum_lengkap' => 0,
            ];
        }
    }

    // --- 2. Total siswa yang sudah mengerjakan quiz (N_siswa global) ---
    $total_siswa_mengerjakan = 0;
    $res_total = qs($conn, "SELECT COUNT(DISTINCT user_id) AS total FROM student_answers");
    if ($res_total) {
        $total_siswa_mengerjakan = (int) (mysqli_fetch_assoc($res_total)['total'] ?? 0);
    }

    // --- 3. Peta Tier 1 (per user + soal): lengkap & benar semua atau tidak ---
    $tier1_map = [];
    $res_tier1 = qs($conn, "
        SELECT
            ssa.user_id,
            ssa.question_id,
            COUNT(*) AS dijawab,
            SUM(ssa.is_correct) AS jumlah_benar,
            (SELECT COUNT(*) FROM question_statements qst
              WHERE qst.question_id = ssa.question_id) AS total_pernyataan
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

    // --- 4. Data utama: Tier 2, 3, 4 per (user, soal), dikelompokkan per SOAL ---
    $res_jawaban = qs($conn, "
        SELECT
            sa.user_id,
            sa.question_id,
            sa.confidence_answer,
            sa.reason_score,
            sa.confidence_reason
        FROM student_answers sa
        INNER JOIN questions q ON q.id = sa.question_id
        WHERE q.is_active = 1
    ");

    if ($res_jawaban) {
        while ($row = mysqli_fetch_assoc($res_jawaban)) {

            $qid = (int) $row['question_id'];

            // Kalau soalnya (karena suatu sebab) tidak ada di daftar_soal
            // (mis. race condition dinonaktifkan barusan), lewati saja.
            if (!isset($daftar_soal[$qid])) {
                continue;
            }

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

            $daftar_soal[$qid]['jumlah_jawaban']++;

            if ($kategori === 'belum_lengkap') {
                $daftar_soal[$qid]['jumlah_belum_lengkap']++;
            }

            if ($kategori === 'miskonsepsi') {
                $daftar_soal[$qid]['siswa_miskonsepsi'][$row['user_id']] = true;
            }
        }
    }

    // --- 5. Susun output per soal + rumus P = N_miskonsepsi / N_siswa x 100% ---
    $per_soal = [];

    foreach ($daftar_soal as $qid => $d) {

        $n_miskonsepsi = count($d['siswa_miskonsepsi']);

        $persentase = $total_siswa_mengerjakan > 0
            ? round(($n_miskonsepsi / $total_siswa_mengerjakan) * 100, 1)
            : 0.0;

        $per_soal[] = [
            'question_id'          => $qid,
            'nomor_soal'           => $d['nomor_soal'],
            'label_soal'           => 'Soal ' . $d['nomor_soal'],
            'question_text'        => $d['question_text'],
            'concept_name'         => $d['concept_name'],
            'n_miskonsepsi'        => $n_miskonsepsi,
            'total_siswa'          => $total_siswa_mengerjakan,
            'persentase'           => $persentase,
            'jumlah_jawaban'       => $d['jumlah_jawaban'],
            'jumlah_belum_lengkap' => $d['jumlah_belum_lengkap'],
            'formula'              => "P = {$n_miskonsepsi}/{$total_siswa_mengerjakan} x 100% = {$persentase}%",
        ];
    }

    // Urutan tampil di chart & tabel: nomor soal ASC (1, 2, 3, ...)
    usort($per_soal, function ($a, $b) {
        return $a['nomor_soal'] <=> $b['nomor_soal'];
    });

    // --- 6. Ringkasan tambahan: soal dengan miskonsepsi terbanyak & rata-rata ---
    $soal_terbanyak = null;
    $total_miskonsepsi_semua_soal = 0;

    foreach ($per_soal as $s) {
        $total_miskonsepsi_semua_soal += $s['n_miskonsepsi'];

        if ($soal_terbanyak === null || $s['n_miskonsepsi'] > $soal_terbanyak['n_miskonsepsi']) {
            $soal_terbanyak = $s;
        }
    }

    $rata_rata_miskonsepsi = count($per_soal) > 0
        ? round($total_miskonsepsi_semua_soal / count($per_soal), 1)
        : 0.0;

    return [
        'updated_at'              => date('H:i:s'),
        'total_siswa_mengerjakan' => $total_siswa_mengerjakan,
        'total_soal'              => count($per_soal),
        'rata_rata_miskonsepsi'   => $rata_rata_miskonsepsi,
        'soal_terbanyak'          => $soal_terbanyak,
        'kkm_tier3'               => KKM_TIER3,
        'per_soal'                => $per_soal,
    ];
}


/* =========================================================
   AJAX / REAL-TIME DATA
========================================================= */

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        ambilDataMiskonsepsiSoal($conn),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}


/* =========================================================
   DATA AWAL (render pertama)
========================================================= */

$data_awal = ambilDataMiskonsepsiSoal($conn);

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Miskonsepsi Terbanyak - Quiz Fluida Statis</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-common.css">
    <link rel="stylesheet" href="../assets/css/admin-layout.css">
    <link rel="stylesheet" href="../assets/css/persentase_miskonsepsi.css">
    <link rel="stylesheet" href="../assets/css/miskonsepsi_terbanyak.css">

    <!--
      Chart.js dimuat lewat loader multi-sumber di script utama
      (lihat fungsi muatChartJs): file lokal assets/js/chart.umd.min.js
      dicoba dulu, baru beberapa CDN cadangan (jsDelivr, cdnjs, unpkg).
      Ini supaya kalau satu sumber diblokir/unreachable dari browser
      admin, chart tetap bisa jalan lewat sumber lain.
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
                <a href="persentase_miskonsepsi.php">
                    <i class="fa-solid fa-chart-column"></i>
                    <span>Persentase Miskonsepsi</span>
                </a>
            </li>
            <li>
                <a href="miskonsepsi_terbanyak.php" class="active">
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
                <h1>Miskonsepsi Terbanyak</h1>
                <p>Jumlah siswa miskonsepsi per nomor soal, realtime mengikuti jawaban siswa.</p>
            </div>

            <div class="admin-profile">
                <i class="fa-solid fa-user"></i>
                <span><?= htmlspecialchars($nama_admin); ?></span>
            </div>
        </div>

        <?php if (!empty($data_awal['error'])): ?>
            <div class="alert-box error" id="mtErrorBanner">
                <strong>Gagal memuat data miskonsepsi per soal:</strong>
                <?= htmlspecialchars($data_awal['error']); ?>
                <br>
                Cek nama tabel/kolom di database (questions, concepts, question_statements,
                student_statement_answers, student_answers) apakah sudah sesuai migrasi.
            </div>
        <?php endif; ?>

        <!-- KARTU RINGKASAN -->
        <div class="mk-cards">

            <div class="mk-card">
                <div class="mk-card-icon blue"><i class="fa-solid fa-list-ol"></i></div>
                <div>
                    <p>Total Soal Aktif</p>
                    <h3 id="cardTotalSoal"><?= $data_awal['total_soal']; ?></h3>
                </div>
            </div>

            <div class="mk-card">
                <div class="mk-card-icon teal"><i class="fa-solid fa-users"></i></div>
                <div>
                    <p>Total Siswa Mengerjakan</p>
                    <h3 id="cardTotalSiswa"><?= $data_awal['total_siswa_mengerjakan']; ?></h3>
                </div>
            </div>

            <div class="mk-card">
                <div class="mk-card-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <p>Soal Miskonsepsi Terbanyak</p>
                    <h3 id="cardSoalTerbanyak">
                        <?= $data_awal['soal_terbanyak']
                            ? 'Soal ' . $data_awal['soal_terbanyak']['nomor_soal'] . ' (' . $data_awal['soal_terbanyak']['n_miskonsepsi'] . ')'
                            : '-'; ?>
                    </h3>
                </div>
            </div>

            <div class="mk-card">
                <div class="mk-card-icon gray"><i class="fa-solid fa-chart-simple"></i></div>
                <div>
                    <p>Rata-rata Miskonsepsi / Soal</p>
                    <h3 id="cardRataRata"><?= $data_awal['rata_rata_miskonsepsi']; ?></h3>
                </div>
            </div>

        </div>

        <!-- BAR CHART -->
        <div class="panel mt-chart-panel">
            <div class="panel-head">
                <h2>Jumlah Siswa Miskonsepsi per Nomor Soal</h2>
                <span class="live-dot" id="lastUpdate">Terakhir diperbarui: <?= $data_awal['updated_at']; ?></span>
            </div>

            <p class="mk-formula-info">
                Rumus per soal: <strong>P = N<sub>miskonsepsi</sub> / N<sub>siswa</sub> &times; 100%</strong>
                &mdash; alasan (Tier 3) dianggap benar jika nilai &ge; <?= KKM_TIER3; ?>.
                Batang berwarna merah menandai 3 soal dengan miskonsepsi terbanyak.
            </p>

            <div class="mt-chart-wrap">
                <canvas id="chartSoalMiskonsepsi"></canvas>
            </div>

            <p class="mk-note" id="chartEmptyNote" style="display:none;">
                Belum ada data miskonsepsi untuk ditampilkan.
            </p>
        </div>

        <!-- TABEL PERINGKAT -->
        <div class="panel mt-table-panel">
            <div class="panel-head">
                <h2>Peringkat Soal &mdash; Miskonsepsi Terbanyak</h2>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Peringkat</th>
                            <th>Soal</th>
                            <th>Konsep</th>
                            <th>Teks Soal</th>
                            <th>Jumlah Siswa Miskonsepsi</th>
                            <th>Persentase</th>
                            <th>Rumus</th>
                        </tr>
                    </thead>
                    <tbody id="tabelSoalBody">
                        <tr><td colspan="7" class="empty-row">Memuat data...</td></tr>
                    </tbody>
                </table>
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
   WARNA BATANG
   3 soal dengan miskonsepsi terbanyak ditandai merah,
   sisanya memakai warna teal netral.
========================================================= */

const WARNA_NORMAL  = '#5c7cfa';
const WARNA_SOROTAN = '#ff6b6b';

function warnaBatang(perSoal) {
    // Ambil 3 nilai n_miskonsepsi tertinggi (>0) sebagai ambang sorotan
    const nilaiUnik = [...new Set(perSoal.map(s => s.n_miskonsepsi))]
        .filter(v => v > 0)
        .sort((a, b) => b - a);
    const ambang = nilaiUnik.slice(0, 3);

    return perSoal.map(s => (ambang.includes(s.n_miskonsepsi) ? WARNA_SOROTAN : WARNA_NORMAL));
}


/* =========================================================
   LOADER CHART.JS - MULTI SUMBER (fallback berantai)

   Urutan dicoba: file lokal -> jsDelivr -> cdnjs -> unpkg.
   Begitu salah satu berhasil, langsung dipakai. Kalau semuanya
   gagal, chart dilewati dengan pesan yang jelas (kartu & tabel
   tetap jalan seperti biasa).
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
   INISIALISASI CHART.JS (BAR)

   Dibungkus try/catch: kalau SEMUA sumber Chart.js gagal dimuat,
   kartu ringkasan & tabel TETAP jalan. Hanya bagian chart yang
   dilewati + diberi pesan.
========================================================= */

let chartSoalMiskonsepsi = null;
let dataTerakhir = null;

async function inisialisasiChart() {
    try {
        const berhasil = await muatChartJs();

        if (!berhasil || typeof Chart === 'undefined') {
            throw new Error(
                'Semua sumber Chart.js gagal dimuat (cek koneksi internet server/browser, ' +
                'atau upload chart.umd.min.js ke folder assets/js/ agar tidak bergantung pada internet).'
            );
        }

        const ctx = document.getElementById('chartSoalMiskonsepsi').getContext('2d');

        chartSoalMiskonsepsi = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'Jumlah siswa miskonsepsi',
                    data: [],
                    backgroundColor: [],
                    borderRadius: 6,
                    maxBarThickness: 46
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                const s = dataTerakhir?.per_soal?.[items[0].dataIndex];
                                return s ? (s.label_soal + ' - ' + s.concept_name) : items[0].label;
                            },
                            label: function (item) {
                                const s = dataTerakhir?.per_soal?.[item.dataIndex];
                                const persen = s ? s.persentase : 0;
                                return item.raw + ' siswa miskonsepsi (' + persen + '%)';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });

        // Chart baru siap -> render ulang pakai data yang sudah ada
        // (data awal dari PHP, atau data terakhir dari polling kalau sudah sempat jalan)
        renderAll(dataTerakhir || dataAwal);

    } catch (error) {
        console.error('Gagal membuat bar chart:', error);
        const note = document.getElementById('chartEmptyNote');
        note.textContent = 'Chart tidak bisa ditampilkan: ' + error.message;
        note.style.display = 'block';
    }
}

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

    dataTerakhir = data;

    // --- Pesan error dari server (kalau ada) ---
    if (data.error) {
        console.error('Error dari server:', data.error);
    }

    const perSoal = data.per_soal || [];

    // --- Kartu ringkasan ---
    try {
        document.getElementById('cardTotalSoal').textContent = data.total_soal ?? 0;
        document.getElementById('cardTotalSiswa').textContent = data.total_siswa_mengerjakan ?? 0;
        document.getElementById('cardRataRata').textContent = data.rata_rata_miskonsepsi ?? 0;

        const st = data.soal_terbanyak;
        document.getElementById('cardSoalTerbanyak').textContent = st
            ? ('Soal ' + st.nomor_soal + ' (' + st.n_miskonsepsi + ')')
            : '-';
    } catch (error) {
        console.error('Gagal update kartu ringkasan:', error);
    }

    // --- Bar chart ---
    if (chartSoalMiskonsepsi) {
        try {
            const adaData = perSoal.some(s => s.n_miskonsepsi > 0);

            chartSoalMiskonsepsi.data.labels = perSoal.map(s => s.label_soal);
            chartSoalMiskonsepsi.data.datasets[0].data = perSoal.map(s => s.n_miskonsepsi);
            chartSoalMiskonsepsi.data.datasets[0].backgroundColor = warnaBatang(perSoal);
            chartSoalMiskonsepsi.update();

            document.getElementById('chartEmptyNote').style.display =
                (perSoal.length === 0 || !adaData) ? 'block' : 'none';

            if (perSoal.length === 0 || !adaData) {
                document.getElementById('chartEmptyNote').textContent =
                    'Belum ada data miskonsepsi untuk ditampilkan.';
            }
        } catch (error) {
            console.error('Gagal update bar chart:', error);
        }
    }

    // --- Tabel peringkat (diurutkan dari miskonsepsi terbanyak) ---
    try {
        const tbody = document.getElementById('tabelSoalBody');

        if (perSoal.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="empty-row">Belum ada data soal aktif.</td></tr>';
        } else {
            const terurut = [...perSoal].sort((a, b) => b.n_miskonsepsi - a.n_miskonsepsi);

            tbody.innerHTML = terurut.map((s, idx) => {
                const cuplikan = (s.question_text || '').length > 90
                    ? s.question_text.slice(0, 90) + '...'
                    : (s.question_text || '-');

                const badgeClass = s.n_miskonsepsi > 0 ? 'mk-badge' : 'mk-badge mk-badge-muted';

                return `
                    <tr>
                        <td>#${idx + 1}</td>
                        <td><strong>${s.label_soal}</strong></td>
                        <td>${s.concept_name}</td>
                        <td>${cuplikan}</td>
                        <td><span class="${badgeClass}">${s.n_miskonsepsi} siswa</span></td>
                        <td>${s.persentase}%</td>
                        <td class="mk-formula-cell">${s.formula}</td>
                    </tr>
                `;
            }).join('');
        }
    } catch (error) {
        console.error('Gagal update tabel peringkat:', error);
    }

    // --- Waktu update terakhir ---
    try {
        const el = document.getElementById('lastUpdate');
        if (el) {
            el.textContent = 'Terakhir diperbarui: ' + (data.updated_at ?? '-');
        }
    } catch (error) {
        console.error('Gagal update waktu:', error);
    }
}


/* =========================================================
   REAL-TIME: POLLING SETIAP 5 DETIK

   Mengikuti jawaban siswa terbaru tanpa perlu reload halaman.
   setInterval didaftarkan lebih dulu (sebelum render pertama)
   supaya polling TETAP jalan walau render pertama gagal karena
   sebab apa pun.
========================================================= */

async function perbaruiMiskonsepsiSoal() {
    try {
        const response = await fetch(
            'miskonsepsi_terbanyak.php?ajax=1&_=' + Date.now(),
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

setInterval(perbaruiMiskonsepsiSoal, 5000);

// Render pertama dari data PHP, lalu langsung tarik data terbaru sekali lagi
try {
    renderAll(dataAwal);
} catch (error) {
    console.error('Gagal render pertama:', error);
}
perbaruiMiskonsepsiSoal();
</script>

</body>
</html>

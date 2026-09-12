<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

/** @var mysqli $conn */

// ========================================
// CEK APAKAH SUDAH LOGIN SEBAGAI ADMIN
// ========================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login_admin.php");
    exit;
}

$nama_admin = $_SESSION['name'] ?? 'Admin';


// ========================================
// PERHITUNGAN SCORE (format baru)
// ========================================
// Skor tiap soal = skor Tier 1 (otomatis) + skor Tier 3 (manual):
//   Tier 1 : jumlah poin pernyataan yang dijawab benar
//            (Mudah = 2 poin, Sedang/Sulit = 8 poin masing-masing;
//             tersimpan langsung di student_statement_answers.points_earned)
//   Tier 3 : nilai uraian alasan yang diinput manual oleh admin
//            di halaman Jawaban Siswa (student_answers.reason_score,
//            skala 0-100). Selama belum dinilai, dianggap 0.
// Tier 2 & Tier 4 (Yakin/Tidak Yakin) tidak mempengaruhi skor,
// hanya informasi keyakinan siswa.
//
// CATATAN: Query ini mengasumsikan tabel `users` punya kolom
// `kelas`. Jika nama kolomnya berbeda di database Anda, ganti
// semua `u.kelas` di bawah ini sesuai nama kolom yang sebenarnya.

$sql = "
    SELECT
        u.id,
        u.name,
        u.kelas,
        (SELECT COUNT(DISTINCT sa.question_id)
           FROM student_answers sa WHERE sa.user_id = u.id) AS soal_dikerjakan,
        COALESCE(tier1.total_tier1, 0) AS skor_tier1,
        COALESCE(tier3.total_tier3, 0) AS skor_tier3,
        COALESCE(tier1.total_tier1, 0) + COALESCE(tier3.total_tier3, 0) AS score
    FROM users u
    LEFT JOIN (
        SELECT user_id, SUM(points_earned) AS total_tier1
        FROM student_statement_answers
        GROUP BY user_id
    ) tier1 ON tier1.user_id = u.id
    LEFT JOIN (
        SELECT user_id, SUM(COALESCE(reason_score, 0)) AS total_tier3
        FROM student_answers
        GROUP BY user_id
    ) tier3 ON tier3.user_id = u.id
    WHERE u.role = 'student'
    ORDER BY score DESC, u.name ASC
";

$data_siswa = [];

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $data_siswa[] = $row;
    }
}


// ========================================
// FILTER KELAS (opsional, dari dropdown)
// ========================================

$daftar_kelas = [];
foreach ($data_siswa as $siswa) {
    if (!empty($siswa['kelas']) && !in_array($siswa['kelas'], $daftar_kelas, true)) {
        $daftar_kelas[] = $siswa['kelas'];
    }
}
sort($daftar_kelas);

$filter_kelas = $_GET['kelas'] ?? '';

if ($filter_kelas !== '') {
    $data_siswa = array_values(array_filter(
        $data_siswa,
        fn($siswa) => $siswa['kelas'] === $filter_kelas
    ));
}


// ========================================
// HITUNG RANKING (dengan peringkat sama jika score sama)
// ========================================

$ranking = [];
$rank_terakhir = 0;
$score_terakhir = null;

foreach ($data_siswa as $i => $siswa) {

    if ($siswa['score'] !== $score_terakhir) {
        $rank_terakhir = $i + 1;
        $score_terakhir = $siswa['score'];
    }

    $siswa['rank'] = $rank_terakhir;
    $ranking[] = $siswa;
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Score Siswa - Quiz Fluida Statis</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-common.css">
    <link rel="stylesheet" href="../assets/css/admin-layout.css">
    <link rel="stylesheet" href="../assets/css/score_siswa.css">
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
                <a href="score_siswa.php" class="active">
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
                <h1>Score Siswa</h1>
                <p>Ranking skor siswa berdasarkan kunci jawaban di Kelola Soal.</p>
            </div>

            <div class="admin-profile">
                <i class="fa-solid fa-user"></i>
                <span><?= htmlspecialchars($nama_admin); ?></span>
            </div>
        </div>

        <div class="panel">

            <div class="panel-head">
                <h2>Papan Score (<?= count($ranking); ?> siswa)</h2>

                <form method="GET" class="filter-form">
                    <select name="kelas" onchange="this.form.submit()">
                        <option value="">Semua Kelas</option>
                        <?php foreach ($daftar_kelas as $kelas): ?>
                            <option value="<?= htmlspecialchars($kelas); ?>" <?= $filter_kelas === $kelas ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($kelas); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Ranking</th>
                            <th>Nama</th>
                            <th>Kelas</th>
                            <th>Soal Dikerjakan</th>
                            <th>Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ranking)): ?>
                            <tr>
                                <td colspan="5" class="empty-row">Belum ada data siswa.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ranking as $siswa): ?>
                                <tr>
                                    <td>
                                        <span class="rank-badge
                                            <?= $siswa['rank'] === 1 ? 'gold' : ($siswa['rank'] === 2 ? 'silver' : ($siswa['rank'] === 3 ? 'bronze' : '')); ?>">
                                            #<?= $siswa['rank']; ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($siswa['name']); ?></td>
                                    <td><?= htmlspecialchars($siswa['kelas'] ?? '-'); ?></td>
                                    <td><?= (int) $siswa['soal_dikerjakan']; ?></td>
                                    <td><strong><?= (int) $siswa['score']; ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

    </main>

</body>
</html>
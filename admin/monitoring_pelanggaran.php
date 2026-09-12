<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

/** @var mysqli $conn */

/* =========================================================
   CEK LOGIN ADMIN
========================================================= */

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login_admin.php");
    exit;
}

$nama_admin = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Admin';


/* =========================================================
   FUNGSI AMBIL DATA PELANGGARAN
========================================================= */

function getDataPelanggaran($conn)
{
    $list = [];

    $query = mysqli_query($conn, "
        SELECT
            id,
            user_id,
            student_name,
            student_class,
            event_type,
            occurred_at,
            UNIX_TIMESTAMP(occurred_at) AS occurred_at_unix
        FROM quiz_violations
        ORDER BY occurred_at DESC, id DESC
        LIMIT 200
    ");

    if (!$query) {
        return [
            'error' => mysqli_error($conn),
        ];
    }

    while ($row = mysqli_fetch_assoc($query)) {
        $list[] = $row;
    }

    // Total pelanggaran per siswa (dipakai sebagai badge di tabel)
    $count_per_user = [];

    $count_query = mysqli_query($conn, "
        SELECT user_id, COUNT(*) AS total
        FROM quiz_violations
        GROUP BY user_id
    ");

    if ($count_query) {
        while ($row = mysqli_fetch_assoc($count_query)) {
            $count_per_user[(int) $row['user_id']] = (int) $row['total'];
        }
    }

    foreach ($list as &$item) {
        $item['total_pelanggaran_siswa'] = $count_per_user[(int) $item['user_id']] ?? 1;
    }
    unset($item);

    // Statistik ringkas
    $stat_total  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM quiz_violations"));
    $stat_siswa  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(DISTINCT user_id) AS total FROM quiz_violations"));
    $stat_hariini = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM quiz_violations WHERE DATE(occurred_at) = CURDATE()"));

    return [
        'list'  => $list,
        'stats' => [
            'total_pelanggaran' => (int) ($stat_total['total'] ?? 0),
            'total_siswa'       => (int) ($stat_siswa['total'] ?? 0),
            'total_hari_ini'    => (int) ($stat_hariini['total'] ?? 0),
        ],
    ];
}


/* =========================================================
   AJAX / REAL-TIME DATA
========================================================= */

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {

    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        getDataPelanggaran($conn),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* =========================================================
   DATA AWAL
========================================================= */

$data_pelanggaran = getDataPelanggaran($conn);

$daftar_pelanggaran = $data_pelanggaran['list'] ?? [];
$stats              = $data_pelanggaran['stats'] ?? [
    'total_pelanggaran' => 0,
    'total_siswa'       => 0,
    'total_hari_ini'    => 0,
];

$label_event = [
    'tab_hidden'   => 'Pindah Tab / Minimize',
    'window_blur'  => 'Jendela Tidak Aktif',
];

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring Pelanggaran - Quiz Fluida Statis</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-common.css">
    <link rel="stylesheet" href="../assets/css/admin-layout.css">
    <link rel="stylesheet" href="../assets/css/monitoring_pelanggaran.css">
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
                <a href="monitoring_pelanggaran.php" class="active">
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
                <h1>Monitoring Pelanggaran</h1>
                <p>Siswa yang terdeteksi pindah tab / minimize saat mengerjakan quiz.</p>
            </div>

            <div class="admin-profile">
                <i class="fa-solid fa-user"></i>
                <span><?= htmlspecialchars($nama_admin); ?></span>
            </div>
        </div>


        <!-- STATISTIK -->
        <div class="stat-cards">

            <div class="stat-card total">
                <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <p>Total Pelanggaran</p>
                    <h3 id="statTotal"><?= $stats['total_pelanggaran']; ?></h3>
                </div>
            </div>

            <div class="stat-card students">
                <div class="stat-icon"><i class="fa-solid fa-user-group"></i></div>
                <div>
                    <p>Siswa Terindikasi</p>
                    <h3 id="statSiswa"><?= $stats['total_siswa']; ?></h3>
                </div>
            </div>

            <div class="stat-card today">
                <div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
                <div>
                    <p>Pelanggaran Hari Ini</p>
                    <h3 id="statHariIni"><?= $stats['total_hari_ini']; ?></h3>
                </div>
            </div>

        </div>


        <div class="panel">

            <div class="panel-head">
                <div>
                    <h2>Riwayat Pelanggaran</h2>
                    <p>Diperbarui otomatis setiap beberapa detik, tanpa perlu refresh halaman.</p>
                </div>

                <div class="realtime-status">
                    <span class="status-dot"></span>
                    <span>Real-time</span>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Nama Siswa</th>
                            <th>Kelas</th>
                            <th>Jenis Pelanggaran</th>
                            <th>Total Pelanggaran</th>
                            <th>Waktu</th>
                        </tr>
                    </thead>
                    <tbody id="pelanggaranTableBody">
                        <?php if (!empty($daftar_pelanggaran) && !isset($daftar_pelanggaran['error'])): ?>

                            <?php foreach ($daftar_pelanggaran as $index => $row): ?>
                                <tr>
                                    <td><?= $index + 1; ?></td>

                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                            <span><?= htmlspecialchars($row['student_name'] ?: '-'); ?></span>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="class-badge"><?= htmlspecialchars($row['student_class'] ?: '-'); ?></span>
                                    </td>

                                    <td>
                                        <span class="event-badge">
                                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                            <?= htmlspecialchars($label_event[$row['event_type']] ?? $row['event_type']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="count-badge"><?= (int) $row['total_pelanggaran_siswa']; ?>x</span>
                                    </td>

                                    <td>
                                        <?= date('d M Y, H:i:s', strtotime($row['occurred_at'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-row">
                                    <i class="fa-solid fa-shield-halved"></i>
                                    Belum ada pelanggaran yang terdeteksi.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

    </main>


<script>

const labelEvent = <?= json_encode($label_event, JSON_UNESCAPED_UNICODE); ?>;


/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(value) {

    if (value === null || value === undefined) {
        return '';
    }

    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}


/* =========================================================
   FORMAT WAKTU
========================================================= */

function formatWaktu(unixSeconds) {

    const date = new Date(unixSeconds * 1000);

    return date.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
}


/* =========================================================
   BUAT BARIS TABEL
========================================================= */

let lastSeenId = <?= !empty($daftar_pelanggaran) ? (int) $daftar_pelanggaran[0]['id'] : 0; ?>;

function createTableRows(data) {

    if (!Array.isArray(data) || data.length === 0) {

        return `
            <tr>
                <td colspan="6" class="empty-row">
                    <i class="fa-solid fa-shield-halved"></i>
                    Belum ada pelanggaran yang terdeteksi.
                </td>
            </tr>
        `;
    }

    return data.map(function (row, index) {

        const isNew = row.id > lastSeenId;
        const eventLabel = labelEvent[row.event_type] || row.event_type;

        return `
            <tr class="${isNew ? 'row-new' : ''}">
                <td>${index + 1}</td>

                <td>
                    <div class="student-info">
                        <div class="student-avatar">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <span>${escapeHtml(row.student_name || '-')}</span>
                    </div>
                </td>

                <td>
                    <span class="class-badge">${escapeHtml(row.student_class || '-')}</span>
                </td>

                <td>
                    <span class="event-badge">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        ${escapeHtml(eventLabel)}
                    </span>
                </td>

                <td>
                    <span class="count-badge">${escapeHtml(row.total_pelanggaran_siswa)}x</span>
                </td>

                <td>
                    ${formatWaktu(row.occurred_at_unix)}
                </td>
            </tr>
        `;

    }).join('');
}


/* =========================================================
   AMBIL & PERBARUI DATA
========================================================= */

async function updatePelanggaran() {

    try {

        const response = await fetch(
            'monitoring_pelanggaran.php?ajax=1&_=' + Date.now(),
            {
                method: 'GET',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }
        );

        if (!response.ok) {
            throw new Error('Gagal mengambil data.');
        }

        const result = await response.json();

        if (!result || !Array.isArray(result.list)) {
            throw new Error('Format data tidak valid.');
        }

        document.getElementById('pelanggaranTableBody').innerHTML =
            createTableRows(result.list);

        if (result.list.length > 0) {
            lastSeenId = Math.max(lastSeenId, result.list[0].id);
        }

        if (result.stats) {
            document.getElementById('statTotal').textContent = result.stats.total_pelanggaran;
            document.getElementById('statSiswa').textContent = result.stats.total_siswa;
            document.getElementById('statHariIni').textContent = result.stats.total_hari_ini;
        }

    } catch (error) {
        console.error('Monitoring error:', error);
    }

}


/* =========================================================
   TUTUP SIDEBAR SAAT MENU DIPILIH (MOBILE)
========================================================= */

document.querySelectorAll('.menu a').forEach(function (link) {
    link.addEventListener('click', function () {
        if (window.innerWidth <= 700) {
            document.querySelector('.sidebar').classList.remove('show');
            document.querySelector('.sidebar-overlay').classList.remove('show');
        }
    });
});


/* =========================================================
   JALANKAN REAL-TIME (setiap 4 detik)
========================================================= */

setInterval(updatePelanggaran, 4000);

</script>

</body>
</html>

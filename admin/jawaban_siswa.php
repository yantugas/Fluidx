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


/* =========================================================
   NAMA ADMIN
========================================================= */

$nama_admin = $_SESSION['name']
    ?? $_SESSION['username']
    ?? 'Admin';


/* =========================================================
   PROSES: SIMPAN NILAI MANUAL TIER 3 (AJAX)
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grade') {

    header('Content-Type: application/json; charset=UTF-8');

    $answer_id = (int) ($_POST['answer_id'] ?? 0);
    $skor_raw  = $_POST['score'] ?? '';

    if ($answer_id <= 0 || $skor_raw === '' || !is_numeric($skor_raw)) {
        echo json_encode(['success' => false, 'message' => 'Skor tidak valid.']);
        exit;
    }

    $skor = max(0, min(100, (int) $skor_raw));

    $stmt = mysqli_prepare($conn, "UPDATE student_answers SET reason_score = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $skor, $answer_id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    echo json_encode(['success' => (bool) $ok, 'score' => $skor]);
    exit;
}


/* =========================================================
   FUNGSI AMBIL DATA JAWABAN SISWA
========================================================= */

function getJawabanSiswa($conn)
{
    $data = [];

    // --- Data utama: Tier 2, 3, 4 dari student_answers ---
    $query = mysqli_query($conn, "
        SELECT
            sa.id,
            sa.user_id,
            sa.question_id,

            sa.confidence_answer,
            sa.reason_text,
            sa.confidence_reason,
            sa.reason_score,

            sa.answered_at,

            u.name,
            u.kelas,

            q.question_text

        FROM student_answers sa

        INNER JOIN users u
            ON sa.user_id = u.id

        INNER JOIN questions q
            ON sa.question_id = q.id

        WHERE u.role = 'student'

        ORDER BY
            sa.answered_at DESC,
            sa.id DESC
    ");

    if (!$query) {
        return [
            'error' => mysqli_error($conn)
        ];
    }

    while ($row = mysqli_fetch_assoc($query)) {
        $data[] = $row;
    }

    // --- Tier 1: ambil semua jawaban per-pernyataan, lalu gabungkan per (user, soal) ---
    $tier1_map = [];

    $stmtQuery = mysqli_query($conn, "
        SELECT
            ssa.user_id,
            ssa.question_id,
            qs.statement_order,
            qs.statement_text,
            qs.points AS max_points,
            ssa.is_correct,
            ssa.points_earned
        FROM student_statement_answers ssa
        INNER JOIN question_statements qs ON qs.id = ssa.statement_id
        ORDER BY ssa.user_id ASC, ssa.question_id ASC, qs.statement_order ASC
    ");

    if ($stmtQuery) {
        while ($row = mysqli_fetch_assoc($stmtQuery)) {
            $key = $row['user_id'] . '-' . $row['question_id'];
            $tier1_map[$key]['statements'][] = $row;
            $tier1_map[$key]['total_earned'] = ($tier1_map[$key]['total_earned'] ?? 0) + (int) $row['points_earned'];
            $tier1_map[$key]['total_max'] = ($tier1_map[$key]['total_max'] ?? 0) + (int) $row['max_points'];
        }
    }

    foreach ($data as &$row) {
        $key = $row['user_id'] . '-' . $row['question_id'];
        $row['tier1'] = $tier1_map[$key] ?? ['statements' => [], 'total_earned' => 0, 'total_max' => 0];
    }
    unset($row);

    return $data;
}


/* =========================================================
   AJAX / REAL-TIME DATA
========================================================= */

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {

    header('Content-Type: application/json; charset=UTF-8');

    $jawaban = getJawabanSiswa($conn);

    echo json_encode(
        $jawaban,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/* =========================================================
   DATA AWAL
========================================================= */

$jawaban_siswa = getJawabanSiswa($conn);

$total_jawaban = is_array($jawaban_siswa)
    ? count($jawaban_siswa)
    : 0;

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Jawaban Siswa - Quiz Fluida Statis</title>


    <!-- =====================================================
         FONT AWESOME
    ====================================================== -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >


    <!-- =====================================================
         CSS COMMON (reset global, dipakai semua halaman admin)
    ====================================================== -->

    <link
        rel="stylesheet"
        href="../assets/css/admin-common.css"
    >


    <!-- =====================================================
         CSS JAWABAN SISWA (sidebar/header + konten halaman ini)
    ====================================================== -->

    <link
        rel="stylesheet"
        href="../assets/css/jawaban_siswa_admin.css"
    >

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">


    <!-- LOGO -->

    <div class="sidebar-header">

        <div class="logo">
            <i class="fa-solid fa-water"></i>
        </div>

        <div>
            <h2>Quiz Fluida</h2>
            <span>Admin Panel</span>
        </div>

    </div>


    <!-- MENU -->

    <nav class="sidebar-menu">

        <a href="dashboard_admin.php">

            <i class="fa-solid fa-gauge-high"></i>

            <span>Dashboard</span>

        </a>


        <a href="kelola_soal.php">

            <i class="fa-solid fa-file-circle-plus"></i>

            <span>Kelola Soal</span>

        </a>


        <a href="kelola_materi.php">

            <i class="fa-solid fa-file-arrow-up"></i>

            <span>Kelola Materi</span>

        </a>


        <a
            href="jawaban_siswa.php"
            class="active"
        >

            <i class="fa-solid fa-clipboard-check"></i>

            <span>Jawaban Siswa</span>

        </a>


        <a href="monitoring_pelanggaran.php">

            <i class="fa-solid fa-eye"></i>

            <span>Monitoring Pelanggaran</span>

        </a>


        <a href="score_siswa.php">

            <i class="fa-solid fa-chart-line"></i>

            <span>Score Siswa</span>

        </a>


        <a href="persentase_miskonsepsi.php">

            <i class="fa-solid fa-chart-column"></i>

            <span>Persentase Miskonsepsi</span>

        </a>


        <a href="miskonsepsi_terbanyak.php">

            <i class="fa-solid fa-triangle-exclamation"></i>

            <span>Miskonsepsi Terbanyak</span>

        </a>

    </nav>


    <!-- LOGOUT -->

    <div class="sidebar-bottom">

        <a
            href="../logout.php"
            class="logout"
        >

            <i class="fa-solid fa-right-from-bracket"></i>

            <span>Logout</span>

        </a>

    </div>

</aside>


<!-- =========================================================
     OVERLAY MOBILE
========================================================= -->

<div
    class="sidebar-overlay"
    onclick="toggleSidebar()"
></div>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<main class="main-content">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <header class="admin-header">


        <!-- TOMBOL MOBILE -->

        <button
            class="menu-toggle"
            onclick="toggleSidebar()"
            aria-label="Buka menu"
        >

            <i class="fa-solid fa-bars"></i>

        </button>


        <!-- TITLE -->

        <div class="header-title">

            <h1>Jawaban Siswa</h1>

            <p>
                Melihat hasil jawaban siswa berdasarkan empat tier.
            </p>

        </div>


        <!-- PROFILE -->

        <div class="admin-profile">

            <div class="profile-icon">

                <i class="fa-solid fa-user-shield"></i>

            </div>

            <div>

                <strong>
                    <?= htmlspecialchars($nama_admin); ?>
                </strong>

                <span>Administrator</span>

            </div>

        </div>

    </header>


    <!-- =====================================================
         CONTAINER
    ====================================================== -->

    <section class="answer-container">


        <div class="answer-card">


            <!-- HEADER CARD -->

            <div class="answer-card-header">

                <div>

                    <h2>

                        <i class="fa-solid fa-clipboard-check"></i>

                        Hasil Jawaban Siswa

                    </h2>

                    <p>
                        Data akan diperbarui otomatis ketika siswa
                        mengirimkan jawaban.
                    </p>

                </div>


                <!-- STATUS REAL-TIME -->

                <div class="realtime-status">

                    <span class="status-dot"></span>

                    <span>Real-time</span>

                </div>

            </div>


            <!-- INFO -->

            <div class="answer-info">

                <div class="answer-count">

                    <i class="fa-solid fa-list-check"></i>

                    <span>
                        Total jawaban:
                        <strong id="totalJawaban">
                            <?= $total_jawaban; ?>
                        </strong>
                    </span>

                </div>


                <div
                    class="last-update"
                    id="lastUpdate"
                >

                    Memuat data...

                </div>

            </div>


            <!-- TABLE -->

            <div class="table-wrapper">

                <table class="answer-table">

                    <thead>

                        <tr>

                            <th class="number">
                                No.
                            </th>

                            <th>
                                Nama
                            </th>

                            <th>
                                Kelas
                            </th>

                            <th>
                                Soal
                            </th>

                            <th>
                                Tier 1
                                <br>
                                Pernyataan (poin)
                            </th>

                            <th>
                                Tier 2
                                <br>
                                Keyakinan
                            </th>

                            <th>
                                Tier 3
                                <br>
                                Alasan &amp; Nilai
                            </th>

                            <th>
                                Tier 4
                                <br>
                                Keyakinan
                            </th>

                        </tr>

                    </thead>


                    <tbody id="answerTableBody">

                    <?php if (
                        !empty($jawaban_siswa)
                        && !isset($jawaban_siswa['error'])
                    ): ?>


                        <?php foreach (
                            $jawaban_siswa as $index => $row
                        ): ?>

                            <tr>

                                <!-- NO -->

                                <td class="number">

                                    <?= $index + 1; ?>

                                </td>


                                <!-- NAMA -->

                                <td class="student-name">

                                    <div class="student-info">

                                        <div class="student-avatar">

                                            <i class="fa-solid fa-user"></i>

                                        </div>

                                        <span>

                                            <?= htmlspecialchars(
                                                $row['name']
                                            ); ?>

                                        </span>

                                    </div>

                                </td>


                                <!-- KELAS -->

                                <td>

                                    <span class="class-badge">

                                        <?= htmlspecialchars(
                                            $row['kelas']
                                        ); ?>

                                    </span>

                                </td>


                                <!-- SOAL -->

                                <td class="question-cell">

                                    <strong>
                                        Soal #<?= $row['question_id']; ?>
                                    </strong>

                                    <p>
                                        <?= htmlspecialchars(
                                            $row['question_text']
                                        ); ?>
                                    </p>

                                </td>


                                <!-- TIER 1 -->

                                <td>

                                    <div class="answer-badge">

                                        <?php foreach (($row['tier1']['statements'] ?? []) as $i => $s): ?>
                                            <span class="option-code" title="<?= htmlspecialchars($s['statement_text']); ?>">
                                                <?= chr(97 + $i); ?>:
                                                <?= $s['is_correct'] ? '✔' : '✘'; ?>
                                            </span>
                                        <?php endforeach; ?>

                                        <?php if (empty($row['tier1']['statements'])): ?>
                                            <span class="answer-text">-</span>
                                        <?php endif; ?>

                                        <span class="answer-text">
                                            (<?= (int) ($row['tier1']['total_earned'] ?? 0); ?>/<?= (int) ($row['tier1']['total_max'] ?? 0); ?> poin)
                                        </span>

                                    </div>

                                </td>


                                <!-- TIER 2 -->

                                <td>

                                    <span class="confidence-badge <?= $row['confidence_answer'] === 'yakin' ? 'yakin' : 'tidak-yakin'; ?>">
                                        <?= $row['confidence_answer'] === 'yakin' ? 'Yakin' : ($row['confidence_answer'] === 'tidak_yakin' ? 'Tidak Yakin' : '-'); ?>
                                    </span>

                                </td>


                                <!-- TIER 3 -->

                                <td>

                                    <div class="reason-badge">

                                        <span class="reason-text">
                                            <?= htmlspecialchars($row['reason_text'] ?? '-'); ?>
                                        </span>

                                        <div class="nilai-alasan-form" data-answer-id="<?= $row['id']; ?>" style="margin-top:6px; display:flex; gap:6px; align-items:center;">
                                            <input
                                                type="number" min="0" max="100"
                                                class="nilai-input"
                                                style="width:64px; padding:4px;"
                                                value="<?= $row['reason_score'] !== null ? (int) $row['reason_score'] : ''; ?>"
                                                placeholder="0-100"
                                            >
                                            <button type="button" class="btn-simpan-nilai" style="padding:4px 8px;">Simpan</button>
                                            <span class="status-simpan" style="font-size:11px; color:#888;"></span>
                                        </div>

                                    </div>

                                </td>


                                <!-- TIER 4 -->

                                <td>

                                    <span class="confidence-badge <?= $row['confidence_reason'] === 'yakin' ? 'yakin' : 'tidak-yakin'; ?>">
                                        <?= $row['confidence_reason'] === 'yakin' ? 'Yakin' : ($row['confidence_reason'] === 'tidak_yakin' ? 'Tidak Yakin' : '-'); ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                    <?php else: ?>


                        <tr>

                            <td
                                colspan="8"
                                class="empty-data"
                            >

                                <div class="empty-icon">

                                    <i class="fa-solid fa-inbox"></i>

                                </div>

                                <h3>
                                    Belum ada jawaban siswa
                                </h3>

                                <p>
                                    Jawaban siswa akan muncul
                                    otomatis setelah siswa
                                    mengerjakan quiz.
                                </p>

                            </td>

                        </tr>


                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </section>

</main>


<!-- =========================================================
     JAVASCRIPT
     REAL-TIME UPDATE
========================================================= -->

<script>


/* =========================================================
   SIDEBAR MOBILE
========================================================= */

function toggleSidebar() {

    const sidebar =
        document.querySelector('.sidebar');

    const overlay =
        document.querySelector('.sidebar-overlay');


    sidebar.classList.toggle('show');

    overlay.classList.toggle('show');
}


/* =========================================================
   TUTUP SIDEBAR SAAT MENU DIPILIH
========================================================= */

document
    .querySelectorAll('.sidebar-menu a')
    .forEach(function(link) {

        link.addEventListener(
            'click',
            function() {

                if (window.innerWidth <= 700) {

                    document
                        .querySelector('.sidebar')
                        .classList.remove('show');

                    document
                        .querySelector('.sidebar-overlay')
                        .classList.remove('show');

                }

            }
        );

    });


/* =========================================================
   FORMAT CONFIDENCE
========================================================= */

function formatConfidence(value) {

    if (value === 'yakin') {

        return `
            <span class="confidence-badge yakin">
                Yakin
            </span>
        `;

    }


    if (value === 'tidak_yakin') {

        return `
            <span class="confidence-badge tidak-yakin">
                Tidak Yakin
            </span>
        `;

    }


    return `
        <span class="confidence-badge">-</span>
    `;
}


/* =========================================================
   FORMAT TIER 1 (pernyataan benar/salah + poin)
========================================================= */

function formatTier1(tier1) {

    if (!tier1 || !Array.isArray(tier1.statements) || tier1.statements.length === 0) {
        return `<div class="answer-badge"><span class="answer-text">-</span></div>`;
    }

    const badges = tier1.statements.map(function (s, i) {
        const huruf = String.fromCharCode(97 + i);
        const mark = s.is_correct == 1 ? '✔' : '✘';
        return `<span class="option-code" title="${escapeHtml(s.statement_text)}">${huruf}: ${mark}</span>`;
    }).join('');

    return `
        <div class="answer-badge">
            ${badges}
            <span class="answer-text">(${tier1.total_earned || 0}/${tier1.total_max || 0} poin)</span>
        </div>
    `;
}


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
   BUAT BARIS TABEL
========================================================= */

function createTableRows(data) {

    if (!Array.isArray(data) || data.length === 0) {

        return `
            <tr>

                <td
                    colspan="8"
                    class="empty-data"
                >

                    <div class="empty-icon">

                        <i class="fa-solid fa-inbox"></i>

                    </div>

                    <h3>
                        Belum ada jawaban siswa
                    </h3>

                    <p>
                        Jawaban siswa akan muncul
                        otomatis setelah siswa
                        mengerjakan quiz.
                    </p>

                </td>

            </tr>
        `;

    }


    return data.map(function(row, index) {

        return `

            <tr>

                <td class="number">
                    ${index + 1}
                </td>


                <td class="student-name">

                    <div class="student-info">

                        <div class="student-avatar">

                            <i class="fa-solid fa-user"></i>

                        </div>

                        <span>
                            ${escapeHtml(row.name)}
                        </span>

                    </div>

                </td>


                <td>

                    <span class="class-badge">

                        ${escapeHtml(row.kelas)}

                    </span>

                </td>


                <td class="question-cell">

                    <strong>
                        Soal #${escapeHtml(row.question_id)}
                    </strong>

                    <p>
                        ${escapeHtml(row.question_text)}
                    </p>

                </td>


                <td>

                    ${formatTier1(row.tier1)}

                </td>


                <td>

                    ${formatConfidence(
                        row.confidence_answer
                    )}

                </td>


                <td>

                    <div class="reason-badge">

                        <span class="reason-text">

                            ${escapeHtml(
                                row.reason_text || '-'
                            )}

                        </span>

                        <div class="nilai-alasan-form" data-answer-id="${row.id}" style="margin-top:6px; display:flex; gap:6px; align-items:center;">
                            <input
                                type="number" min="0" max="100"
                                class="nilai-input"
                                style="width:64px; padding:4px;"
                                value="${row.reason_score !== null && row.reason_score !== undefined ? row.reason_score : ''}"
                                placeholder="0-100"
                            >
                            <button type="button" class="btn-simpan-nilai" style="padding:4px 8px;">Simpan</button>
                            <span class="status-simpan" style="font-size:11px; color:#888;"></span>
                        </div>

                    </div>

                </td>


                <td>

                    ${formatConfidence(
                        row.confidence_reason
                    )}

                </td>

            </tr>

        `;

    }).join('');

}


/* =========================================================
   SIMPAN NILAI URAIAN TIER 3 (event delegation, karena
   baris tabel dibuat ulang tiap kali auto-refresh)
========================================================= */

document.getElementById('answerTableBody').addEventListener('click', function (event) {

    const tombol = event.target.closest('.btn-simpan-nilai');
    if (!tombol) return;

    const wrap = tombol.closest('.nilai-alasan-form');
    const answerId = wrap.dataset.answerId;
    const input = wrap.querySelector('.nilai-input');
    const status = wrap.querySelector('.status-simpan');
    const skor = input.value;

    if (skor === '' || Number(skor) < 0 || Number(skor) > 100) {
        status.textContent = 'Skor 0-100.';
        status.style.color = '#c53030';
        return;
    }

    status.textContent = 'Menyimpan...';
    status.style.color = '#888';

    const payload = new URLSearchParams();
    payload.append('action', 'grade');
    payload.append('answer_id', answerId);
    payload.append('score', skor);

    fetch('jawaban_siswa.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                status.textContent = 'Tersimpan.';
                status.style.color = '#2f855a';
            } else {
                status.textContent = data.message || 'Gagal menyimpan.';
                status.style.color = '#c53030';
            }
        })
        .catch(() => {
            status.textContent = 'Gagal menyimpan.';
            status.style.color = '#c53030';
        });
});


/* =========================================================
   JEDA AUTO-REFRESH SAAT ADMIN SEDANG MENGISI NILAI
   (supaya input tidak tertimpa/hilang tiap 3 detik)
========================================================= */

let sedangMengisiNilai = false;

document.getElementById('answerTableBody').addEventListener('focusin', function (event) {
    if (event.target.classList.contains('nilai-input')) {
        sedangMengisiNilai = true;
    }
});

document.getElementById('answerTableBody').addEventListener('focusout', function (event) {
    if (event.target.classList.contains('nilai-input')) {
        sedangMengisiNilai = false;
    }
});


/* =========================================================
   UPDATE DATA
========================================================= */

let isFirstLoad = true;


async function updateJawabanSiswa() {

    if (sedangMengisiNilai) {
        return;
    }

    try {

        const response = await fetch(
            'jawaban_siswa.php?ajax=1&_='
            + Date.now(),
            {
                method: 'GET',

                cache: 'no-store',

                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        );


        if (!response.ok) {

            throw new Error(
                'Gagal mengambil data.'
            );

        }


        const data = await response.json();


        if (!Array.isArray(data)) {

            throw new Error(
                'Format data tidak valid.'
            );

        }


        /* UPDATE TABEL */

        const tableBody =
            document.getElementById(
                'answerTableBody'
            );


        tableBody.innerHTML =
            createTableRows(data);


        /* UPDATE TOTAL */

        document.getElementById(
            'totalJawaban'
        ).textContent = data.length;


        /* UPDATE WAKTU */

        const now = new Date();


        const time =
            now.toLocaleTimeString(
                'id-ID',
                {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                }
            );


        document.getElementById(
            'lastUpdate'
        ).textContent =
            'Terakhir diperbarui: ' + time;


        isFirstLoad = false;


    } catch (error) {

        console.error(
            'Real-time error:',
            error
        );


        document.getElementById(
            'lastUpdate'
        ).textContent =
            'Gagal memperbarui data';

    }

}


/* =========================================================
   JALANKAN REAL-TIME
========================================================= */

/*
   Data akan dicek setiap 3 detik.

   Jadi ketika siswa mengirim jawaban,
   admin tidak perlu menekan refresh.
*/

setInterval(
    updateJawabanSiswa,
    3000
);


/* Update pertama */

updateJawabanSiswa();


</script>


</body>

</html>
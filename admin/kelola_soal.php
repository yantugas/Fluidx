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

$tingkat_valid = ['mudah', 'sedang', 'sulit'];
$kunci_valid   = ['tepat', 'tidak_tepat'];
$poin_per_tingkat = ['mudah' => 2, 'sedang' => 8, 'sulit' => 8];
$message = "";
$message_type = "";


// ========================================
// KONFIGURASI UPLOAD MEDIA (GAMBAR & VIDEO)
// ========================================
// Video stimulus Tier 1 maksimal 8 MB.
// PENTING: hosting gratis seperti InfinityFree membatasi ukuran file
// maksimal 10 MB per file secara hard limit di semua servernya (tidak
// bisa dinaikkan lewat php.ini/.htaccess). Diset 8 MB untuk beri sedikit
// ruang aman dari overhead upload. Kalau hosting Anda tidak punya
// batasan ini (VPS/hosting berbayar), silakan naikkan lagi nilainya di
// bawah, dan sesuaikan juga label serta php_value di
// assets/uploads/.htaccess.

define('MEDIA_DIR_IMAGE', __DIR__ . '/../assets/uploads/questions/images/');
define('MEDIA_DIR_VIDEO', __DIR__ . '/../assets/uploads/questions/videos/');
define('MEDIA_URL_IMAGE', 'assets/uploads/questions/images/'); // relatif dari root project
define('MEDIA_URL_VIDEO', 'assets/uploads/questions/videos/');

define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);     // 5 MB
define('MAX_VIDEO_SIZE', 8 * 1024 * 1024);     // 8 MB

$allowed_image_ext  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$allowed_image_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

$allowed_video_ext  = ['mp4', 'webm', 'ogg', 'mov'];
$allowed_video_mime = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];

foreach ([MEDIA_DIR_IMAGE, MEDIA_DIR_VIDEO] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

/**
 * Validasi + pindahkan file upload ke folder tujuan.
 * Mengembalikan array [pathRelatif, errorMessage]. Salah satu selalu null.
 * Jika tidak ada file yang diupload, mengembalikan [null, null] (bukan error).
 */
function proses_upload_media(
    string $field,
    string $destDir,
    string $destUrl,
    array $allowedExt,
    array $allowedMime,
    int $maxSize,
    string $labelUntukPesan
): array {

    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return [null, "$labelUntukPesan terlalu besar (melebihi batas server)."];
        }
        return [null, "Gagal mengupload $labelUntukPesan (kode error: {$file['error']})."];
    }

    if ($file['size'] > $maxSize) {
        $maxMb = round($maxSize / (1024 * 1024));
        return [null, "$labelUntukPesan melebihi ukuran maksimal {$maxMb} MB."];
    }

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = mime_content_type($file['tmp_name']);

    if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
        return [null, "Format $labelUntukPesan tidak didukung. Gunakan: " . implode(', ', $allowedExt) . "."];
    }

    $namaFile = uniqid('soal_', true) . '.' . $ext;
    $tujuan   = $destDir . $namaFile;

    if (!move_uploaded_file($file['tmp_name'], $tujuan)) {
        return [null, "Gagal menyimpan $labelUntukPesan ke server."];
    }

    return [$destUrl . $namaFile, null];
}

/**
 * Hapus file media lama dari disk (dipanggil saat media diganti/dihapus/soal dihapus).
 */
function hapus_file_media(?string $pathRelatif): void {
    if (!$pathRelatif) {
        return;
    }
    $fullPath = __DIR__ . '/../' . $pathRelatif;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}


// ========================================
// AMBIL DAFTAR KONSEP (untuk dropdown)
// ========================================

$daftar_konsep = [];
$res_konsep = mysqli_query($conn, "SELECT id, concept_name FROM concepts ORDER BY concept_name ASC");
if ($res_konsep) {
    while ($row = mysqli_fetch_assoc($res_konsep)) {
        $daftar_konsep[] = $row;
    }
}


// ========================================
// PROSES: TAMBAH SOAL
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {

    $concept_id_input = $_POST['concept_id'] ?? '';
    $concept_new      = trim($_POST['concept_new'] ?? '');

    $question_text  = trim($_POST['question_text'] ?? '');

    // --- Ambil 3 pernyataan Tier 1 (teks, tingkat kesulitan, kunci) ---
    $pernyataan = [];
    for ($i = 1; $i <= 3; $i++) {
        $pernyataan[$i] = [
            'text'       => trim($_POST['statement_text_' . $i] ?? ''),
            'difficulty' => $_POST['statement_difficulty_' . $i] ?? '',
            'correct'    => $_POST['statement_correct_' . $i] ?? '',
        ];
    }

    $errors = [];

    // --- Validasi konsep ---
    if ($concept_id_input === 'new') {
        if ($concept_new === '') {
            $errors[] = "Nama konsep baru wajib diisi.";
        }
    } elseif ($concept_id_input === '' || !ctype_digit((string) $concept_id_input)) {
        $errors[] = "Konsep wajib dipilih.";
    }

    // --- Validasi teks stimulus ---
    if ($question_text === '') {
        $errors[] = "Teks stimulus/pertanyaan wajib diisi.";
    }

    // --- Validasi 3 pernyataan ---
    foreach ($pernyataan as $no => $p) {
        if ($p['text'] === '') {
            $errors[] = "Teks pernyataan $no wajib diisi.";
        }
        if (!in_array($p['difficulty'], $tingkat_valid, true)) {
            $errors[] = "Tingkat kesulitan pernyataan $no wajib dipilih.";
        }
        if (!in_array($p['correct'], $kunci_valid, true)) {
            $errors[] = "Kunci (Tepat/Tidak Tepat) pernyataan $no wajib dipilih.";
        }
    }

    // --- Validasi & upload media (divalidasi dulu sebelum disimpan ke DB) ---
    $stimulus_image = null;
    $stimulus_video = null;

    if (empty($errors)) {
        [$stimulus_image, $errImage] = proses_upload_media(
            'stimulus_image', MEDIA_DIR_IMAGE, MEDIA_URL_IMAGE,
            $allowed_image_ext, $allowed_image_mime, MAX_IMAGE_SIZE, 'Gambar stimulus'
        );
        if ($errImage) $errors[] = $errImage;

        [$stimulus_video, $errVideo] = proses_upload_media(
            'stimulus_video', MEDIA_DIR_VIDEO, MEDIA_URL_VIDEO,
            $allowed_video_ext, $allowed_video_mime, MAX_VIDEO_SIZE, 'Video stimulus (Tier 1)'
        );
        if ($errVideo) $errors[] = $errVideo;
    }

    if (!empty($errors)) {
        // Bersihkan file yang sudah terlanjur terupload jika ada error lain
        hapus_file_media($stimulus_image);
        hapus_file_media($stimulus_video);

        $message = implode(' ', $errors);
        $message_type = "error";

    } else {

        // Buat konsep baru jika dipilih "tambah konsep baru"
        if ($concept_id_input === 'new') {
            $stmtK = mysqli_prepare($conn, "INSERT INTO concepts (concept_name) VALUES (?)");
            mysqli_stmt_bind_param($stmtK, "s", $concept_new);
            mysqli_stmt_execute($stmtK);
            $concept_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmtK);
        } else {
            $concept_id = (int) $concept_id_input;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO questions
                (concept_id, question_text, stimulus_image, stimulus_video, is_active)
             VALUES (?, ?, ?, ?, 1)"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "isss",
            $concept_id,
            $question_text,
            $stimulus_image,
            $stimulus_video
        );

        if (mysqli_stmt_execute($stmt)) {

            $question_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            // Simpan 3 pernyataan Tier 1 ke question_statements
            $stmtStmt = mysqli_prepare(
                $conn,
                "INSERT INTO question_statements
                    (question_id, statement_order, statement_text, difficulty, points, correct_answer)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            foreach ($pernyataan as $urutan => $p) {
                $poin = $poin_per_tingkat[$p['difficulty']];
                mysqli_stmt_bind_param(
                    $stmtStmt, "iissis",
                    $question_id, $urutan, $p['text'], $p['difficulty'], $poin, $p['correct']
                );
                mysqli_stmt_execute($stmtStmt);
            }

            mysqli_stmt_close($stmtStmt);

            $message = "Soal berhasil ditambahkan.";
            $message_type = "success";

        } else {
            $message = "Gagal menambahkan soal: " . mysqli_stmt_error($stmt);
            $message_type = "error";
            hapus_file_media($stimulus_image);
            hapus_file_media($stimulus_video);
        }
    }
}


// ========================================
// PROSES: EDIT SOAL
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {

    $id = (int) ($_POST['id'] ?? 0);

    $concept_id_input = $_POST['concept_id'] ?? '';
    $concept_new      = trim($_POST['concept_new'] ?? '');

    $question_text  = trim($_POST['question_text'] ?? '');

    $pernyataan = [];
    for ($i = 1; $i <= 3; $i++) {
        $pernyataan[$i] = [
            'text'       => trim($_POST['statement_text_' . $i] ?? ''),
            'difficulty' => $_POST['statement_difficulty_' . $i] ?? '',
            'correct'    => $_POST['statement_correct_' . $i] ?? '',
        ];
    }

    $hapus_gambar_lama = isset($_POST['hapus_gambar']);
    $hapus_video_lama  = isset($_POST['hapus_video']);

    $errors = [];

    if ($id <= 0) {
        $errors[] = "Soal tidak ditemukan.";
    }

    if ($concept_id_input === 'new') {
        if ($concept_new === '') {
            $errors[] = "Nama konsep baru wajib diisi.";
        }
    } elseif ($concept_id_input === '' || !ctype_digit((string) $concept_id_input)) {
        $errors[] = "Konsep wajib dipilih.";
    }

    if ($question_text === '') {
        $errors[] = "Teks stimulus/pertanyaan wajib diisi.";
    }

    foreach ($pernyataan as $no => $p) {
        if ($p['text'] === '') {
            $errors[] = "Teks pernyataan $no wajib diisi.";
        }
        if (!in_array($p['difficulty'], $tingkat_valid, true)) {
            $errors[] = "Tingkat kesulitan pernyataan $no wajib dipilih.";
        }
        if (!in_array($p['correct'], $kunci_valid, true)) {
            $errors[] = "Kunci (Tepat/Tidak Tepat) pernyataan $no wajib dipilih.";
        }
    }

    // Ambil data lama (untuk tahu path media lama)
    $soalLama = null;
    if ($id > 0) {
        $stmtOld = mysqli_prepare($conn, "SELECT stimulus_image, stimulus_video FROM questions WHERE id = ?");
        mysqli_stmt_bind_param($stmtOld, "i", $id);
        mysqli_stmt_execute($stmtOld);
        $soalLama = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtOld));
        mysqli_stmt_close($stmtOld);
    }

    $stimulus_image_baru = null;
    $stimulus_video_baru = null;

    if (empty($errors)) {
        [$stimulus_image_baru, $errImage] = proses_upload_media(
            'stimulus_image', MEDIA_DIR_IMAGE, MEDIA_URL_IMAGE,
            $allowed_image_ext, $allowed_image_mime, MAX_IMAGE_SIZE, 'Gambar stimulus'
        );
        if ($errImage) $errors[] = $errImage;

        [$stimulus_video_baru, $errVideo] = proses_upload_media(
            'stimulus_video', MEDIA_DIR_VIDEO, MEDIA_URL_VIDEO,
            $allowed_video_ext, $allowed_video_mime, MAX_VIDEO_SIZE, 'Video stimulus (Tier 1)'
        );
        if ($errVideo) $errors[] = $errVideo;
    }

    if (!empty($errors)) {
        hapus_file_media($stimulus_image_baru);
        hapus_file_media($stimulus_video_baru);

        $message = implode(' ', $errors);
        $message_type = "error";

    } else {

        if ($concept_id_input === 'new') {
            $stmtK = mysqli_prepare($conn, "INSERT INTO concepts (concept_name) VALUES (?)");
            mysqli_stmt_bind_param($stmtK, "s", $concept_new);
            mysqli_stmt_execute($stmtK);
            $concept_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmtK);
        } else {
            $concept_id = (int) $concept_id_input;
        }

        // Tentukan nilai final kolom media:
        // - kalau upload baru ada isinya -> pakai yang baru (hapus file lama)
        // - kalau dicentang "hapus" -> null (hapus file lama)
        // - kalau tidak -> tetap pakai path lama
        $stimulus_image_final = $soalLama['stimulus_image'] ?? null;
        if ($stimulus_image_baru !== null) {
            hapus_file_media($stimulus_image_final);
            $stimulus_image_final = $stimulus_image_baru;
        } elseif ($hapus_gambar_lama) {
            hapus_file_media($stimulus_image_final);
            $stimulus_image_final = null;
        }

        $stimulus_video_final = $soalLama['stimulus_video'] ?? null;
        if ($stimulus_video_baru !== null) {
            hapus_file_media($stimulus_video_final);
            $stimulus_video_final = $stimulus_video_baru;
        } elseif ($hapus_video_lama) {
            hapus_file_media($stimulus_video_final);
            $stimulus_video_final = null;
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE questions SET
                concept_id = ?, question_text = ?, stimulus_image = ?, stimulus_video = ?
             WHERE id = ?"
        );

        mysqli_stmt_bind_param(
            $stmt,
            "isssi",
            $concept_id,
            $question_text,
            $stimulus_image_final,
            $stimulus_video_final,
            $id
        );

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            // Ganti 3 pernyataan Tier 1: hapus yang lama, masukkan yang baru
            $stmtDel = mysqli_prepare($conn, "DELETE FROM question_statements WHERE question_id = ?");
            mysqli_stmt_bind_param($stmtDel, "i", $id);
            mysqli_stmt_execute($stmtDel);
            mysqli_stmt_close($stmtDel);

            $stmtStmt = mysqli_prepare(
                $conn,
                "INSERT INTO question_statements
                    (question_id, statement_order, statement_text, difficulty, points, correct_answer)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            foreach ($pernyataan as $urutan => $p) {
                $poin = $poin_per_tingkat[$p['difficulty']];
                mysqli_stmt_bind_param(
                    $stmtStmt, "iissis",
                    $id, $urutan, $p['text'], $p['difficulty'], $poin, $p['correct']
                );
                mysqli_stmt_execute($stmtStmt);
            }

            mysqli_stmt_close($stmtStmt);

            $message = "Soal berhasil diperbarui.";
            $message_type = "success";
        } else {
            $message = "Gagal memperbarui soal: " . mysqli_stmt_error($stmt);
            $message_type = "error";
        }
    }
}


// ========================================
// PROSES: HAPUS SOAL
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {

    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {

        // Hapus file media dari disk sebelum baris dihapus
        $stmtMedia = mysqli_prepare($conn, "SELECT stimulus_image, stimulus_video FROM questions WHERE id = ?");
        mysqli_stmt_bind_param($stmtMedia, "i", $id);
        mysqli_stmt_execute($stmtMedia);
        $mediaLama = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtMedia));
        mysqli_stmt_close($stmtMedia);

        if ($mediaLama) {
            hapus_file_media($mediaLama['stimulus_image']);
            hapus_file_media($mediaLama['stimulus_video']);
        }

        // question_options ikut terhapus otomatis (ON DELETE CASCADE)
        $stmt = mysqli_prepare($conn, "DELETE FROM questions WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);

        if (mysqli_stmt_execute($stmt)) {
            $message = "Soal berhasil dihapus.";
            $message_type = "success";
        } else {
            $message = "Gagal menghapus soal: " . mysqli_stmt_error($stmt);
            $message_type = "error";
        }

        mysqli_stmt_close($stmt);
    }
}


// ========================================
// PROSES: TOGGLE STATUS AKTIF / NONAKTIF
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle') {

    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE questions SET is_active = NOT is_active WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, "i", $id);

        if (mysqli_stmt_execute($stmt)) {
            $message = "Status soal berhasil diubah.";
            $message_type = "success";
        } else {
            $message = "Gagal mengubah status soal: " . mysqli_stmt_error($stmt);
            $message_type = "error";
        }

        mysqli_stmt_close($stmt);
    }
}


// ========================================
// AMBIL DATA SOAL YANG SEDANG DIEDIT (JIKA ADA)
// ========================================

$editing = null;
$editing_statements = [
    1 => ['text' => '', 'difficulty' => '', 'correct' => ''],
    2 => ['text' => '', 'difficulty' => '', 'correct' => ''],
    3 => ['text' => '', 'difficulty' => '', 'correct' => ''],
];

if (isset($_GET['edit'])) {

    $edit_id = (int) $_GET['edit'];

    $stmt = mysqli_prepare($conn, "SELECT * FROM questions WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $editing = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if ($editing) {
        $stmtStmt = mysqli_prepare(
            $conn,
            "SELECT statement_order, statement_text, difficulty, correct_answer
             FROM question_statements WHERE question_id = ? ORDER BY statement_order ASC"
        );
        mysqli_stmt_bind_param($stmtStmt, "i", $edit_id);
        mysqli_stmt_execute($stmtStmt);
        $resStmt = mysqli_stmt_get_result($stmtStmt);
        while ($row = mysqli_fetch_assoc($resStmt)) {
            $editing_statements[(int) $row['statement_order']] = [
                'text'       => $row['statement_text'],
                'difficulty' => $row['difficulty'],
                'correct'    => $row['correct_answer'],
            ];
        }
        mysqli_stmt_close($stmtStmt);
    }
}


// ========================================
// AMBIL DAFTAR SOAL
// ========================================

$list_result = mysqli_query(
    $conn,
    "SELECT q.*, c.concept_name
     FROM questions q
     LEFT JOIN concepts c ON c.id = q.concept_id
     ORDER BY q.id DESC"
);
$daftar_soal = [];

if ($list_result) {
    while ($row = mysqli_fetch_assoc($list_result)) {
        $daftar_soal[] = $row;
    }
}

// Ambil ringkasan pernyataan (kunci + poin) tiap soal untuk ditampilkan di tabel
$statements_by_question = [];
$resAllStmt = mysqli_query(
    $conn,
    "SELECT question_id, statement_order, difficulty, points, correct_answer
     FROM question_statements ORDER BY question_id ASC, statement_order ASC"
);
if ($resAllStmt) {
    while ($row = mysqli_fetch_assoc($resAllStmt)) {
        $statements_by_question[$row['question_id']][] = $row;
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Soal - Quiz Fluida Statis</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-common.css">
    <link rel="stylesheet" href="../assets/css/admin-layout.css">
    <link rel="stylesheet" href="../assets/css/kelola_soal.css">
</head>

<script>
const MAX_VIDEO_SIZE_BYTES = <?= MAX_VIDEO_SIZE; ?>;
const MAX_IMAGE_SIZE_BYTES = <?= MAX_IMAGE_SIZE; ?>;

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

function bukaModalTambah() {
    document.getElementById('modalSoal').classList.add('show');
    document.getElementById('modalTitle').textContent = 'Tambah Soal';
    document.getElementById('formSoal').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';

    toggleKonsepBaru(false);
    document.getElementById('previewGambarWrap').style.display = 'none';
    document.getElementById('previewVideoWrap').style.display = 'none';
    document.getElementById('hapusGambarWrap').style.display = 'none';
    document.getElementById('hapusVideoWrap').style.display = 'none';
}

function tutupModal() {
    document.getElementById('modalSoal').classList.remove('show');
}

function konfirmasiHapus(id, teks) {
    if (confirm('Yakin ingin menghapus soal berikut?\n\n' + teks)) {
        document.getElementById('deleteId_' + id).submit();
    }
}

function toggleKonsepBaru(show) {
    const wrap = document.getElementById('konsepBaruWrap');
    const select = document.getElementById('konsepSelect');
    if (show) {
        wrap.style.display = 'block';
        select.value = 'new';
    } else {
        wrap.style.display = 'none';
        document.getElementById('konsepBaruInput').value = '';
        if (select.value === 'new') select.value = '';
    }
}

function onKonsepChange(select) {
    toggleKonsepBaru(select.value === 'new');
}

function validasiUkuranFile(input, maxBytes, labelSoal) {
    if (input.files && input.files[0] && input.files[0].size > maxBytes) {
        alert(labelSoal + ' terlalu besar. Maksimal ' + Math.round(maxBytes / (1024 * 1024)) + ' MB.');
        input.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('inputGambar').addEventListener('change', function () {
        validasiUkuranFile(this, MAX_IMAGE_SIZE_BYTES, 'Gambar stimulus');
    });
    document.getElementById('inputVideo').addEventListener('change', function () {
        validasiUkuranFile(this, MAX_VIDEO_SIZE_BYTES, 'Video stimulus (Tier 1)');
    });
});
</script>

<body <?php echo $editing ? "onload=\"document.getElementById('modalSoal').classList.add('show')\"" : ""; ?>>

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
                <a href="kelola_soal.php" class="active">
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
                <h1>Kelola Soal</h1>
                <p>Tambah, edit, dan atur soal four-tier beserta kunci jawaban.</p>
            </div>

            <div class="admin-profile">
                <i class="fa-solid fa-user"></i>
                <span><?= htmlspecialchars($nama_admin); ?></span>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert-box <?= htmlspecialchars($message_type); ?>">
                <?= htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="panel">

            <div class="panel-head">
                <h2>Daftar Soal (<?= count($daftar_soal); ?>)</h2>
                <button class="btn-primary" onclick="bukaModalTambah()">
                    <i class="fa-solid fa-plus"></i> Tambah Soal
                </button>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Konsep</th>
                            <th>Soal</th>
                            <th>Media</th>
                            <th>Pernyataan (Tier 1)</th>
                            <th>Total Poin</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_soal)): ?>
                            <tr>
                                <td colspan="8" class="empty-row">Belum ada soal. Klik "Tambah Soal" untuk membuat soal baru.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daftar_soal as $i => $soal): ?>
                                <tr>
                                    <td><?= $i + 1; ?></td>
                                    <td><?= htmlspecialchars($soal['concept_name'] ?? '-'); ?></td>
                                    <td class="soal-cell"><?= htmlspecialchars(mb_strimwidth($soal['question_text'], 0, 90, '...')); ?></td>
                                    <td>
                                        <?php if (!empty($soal['stimulus_image'])): ?>
                                            <i class="fa-solid fa-image" title="Ada gambar"></i>
                                        <?php endif; ?>
                                        <?php if (!empty($soal['stimulus_video'])): ?>
                                            <i class="fa-solid fa-video" title="Ada video"></i>
                                        <?php endif; ?>
                                        <?php if (empty($soal['stimulus_image']) && empty($soal['stimulus_video'])): ?>
                                            <span style="color:#bbb;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php
                                        $stmts_soal = $statements_by_question[$soal['id']] ?? [];
                                        $total_poin = array_sum(array_column($stmts_soal, 'points'));
                                    ?>
                                    <td>
                                        <?php foreach ($stmts_soal as $s): ?>
                                            <span class="badge-key" title="<?= htmlspecialchars(ucfirst($s['difficulty'])); ?> (<?= $s['points']; ?> poin)">
                                                <?= $s['correct_answer'] === 'tepat' ? 'Tepat' : 'Tidak Tepat'; ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (empty($stmts_soal)): ?>
                                            <span style="color:#bbb;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= $total_poin; ?></strong></td>
                                    <td>
                                        <?php if ($soal['is_active']): ?>
                                            <span class="badge-status active">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge-status inactive">Nonaktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="aksi-cell">
                                        <a class="btn-icon" href="?edit=<?= $soal['id']; ?>" title="Edit">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>

                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= $soal['id']; ?>">
                                            <button type="submit" class="btn-icon" title="Toggle Status">
                                                <i class="fa-solid fa-toggle-on"></i>
                                            </button>
                                        </form>

                                        <form id="deleteId_<?= $soal['id']; ?>" method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $soal['id']; ?>">
                                        </form>
                                        <button
                                            type="button"
                                            class="btn-icon danger"
                                            title="Hapus"
                                            onclick="konfirmasiHapus(<?= $soal['id']; ?>, '<?= htmlspecialchars(addslashes(mb_strimwidth($soal['question_text'], 0, 60, '...')), ENT_QUOTES); ?>')"
                                        >
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

    </main>


    <!-- MODAL TAMBAH / EDIT SOAL -->
    <div class="modal-overlay" id="modalSoal">
        <div class="modal-box">

            <div class="modal-header">
                <h2 id="modalTitle"><?= $editing ? 'Edit Soal' : 'Tambah Soal'; ?></h2>
                <button type="button" class="modal-close" onclick="tutupModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" id="formSoal" enctype="multipart/form-data">

                <input type="hidden" name="action" id="formAction" value="<?= $editing ? 'edit' : 'add'; ?>">
                <input type="hidden" name="id" id="formId" value="<?= $editing ? $editing['id'] : ''; ?>">

                <!-- Wajib diletakkan SEBELUM input file, sekadar hint ukuran (validasi asli tetap di server) -->
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_VIDEO_SIZE; ?>">

                <div class="form-section-title">Konsep</div>

                <div class="form-group">
                    <label>Konsep / Materi</label>
                    <select name="concept_id" id="konsepSelect" onchange="onKonsepChange(this)" required>
                        <option value="">-- Pilih Konsep --</option>
                        <?php foreach ($daftar_konsep as $k): ?>
                            <option value="<?= $k['id']; ?>" <?= ($editing && (int)$editing['concept_id'] === (int)$k['id']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($k['concept_name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="new">+ Tambah konsep baru...</option>
                    </select>
                </div>

                <div class="form-group" id="konsepBaruWrap" style="display:none;">
                    <label>Nama Konsep Baru</label>
                    <input type="text" name="concept_new" id="konsepBaruInput" placeholder="Contoh: Tekanan Hidrostatis">
                </div>

                <div class="form-section-title">Stimulus &amp; Pertanyaan (Tier 1)</div>

                <div class="form-group">
                    <label>Teks Stimulus / Pertanyaan</label>
                    <textarea name="question_text" rows="4" required><?= $editing ? htmlspecialchars($editing['question_text']) : ''; ?></textarea>
                </div>

                <div class="option-grid">
                    <div class="form-group">
                        <label>Gambar Stimulus (opsional, maks 5 MB)</label>
                        <input type="file" name="stimulus_image" id="inputGambar" accept="image/jpeg,image/png,image/gif,image/webp">

                        <div id="previewGambarWrap" style="<?= ($editing && !empty($editing['stimulus_image'])) ? '' : 'display:none;'; ?> margin-top:8px;">
                            <?php if ($editing && !empty($editing['stimulus_image'])): ?>
                                <img src="../<?= htmlspecialchars($editing['stimulus_image']); ?>" alt="Gambar saat ini" style="max-width:120px; border-radius:8px; display:block; margin-bottom:6px;">
                            <?php endif; ?>
                        </div>

                        <label id="hapusGambarWrap" style="<?= ($editing && !empty($editing['stimulus_image'])) ? '' : 'display:none;'; ?> font-weight:400; font-size:12.5px; color:#c53030;">
                            <input type="checkbox" name="hapus_gambar" value="1"> Hapus gambar saat ini
                        </label>
                    </div>

                    <div class="form-group">
                        <label>Video Stimulus Tier 1 (opsional, maks 8 MB)</label>
                        <input type="file" name="stimulus_video" id="inputVideo" accept="video/mp4,video/webm,video/ogg,video/quicktime">

                        <div id="previewVideoWrap" style="<?= ($editing && !empty($editing['stimulus_video'])) ? '' : 'display:none;'; ?> margin-top:8px;">
                            <?php if ($editing && !empty($editing['stimulus_video'])): ?>
                                <video src="../<?= htmlspecialchars($editing['stimulus_video']); ?>" controls style="max-width:180px; border-radius:8px; display:block; margin-bottom:6px;"></video>
                            <?php endif; ?>
                        </div>

                        <label id="hapusVideoWrap" style="<?= ($editing && !empty($editing['stimulus_video'])) ? '' : 'display:none;'; ?> font-weight:400; font-size:12.5px; color:#c53030;">
                            <input type="checkbox" name="hapus_video" value="1"> Hapus video saat ini
                        </label>
                    </div>
                </div>

                <div class="form-section-title">Pernyataan Tier 1 (3 pernyataan, Tepat / Tidak Tepat)</div>

                <?php foreach ([1, 2, 3] as $no): ?>
                    <div class="form-group" style="border:1px solid #e5e5e5; border-radius:8px; padding:12px; margin-bottom:10px;">
                        <label>Pernyataan <?= $no; ?></label>
                        <textarea name="statement_text_<?= $no; ?>" rows="2" required><?= $editing ? htmlspecialchars($editing_statements[$no]['text']) : ''; ?></textarea>

                        <div class="option-grid" style="margin-top:8px;">
                            <div class="form-group">
                                <label>Tingkat Kesulitan</label>
                                <select name="statement_difficulty_<?= $no; ?>" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="mudah" <?= ($editing && $editing_statements[$no]['difficulty'] === 'mudah') ? 'selected' : ''; ?>>Mudah (2 poin)</option>
                                    <option value="sedang" <?= ($editing && $editing_statements[$no]['difficulty'] === 'sedang') ? 'selected' : ''; ?>>Sedang (8 poin)</option>
                                    <option value="sulit" <?= ($editing && $editing_statements[$no]['difficulty'] === 'sulit') ? 'selected' : ''; ?>>Sulit (8 poin)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Kunci Jawaban</label>
                                <select name="statement_correct_<?= $no; ?>" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="tepat" <?= ($editing && $editing_statements[$no]['correct'] === 'tepat') ? 'selected' : ''; ?>>Tepat</option>
                                    <option value="tidak_tepat" <?= ($editing && $editing_statements[$no]['correct'] === 'tidak_tepat') ? 'selected' : ''; ?>>Tidak Tepat</option>
                                </select>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <p class="form-note">
                    Catatan: Tier 2 (keyakinan jawaban) dan Tier 4 (keyakinan alasan)
                    berupa pilihan Yakin / Tidak Yakin yang diisi siswa saat mengerjakan
                    quiz, sehingga tidak diatur di sini. Tier 3 (alasan) diisi siswa
                    secara uraian bebas dan dinilai manual oleh admin di halaman
                    Jawaban Siswa.
                </p>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="tutupModal()">Batal</button>
                    <button type="submit" class="btn-primary">Simpan Soal</button>
                </div>

            </form>

        </div>
    </div>

</body>
</html>

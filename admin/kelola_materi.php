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

$message = "";
$message_type = "";


// ========================================
// KONFIGURASI UPLOAD MATERI (PPT, PDF, DLL)
// ========================================

define('MATERI_DIR', __DIR__ . '/../assets/uploads/materi/');
define('MATERI_URL', 'assets/uploads/materi/'); // relatif dari root project
// Batas upload disesuaikan dengan hosting gratis seperti InfinityFree,
// yang membatasi ukuran file maksimal 10 MB per file di semua servernya
// (tidak bisa dinaikkan lewat php.ini/.htaccess). Diset 8 MB untuk beri
// sedikit ruang aman dari overhead upload. Kalau hosting Anda tidak
// punya batasan ini (VPS/hosting berbayar), silakan naikkan lagi.
define('MAX_MATERI_SIZE', 8 * 1024 * 1024);    // 8 MB

$allowed_materi_ext = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx'];
$allowed_materi_mime = [
    'application/pdf',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/zip', // beberapa server mendeteksi file office (docx/pptx/xlsx) sebagai zip
];

if (!is_dir(MATERI_DIR)) {
    @mkdir(MATERI_DIR, 0755, true);
}

/**
 * Validasi + pindahkan file materi ke folder tujuan.
 * Mengembalikan array [namaAsli, pathRelatif, ekstensi, ukuran, errorMessage].
 * Kalau tidak ada file yang diupload, mengembalikan semua null (bukan error).
 */
function proses_upload_materi(
    string $field,
    array $allowedExt,
    array $allowedMime,
    int $maxSize
): array {

    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null, null, null, null];
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $pesan = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
            ? "File materi terlalu besar (melebihi batas server)."
            : "Gagal mengupload file materi (kode error: {$file['error']}).";
        return [null, null, null, null, $pesan];
    }

    if ($file['size'] > $maxSize) {
        $maxMb = round($maxSize / (1024 * 1024));
        return [null, null, null, null, "File materi melebihi ukuran maksimal {$maxMb} MB."];
    }

    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = mime_content_type($file['tmp_name']);

    if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
        return [null, null, null, null, "Format file tidak didukung. Gunakan: " . implode(', ', $allowedExt) . "."];
    }

    $namaFile = uniqid('materi_', true) . '.' . $ext;
    $tujuan   = MATERI_DIR . $namaFile;

    if (!move_uploaded_file($file['tmp_name'], $tujuan)) {
        return [null, null, null, null, "Gagal menyimpan file materi ke server."];
    }

    return [$file['name'], MATERI_URL . $namaFile, $ext, $file['size'], null];
}

/**
 * Hapus file materi lama dari disk.
 */
function hapus_file_materi(?string $pathRelatif): void {
    if (!$pathRelatif) {
        return;
    }
    $fullPath = __DIR__ . '/../' . $pathRelatif;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}


// ========================================
// PROSES: TAMBAH MATERI
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {

    $judul     = trim($_POST['judul'] ?? '');
    $deskripsi = trim($_POST['deskripsi'] ?? '');

    $errors = [];

    if ($judul === '') {
        $errors[] = "Judul materi wajib diisi.";
    }

    [$file_name, $file_path, $file_type, $file_size, $errFile] = proses_upload_materi(
        'file_materi', $allowed_materi_ext, $allowed_materi_mime, MAX_MATERI_SIZE
    );

    if ($errFile) {
        $errors[] = $errFile;
    } elseif ($file_path === null) {
        $errors[] = "File materi (PPT/PDF/dll) wajib diupload.";
    }

    if (!empty($errors)) {
        hapus_file_materi($file_path);
        $message = implode(' ', $errors);
        $message_type = "error";

    } else {

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO materi (judul, deskripsi, file_name, file_path, file_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        mysqli_stmt_bind_param(
            $stmt, "sssssi",
            $judul, $deskripsi, $file_name, $file_path, $file_type, $file_size
        );

        if (mysqli_stmt_execute($stmt)) {
            $message = "Materi berhasil diupload.";
            $message_type = "success";
        } else {
            $message = "Gagal menyimpan materi: " . mysqli_stmt_error($stmt);
            $message_type = "error";
            hapus_file_materi($file_path);
        }

        mysqli_stmt_close($stmt);
    }
}


// ========================================
// PROSES: EDIT MATERI
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {

    $id        = (int) ($_POST['id'] ?? 0);
    $judul     = trim($_POST['judul'] ?? '');
    $deskripsi = trim($_POST['deskripsi'] ?? '');

    $errors = [];

    if ($id <= 0) {
        $errors[] = "Materi tidak ditemukan.";
    }

    if ($judul === '') {
        $errors[] = "Judul materi wajib diisi.";
    }

    $materiLama = null;
    if ($id > 0) {
        $stmtOld = mysqli_prepare($conn, "SELECT * FROM materi WHERE id = ?");
        mysqli_stmt_bind_param($stmtOld, "i", $id);
        mysqli_stmt_execute($stmtOld);
        $materiLama = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtOld));
        mysqli_stmt_close($stmtOld);

        if (!$materiLama) {
            $errors[] = "Materi tidak ditemukan.";
        }
    }

    [$file_name_baru, $file_path_baru, $file_type_baru, $file_size_baru, $errFile] = proses_upload_materi(
        'file_materi', $allowed_materi_ext, $allowed_materi_mime, MAX_MATERI_SIZE
    );

    if ($errFile) {
        $errors[] = $errFile;
    }

    if (!empty($errors)) {
        hapus_file_materi($file_path_baru);
        $message = implode(' ', $errors);
        $message_type = "error";

    } else {

        // Kalau ada file baru, pakai yang baru & hapus file lama. Kalau tidak, tetap pakai yang lama.
        $file_name_final = $materiLama['file_name'];
        $file_path_final = $materiLama['file_path'];
        $file_type_final = $materiLama['file_type'];
        $file_size_final = $materiLama['file_size'];

        if ($file_path_baru !== null) {
            hapus_file_materi($file_path_final);
            $file_name_final = $file_name_baru;
            $file_path_final = $file_path_baru;
            $file_type_final = $file_type_baru;
            $file_size_final = $file_size_baru;
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE materi SET
                judul = ?, deskripsi = ?, file_name = ?, file_path = ?, file_type = ?, file_size = ?
             WHERE id = ?"
        );

        mysqli_stmt_bind_param(
            $stmt, "sssssii",
            $judul, $deskripsi, $file_name_final, $file_path_final, $file_type_final, $file_size_final, $id
        );

        if (mysqli_stmt_execute($stmt)) {
            $message = "Materi berhasil diperbarui.";
            $message_type = "success";
        } else {
            $message = "Gagal memperbarui materi: " . mysqli_stmt_error($stmt);
            $message_type = "error";
            hapus_file_materi($file_path_baru);
        }

        mysqli_stmt_close($stmt);
    }
}


// ========================================
// PROSES: HAPUS MATERI
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {

    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0) {

        $stmtOld = mysqli_prepare($conn, "SELECT file_path FROM materi WHERE id = ?");
        mysqli_stmt_bind_param($stmtOld, "i", $id);
        mysqli_stmt_execute($stmtOld);
        $materiLama = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtOld));
        mysqli_stmt_close($stmtOld);

        $stmt = mysqli_prepare($conn, "DELETE FROM materi WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $id);

        if (mysqli_stmt_execute($stmt)) {
            if ($materiLama) {
                hapus_file_materi($materiLama['file_path']);
            }
            $message = "Materi berhasil dihapus.";
            $message_type = "success";
        } else {
            $message = "Gagal menghapus materi: " . mysqli_stmt_error($stmt);
            $message_type = "error";
        }

        mysqli_stmt_close($stmt);
    }
}


// ========================================
// AMBIL DATA MATERI YANG SEDANG DIEDIT (JIKA ADA)
// ========================================

$editing = null;

if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];

    $stmt = mysqli_prepare($conn, "SELECT * FROM materi WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $editing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}


// ========================================
// AMBIL DAFTAR MATERI
// ========================================

$daftar_materi = [];
$list_result = mysqli_query($conn, "SELECT * FROM materi ORDER BY created_at DESC");
if ($list_result) {
    while ($row = mysqli_fetch_assoc($list_result)) {
        $daftar_materi[] = $row;
    }
}

/**
 * Format ukuran file (bytes) jadi string yang mudah dibaca (KB/MB).
 */
function format_ukuran_file(int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Ikon Font Awesome sesuai tipe file materi.
 */
function ikon_tipe_materi(string $tipe): string {
    switch ($tipe) {
        case 'pdf':
            return 'fa-file-pdf';
        case 'ppt':
        case 'pptx':
            return 'fa-file-powerpoint';
        case 'doc':
        case 'docx':
            return 'fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fa-file-excel';
        default:
            return 'fa-file';
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Materi - Quiz Fluida Statis</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin-common.css">
    <link rel="stylesheet" href="../assets/css/admin-layout.css">
    <link rel="stylesheet" href="../assets/css/kelola_materi.css">
</head>

<script>
const MAX_MATERI_SIZE_BYTES = <?= MAX_MATERI_SIZE; ?>;

function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

function bukaModalTambah() {
    document.getElementById('modalMateri').classList.add('show');
    document.getElementById('modalTitle').textContent = 'Upload Materi';
    document.getElementById('formMateri').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('fileMateriWrap').classList.remove('is-edit');
    document.getElementById('inputFileMateri').required = true;
}

function tutupModal() {
    document.getElementById('modalMateri').classList.remove('show');
}

function konfirmasiHapus(id, judul) {
    if (confirm('Yakin ingin menghapus materi berikut?\n\n' + judul)) {
        document.getElementById('deleteId_' + id).submit();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('inputFileMateri').addEventListener('change', function () {
        if (this.files && this.files[0] && this.files[0].size > MAX_MATERI_SIZE_BYTES) {
            alert('File materi terlalu besar. Maksimal ' + Math.round(MAX_MATERI_SIZE_BYTES / (1024 * 1024)) + ' MB.');
            this.value = '';
        }
    });
});
</script>

<body <?php echo $editing ? "onload=\"document.getElementById('modalMateri').classList.add('show')\"" : ""; ?>>

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="logo">
            <div class="logo-icon">
                <i class="fa-solid fa-flask"></i>
            </div>
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
                <a href="kelola_materi.php" class="active">
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
                <h1>Kelola Materi</h1>
                <p>Upload materi (PPT, PDF, Word, Excel) yang otomatis tampil di halaman Materi.</p>
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
                <h2>Daftar Materi (<?= count($daftar_materi); ?>)</h2>
                <button class="btn-primary" onclick="bukaModalTambah()">
                    <i class="fa-solid fa-plus"></i> Upload Materi
                </button>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Judul</th>
                            <th>Deskripsi</th>
                            <th>File</th>
                            <th>Ukuran</th>
                            <th>Tanggal Upload</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($daftar_materi)): ?>
                            <tr>
                                <td colspan="7" class="empty-row">Belum ada materi. Klik "Upload Materi" untuk menambahkan.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($daftar_materi as $i => $materi): ?>
                                <tr>
                                    <td><?= $i + 1; ?></td>
                                    <td class="materi-judul-cell"><?= htmlspecialchars($materi['judul']); ?></td>
                                    <td class="materi-deskripsi-cell">
                                        <?= htmlspecialchars(mb_strimwidth($materi['deskripsi'] ?? '', 0, 70, '...')) ?: '<span style="color:#bbb;">-</span>'; ?>
                                    </td>
                                    <td>
                                        <span class="file-type-badge tipe-<?= htmlspecialchars($materi['file_type']); ?>">
                                            <i class="fa-solid <?= ikon_tipe_materi($materi['file_type']); ?>"></i>
                                            <?= strtoupper(htmlspecialchars($materi['file_type'])); ?>
                                        </span>
                                    </td>
                                    <td><?= format_ukuran_file((int) $materi['file_size']); ?></td>
                                    <td><?= date('d M Y', strtotime($materi['created_at'])); ?></td>
                                    <td class="aksi-cell">
                                        <a class="btn-icon" href="../<?= htmlspecialchars($materi['file_path']); ?>" target="_blank" title="Lihat/Unduh">
                                            <i class="fa-solid fa-download"></i>
                                        </a>

                                        <a class="btn-icon" href="?edit=<?= $materi['id']; ?>" title="Edit">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>

                                        <form id="deleteId_<?= $materi['id']; ?>" method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $materi['id']; ?>">
                                        </form>
                                        <button
                                            type="button"
                                            class="btn-icon danger"
                                            title="Hapus"
                                            onclick="konfirmasiHapus(<?= $materi['id']; ?>, '<?= htmlspecialchars(addslashes($materi['judul']), ENT_QUOTES); ?>')"
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


    <!-- MODAL TAMBAH / EDIT MATERI -->
    <div class="modal-overlay" id="modalMateri">
        <div class="modal-box">

            <div class="modal-header">
                <h2 id="modalTitle"><?= $editing ? 'Edit Materi' : 'Upload Materi'; ?></h2>
                <button type="button" class="modal-close" onclick="tutupModal()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" id="formMateri" enctype="multipart/form-data">

                <input type="hidden" name="action" id="formAction" value="<?= $editing ? 'edit' : 'add'; ?>">
                <input type="hidden" name="id" id="formId" value="<?= $editing ? $editing['id'] : ''; ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_MATERI_SIZE; ?>">

                <div class="form-group">
                    <label>Judul Materi</label>
                    <input type="text" name="judul" required
                           value="<?= $editing ? htmlspecialchars($editing['judul']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label>Deskripsi (opsional)</label>
                    <textarea name="deskripsi" rows="3"><?= $editing ? htmlspecialchars($editing['deskripsi'] ?? '') : ''; ?></textarea>
                </div>

                <div class="form-group <?= $editing ? 'is-edit' : ''; ?>" id="fileMateriWrap">
                    <label>File Materi (PDF, PPT/PPTX, DOC/DOCX, XLS/XLSX &mdash; maks 8 MB)</label>
                    <input type="file" name="file_materi" id="inputFileMateri"
                           accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx"
                           <?= $editing ? '' : 'required'; ?>>

                    <?php if ($editing): ?>
                        <p class="form-note">
                            File saat ini: <strong><?= htmlspecialchars($editing['file_name']); ?></strong>.
                            Biarkan kosong jika tidak ingin mengganti file.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="tutupModal()">Batal</button>
                    <button type="submit" class="btn-primary">Simpan Materi</button>
                </div>

            </form>

        </div>
    </div>

</body>

</html>

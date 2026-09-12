<?php
session_start();

include '../config/database.php';


// ========================================
// CEK APAKAH SUDAH LOGIN SEBAGAI ADMIN
// ========================================

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login_admin.php");
    exit;
}


// ========================================
// TOTAL SOAL
// ========================================

$query_soal = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total 
     FROM questions 
     WHERE is_active = 1"
);

$data_soal = mysqli_fetch_assoc($query_soal);

$total_soal = $data_soal['total'];


// ========================================
// TOTAL SISWA
// ========================================

$query_siswa = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total 
     FROM users 
     WHERE role = 'student'"
);

$data_siswa = mysqli_fetch_assoc($query_siswa);

$total_siswa = $data_siswa['total'];


// ========================================
// TOTAL PENGERJAAN
// ========================================

$query_pengerjaan = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total 
     FROM diagnosis_results"
);

$data_pengerjaan = mysqli_fetch_assoc($query_pengerjaan);

$total_pengerjaan = $data_pengerjaan['total'];


// ========================================
// NAMA ADMIN
// ========================================

$nama_admin = $_SESSION['name'] ?? 'Admin';


// ========================================
// KELOLA HERO (LANDING PAGE) - AMBIL DATA
// ========================================

define('HERO_IMAGE_DIR', __DIR__ . '/../assets/uploads/hero/');
define('HERO_IMAGE_URL', 'assets/uploads/hero/'); // relatif dari root project
define('MAX_HERO_IMAGE_SIZE', 5 * 1024 * 1024); // 5 MB

$allowed_hero_ext  = ['jpg', 'jpeg', 'png', 'webp'];
$allowed_hero_mime = ['image/jpeg', 'image/png', 'image/webp'];

if (!is_dir(HERO_IMAGE_DIR)) {
    @mkdir(HERO_IMAGE_DIR, 0755, true);
}

$hero_message = "";
$hero_message_type = "";

$hero = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hero_settings WHERE id = 1"));

// Jaga-jaga kalau migration belum dijalankan / baris default belum ada
if (!$hero) {
    mysqli_query($conn, "
        INSERT INTO hero_settings (id, badge_text, heading_text, heading_highlight, description_text, button_text, image_path)
        VALUES (1, 'Four-Tier Diagnostic Test', 'Yuk, Uji Pemahamanmu tentang', 'Fluida Statis!',
                'Kerjakan quiz 4-tier untuk mengetahui tingkat pemahaman dan miskonsepsi kamu pada konsep Fluida Statis.',
                'Mulai Quiz', NULL)
    ");
    $hero = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hero_settings WHERE id = 1"));
}


// ========================================
// KELOLA HERO - PROSES SIMPAN PERUBAHAN
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_hero') {

    $badge_text        = trim($_POST['badge_text'] ?? '');
    $heading_text      = trim($_POST['heading_text'] ?? '');
    $heading_highlight = trim($_POST['heading_highlight'] ?? '');
    $description_text  = trim($_POST['description_text'] ?? '');
    $button_text       = trim($_POST['button_text'] ?? '');
    $hapus_gambar      = isset($_POST['hapus_gambar']) && $_POST['hapus_gambar'] === '1';

    if (
        $badge_text === '' || $heading_text === '' || $heading_highlight === '' ||
        $description_text === '' || $button_text === ''
    ) {
        $hero_message = "Semua kolom teks hero wajib diisi.";
        $hero_message_type = "error";

    } else {

        $image_path_baru = $hero['image_path']; // default: pakai yang lama
        $file_lama_untuk_dihapus = null;

        // --- Hapus gambar (jika dicentang & tidak upload baru) ---
        if ($hapus_gambar) {
            $file_lama_untuk_dihapus = $hero['image_path'];
            $image_path_baru = null;
        }

        // --- Upload gambar baru (jika ada) ---
        if (isset($_FILES['hero_image']) && $_FILES['hero_image']['error'] !== UPLOAD_ERR_NO_FILE) {

            $file = $_FILES['hero_image'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $hero_message = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
                    ? "Gambar hero terlalu besar (melebihi batas server)."
                    : "Gagal mengupload gambar hero (kode error: {$file['error']}).";
                $hero_message_type = "error";

            } elseif ($file['size'] > MAX_HERO_IMAGE_SIZE) {
                $hero_message = "Gambar hero melebihi ukuran maksimal " . round(MAX_HERO_IMAGE_SIZE / (1024 * 1024)) . " MB.";
                $hero_message_type = "error";

            } else {

                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $mime = mime_content_type($file['tmp_name']);

                if (!in_array($ext, $allowed_hero_ext, true) || !in_array($mime, $allowed_hero_mime, true)) {
                    $hero_message = "Format gambar tidak didukung. Gunakan: " . implode(', ', $allowed_hero_ext) . ".";
                    $hero_message_type = "error";

                } else {

                    $nama_file = uniqid('hero_', true) . '.' . $ext;
                    $tujuan = HERO_IMAGE_DIR . $nama_file;

                    if (!move_uploaded_file($file['tmp_name'], $tujuan)) {
                        $hero_message = "Gagal menyimpan gambar hero ke server.";
                        $hero_message_type = "error";
                    } else {
                        $file_lama_untuk_dihapus = $hero['image_path']; // gambar lama diganti
                        $image_path_baru = HERO_IMAGE_URL . $nama_file;
                    }
                }
            }
        }

        // --- Simpan ke database jika tidak ada error ---
        if ($hero_message_type !== "error") {

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE hero_settings SET
                    badge_text = ?, heading_text = ?, heading_highlight = ?,
                    description_text = ?, button_text = ?, image_path = ?
                 WHERE id = 1"
            );

            mysqli_stmt_bind_param(
                $stmt,
                "ssssss",
                $badge_text,
                $heading_text,
                $heading_highlight,
                $description_text,
                $button_text,
                $image_path_baru
            );

            if (mysqli_stmt_execute($stmt)) {

                // Hapus file gambar lama dari server (kalau diganti/dihapus)
                if ($file_lama_untuk_dihapus) {
                    $path_fisik = __DIR__ . '/../' . $file_lama_untuk_dihapus;
                    if (is_file($path_fisik)) {
                        @unlink($path_fisik);
                    }
                }

                $hero_message = "Konten hero berhasil diperbarui.";
                $hero_message_type = "success";

            } else {
                $hero_message = "Gagal menyimpan perubahan: " . mysqli_stmt_error($stmt);
                $hero_message_type = "error";
            }

            mysqli_stmt_close($stmt);
        }

        // Refresh data terbaru untuk ditampilkan di form
        $hero = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hero_settings WHERE id = 1"));
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard Admin - Quiz Fluida Statis</title>


    <!-- Font Awesome -->

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">


    <!-- CSS Admin -->

    <link rel="stylesheet" href="../assets/css/admin-common.css">
    <link rel="stylesheet" href="../assets/css/admin-layout.css">
    <link rel="stylesheet" href="../assets/css/admin.css">

</head>

<script>

function toggleSidebar() {

    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');

}

function pratinjauGambarHero(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function (e) {
            const img = document.getElementById('previewGambarHero');
            img.src = e.target.result;
            img.style.display = 'block';
            document.getElementById('previewKosongHero').style.display = 'none';
            document.getElementById('checkHapusGambarHero').checked = false;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function tandaiHapusGambarHero() {
    if (document.getElementById('checkHapusGambarHero').checked) {
        document.getElementById('previewGambarHero').style.display = 'none';
        document.getElementById('previewKosongHero').style.display = 'flex';
        document.getElementById('inputGambarHero').value = '';
    } else {
        location.reload();
    }
}

function updatePratinjauHero() {
    document.getElementById('pvBadgeHero').textContent = document.getElementById('inputBadgeHero').value || 'Badge';
    document.getElementById('pvHeadingHero').textContent = document.getElementById('inputHeadingHero').value || 'Judul hero';
    document.getElementById('pvHighlightHero').textContent = document.getElementById('inputHighlightHero').value || 'Sorotan';
    document.getElementById('pvDescHero').textContent = document.getElementById('inputDescHero').value || 'Deskripsi hero';
    document.getElementById('pvButtonHero').textContent = document.getElementById('inputButtonHero').value || 'Tombol';
}

</script>


<body>


    <!-- ========================================
         SIDEBAR
    ========================================= -->

    <aside class="sidebar">


        <!-- LOGO -->

        <div class="logo">

            <div class="logo-icon">

                <i class="fa-solid fa-flask"></i>

            </div>


            <div class="logo-text">

                <h2>Quiz Fluida Statis</h2>

                <p>Panel Admin</p>

            </div>

        </div>



        <!-- JUDUL MENU -->

        <div class="menu-title">

            Menu Utama

        </div>



        <!-- MENU -->

        <ul class="menu">


            <!-- DASHBOARD -->

            <li>

                <a href="dashboard.php" class="active">

                    <i class="fa-solid fa-house"></i>

                    <span>Dashboard</span>

                </a>

            </li>



            <!-- KELOLA SOAL -->

            <li>

                <a href="kelola_soal.php">

                    <i class="fa-solid fa-pen-to-square"></i>

                    <span>Kelola Soal</span>

                </a>

            </li>



            <!-- KELOLA MATERI -->

            <li>

                <a href="kelola_materi.php">

                    <i class="fa-solid fa-file-arrow-up"></i>

                    <span>Kelola Materi</span>

                </a>

            </li>



            <!-- JAWABAN SISWA -->

            <li>

                <a href="jawaban_siswa.php">

                    <i class="fa-solid fa-users"></i>

                    <span>Jawaban Siswa</span>

                </a>

            </li>



            <!-- MONITORING PELANGGARAN -->

            <li>

                <a href="monitoring_pelanggaran.php">

                    <i class="fa-solid fa-eye"></i>

                    <span>Monitoring Pelanggaran</span>

                </a>

            </li>



            <!-- SCORE SISWA -->

            <li>

                <a href="score_siswa.php">

                    <i class="fa-solid fa-trophy"></i>

                    <span>Score Siswa</span>

                </a>

            </li>



            <!-- PERSENTASE MISKONSEPSI -->

            <li>

                <a href="persentase_miskonsepsi.php">

                    <i class="fa-solid fa-chart-column"></i>

                    <span>Persentase Miskonsepsi</span>

                </a>

            </li>



            <!-- MISKONSEPSI TERBANYAK -->

            <li>

                <a href="miskonsepsi_terbanyak.php">

                    <i class="fa-solid fa-triangle-exclamation"></i>

                    <span>Miskonsepsi Terbanyak</span>

                </a>

            </li>


        </ul>



        <!-- LOGOUT -->

        <div class="logout">

            <a href="../logout.php">

                <i class="fa-solid fa-right-from-bracket"></i>

                <span>Logout</span>

            </a>

        </div>


    </aside>

    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>



    <!-- ========================================
         MAIN CONTENT
    ========================================= -->

    <main class="main">
        <button class="menu-toggle" onclick="toggleSidebar()">
            <i class="fa-solid fa-bars"></i>
        </button>


        <!-- HEADER -->

        <div class="header">


            <div>

                <h1>Dashboard</h1>

                <p>
                    Selamat datang di panel admin Quiz Fluida Statis.
                </p>

            </div>



            <!-- PROFILE ADMIN -->

            <div class="admin-profile">

                <i class="fa-solid fa-user"></i>

                <span>
                    <?= htmlspecialchars($nama_admin); ?>
                </span>

            </div>


        </div>



        <!-- ========================================
             STATISTIK
        ========================================= -->

        <div class="cards">


            <!-- TOTAL SOAL -->

            <div class="card">

                <div class="card-top">


                    <div>

                        <p>Total Soal</p>

                        <h3>
                            <?= $total_soal; ?>
                        </h3>

                    </div>


                    <div class="card-icon">

                        <i class="fa-solid fa-file-lines"></i>

                    </div>


                </div>

            </div>



            <!-- TOTAL SISWA -->

            <div class="card">

                <div class="card-top">


                    <div>

                        <p>Total Siswa</p>

                        <h3>
                            <?= $total_siswa; ?>
                        </h3>

                    </div>


                    <div class="card-icon">

                        <i class="fa-solid fa-users"></i>

                    </div>


                </div>

            </div>



            <!-- TOTAL PENGERJAAN -->

            <div class="card">

                <div class="card-top">


                    <div>

                        <p>Total Pengerjaan</p>

                        <h3>
                            <?= $total_pengerjaan; ?>
                        </h3>

                    </div>


                    <div class="card-icon">

                        <i class="fa-solid fa-chart-simple"></i>

                    </div>


                </div>

            </div>


        </div>



        <!-- ========================================
             WELCOME
        ========================================= -->

        <div class="welcome">


            <h2>
                Selamat Datang, <?= htmlspecialchars($nama_admin); ?> 👋
            </h2>


            <p>

                Melalui halaman admin ini, Anda dapat mengelola soal
                Quiz Fluida Statis, melihat jawaban dan score siswa,
                serta melakukan analisis terhadap miskonsepsi siswa
                berdasarkan hasil pengerjaan quiz.

            </p>


        </div>



        <!-- ========================================
             KELOLA HERO (LANDING PAGE)
        ========================================= -->

        <div class="hero-editor-section">

            <div class="hero-editor-title">
                <h2>Kelola Hero Landing Page</h2>
                <p>Atur gambar dan teks pada bagian hero di halaman utama (index.php).</p>
            </div>

            <?php if (!empty($hero_message)): ?>
                <div class="alert-box <?= htmlspecialchars($hero_message_type); ?>">
                    <?= htmlspecialchars($hero_message); ?>
                </div>
            <?php endif; ?>

            <div class="hero-editor-layout">

                <!-- FORM -->
                <div class="panel">

                    <div class="panel-head">
                        <h2>Edit Konten Hero</h2>
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_hero">

                        <div class="form-group">
                            <label>Gambar Hero</label>

                            <div class="hero-image-uploader">
                                <div class="hero-image-preview">
                                    <img
                                        id="previewGambarHero"
                                        src="<?= $hero['image_path'] ? '../' . htmlspecialchars($hero['image_path']) : ''; ?>"
                                        style="<?= $hero['image_path'] ? '' : 'display:none;'; ?>"
                                        alt="Pratinjau gambar hero"
                                    >
                                    <div id="previewKosongHero" class="hero-image-empty" style="<?= $hero['image_path'] ? 'display:none;' : ''; ?>">
                                        <i class="fa-solid fa-image"></i>
                                        <span>Belum ada gambar</span>
                                    </div>
                                </div>

                                <div class="hero-image-actions">
                                    <label class="btn-secondary btn-upload">
                                        <i class="fa-solid fa-upload"></i> Pilih Gambar
                                        <input
                                            type="file"
                                            id="inputGambarHero"
                                            name="hero_image"
                                            accept=".jpg,.jpeg,.png,.webp"
                                            onchange="pratinjauGambarHero(this)"
                                            style="display:none;"
                                        >
                                    </label>

                                    <?php if ($hero['image_path']): ?>
                                        <label class="checkbox-line">
                                            <input type="checkbox" id="checkHapusGambarHero" name="hapus_gambar" value="1" onchange="tandaiHapusGambarHero()">
                                            Hapus gambar saat ini
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <p class="form-note">
                                Format: JPG, PNG, atau WEBP. Maksimal 5 MB. Kalau tidak ada
                                gambar, halaman utama menampilkan tampilan default (mockup quiz).
                            </p>
                        </div>

                        <div class="form-group">
                            <label>Teks Badge (di atas judul)</label>
                            <input type="text" id="inputBadgeHero" name="badge_text" required oninput="updatePratinjauHero()"
                                   value="<?= htmlspecialchars($hero['badge_text']); ?>">
                        </div>

                        <div class="form-group">
                            <label>Judul (bagian normal)</label>
                            <input type="text" id="inputHeadingHero" name="heading_text" required oninput="updatePratinjauHero()"
                                   value="<?= htmlspecialchars($hero['heading_text']); ?>">
                        </div>

                        <div class="form-group">
                            <label>Judul (bagian disorot/berwarna)</label>
                            <input type="text" id="inputHighlightHero" name="heading_highlight" required oninput="updatePratinjauHero()"
                                   value="<?= htmlspecialchars($hero['heading_highlight']); ?>">
                        </div>

                        <div class="form-group">
                            <label>Deskripsi</label>
                            <textarea id="inputDescHero" name="description_text" rows="3" required oninput="updatePratinjauHero()"><?= htmlspecialchars($hero['description_text']); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Teks Tombol</label>
                            <input type="text" id="inputButtonHero" name="button_text" required oninput="updatePratinjauHero()"
                                   value="<?= htmlspecialchars($hero['button_text']); ?>">
                        </div>

                        <div class="modal-actions">
                            <button type="submit" class="btn-primary">
                                <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
                            </button>
                        </div>
                    </form>

                </div>

                <!-- PREVIEW -->
                <div class="panel hero-preview-panel">

                    <div class="panel-head">
                        <h2>Pratinjau</h2>
                    </div>

                    <div class="hero-live-preview">

                        <div class="hero-badge" id="pvBadgeHero"><?= htmlspecialchars($hero['badge_text']); ?></div>

                        <h1 class="hero-preview-heading">
                            <span id="pvHeadingHero"><?= htmlspecialchars($hero['heading_text']); ?></span>
                            <span class="highlight" id="pvHighlightHero"><?= htmlspecialchars($hero['heading_highlight']); ?></span>
                        </h1>

                        <p id="pvDescHero"><?= htmlspecialchars($hero['description_text']); ?></p>

                        <button type="button" class="btn-start" id="pvButtonHero" disabled><?= htmlspecialchars($hero['button_text']); ?></button>

                    </div>

                    <p class="form-note">
                        Pratinjau kasar teks hero. Untuk tampilan asli lengkap dengan
                        gambar, buka halaman utama situs setelah menyimpan perubahan.
                    </p>

                </div>

            </div>

        </div>


    </main>


</body>

</html>
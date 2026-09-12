<?php
require_once __DIR__ . '/config/database.php';

// ========================================
// AMBIL DAFTAR MATERI DARI DATABASE
// ========================================
// Dikelola admin lewat admin/kelola_materi.php.
// Kalau tabel belum ada (migration belum dijalankan),
// tampilkan daftar kosong supaya halaman tetap tampil normal.

$daftar_materi = [];

$cek_tabel = @mysqli_query($conn, "SELECT * FROM materi ORDER BY created_at DESC");
if ($cek_tabel) {
    while ($row = mysqli_fetch_assoc($cek_tabel)) {
        $daftar_materi[] = $row;
    }
}

function format_ukuran_file_materi(int $bytes): string {
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

function ikon_tipe_materi_publik(string $tipe): string {
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

    <title>Materi - Quiz Fluida Statis</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/materi.css">
</head>

<body>

    <!-- ================= NAVBAR ================= -->
    <nav class="navbar">

        <a href="index.php" class="logo">
            Quiz<span>Fluida</span>
        </a>

        <div class="nav-menu">
            <a href="index.php#beranda">Beranda</a>
            <a href="index.php#petunjuk">Petunjuk</a>
            <a href="materi.php" class="active">Materi</a>
            <a href="auth/register.php" class="btn-register">
                Registrasi Akun
            </a>
        </div>

    </nav>


    <!-- ================= HEADER MATERI ================= -->
    <section class="materi-header">

        <div class="section-title">

            <span class="section-badge">
                Bahan Belajar
            </span>

            <h1>
                Materi Fluida Statis
            </h1>

            <p>
                Kumpulan materi pembelajaran (PPT, PDF, dan dokumen lainnya)
                yang diunggah oleh admin untuk membantu kamu memahami konsep
                Fluida Statis sebelum mengerjakan quiz.
            </p>

        </div>

    </section>


    <!-- ================= DAFTAR MATERI ================= -->
    <section class="materi-list-section">

        <?php if (empty($daftar_materi)): ?>

            <div class="materi-empty">
                <i class="fa-solid fa-folder-open"></i>
                <h3>Belum Ada Materi</h3>
                <p>Admin belum mengunggah materi. Silakan cek kembali nanti.</p>
            </div>

        <?php else: ?>

            <div class="materi-grid">

                <?php foreach ($daftar_materi as $materi): ?>

                    <div class="materi-card">

                        <div class="materi-icon tipe-<?= htmlspecialchars($materi['file_type']); ?>">
                            <i class="fa-solid <?= ikon_tipe_materi_publik($materi['file_type']); ?>"></i>
                        </div>

                        <div class="materi-body">

                            <h3><?= htmlspecialchars($materi['judul']); ?></h3>

                            <?php if (!empty($materi['deskripsi'])): ?>
                                <p><?= nl2br(htmlspecialchars($materi['deskripsi'])); ?></p>
                            <?php endif; ?>

                            <div class="materi-meta">
                                <span><?= strtoupper(htmlspecialchars($materi['file_type'])); ?></span>
                                <span><?= format_ukuran_file_materi((int) $materi['file_size']); ?></span>
                                <span><?= date('d M Y', strtotime($materi['created_at'])); ?></span>
                            </div>

                        </div>

                        <a
                            href="<?= htmlspecialchars($materi['file_path']); ?>"
                            class="btn-start materi-download"
                            target="_blank"
                            download="<?= htmlspecialchars($materi['file_name']); ?>"
                        >
                            <i class="fa-solid fa-download"></i> Unduh
                        </a>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>


    <!-- ================= CTA ================= -->
    <section class="cta-section">

        <div class="cta-content">

            <h2>
                Sudah Selesai Belajar?
            </h2>

            <p>
                Login dan uji pemahamanmu lewat Quiz Fluida Statis sekarang.
            </p>

            <a href="auth/login.php" class="btn-start">
                Mulai Quiz
            </a>

        </div>

    </section>


    <!-- ================= FOOTER ================= -->
    <footer>

        <div class="footer-content">

            <div>
                <h3>Quiz Fluida Statis</h3>

                <p>
                    Asesmen digital berbasis Four-Tier Diagnostic Test
                    untuk mengidentifikasi miskonsepsi peserta didik.
                </p>
            </div>

        </div>

        <div class="footer-bottom">
            <p>
                © <?php echo date('Y'); ?>
                Quiz Fluida Statis
            </p>
        </div>

    </footer>

</body>
</html>

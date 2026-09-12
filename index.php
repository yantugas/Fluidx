<?php
session_start();

require_once __DIR__ . '/config/database.php';

// ========================================
// AMBIL KONTEN HERO DARI DATABASE
// ========================================
// Dikelola admin lewat admin/kelola_hero.php.
// Kalau tabel/baris belum ada (migration belum dijalankan),
// pakai teks default supaya halaman tetap tampil normal.

$hero = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM hero_settings WHERE id = 1"));

if (!$hero) {
    $hero = [
        'badge_text'        => 'Four-Tier Diagnostic Test',
        'heading_text'      => 'Yuk, Uji Pemahamanmu tentang',
        'heading_highlight' => 'Fluida Statis!',
        'description_text'  => 'Kerjakan quiz 4-tier untuk mengetahui tingkat pemahaman dan miskonsepsi kamu pada konsep Fluida Statis.',
        'button_text'       => 'Mulai Quiz',
        'image_path'        => null,
    ];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Quiz Fluida Statis</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>

    <!-- ================= NAVBAR ================= -->
    <nav class="navbar">

        <div class="logo">
            Quiz<span>Fluida</span>
        </div>

        <div class="nav-menu">
            <a href="#beranda">Beranda</a>
            <a href="#petunjuk">Petunjuk</a>
            <a href="materi.php">Materi</a>
            <a href="auth/register.php" class="btn-register">
                Registrasi Akun
            </a>
        </div>

    </nav>


    <!-- ================= HERO SECTION ================= -->
    <section class="hero" id="beranda">

        <!-- BAGIAN KIRI -->
        <div class="hero-content">

            <div class="hero-badge">
                <?= htmlspecialchars($hero['badge_text']); ?>
            </div>

            <h1>
                <?= htmlspecialchars($hero['heading_text']); ?>
                <span><?= htmlspecialchars($hero['heading_highlight']); ?></span>
            </h1>

            <p>
                <?= nl2br(htmlspecialchars($hero['description_text'])); ?>
            </p>

            <a href="auth/login.php" class="btn-start">
                <?= htmlspecialchars($hero['button_text']); ?>
            </a>

        </div>


        <!-- BAGIAN KANAN -->
        <div class="hero-visual">

            <?php if (!empty($hero['image_path'])): ?>

                <img
                    src="<?= htmlspecialchars($hero['image_path']); ?>"
                    alt="<?= htmlspecialchars($hero['heading_highlight']); ?>"
                    class="hero-image"
                >

            <?php else: ?>

                <div class="quiz-preview">

                    <div class="preview-header">
                        <span>Quiz Fluida Statis</span>
                        <span>Soal 1</span>
                    </div>

                    <div class="preview-progress">
                        <div class="progress-bar"></div>
                    </div>

                    <h3>
                        Bagaimana pengaruh kedalaman terhadap
                        tekanan hidrostatis?
                    </h3>

                    <div class="preview-option active">
                        A. Semakin dalam, tekanan semakin besar
                    </div>

                    <div class="preview-option">
                        B. Semakin dalam, tekanan semakin kecil
                    </div>

                    <div class="preview-option">
                        C. Tekanan selalu tetap
                    </div>

                    <div class="preview-option">
                        D. Tidak dipengaruhi kedalaman
                    </div>

                </div>

            <?php endif; ?>

        </div>

    </section>


    <!-- ================= PETUNJUK ================= -->
    <section class="petunjuk-section" id="petunjuk">

        <div class="section-title">

            <span class="section-badge">
                Cara Pengerjaan
            </span>

            <h2>
                Petunjuk Quiz Four-Tier
            </h2>

            <p>
                Setiap soal terdiri dari empat tahap untuk membantu
                mengidentifikasi tingkat pemahaman konsep dan miskonsepsi.
            </p>

        </div>


        <div class="tier-container">

            <!-- TIER 1 -->
            <div class="tier-card">

                <div class="tier-number">
                    1
                </div>

                <h3>Jawaban</h3>

                <p>
                    Pilih jawaban yang menurut kamu paling tepat.
                </p>

            </div>


            <!-- TIER 2 -->
            <div class="tier-card">

                <div class="tier-number">
                    2
                </div>

                <h3>Keyakinan Jawaban</h3>

                <p>
                    Tentukan tingkat keyakinan terhadap jawaban yang dipilih.
                </p>

            </div>


            <!-- TIER 3 -->
            <div class="tier-card">

                <div class="tier-number">
                    3
                </div>

                <h3>Alasan</h3>

                <p>
                    Pilih alasan yang paling sesuai dengan jawaban kamu.
                </p>

            </div>


            <!-- TIER 4 -->
            <div class="tier-card">

                <div class="tier-number">
                    4
                </div>

                <h3>Keyakinan Alasan</h3>

                <p>
                    Tentukan tingkat keyakinan terhadap alasan yang dipilih.
                </p>

            </div>

        </div>

    </section>


    <!-- ================= INFORMASI QUIZ ================= -->
    <section class="info-section">

        <div class="section-title">

            <span class="section-badge">
                Tentang Quiz
            </span>

            <h2>
                Kenali Pemahaman Konsepmu
            </h2>

            <p>
                Hasil pengerjaan quiz digunakan untuk memberikan diagnosis
                terhadap pemahaman kamu pada materi Fluida Statis.
            </p>

        </div>


        <div class="info-container">

            <div class="info-card">
                <h3>4</h3>
                <p>Tier dalam setiap soal</p>
            </div>

            <div class="info-card">
                <h3>Digital</h3>
                <p>Quiz dapat dikerjakan secara online</p>
            </div>

            <div class="info-card">
                <h3>Diagnosis</h3>
                <p>Identifikasi pemahaman dan miskonsepsi</p>
            </div>

        </div>

    </section>


    <!-- ================= CTA ================= -->
    <section class="cta-section">

        <div class="cta-content">

            <h2>
                Siap Menguji Pemahamanmu?
            </h2>

            <p>
                Login dan mulai kerjakan Quiz Fluida Statis sekarang.
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
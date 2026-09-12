<?php
session_start();

require_once "../config/database.php";

/*
|--------------------------------------------------------------------------
| Pastikan siswa sudah login
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "student") {
    header("Location: ../auth/login_siswa.php");
    exit;
}

$message = "";

/*
|--------------------------------------------------------------------------
| Ambil nama dari session jika tersedia
|--------------------------------------------------------------------------
*/
$nama_default = $_SESSION["name"] ?? "";
$kelas_default = $_SESSION["kelas"] ?? "";

/*
|--------------------------------------------------------------------------
| Ketika tombol MULAI QUIZ ditekan
|--------------------------------------------------------------------------
*/
if (isset($_POST["mulai_quiz"])) {

    $nama = trim($_POST["name"] ?? "");
    $kelas = trim($_POST["kelas"] ?? "");

    /*
    |--------------------------------------------------------------------------
    | Validasi
    |--------------------------------------------------------------------------
    */
    if ($nama === "" || $kelas === "") {

        $message = "Nama dan kelas wajib diisi.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Simpan identitas siswa ke session
        |--------------------------------------------------------------------------
        */
        $_SESSION["quiz_name"] = $nama;
        $_SESSION["quiz_class"] = $kelas;

        /*
        |--------------------------------------------------------------------------
        | Simpan waktu mulai quiz
        |--------------------------------------------------------------------------
        */
        $_SESSION["quiz_start_time"] = time();

        /*
        |--------------------------------------------------------------------------
        | Durasi quiz
        | 20 menit = 1200 detik
        |--------------------------------------------------------------------------
        */
        $_SESSION["quiz_duration"] = 20 * 60;

        /*
        |--------------------------------------------------------------------------
        | Status quiz
        |--------------------------------------------------------------------------
        */
        $_SESSION["quiz_started"] = true;

        /*
        |--------------------------------------------------------------------------
        | Lanjut ke halaman quiz
        |--------------------------------------------------------------------------
        */
        header("Location: quiz.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Persiapan Quiz | Quiz Fluida Statis</title>

    <link rel="stylesheet"
          href="../assets/css/persiapan_quiz.css">

</head>

<body>

<div class="preparation-container">

    <div class="preparation-card">

        <!-- HEADER -->
        <div class="preparation-header">

            <div class="quiz-icon">
                🌊
            </div>

            <h1>Persiapan Quiz</h1>

            <p>
                Quiz Fluida Statis
            </p>

        </div>


        <!-- INFORMASI QUIZ -->
        <div class="info-box">

            <h2>📋 Informasi Pengerjaan</h2>

            <div class="info-item">

                <span class="info-icon">⏱️</span>

                <div>
                    <strong>Waktu Pengerjaan</strong>

                    <p>
                        Maksimal <b>20 menit</b>
                        setelah quiz dimulai.
                    </p>
                </div>

            </div>


            <div class="info-item">

                <span class="info-icon">📝</span>

                <div>
                    <strong>Bentuk Soal</strong>

                    <p>
                        Setiap soal dikerjakan menggunakan
                        <b>4 Tier</b>.
                    </p>
                </div>

            </div>


            <div class="info-item">

                <span class="info-icon">🎯</span>

                <div>
                    <strong>Pengerjaan Mandiri</strong>

                    <p>
                        Kerjakan setiap soal berdasarkan
                        pemahaman Anda sendiri.
                    </p>
                </div>

            </div>

        </div>


        <!-- PERINGATAN -->
        <div class="warning-box">

            <h2>⚠️ Peraturan Quiz</h2>

            <ul>

                <li>
                    Quiz harus dikerjakan secara mandiri.
                </li>

                <li>
                    Dilarang membuka tab atau halaman lain
                    selama pengerjaan quiz.
                </li>

                <li>
                    Dilarang menggunakan AI, mesin pencari,
                    atau sumber lain untuk mencari jawaban.
                </li>

                <li>
                    Sistem dapat mendeteksi aktivitas ketika
                    Anda berpindah tab atau meninggalkan
                    halaman quiz.
                </li>

                <li>
                    Pastikan Anda sudah siap sebelum menekan
                    tombol <b>Mulai Quiz</b>.
                </li>

                <li>
                    Waktu akan mulai dihitung setelah quiz
                    dimulai.
                </li>

            </ul>

        </div>


        <!-- PESAN ERROR -->
        <?php if ($message !== ""): ?>

            <div class="alert error">
                <?php echo htmlspecialchars($message); ?>
            </div>

        <?php endif; ?>


        <!-- FORM IDENTITAS -->
        <form method="POST" action="">

            <div class="form-section">

                <h2>👤 Identitas Siswa</h2>

                <!-- NAMA -->
                <div class="form-group">

                    <label for="name">
                        Nama Lengkap
                    </label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="Masukkan nama lengkap"
                        value="<?php echo htmlspecialchars($nama_default); ?>"
                        required
                    >

                </div>


                <!-- KELAS -->
                <div class="form-group">

                    <label for="kelas">
                        Kelas
                    </label>

                    <input
                        type="text"
                        id="kelas"
                        name="kelas"
                        placeholder="Contoh: XI IPA 1"
                        value="<?php echo htmlspecialchars($kelas_default); ?>"
                        required
                    >

                </div>

            </div>


            <!-- CHECK PERSETUJUAN -->
            <div class="agreement">

                <label>

                    <input
                        type="checkbox"
                        name="agreement"
                        id="agreement"
                        required
                    >

                    <span>
                        Saya sudah membaca dan memahami
                        peraturan serta siap mengerjakan
                        quiz secara mandiri.
                    </span>

                </label>

            </div>


            <!-- BUTTON -->
            <button
                type="submit"
                name="mulai_quiz"
                class="btn-start"
            >
                🚀 Mulai Quiz
            </button>

        </form>


        <!-- KEMBALI -->
        <div class="back-link">

            <a href="dashboard.php">
                ← Kembali ke Dashboard
            </a>

        </div>

    </div>

</div>

</body>

</html>
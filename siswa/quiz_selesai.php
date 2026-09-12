<?php
session_start();

/*
|--------------------------------------------------------------------------
| CEK LOGIN SISWA
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "student") {
    header("Location: ../auth/login_siswa.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| HALAMAN INI HANYA BOLEH DIAKSES SETELAH QUIZ DIKIRIM
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["quiz_finished"]) || $_SESSION["quiz_finished"] !== true) {
    header("Location: dashboard.php");
    exit;
}

$nama = $_SESSION["quiz_name"] ?? $_SESSION["name"] ?? "";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Quiz Selesai | Quiz Fluida Statis</title>

    <link rel="stylesheet" href="../assets/css/quiz_selesai.css">
</head>

<body>

<div class="finish-container">

    <div class="finish-card">

        <div class="finish-icon">
            ✅
        </div>

        <h1>Terima Kasih!</h1>

        <p class="finish-name">
            <?= $nama !== "" ? "Kerja bagus, " . htmlspecialchars($nama) . "!" : "Kerja bagus!"; ?>
        </p>

        <p class="finish-text">
            Jawaban kamu untuk Quiz Fluida Statis sudah berhasil
            dikirim dan tersimpan. Terima kasih sudah mengerjakan
            quiz ini dengan sungguh-sungguh dan jujur.
        </p>

        <div class="finish-info">
            📊 Hasil pengerjaanmu akan direkap oleh guru/admin.
        </div>

        <a href="dashboard.php" class="btn-home">
            🏠 Kembali ke Beranda
        </a>

    </div>

</div>

</body>
</html>

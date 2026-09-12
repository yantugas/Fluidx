<?php
session_start();

// Cek apakah sudah login
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login_siswa.php");
    exit;
}

// Pastikan yang masuk adalah siswa
if ($_SESSION["role"] !== "student") {
    header("Location: ../auth/login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard Siswa | Quiz Fluida Statis</title>
</head>

<body>

    <h1>
        Selamat Datang, <?php echo htmlspecialchars($_SESSION["name"]); ?>!
    </h1>

    <p>
        Anda berhasil login sebagai siswa.
    </p>

    <br>

    <a href="../logout.php">
        Logout
    </a>

</body>
</html>
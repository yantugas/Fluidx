<?php
require_once "../config/database.php";

$message = "";
$message_type = "";

if (isset($_POST["register"])) {

    $name = trim($_POST["name"]);
    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    // Validasi input kosong
    if (
        empty($name) ||
        empty($username) ||
        empty($email) ||
        empty($password) ||
        empty($confirm_password)
    ) {

        $message = "Semua kolom wajib diisi.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Format email tidak valid.";
        $message_type = "error";

    } elseif ($password !== $confirm_password) {

        $message = "Konfirmasi password tidak sama.";
        $message_type = "error";

    } else {

        // Cek username
        $check_username = mysqli_prepare(
            $conn,
            "SELECT id FROM users WHERE username = ?"
        );

        mysqli_stmt_bind_param(
            $check_username,
            "s",
            $username
        );

        mysqli_stmt_execute($check_username);

        $result_username = mysqli_stmt_get_result($check_username);

        if (mysqli_num_rows($result_username) > 0) {

            $message = "Username sudah digunakan.";
            $message_type = "error";

        } else {

            // Cek email
            $check_email = mysqli_prepare(
                $conn,
                "SELECT id FROM users WHERE email = ?"
            );

            mysqli_stmt_bind_param(
                $check_email,
                "s",
                $email
            );

            mysqli_stmt_execute($check_email);

            $result_email = mysqli_stmt_get_result($check_email);

            if (mysqli_num_rows($result_email) > 0) {

                $message = "Email sudah terdaftar.";
                $message_type = "error";

            } else {

                // Enkripsi password
                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                // Registrasi siswa
                $role = "student";

                /*
                 * PERBAIKAN:
                 * Sebelumnya jumlah kolom hanya 4,
                 * tetapi jumlah VALUES dan bind_param ada 5.
                 *
                 * Sekarang kolom name ikut dimasukkan.
                 */

                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO users
                    (name, kelas, username, email, password, role)
                    VALUES (?, '', ?, ?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $insert,
                    "sssss",
                    $name,
                    $username,
                    $email,
                    $hashed_password,
                    $role
                );

                if (mysqli_stmt_execute($insert)) {

                    $message = "Registrasi berhasil! Silakan login.";
                    $message_type = "success";

                } else {

                    $message = "Registrasi gagal. Silakan coba lagi.";
                    $message_type = "error";

                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Registrasi Akun | Quiz Fluida Statis</title>

    <link rel="stylesheet"
          href="../assets/css/register.css">

</head>

<body>

    <div class="register-container">

        <div class="register-card">

            <!-- HEADER -->
            <div class="register-header">

                <a href="../index.php" class="back-link">
                    ← Kembali ke Beranda
                </a>

                <h1>Registrasi Akun</h1>

                <p>
                    Buat akun untuk mulai mengerjakan
                    Quiz Fluida Statis.
                </p>

            </div>


            <!-- PESAN -->
            <?php if (!empty($message)): ?>

                <div class="alert <?php echo $message_type; ?>">
                    <?php echo $message; ?>
                </div>

            <?php endif; ?>


            <!-- FORM -->
            <form method="POST" action="">

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
                        required
                    >

                </div>


                <!-- USERNAME -->
                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Masukkan username"
                        required
                    >

                </div>


                <!-- EMAIL -->
                <div class="form-group">

                    <label for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Masukkan email"
                        required
                    >

                </div>


                <!-- PASSWORD -->
                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Masukkan password"
                        required
                    >

                </div>


                <!-- KONFIRMASI PASSWORD -->
                <div class="form-group">

                    <label for="confirm_password">
                        Konfirmasi Password
                    </label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Masukkan kembali password"
                        required
                    >

                </div>


                <!-- BUTTON -->
                <button
                    type="submit"
                    name="register"
                    class="btn-register-submit"
                >
                    Daftar Sekarang
                </button>

            </form>


            <!-- LOGIN -->
            <div class="login-link">

                <p>
                    Sudah memiliki akun?
                    <a href="login.php">
                        Login di sini
                    </a>
                </p>

            </div>

        </div>

    </div>

</body>

</html>
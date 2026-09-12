<?php
session_start();

require_once "../config/database.php";

$message = "";

if (isset($_POST["login"])) {

    $username = trim($_POST["username"]);
    $password = $_POST["password"];

    if (empty($username) || empty($password)) {

        $message = "Username dan password wajib diisi.";

    } else {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, name, username, password, role
             FROM users
             WHERE username = ? AND role = 'student'
             LIMIT 1"
        );

        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) === 1) {

            $user = mysqli_fetch_assoc($result);

            // Verifikasi password
            if (password_verify($password, $user["password"])) {

                // Simpan session siswa
                $_SESSION["user_id"] = $user["id"];
                $_SESSION["name"] = $user["name"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["role"] = $user["role"];

                // Langsung menuju persiapan quiz
                header("Location: ../siswa/persiapan_quiz.php");
                exit;

            } else {

                $message = "Password yang Anda masukkan salah.";

            }

        } else {

            $message = "Akun siswa tidak ditemukan.";

        }

        mysqli_stmt_close($stmt);
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login Siswa | Quiz Fluida Statis</title>

    <link rel="stylesheet" href="../assets/css/login_siswa.css">
</head>

<body>

    <div class="login-container">

        <div class="login-card">

            <a href="login.php" class="back-link">
                ← Kembali
            </a>

            <div class="login-header">

                <div class="login-icon">
                    🎓
                </div>

                <h1>Login Siswa</h1>

                <p>
                    Masukkan akun Anda untuk mulai mengerjakan
                    Quiz Fluida Statis.
                </p>

            </div>


            <!-- PESAN ERROR -->
            <?php if (!empty($message)): ?>

                <div class="alert error">
                    <?php echo htmlspecialchars($message); ?>
                </div>

            <?php endif; ?>


            <!-- FORM LOGIN -->
            <form method="POST">

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


                <button
                    type="submit"
                    name="login"
                    class="btn-login-submit"
                >
                    Login
                </button>

            </form>


            <div class="register-link">

                <p>
                    Belum memiliki akun?
                    <a href="register.php">
                        Registrasi di sini
                    </a>
                </p>

            </div>

        </div>

    </div>

</body>
</html>
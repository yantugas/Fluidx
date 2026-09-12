<?php
session_start();

include '../config/database.php';

$error = "";

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header("Location: ../admin/dashboard_admin.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {

        $error = "Username dan password wajib diisi.";

    } else {

        $query = "SELECT * FROM users 
                  WHERE username = ? 
                  AND role = 'admin'
                  LIMIT 1";

        $stmt = mysqli_prepare($conn, $query);

        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) === 1) {

            $user = mysqli_fetch_assoc($result);

            if (password_verify($password, $user['password'])) {

                $_SESSION['id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];

                header("Location: ../admin/dashboard_admin.php");
                exit;

            } else {

                $error = "Username atau password salah.";

            }

        } else {

            $error = "Username atau password salah.";

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

    <title>Login Admin - Quiz Fluida Statis</title>

    <!-- Font Awesome -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <!-- CSS Login -->
    <link rel="stylesheet" href="../assets/css/login_admin.css">

</head>

<body>

    <div class="login-container">

        <!-- BAGIAN KIRI -->
        <div class="login-info">

            <div class="brand">

                <div class="brand-icon">
                    <i class="fa-solid fa-flask"></i>
                </div>

                <div>
                    <h2>Quiz Fluida Statis</h2>
                    <p>Panel Admin</p>
                </div>

            </div>


            <div class="info-content">

                <span class="badge">
                    <i class="fa-solid fa-graduation-cap"></i>
                    SISTEM QUIZ FISIKA
                </span>

                <h1>
                    Kelola Quiz<br>
                    <span>Fluida Statis</span>
                </h1>

                <p>
                    Kelola soal, lihat hasil pengerjaan siswa,
                    dan analisis miskonsepsi pada materi
                    Fluida Statis melalui panel admin.
                </p>

            </div>

        </div>


        <!-- BAGIAN KANAN -->
        <div class="login-form-container">

            <div class="login-form">

                <div class="form-header">

                    <div class="form-icon">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>

                    <h2>Login Admin</h2>

                    <p>
                        Silakan masuk untuk mengakses
                        panel admin.
                    </p>

                </div>


                <?php if (!empty($error)) : ?>

                    <div class="error-message">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span><?= htmlspecialchars($error); ?></span>
                    </div>

                <?php endif; ?>


                <form method="POST" action="">

                    <!-- USERNAME -->
                    <div class="form-group">

                        <label for="username">
                            Username
                        </label>

                        <div class="input-box">

                            <i class="fa-solid fa-user"></i>

                            <input
                                type="text"
                                id="username"
                                name="username"
                                placeholder="Masukkan username"
                                autocomplete="username"
                                required
                            >

                        </div>

                    </div>


                    <!-- PASSWORD -->
                    <div class="form-group">

                        <label for="password">
                            Password
                        </label>

                        <div class="input-box">

                            <i class="fa-solid fa-lock"></i>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="Masukkan password"
                                autocomplete="current-password"
                                required
                            >

                            <button
                                type="button"
                                class="show-password"
                                onclick="togglePassword()"
                            >
                                <i class="fa-solid fa-eye" id="eyeIcon"></i>
                            </button>

                        </div>

                    </div>


                    <!-- BUTTON -->
                    <button type="submit" class="login-button">

                        <span>Masuk ke Dashboard</span>

                        <i class="fa-solid fa-arrow-right"></i>

                    </button>

                </form>


                <div class="back-home">

                    <a href="../index.php">

                        <i class="fa-solid fa-arrow-left"></i>

                        Kembali ke halaman utama

                    </a>

                </div>

            </div>

        </div>

    </div>


    <script>

        function togglePassword() {

            const password = document.getElementById("password");
            const eyeIcon = document.getElementById("eyeIcon");

            if (password.type === "password") {

                password.type = "text";

                eyeIcon.classList.remove("fa-eye");
                eyeIcon.classList.add("fa-eye-slash");

            } else {

                password.type = "password";

                eyeIcon.classList.remove("fa-eye-slash");
                eyeIcon.classList.add("fa-eye");

            }
        }

    </script>

</body>

</html>
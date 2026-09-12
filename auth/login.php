<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Pilih Login | Quiz Fluida Statis</title>

    <link rel="stylesheet" href="../assets/css/login.css">
</head>

<body>

    <div class="login-container">

        <div class="login-card">

            <a href="../index.php" class="back-link">
                ← Kembali ke Beranda
            </a>

            <div class="login-header">
                <h1>Masuk ke Quiz</h1>

                <p>
                    Pilih jenis akun untuk melanjutkan ke
                    Quiz Fluida Statis.
                </p>
            </div>


            <div class="login-options">

                <!-- LOGIN SISWA -->
                <a href="login_siswa.php" class="login-option student">

                    <div class="login-icon">
                        🎓
                    </div>

                    <div class="login-info">
                        <h3>Login sebagai Siswa</h3>

                        <p>
                            Masuk untuk mengerjakan quiz dan
                            melihat hasil diagnosis.
                        </p>
                    </div>

                    <div class="arrow">
                        →
                    </div>

                </a>


                <!-- LOGIN ADMIN -->
                <a href="login_admin.php" class="login-option admin">

                    <div class="login-icon">
                        👨‍🏫
                    </div>

                    <div class="login-info">
                        <h3>Login sebagai Admin / Guru</h3>

                        <p>
                            Kelola soal dan lihat hasil
                            diagnosis seluruh siswa.
                        </p>
                    </div>

                    <div class="arrow">
                        →
                    </div>

                </a>

            </div>


            <div class="register-link">

                <p>
                    Belum memiliki akun?
                    <a href="register.php">
                        Registrasi sebagai siswa
                    </a>
                </p>

            </div>

        </div>

    </div>

</body>
</html>
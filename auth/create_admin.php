<?php

require_once "../config/database.php";

$password = "admin123";

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt = mysqli_prepare(
    $conn,
    "UPDATE users
     SET password = ?
     WHERE username = 'admin'
     AND role = 'admin'"
);

mysqli_stmt_bind_param(
    $stmt,
    "s",
    $hashed_password
);

if (mysqli_stmt_execute($stmt)) {
    echo "Password admin berhasil diperbarui.";
} else {
    echo "Gagal memperbarui password admin.";
}

mysqli_stmt_close($stmt);
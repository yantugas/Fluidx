<?php

// Pakai environment variable kalau ada (dibutuhkan di Vercel/hosting online),
// tapi tetap fallback ke nilai default lama supaya XAMPP/lokal tidak berubah.
$host     = getenv('DB_HOST') ?: "localhost";
$username = getenv('DB_USER') ?: "root";
$password = getenv('DB_PASS') ?: "";
$database = getenv('DB_NAME') ?: "fluidastatis_db";

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

?>
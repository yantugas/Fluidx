<?php

session_start();

// Hapus semua data session
$_SESSION = [];

// Hancurkan session
session_destroy();

// Kembali ke halaman utama
header("Location: index.php");
exit;

?>
<?php
session_start();
require_once "../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| CEK LOGIN SISWA & QUIZ SEDANG BERLANGSUNG
|--------------------------------------------------------------------------
|
| Endpoint ini hanya boleh dipanggil dari halaman quiz.php saat
| siswa benar-benar sedang mengerjakan quiz. Jika session tidak
| valid, tolak permintaan.
|
*/
if (
    !isset($_SESSION["user_id"])
    || $_SESSION["role"] !== "student"
    || !isset($_SESSION["quiz_started"])
    || $_SESSION["quiz_started"] !== true
) {
    http_response_code(403);
    echo json_encode([
        "status"  => "error",
        "message" => "Sesi quiz tidak valid.",
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL JENIS PELANGGARAN
|--------------------------------------------------------------------------
|
| navigator.sendBeacon() pada browser mengirim data sebagai
| application/x-www-form-urlencoded (bisa terbaca via $_POST),
| tapi sebagai jaga-jaga (mis. dikirim via fetch dengan body
| mentah), coba juga baca dari php://input.
|
*/
$event_type = $_POST["event_type"] ?? "";

if ($event_type === "") {
    parse_str(file_get_contents("php://input"), $raw_body);
    $event_type = $raw_body["event_type"] ?? "";
}

$allowed_events = ["tab_hidden", "window_blur"];

if (!in_array($event_type, $allowed_events, true)) {
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => "Jenis pelanggaran tidak dikenali.",
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL IDENTITAS SISWA DARI SESSION
|--------------------------------------------------------------------------
|
| Gunakan nama & kelas yang diisi siswa di halaman Persiapan
| Quiz (quiz_name / quiz_class), sama seperti yang dipakai di
| header quiz.php, karena users.name / users.kelas bisa saja
| masih kosong.
|
*/
$user_id = $_SESSION["user_id"];
$nama    = $_SESSION["quiz_name"] ?? $_SESSION["name"] ?? "";
$kelas   = $_SESSION["quiz_class"] ?? "";

/*
|--------------------------------------------------------------------------
| SIMPAN PELANGGARAN
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO quiz_violations (user_id, student_name, student_class, event_type)
     VALUES (?, ?, ?, ?)"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "Gagal menyimpan data pelanggaran.",
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, "isss", $user_id, $nama, $kelas, $event_type);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(["status" => "ok"]);

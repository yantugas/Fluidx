<?php
session_start();
require_once "../config/database.php";

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
| CEK APAKAH QUIZ SEDANG BERLANGSUNG
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["quiz_started"]) || $_SESSION["quiz_started"] !== true) {
    header("Location: persiapan_quiz.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| HANYA TERIMA METHOD POST (DARI FORM QUIZ)
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: quiz.php");
    exit;
}

$user_id = $_SESSION["user_id"];
$answers = $_POST["answers"] ?? [];

/*
|--------------------------------------------------------------------------
| AMBIL SEMUA PERNYATAAN TIER 1 (question_statements) DARI DATABASE
|--------------------------------------------------------------------------
|
| Dipakai untuk memvalidasi bahwa id pernyataan yang dikirim client
| benar-benar milik soal yang bersangkutan, dan untuk menghitung
| skor otomatis (points_earned) berdasarkan kunci jawaban admin.
|
*/
$statements_by_id = [];

$statement_query = mysqli_query(
    $conn,
    "SELECT id, question_id, points, correct_answer
     FROM question_statements"
);

if ($statement_query) {
    while ($row = mysqli_fetch_assoc($statement_query)) {
        $statements_by_id[$row["id"]] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| SIMPAN TIER 2 (keyakinan jawaban), TIER 3 (uraian alasan),
| TIER 4 (keyakinan alasan) KE student_answers
|--------------------------------------------------------------------------
|
| reason_score (nilai Tier 3) TIDAK diisi di sini karena dinilai
| manual oleh admin di halaman Jawaban Siswa.
|
*/
$stmtJawaban = mysqli_prepare(
    $conn,
    "INSERT INTO student_answers
        (user_id, question_id, confidence_answer, reason_text, confidence_reason)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        confidence_answer = VALUES(confidence_answer),
        reason_text       = VALUES(reason_text),
        confidence_reason = VALUES(confidence_reason)"
);

/*
|--------------------------------------------------------------------------
| SIMPAN TIER 1 (per pernyataan) KE student_statement_answers
| SEKALIGUS HITUNG SKOR OTOMATIS (points_earned)
|--------------------------------------------------------------------------
*/
$stmtPernyataan = mysqli_prepare(
    $conn,
    "INSERT INTO student_statement_answers
        (user_id, question_id, statement_id, selected_answer, is_correct, points_earned)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        selected_answer = VALUES(selected_answer),
        is_correct      = VALUES(is_correct),
        points_earned   = VALUES(points_earned)"
);

$pilihan_valid_tf = ['tepat', 'tidak_tepat'];
$pilihan_valid_conf = ['yakin', 'tidak_yakin'];

if ($stmtJawaban && $stmtPernyataan && is_array($answers)) {

    foreach ($answers as $question_id => $jawaban) {

        $question_id = (int) $question_id;

        $confidence_answer = $jawaban["confidence_answer"] ?? null;
        $confidence_reason = $jawaban["confidence_reason"] ?? null;
        $reason_text       = trim($jawaban["reason_text"] ?? '');

        if (!in_array($confidence_answer, $pilihan_valid_conf, true)) {
            $confidence_answer = null;
        }
        if (!in_array($confidence_reason, $pilihan_valid_conf, true)) {
            $confidence_reason = null;
        }

        // --- Simpan Tier 2, 3, 4 ---
        mysqli_stmt_bind_param(
            $stmtJawaban,
            "iisss",
            $user_id,
            $question_id,
            $confidence_answer,
            $reason_text,
            $confidence_reason
        );
        mysqli_stmt_execute($stmtJawaban);

        // --- Simpan & nilai Tier 1 (per pernyataan) ---
        $jawaban_pernyataan = $jawaban["statements"] ?? [];

        if (is_array($jawaban_pernyataan)) {

            foreach ($jawaban_pernyataan as $statement_id => $selected) {

                $statement_id = (int) $statement_id;

                if (!in_array($selected, $pilihan_valid_tf, true)) {
                    continue;
                }

                $statement = $statements_by_id[$statement_id] ?? null;

                // Validasi: pernyataan harus ada & milik soal ini
                if (!$statement || (int) $statement["question_id"] !== $question_id) {
                    continue;
                }

                $is_correct    = ($selected === $statement["correct_answer"]) ? 1 : 0;
                $points_earned = $is_correct ? (int) $statement["points"] : 0;

                mysqli_stmt_bind_param(
                    $stmtPernyataan,
                    "iiisii",
                    $user_id,
                    $question_id,
                    $statement_id,
                    $selected,
                    $is_correct,
                    $points_earned
                );
                mysqli_stmt_execute($stmtPernyataan);
            }
        }
    }

    mysqli_stmt_close($stmtJawaban);
    mysqli_stmt_close($stmtPernyataan);
}

/*
|--------------------------------------------------------------------------
| TUTUP SESI QUIZ & TANDAI SUDAH SELESAI
|--------------------------------------------------------------------------
*/
unset($_SESSION["quiz_started"]);
unset($_SESSION["quiz_start_time"]);
unset($_SESSION["quiz_duration"]);

$_SESSION["quiz_finished"] = true;

/*
|--------------------------------------------------------------------------
| ARAHKAN KE HALAMAN UCAPAN TERIMA KASIH
|--------------------------------------------------------------------------
*/
header("Location: quiz_selesai.php");
exit;

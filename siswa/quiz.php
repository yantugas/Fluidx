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
| CEK APAKAH QUIZ SUDAH DIMULAI
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["quiz_started"]) || $_SESSION["quiz_started"] !== true) {
    header("Location: persiapan_quiz.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA SISWA
|--------------------------------------------------------------------------
*/
$user_id = $_SESSION["user_id"];
$nama = $_SESSION["quiz_name"] ?? $_SESSION["name"] ?? "";
$kelas = $_SESSION["quiz_class"] ?? "";

/*
|--------------------------------------------------------------------------
| AMBIL SOAL DARI DATABASE
|--------------------------------------------------------------------------
*/
$questions = [];

$query = mysqli_query(
    $conn,
    "SELECT id, question_text
     FROM questions
     WHERE is_active = 1
     ORDER BY id ASC"
);

if ($query) {
    while ($row = mysqli_fetch_assoc($query)) {
        $questions[] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| JIKA TIDAK ADA SOAL
|--------------------------------------------------------------------------
*/
if (count($questions) === 0) {
    die("Belum ada soal yang tersedia. Silakan hubungi admin/guru.");
}

/*
|--------------------------------------------------------------------------
| AMBIL SEMUA PERNYATAAN TIER 1 (question_statements)
|--------------------------------------------------------------------------
|
| Struktur tabel question_statements:
| - id
| - question_id
| - statement_order (1, 2, 3)
| - statement_text
| - difficulty      ('mudah' / 'sedang' / 'sulit')
| - points
| - correct_answer  ('tepat' / 'tidak_tepat')  -- tidak dikirim ke klien
|
|--------------------------------------------------------------------------
*/
$statements = [];

$statement_query = mysqli_query(
    $conn,
    "SELECT id, question_id, statement_order, statement_text
     FROM question_statements
     ORDER BY question_id ASC, statement_order ASC"
);

if ($statement_query) {
    while ($row = mysqli_fetch_assoc($statement_query)) {
        $statements[$row["question_id"]][] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| TIMER
|--------------------------------------------------------------------------
|
| Mengambil waktu mulai dari persiapan_quiz.php.
| Jika belum ada, gunakan waktu sekarang sebagai cadangan.
|
|--------------------------------------------------------------------------
*/
$duration = $_SESSION["quiz_duration"] ?? (20 * 60);
$start_time = $_SESSION["quiz_start_time"] ?? time();

$elapsed = time() - $start_time;
$remaining_time = max(0, $duration - $elapsed);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Quiz Fluida Statis</title>

    <link rel="stylesheet" href="../assets/css/quiz.css">
</head>

<body>

<div class="quiz-container">

    <!-- =========================================================
         HEADER QUIZ
    ========================================================== -->
    <header class="quiz-header">

        <div class="quiz-title">
            <h1>Quiz Fluida Statis</h1>
            <p>
                Nama:
                <strong><?= htmlspecialchars($nama); ?></strong>
                &nbsp; | &nbsp;
                Kelas:
                <strong><?= htmlspecialchars($kelas); ?></strong>
            </p>
        </div>

        <div class="timer-box">
            <span>Waktu tersisa</span>
            <strong id="timer">00:00</strong>
        </div>

    </header>


    <!-- =========================================================
         PERINGATAN
    ========================================================== -->
    <div id="warning-box" class="warning-box hidden">
        ⚠️ Jangan meninggalkan halaman quiz.
        Perpindahan tab akan terdeteksi oleh sistem.
    </div>


    <!-- =========================================================
         INFORMASI TIER
    ========================================================== -->
    <div class="tier-info">

        <div class="tier-item active">
            <span>1</span>
            <div>
                <strong>Pernyataan</strong>
                <small>Tepat / Tidak Tepat</small>
            </div>
        </div>

        <div class="tier-item">
            <span>2</span>
            <div>
                <strong>Keyakinan Jawaban</strong>
                <small>Yakin / Tidak Yakin</small>
            </div>
        </div>

        <div class="tier-item">
            <span>3</span>
            <div>
                <strong>Alasan</strong>
                <small>Tulis alasanmu</small>
            </div>
        </div>

        <div class="tier-item">
            <span>4</span>
            <div>
                <strong>Keyakinan Alasan</strong>
                <small>Yakin / Tidak Yakin</small>
            </div>
        </div>

    </div>


    <!-- =========================================================
         PROGRESS
    ========================================================== -->
    <div class="progress-section">

        <div class="progress-info">
            <span>
                Soal <strong id="current-number">1</strong>
                dari
                <strong><?= count($questions); ?></strong>
            </span>

            <span id="answered-count">
                0 / <?= count($questions); ?> terjawab
            </span>
        </div>

        <div class="progress-bar">
            <div
                id="progress-fill"
                class="progress-fill"
                style="width: 0%;">
            </div>
        </div>

    </div>


    <!-- =========================================================
         FORM QUIZ
    ========================================================== -->
    <form id="quiz-form" method="POST" action="submit_quiz.php">

        <?php foreach ($questions as $index => $question): ?>

            <?php
            $question_id = $question["id"];

            $question_statements =
                $statements[$question_id]
                ?? [];
            ?>

            <div
                class="question-card <?= $index === 0 ? "active-question" : ""; ?>"
                data-question="<?= $index + 1; ?>"
            >

                <!-- =================================================
                     NOMOR & SOAL
                ================================================== -->
                <div class="question-number">
                    Soal <?= $index + 1; ?>
                </div>

                <div class="question-text">
                    <?= nl2br(htmlspecialchars($question["question_text"])); ?>
                </div>


                <!-- =================================================
                     TIER 1
                ================================================== -->
                <div class="tier-section">

                    <div class="tier-heading">
                        <span class="tier-badge">Tier 1</span>

                        <div>
                            <h3>Pernyataan</h3>
                            <p>Tentukan Tepat atau Tidak Tepat untuk setiap pernyataan.</p>
                        </div>
                    </div>

                    <div class="option-list">

                        <?php foreach ($question_statements as $urut => $statement): ?>

                            <div class="statement-row" style="margin-bottom:14px;">

                                <p class="statement-text">
                                    <strong><?= chr(97 + $urut); ?>.</strong>
                                    <?= nl2br(htmlspecialchars($statement["statement_text"])); ?>
                                </p>

                                <div class="option-list" style="display:flex; gap:12px;">

                                    <label class="option-card">
                                        <input
                                            type="radio"
                                            name="answers[<?= $question_id; ?>][statements][<?= $statement["id"]; ?>]"
                                            value="tepat"
                                            class="answer-input"
                                            data-question="<?= $question_id; ?>"
                                        >
                                        <span class="option-text">Tepat</span>
                                    </label>

                                    <label class="option-card">
                                        <input
                                            type="radio"
                                            name="answers[<?= $question_id; ?>][statements][<?= $statement["id"]; ?>]"
                                            value="tidak_tepat"
                                            class="answer-input"
                                            data-question="<?= $question_id; ?>"
                                        >
                                        <span class="option-text">Tidak Tepat</span>
                                    </label>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>


                <!-- =================================================
                     TIER 2
                ================================================== -->
                <div class="tier-section">

                    <div class="tier-heading">
                        <span class="tier-badge">Tier 2</span>

                        <div>
                            <h3>Keyakinan Jawaban</h3>
                            <p>Seberapa yakin kamu terhadap jawabanmu di Tier 1?</p>
                        </div>
                    </div>

                    <div class="option-list" style="display:flex; gap:12px;">

                        <label class="option-card">
                            <input
                                type="radio"
                                name="answers[<?= $question_id; ?>][confidence_answer]"
                                value="yakin"
                                class="answer-input"
                                data-question="<?= $question_id; ?>"
                            >
                            <span class="option-text">Yakin</span>
                        </label>

                        <label class="option-card">
                            <input
                                type="radio"
                                name="answers[<?= $question_id; ?>][confidence_answer]"
                                value="tidak_yakin"
                                class="answer-input"
                                data-question="<?= $question_id; ?>"
                            >
                            <span class="option-text">Tidak Yakin</span>
                        </label>

                    </div>

                </div>


                <!-- =================================================
                     TIER 3
                ================================================== -->
                <div class="tier-section">

                    <div class="tier-heading">
                        <span class="tier-badge">Tier 3</span>

                        <div>
                            <h3>Alasan</h3>
                            <p>Tuliskan alasan kamu atas jawaban Tier 1 di atas.</p>
                        </div>
                    </div>

                    <textarea
                        name="answers[<?= $question_id; ?>][reason_text]"
                        class="reason-textarea"
                        rows="4"
                        placeholder="Tulis alasanmu di sini..."
                        style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;"
                    ></textarea>

                </div>


                <!-- =================================================
                     TIER 4
                ================================================== -->
                <div class="tier-section">

                    <div class="tier-heading">
                        <span class="tier-badge">Tier 4</span>

                        <div>
                            <h3>Keyakinan Alasan</h3>
                            <p>Seberapa yakin kamu terhadap alasanmu di Tier 3?</p>
                        </div>
                    </div>

                    <div class="option-list" style="display:flex; gap:12px;">

                        <label class="option-card">
                            <input
                                type="radio"
                                name="answers[<?= $question_id; ?>][confidence_reason]"
                                value="yakin"
                                class="answer-input"
                                data-question="<?= $question_id; ?>"
                            >
                            <span class="option-text">Yakin</span>
                        </label>

                        <label class="option-card">
                            <input
                                type="radio"
                                name="answers[<?= $question_id; ?>][confidence_reason]"
                                value="tidak_yakin"
                                class="answer-input"
                                data-question="<?= $question_id; ?>"
                            >
                            <span class="option-text">Tidak Yakin</span>
                        </label>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>


        <!-- =========================================================
             NAVIGASI
        ========================================================== -->
        <div class="quiz-navigation">

            <button
                type="button"
                id="prev-btn"
                class="btn btn-secondary"
                disabled
            >
                ← Sebelumnya
            </button>

            <button
                type="button"
                id="next-btn"
                class="btn btn-primary"
            >
                Berikutnya →
            </button>

            <button
                type="submit"
                id="submit-btn"
                class="btn btn-submit hidden"
            >
                ✓ Selesai & Kirim Jawaban
            </button>

        </div>

    </form>

</div>


<script>

/* ================================================================
   DATA QUIZ
================================================================ */

const totalQuestions =
    <?= count($questions); ?>;

let currentQuestion = 1;


/* ================================================================
   TIMER
================================================================ */

let remainingTime =
    <?= $remaining_time; ?>;

const timerElement =
    document.getElementById("timer");


function updateTimer() {

    if (remainingTime <= 0) {

        timerElement.textContent = "00:00";

        document.getElementById("quiz-form").submit();

        return;
    }

    const minutes =
        Math.floor(remainingTime / 60);

    const seconds =
        remainingTime % 60;

    timerElement.textContent =
        String(minutes).padStart(2, "0")
        + ":"
        + String(seconds).padStart(2, "0");

    remainingTime--;
}


updateTimer();

const timerInterval =
    setInterval(updateTimer, 1000);


/* ================================================================
   TAMPILKAN SOAL
================================================================ */

const questionCards =
    document.querySelectorAll(".question-card");


function showQuestion(number) {

    questionCards.forEach(card => {

        card.classList.remove("active-question");

        if (
            Number(card.dataset.question) === number
        ) {
            card.classList.add("active-question");
        }

    });


    document.getElementById("current-number")
        .textContent = number;


    document.getElementById("prev-btn")
        .disabled = number === 1;


    if (number === totalQuestions) {

        document.getElementById("next-btn")
            .classList.add("hidden");

        document.getElementById("submit-btn")
            .classList.remove("hidden");

    } else {

        document.getElementById("next-btn")
            .classList.remove("hidden");

        document.getElementById("submit-btn")
            .classList.add("hidden");
    }


    updateProgress();
}


/* ================================================================
   NAVIGASI BERIKUTNYA
================================================================ */

document.getElementById("next-btn")
    .addEventListener("click", function () {

        if (currentQuestion < totalQuestions) {

            currentQuestion++;

            showQuestion(currentQuestion);

            window.scrollTo({
                top: 0,
                behavior: "smooth"
            });
        }

    });


/* ================================================================
   NAVIGASI SEBELUMNYA
================================================================ */

document.getElementById("prev-btn")
    .addEventListener("click", function () {

        if (currentQuestion > 1) {

            currentQuestion--;

            showQuestion(currentQuestion);

            window.scrollTo({
                top: 0,
                behavior: "smooth"
            });
        }

    });


/* ================================================================
   HITUNG SOAL TERJAWAB
================================================================ */

function updateProgress() {

    let answered = 0;

    questionCards.forEach(card => {

        const questionInputs =
            card.querySelectorAll("input[type='radio']");

        const checked =
            Array.from(questionInputs)
                .some(input => input.checked);

        const textarea =
            card.querySelector("textarea");

        const hasText =
            textarea && textarea.value.trim() !== "";

        if (checked || hasText) {
            answered++;
        }

    });


    document.getElementById("answered-count")
        .textContent =
        answered + " / " + totalQuestions + " terjawab";


    const percentage =
        (answered / totalQuestions) * 100;


    document.getElementById("progress-fill")
        .style.width = percentage + "%";
}


/* ================================================================
   UPDATE PROGRESS SAAT PILIH JAWABAN
================================================================ */

document.querySelectorAll("input[type='radio']")
    .forEach(input => {

        input.addEventListener(
            "change",
            updateProgress
        );

    });

document.querySelectorAll("textarea")
    .forEach(textarea => {

        textarea.addEventListener(
            "input",
            updateProgress
        );

    });


/* ================================================================
   LAPOR PELANGGARAN KE SERVER (UNTUK ADMIN)
================================================================ */
/*
   Setiap kali siswa terdeteksi pindah tab / minimize, kejadian
   ini dikirim ke server (log_pelanggaran.php) agar bisa dipantau
   admin secara real-time (nama & kelas siswa yang bersangkutan).

   navigator.sendBeacon dipakai karena tetap bisa mengirim data
   walaupun halaman langsung disembunyikan/di-minimize, tanpa
   perlu menunggu response seperti fetch biasa.
*/

function reportViolation(eventType) {

    const payload = new URLSearchParams();
    payload.append("event_type", eventType);

    if (navigator.sendBeacon) {

        navigator.sendBeacon("log_pelanggaran.php", payload);

    } else {

        fetch("log_pelanggaran.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: payload,
            keepalive: true
        }).catch(function () {});

    }

}


/* ================================================================
   DETEKSI PINDAH TAB / MINIMIZE
================================================================ */

let warningCount = 0;

document.addEventListener(
    "visibilitychange",
    function () {

        if (document.hidden) {

            warningCount++;

            const warningBox =
                document.getElementById("warning-box");

            warningBox.classList.remove("hidden");

            warningBox.innerHTML =
                "⚠️ <strong>Peringatan!</strong> " +
                "Kamu terdeteksi meninggalkan halaman quiz. " +
                "Tetap berada di halaman quiz selama pengerjaan.";

            // Laporkan ke admin (tercatat nama & kelas siswa)
            reportViolation("tab_hidden");

        }

    }
);


/* ================================================================
   DETEKSI WINDOW TIDAK AKTIF
================================================================ */
/*
   Hanya menampilkan peringatan lokal (tidak dikirim ke server),
   karena "blur" juga bisa terpicu oleh hal wajar seperti dialog
   konfirmasi bawaan browser (mis. saat menekan tombol Selesai &
   Kirim Jawaban), sehingga bisa menimbulkan pelanggaran palsu
   jika ikut dilaporkan ke admin. Deteksi resmi yang dilaporkan
   ke admin cukup lewat "visibilitychange" di atas.
*/

window.addEventListener(
    "blur",
    function () {

        const warningBox =
            document.getElementById("warning-box");

        warningBox.classList.remove("hidden");

        warningBox.innerHTML =
            "⚠️ <strong>Peringatan!</strong> " +
            "Jangan berpindah dari halaman quiz.";

    }
);


/* ================================================================
   KONFIRMASI SEBELUM SUBMIT
================================================================ */

document.getElementById("quiz-form")
    .addEventListener("submit", function (event) {

        clearInterval(timerInterval);

        const confirmed =
            confirm(
                "Apakah kamu yakin ingin mengirim semua jawaban?"
            );

        if (!confirmed) {
            event.preventDefault();
        }

    });


/* ================================================================
   TAMPILKAN SOAL PERTAMA
================================================================ */

showQuestion(1);

</script>

</body>
</html>
-- =========================================================
-- MIGRATION: Fitur Kelola Soal + Score Siswa
-- =========================================================
-- Jalankan file ini di database `fluidastatis_db`
-- (phpMyAdmin -> Import, atau: mysql -u root fluidastatis_db < migration_score_siswa.sql)
--
-- CATATAN PENTING:
-- Query di score_siswa.php mengasumsikan tabel `users` SUDAH
-- punya kolom `kelas` (VARCHAR). Jika nama kolom kelas di
-- database Anda berbeda, sesuaikan nama kolomnya di query
-- admin/score_siswa.php.
-- =========================================================


-- =========================================================
-- TABEL: questions (Kelola Soal)
-- =========================================================
-- Setiap soal adalah soal four-tier:
--   Tier 1 -> pilihan jawaban (option_a..d) + kunci jawaban (correct_answer)
--   Tier 2 -> tingkat keyakinan jawaban (diisi siswa saat kuis, skala 1-4)
--   Tier 3 -> pilihan alasan (reason_a..d) + kunci alasan (correct_reason)
--   Tier 4 -> tingkat keyakinan alasan (diisi siswa saat kuis, skala 1-4)

CREATE TABLE IF NOT EXISTS questions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    question_text   TEXT NOT NULL,

    option_a        VARCHAR(255) NOT NULL,
    option_b        VARCHAR(255) NOT NULL,
    option_c        VARCHAR(255) NOT NULL,
    option_d        VARCHAR(255) NOT NULL,
    correct_answer  ENUM('A','B','C','D') NOT NULL,

    reason_a        VARCHAR(255) NOT NULL,
    reason_b        VARCHAR(255) NOT NULL,
    reason_c        VARCHAR(255) NOT NULL,
    reason_d        VARCHAR(255) NOT NULL,
    correct_reason  ENUM('A','B','C','D') NOT NULL,

    is_active       TINYINT(1) NOT NULL DEFAULT 1,

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =========================================================
-- TABEL: student_answers (Jawaban Siswa)
-- =========================================================
-- Menyimpan jawaban siswa untuk tiap soal (1 baris = 1 soal
-- yang sudah dikerjakan siswa). Skor dihitung on-the-fly di
-- score_siswa.php dengan membandingkan ke kunci jawaban di
-- tabel questions.

CREATE TABLE IF NOT EXISTS student_answers (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id             INT NOT NULL,
    question_id         INT UNSIGNED NOT NULL,

    selected_answer     ENUM('A','B','C','D') NOT NULL,   -- Tier 1
    confidence_answer   TINYINT UNSIGNED NOT NULL,         -- Tier 2 (1-4)

    selected_reason     ENUM('A','B','C','D') NOT NULL,   -- Tier 3
    confidence_reason   TINYINT UNSIGNED NOT NULL,         -- Tier 4 (1-4)

    answered_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uniq_user_question (user_id, question_id),

    CONSTRAINT fk_student_answers_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_student_answers_question
        FOREIGN KEY (question_id) REFERENCES questions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
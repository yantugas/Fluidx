-- ============================================================
-- MIGRASI: FORMAT SOAL FOUR-TIER VERSI BARU
-- ============================================================
-- Perubahan:
--   Tier 1  : dari pilihan ganda (A-D) -> 3 pernyataan
--             Tepat/Tidak Tepat, tiap pernyataan punya level
--             kesulitan (Mudah=2 poin, Sedang/Sulit=8 poin).
--   Tier 2  : dari skala 1-5 -> Yakin/Tidak Yakin (informasi saja).
--   Tier 3  : dari pilihan ganda (A-D) -> uraian bebas
--             (dinilai manual oleh admin, skala 0-100).
--   Tier 4  : dari skala 1-5 -> Yakin/Tidak Yakin (informasi saja).
--
-- PENTING: Backup database sebelum menjalankan migrasi ini.
-- ============================================================

-- ------------------------------------------------------------
-- 1. TABEL BARU: pernyataan Tier 1 (3 pernyataan per soal)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS question_statements (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    question_id      INT NOT NULL,
    statement_order  TINYINT NOT NULL,                 -- 1, 2, atau 3
    statement_text   TEXT NOT NULL,
    difficulty       ENUM('mudah','sedang','sulit') NOT NULL,
    points           INT NOT NULL,                      -- mudah=2, sedang/sulit=8
    correct_answer   ENUM('tepat','tidak_tepat') NOT NULL,
    CONSTRAINT fk_qs_question
        FOREIGN KEY (question_id) REFERENCES questions(id)
        ON DELETE CASCADE,
    UNIQUE KEY uniq_question_order (question_id, statement_order)
);

-- ------------------------------------------------------------
-- 2. TABEL BARU: jawaban siswa per pernyataan (Tier 1)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_statement_answers (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    question_id      INT NOT NULL,
    statement_id     INT NOT NULL,
    selected_answer  ENUM('tepat','tidak_tepat') NOT NULL,
    is_correct       TINYINT(1) NOT NULL DEFAULT 0,
    points_earned    INT NOT NULL DEFAULT 0,
    answered_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ssa_statement
        FOREIGN KEY (statement_id) REFERENCES question_statements(id)
        ON DELETE CASCADE,
    UNIQUE KEY uniq_user_statement (user_id, statement_id)
);

-- ------------------------------------------------------------
-- 3. UBAH TABEL questions: kolom kunci lama sudah tidak dipakai
--    (kunci sekarang ada per-pernyataan di question_statements)
-- ------------------------------------------------------------
ALTER TABLE questions
    DROP COLUMN IF EXISTS correct_answer,
    DROP COLUMN IF EXISTS correct_reason;

-- ------------------------------------------------------------
-- 4. UBAH TABEL student_answers:
--    - selected_answer & selected_reason (kode A-D) dihapus,
--      karena Tier 1 kini di student_statement_answers dan
--      Tier 3 kini berupa teks uraian.
--    - confidence_answer & confidence_reason jadi Yakin/Tidak Yakin.
--    - reason_text: jawaban uraian Tier 3.
--    - reason_score: nilai manual dari admin (0-100), NULL = belum dinilai.
-- ------------------------------------------------------------
ALTER TABLE student_answers
    DROP COLUMN IF EXISTS selected_answer,
    DROP COLUMN IF EXISTS selected_reason;

ALTER TABLE student_answers
    MODIFY COLUMN confidence_answer ENUM('yakin','tidak_yakin') NULL,
    MODIFY COLUMN confidence_reason ENUM('yakin','tidak_yakin') NULL;

ALTER TABLE student_answers
    ADD COLUMN IF NOT EXISTS reason_text TEXT NULL AFTER confidence_answer,
    ADD COLUMN IF NOT EXISTS reason_score INT NULL AFTER confidence_reason;

-- ------------------------------------------------------------
-- 5. question_options SUDAH TIDAK DIPAKAI LAGI
--    (dulu untuk opsi Tier 1 A-D & Tier 3 A-D).
--    Dibiarkan dulu (tidak dihapus) untuk jaga-jaga / arsip data lama.
--    Kalau sudah yakin tidak perlu, boleh dihapus manual dengan:
--    DROP TABLE question_options;
-- ------------------------------------------------------------

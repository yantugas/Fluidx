-- =========================================================
-- MIGRATION: Deteksi Pelanggaran Quiz (Pindah Tab / Minimize)
-- =========================================================
-- Jalankan file ini di database `fluidastatis_db`
-- (phpMyAdmin -> Import, atau: mysql -u root fluidastatis_db < migration_quiz_violations.sql)
--
-- Tabel ini menyimpan setiap kejadian ketika siswa terdeteksi
-- meninggalkan / berpindah tab / meng-minimize halaman quiz
-- (siswa/quiz.php) selama pengerjaan berlangsung. Data ini
-- dipakai oleh admin/monitoring_pelanggaran.php untuk
-- menampilkan notifikasi & daftar pelanggaran secara real-time
-- (nama & kelas siswa yang terdeteksi keluar/pindah tab).
--
-- CATATAN: student_name & student_class disimpan langsung
-- (snapshot) dari identitas yang diisi siswa saat "Persiapan
-- Quiz", karena kolom users.name / users.kelas belum tentu
-- terisi (lihat auth/login_siswa.php & siswa/persiapan_quiz.php).
-- =========================================================

CREATE TABLE IF NOT EXISTS quiz_violations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    user_id         INT NOT NULL,

    student_name    VARCHAR(100) NOT NULL,
    student_class   VARCHAR(50) NOT NULL,

    event_type      ENUM('tab_hidden', 'window_blur')
                        NOT NULL DEFAULT 'tab_hidden',

    occurred_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_occurred_at (occurred_at),

    CONSTRAINT fk_quiz_violations_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =========================================================
-- MIGRATION: Fitur Kelola Materi (Upload PPT, PDF, dll)
-- =========================================================
-- Jalankan file ini di database `fluidastatis_db`
-- (phpMyAdmin -> Import, atau: mysql -u root fluidastatis_db < migration_materi.sql)
--
-- Tabel ini dipakai oleh admin/kelola_materi.php (upload &
-- kelola materi) dan materi.php (halaman publik daftar materi
-- yang diakses lewat menu "Materi" di Navbar).
-- =========================================================


CREATE TABLE IF NOT EXISTS materi (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    judul           VARCHAR(150) NOT NULL,
    deskripsi       TEXT NULL,

    file_name       VARCHAR(255) NOT NULL,   -- nama file asli (untuk ditampilkan/diunduh)
    file_path       VARCHAR(255) NOT NULL,   -- path relatif dari root project
    file_type       VARCHAR(10) NOT NULL,    -- ekstensi: pdf, ppt, pptx, doc, docx, xls, xlsx
    file_size       INT UNSIGNED NOT NULL DEFAULT 0, -- ukuran file dalam bytes

    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

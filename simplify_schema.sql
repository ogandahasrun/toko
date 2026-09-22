-- =======================================================
-- SIMPLIFIED TOKO DATABASE MIGRATION SCRIPT
-- Single Item Transaction per Sale with Freetext Item Name, Cost Price (Modal), Selling Price & Auto Cash/Installment
-- =======================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. DROP UNNECESSARY TABLES
DROP TABLE IF EXISTS `faktur_detail`;
DROP TABLE IF EXISTS `faktur`;
DROP TABLE IF EXISTS `mutasi_detail`;
DROP TABLE IF EXISTS `mutasi`;
DROP TABLE IF EXISTS `gudang_barang`;
DROP TABLE IF EXISTS `barang_detail`;
DROP TABLE IF EXISTS `barang`;
DROP TABLE IF EXISTS `lokasi`;

-- 2. RESTRUCTURE TABLE `pelanggan`
CREATE TABLE IF NOT EXISTS `pelanggan` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nama` VARCHAR(100) NOT NULL,
  `no_hp` VARCHAR(20) DEFAULT NULL,
  `alamat` TEXT DEFAULT NULL,
  `limit_kredit` DECIMAL(15,2) DEFAULT 0.00,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. RESTRUCTURE TABLE `penjualan`
-- Backup/Drop old structure and create new simplified table
DROP TABLE IF EXISTS `penjualan_detail`;
DROP TABLE IF EXISTS `pembayaran_kredit`;
DROP TABLE IF EXISTS `penjualan`;

CREATE TABLE `penjualan` (
  `no_penjualan` VARCHAR(50) NOT NULL,
  `tanggal` DATE NOT NULL,
  `pelanggan_id` INT(11) DEFAULT NULL,
  `nama_barang` VARCHAR(255) NOT NULL,
  `harga_beli` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `harga_jual` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `jumlah_dibayar` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `sisa_piutang` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tipe_pembayaran` ENUM('tunai','kredit') NOT NULL DEFAULT 'tunai',
  `status_kredit` ENUM('lunas','belum_lunas') NOT NULL DEFAULT 'lunas',
  `tempo_tipe` VARCHAR(20) DEFAULT 'n/a',
  `jatuh_tempo` DATE DEFAULT NULL,
  `catatan` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`no_penjualan`),
  KEY `fk_penjualan_pelanggan` (`pelanggan_id`),
  CONSTRAINT `fk_penjualan_pelanggan` FOREIGN KEY (`pelanggan_id`) REFERENCES `pelanggan` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. RESTRUCTURE TABLE `pembayaran_kredit`
CREATE TABLE `pembayaran_kredit` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `no_penjualan` VARCHAR(50) NOT NULL,
  `tanggal` DATE NOT NULL,
  `jumlah_bayar` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `keterangan` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_pembayaran_kredit_penjualan` (`no_penjualan`),
  CONSTRAINT `fk_pembayaran_kredit_penjualan` FOREIGN KEY (`no_penjualan`) REFERENCES `penjualan` (`no_penjualan`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. RESTRUCTURE TABLE `users`
-- Ensure user table supports simplified permission flags
ALTER TABLE `users` 
  ADD COLUMN IF NOT EXISTS `akses_cicilan` TINYINT(1) NOT NULL DEFAULT 1 AFTER `akses_penjualan`;

-- 6. ENSURE DUMMY/DEFAULT USERS AND SETTINGS
CREATE TABLE IF NOT EXISTS `pengaturan` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nama_toko` VARCHAR(100) NOT NULL DEFAULT 'Toko Kita',
  `alamat` TEXT DEFAULT NULL,
  `cp` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `pengaturan` (`id`, `nama_toko`, `alamat`, `cp`) VALUES
(1, 'Toko Kita Sederhana', 'Jl. Utama No. 123', '08123456789');

SET FOREIGN_KEY_CHECKS = 1;

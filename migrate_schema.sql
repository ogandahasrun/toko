-- =======================================================
-- GUSTRI MARKET DATABASE MIGRATION SCRIPT
-- Refactor to Natural Keys, Composite PKs & Referential Integrity
-- =======================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. DROP EXISTING CONSTRAINTS
ALTER TABLE `barang_detail` DROP FOREIGN KEY `barang_detail_ibfk_1`;
ALTER TABLE `gudang_barang` DROP FOREIGN KEY `gudang_barang_ibfk_1`;
ALTER TABLE `gudang_barang` DROP FOREIGN KEY `gudang_barang_ibfk_2`;
ALTER TABLE `faktur` DROP FOREIGN KEY `fk_faktur_lokasi`;
ALTER TABLE `faktur_detail` DROP FOREIGN KEY `faktur_detail_ibfk_1`;
ALTER TABLE `faktur_detail` DROP FOREIGN KEY `faktur_detail_ibfk_2`;
ALTER TABLE `penjualan` DROP FOREIGN KEY `penjualan_ibfk_1`;
ALTER TABLE `penjualan_detail` DROP FOREIGN KEY `penjualan_detail_ibfk_1`;
ALTER TABLE `penjualan_detail` DROP FOREIGN KEY `penjualan_detail_ibfk_2`;
ALTER TABLE `pembayaran_kredit` DROP FOREIGN KEY `pembayaran_kredit_ibfk_1`;
ALTER TABLE `mutasi` DROP FOREIGN KEY `mutasi_ibfk_1`;
ALTER TABLE `mutasi` DROP FOREIGN KEY `mutasi_ibfk_2`;
ALTER TABLE `mutasi_detail` DROP FOREIGN KEY `mutasi_detail_ibfk_1`;
ALTER TABLE `mutasi_detail` DROP FOREIGN KEY `mutasi_detail_ibfk_2`;

-- 2. RESTRUCTURE TABLE `barang`
-- Remove id, make kode_barang PRIMARY KEY
ALTER TABLE `barang` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `barang` DROP PRIMARY KEY;
ALTER TABLE `barang` DROP COLUMN `id`;
ALTER TABLE `barang` DROP INDEX `kode_barang`;
ALTER TABLE `barang` ADD PRIMARY KEY (`kode_barang`);

-- 3. RESTRUCTURE TABLE `barang_detail`
-- Remove id, make kode_barang PRIMARY KEY
ALTER TABLE `barang_detail` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `barang_detail` DROP PRIMARY KEY;
ALTER TABLE `barang_detail` DROP COLUMN `id`;
ALTER TABLE `barang_detail` DROP INDEX `kode_barang`;
ALTER TABLE `barang_detail` ADD PRIMARY KEY (`kode_barang`);

-- 4. RESTRUCTURE TABLE `gudang_barang`
-- Remove id, make (lokasi_id, kode_barang) COMPOSITE PRIMARY KEY
ALTER TABLE `gudang_barang` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `gudang_barang` DROP PRIMARY KEY;
ALTER TABLE `gudang_barang` DROP COLUMN `id`;
ALTER TABLE `gudang_barang` DROP INDEX `unik_gudang_stok`;
ALTER TABLE `gudang_barang` ADD PRIMARY KEY (`lokasi_id`, `kode_barang`);

-- 5. RESTRUCTURE TABLE `faktur`
-- Remove id, make no_faktur PRIMARY KEY, add created_at
ALTER TABLE `faktur` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `faktur` DROP PRIMARY KEY;
ALTER TABLE `faktur` DROP COLUMN `id`;
ALTER TABLE `faktur` DROP INDEX `no_faktur`;
ALTER TABLE `faktur` ADD PRIMARY KEY (`no_faktur`);
ALTER TABLE `faktur` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- 6. RESTRUCTURE TABLE `faktur_detail`
-- Remove id, drop faktur_id, add no_faktur, make (no_faktur, kode_barang) COMPOSITE PRIMARY KEY
ALTER TABLE `faktur_detail` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `faktur_detail` DROP PRIMARY KEY;
ALTER TABLE `faktur_detail` DROP COLUMN `id`;
ALTER TABLE `faktur_detail` DROP COLUMN `faktur_id`;
ALTER TABLE `faktur_detail` ADD COLUMN `no_faktur` VARCHAR(50) NOT NULL FIRST;
ALTER TABLE `faktur_detail` ADD PRIMARY KEY (`no_faktur`, `kode_barang`);

-- 7. RESTRUCTURE TABLE `penjualan`
-- Remove id, make no_penjualan PRIMARY KEY, add created_at
ALTER TABLE `penjualan` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `penjualan` DROP PRIMARY KEY;
ALTER TABLE `penjualan` DROP COLUMN `id`;
ALTER TABLE `penjualan` DROP INDEX `no_penjualan`;
ALTER TABLE `penjualan` ADD PRIMARY KEY (`no_penjualan`);
ALTER TABLE `penjualan` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- 8. RESTRUCTURE TABLE `penjualan_detail`
-- Remove id, drop penjualan_id, add no_penjualan, make (no_penjualan, kode_barang) COMPOSITE PRIMARY KEY
ALTER TABLE `penjualan_detail` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `penjualan_detail` DROP PRIMARY KEY;
ALTER TABLE `penjualan_detail` DROP COLUMN `id`;
ALTER TABLE `penjualan_detail` DROP COLUMN `penjualan_id`;
ALTER TABLE `penjualan_detail` ADD COLUMN `no_penjualan` VARCHAR(50) NOT NULL FIRST;
ALTER TABLE `penjualan_detail` ADD PRIMARY KEY (`no_penjualan`, `kode_barang`);

-- 9. RESTRUCTURE TABLE `pembayaran_kredit`
-- Drop penjualan_id, add no_penjualan
ALTER TABLE `pembayaran_kredit` DROP COLUMN `penjualan_id`;
ALTER TABLE `pembayaran_kredit` ADD COLUMN `no_penjualan` VARCHAR(50) NOT NULL AFTER `id`;

-- 10. RESTRUCTURE TABLE `mutasi`
-- Remove id, make no_mutasi PRIMARY KEY, add created_at
ALTER TABLE `mutasi` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `mutasi` DROP PRIMARY KEY;
ALTER TABLE `mutasi` DROP COLUMN `id`;
ALTER TABLE `mutasi` DROP INDEX `no_mutasi`;
ALTER TABLE `mutasi` ADD PRIMARY KEY (`no_mutasi`);
ALTER TABLE `mutasi` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- 11. RESTRUCTURE TABLE `mutasi_detail`
-- Remove id, drop mutasi_id, add no_mutasi, make (no_mutasi, kode_barang) COMPOSITE PRIMARY KEY
ALTER TABLE `mutasi_detail` MODIFY COLUMN `id` INT(11) NOT NULL;
ALTER TABLE `mutasi_detail` DROP PRIMARY KEY;
ALTER TABLE `mutasi_detail` DROP COLUMN `id`;
ALTER TABLE `mutasi_detail` DROP COLUMN `mutasi_id`;
ALTER TABLE `mutasi_detail` ADD COLUMN `no_mutasi` VARCHAR(50) NOT NULL FIRST;
ALTER TABLE `mutasi_detail` ADD PRIMARY KEY (`no_mutasi`, `kode_barang`);

-- 12. APPLY ALL FOREIGN KEY CONSTRAINTS WITH ON UPDATE CASCADE & ON DELETE RESTRICT
-- barang_detail -> barang
ALTER TABLE `barang_detail`
  ADD CONSTRAINT `fk_barang_detail_barang` FOREIGN KEY (`kode_barang`) REFERENCES `barang` (`kode_barang`) ON UPDATE CASCADE ON DELETE RESTRICT;

-- gudang_barang -> lokasi & barang
ALTER TABLE `gudang_barang`
  ADD CONSTRAINT `fk_gudang_lokasi` FOREIGN KEY (`lokasi_id`) REFERENCES `lokasi` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gudang_barang` FOREIGN KEY (`kode_barang`) REFERENCES `barang` (`kode_barang`) ON UPDATE CASCADE ON DELETE RESTRICT;

-- faktur -> lokasi
ALTER TABLE `faktur`
  ADD CONSTRAINT `fk_faktur_lokasi` FOREIGN KEY (`lokasi_id`) REFERENCES `lokasi` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT;

-- faktur_detail -> faktur & barang
ALTER TABLE `faktur_detail`
  ADD CONSTRAINT `fk_faktur_detail_faktur` FOREIGN KEY (`no_faktur`) REFERENCES `faktur` (`no_faktur`) ON UPDATE CASCADE ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_faktur_detail_barang` FOREIGN KEY (`kode_barang`) REFERENCES `barang` (`kode_barang`) ON UPDATE CASCADE ON DELETE RESTRICT;

-- penjualan -> pelanggan
ALTER TABLE `penjualan`
  ADD CONSTRAINT `fk_penjualan_pelanggan` FOREIGN KEY (`pelanggan_id`) REFERENCES `pelanggan` (`id`) ON UPDATE CASCADE ON DELETE SET NULL;

-- penjualan_detail -> penjualan & barang
ALTER TABLE `penjualan_detail`
  ADD CONSTRAINT `fk_penjualan_detail_penjualan` FOREIGN KEY (`no_penjualan`) REFERENCES `penjualan` (`no_penjualan`) ON UPDATE CASCADE ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_penjualan_detail_barang` FOREIGN KEY (`kode_barang`) REFERENCES `barang` (`kode_barang`) ON UPDATE CASCADE ON DELETE RESTRICT;

-- pembayaran_kredit -> penjualan
ALTER TABLE `pembayaran_kredit`
  ADD CONSTRAINT `fk_pembayaran_kredit_penjualan` FOREIGN KEY (`no_penjualan`) REFERENCES `penjualan` (`no_penjualan`) ON UPDATE CASCADE ON DELETE RESTRICT;

-- mutasi -> lokasi (dari & ke)
ALTER TABLE `mutasi`
  ADD CONSTRAINT `fk_mutasi_dari_lokasi` FOREIGN KEY (`dari_lokasi_id`) REFERENCES `lokasi` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_mutasi_ke_lokasi` FOREIGN KEY (`ke_lokasi_id`) REFERENCES `lokasi` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT;

-- mutasi_detail -> mutasi & barang
ALTER TABLE `mutasi_detail`
  ADD CONSTRAINT `fk_mutasi_detail_mutasi` FOREIGN KEY (`no_mutasi`) REFERENCES `mutasi` (`no_mutasi`) ON UPDATE CASCADE ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_mutasi_detail_barang` FOREIGN KEY (`kode_barang`) REFERENCES `barang` (`kode_barang`) ON UPDATE CASCADE ON DELETE RESTRICT;

SET FOREIGN_KEY_CHECKS = 1;

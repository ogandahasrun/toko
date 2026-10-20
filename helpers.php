<?php
/**
 * Helper Functions untuk Toko POS & Piutang Sederhana
 */

if (!function_exists('syncPenjualanPiutang')) {
    /**
     * Menghitung ulang dan menyinkronkan sisa piutang, status kredit, dan total terbayar
     * pada tabel penjualan berdasarkan riwayat riil transaksi.
     * 
     * @param mysqli $koneksi
     * @param string $no_penjualan
     * @return bool
     */
    function syncPenjualanPiutang($koneksi, $no_penjualan) {
        $no_penjualan = trim($no_penjualan);
        if (empty($no_penjualan)) return false;

        $esc = $koneksi->real_escape_string($no_penjualan);
        $res = $koneksi->query("SELECT harga_jual, tipe_pembayaran FROM penjualan WHERE no_penjualan = '$esc'");
        if (!$res || $res->num_rows === 0) {
            return false;
        }
        $sale = $res->fetch_assoc();
        $harga_jual = (double)$sale['harga_jual'];
        $tipe = $sale['tipe_pembayaran'];

        if ($tipe === 'tunai') {
            $stmt = $koneksi->prepare("UPDATE penjualan SET sisa_piutang = 0, status_kredit = 'lunas', jumlah_dibayar = ? WHERE no_penjualan = ?");
            $stmt->bind_param("ds", $harga_jual, $no_penjualan);
            $stmt->execute();
            $stmt->close();
            return true;
        }

        // Tipe kredit: hitung akumulasi pembayaran dari tabel pembayaran_kredit
        $resBayar = $koneksi->query("SELECT COALESCE(SUM(jumlah_bayar), 0) AS total_terbayar FROM pembayaran_kredit WHERE no_penjualan = '$esc'");
        $total_terbayar = 0;
        if ($resBayar && $rb = $resBayar->fetch_assoc()) {
            $total_terbayar = (double)$rb['total_terbayar'];
        }

        $sisa_piutang = max(0, $harga_jual - $total_terbayar);
        $status_kredit = ($sisa_piutang <= 0) ? 'lunas' : 'belum_lunas';

        $stmt = $koneksi->prepare("UPDATE penjualan SET sisa_piutang = ?, status_kredit = ?, jumlah_dibayar = ? WHERE no_penjualan = ?");
        $stmt->bind_param("dsds", $sisa_piutang, $status_kredit, $total_terbayar, $no_penjualan);
        $stmt->execute();
        $stmt->close();
        return true;
    }
}

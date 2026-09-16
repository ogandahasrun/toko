<?php
if (!defined('host')) { exit; }

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$esc_start = $koneksi->real_escape_string($start_date);
$esc_end = $koneksi->real_escape_string($end_date);

// --- 1. DATA LAPORAN ARUS KAS ---
// Kas Masuk
$kas_penjualan_tunai = 0;
$res = $koneksi->query("SELECT SUM(total_jual) as total FROM penjualan WHERE tipe_pembayaran = 'tunai' AND tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($res) $kas_penjualan_tunai = (double)$res->fetch_assoc()['total'];

$kas_cicilan_piutang = 0;
$res = $koneksi->query("SELECT SUM(jumlah_bayar) as total FROM pembayaran_kredit WHERE tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($res) $kas_cicilan_piutang = (double)$res->fetch_assoc()['total'];

$kas_pemasukan_lain = 0;
$res = $koneksi->query("SELECT SUM(jumlah) as total FROM jurnal_kas WHERE tipe = 'pemasukan' AND tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($res) $kas_pemasukan_lain = (double)$res->fetch_assoc()['total'];

$total_kas_masuk = $kas_penjualan_tunai + $kas_cicilan_piutang + $kas_pemasukan_lain;

// Kas Keluar
$kas_pembelian_supplier = 0;
$res = $koneksi->query("SELECT SUM(total_beli) as total FROM faktur WHERE tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($res) $kas_pembelian_supplier = (double)$res->fetch_assoc()['total'];

$kas_pengeluaran_lain = 0;
$res = $koneksi->query("SELECT SUM(jumlah) as total FROM jurnal_kas WHERE tipe = 'pengeluaran' AND tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($res) $kas_pengeluaran_lain = (double)$res->fetch_assoc()['total'];

$total_kas_keluar = $kas_pembelian_supplier + $kas_pengeluaran_lain;
$arus_kas_netto = $total_kas_masuk - $total_kas_keluar;


// --- 2. DATA LAPORAN LABA RUGI ---
$pendapatan_penjualan = 0;
$res = $koneksi->query("SELECT SUM(total_jual) as total FROM penjualan WHERE tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($res) $pendapatan_penjualan = (double)$res->fetch_assoc()['total'];

$hpp_barang = 0;
$res = $koneksi->query("
    SELECT SUM(pd.jumlah * pd.harga_beli) as total 
    FROM penjualan_detail pd 
    JOIN penjualan p ON pd.no_penjualan = p.no_penjualan 
    WHERE p.tanggal BETWEEN '$esc_start' AND '$esc_end'
");
if ($res) $hpp_barang = (double)$res->fetch_assoc()['total'];

$laba_kotor = $pendapatan_penjualan - $hpp_barang;

$pendapatan_non_ops = $kas_pemasukan_lain; // Sama dengan pemasukan lainnya
$beban_operasional = $kas_pengeluaran_lain; // Sama dengan pengeluaran operasional lainnya

$laba_bersih = $laba_kotor + $pendapatan_non_ops - $beban_operasional;

?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">Laporan Keuangan</h1>
        <p class="text-secondary">Analisis performa profitabilitas & arus kas masuk-keluar</p>
    </div>
    <div style="display:flex; gap: 8px;">
        <button class="btn btn-primary" onclick="window.print()">
            Cetak Laporan
        </button>
    </div>
</div>

<!-- Date Filter Form -->
<div class="content-card no-print" style="margin-bottom: 24px;">
    <form action="" method="GET" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="page" value="laporan">
        <div class="form-group" style="margin-bottom:0; flex: 1; min-width: 150px;">
            <label class="form-label">Tanggal Mulai</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" required>
        </div>
        <div class="form-group" style="margin-bottom:0; flex: 1; min-width: 150px;">
            <label class="form-label">Tanggal Selesai</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" required>
        </div>
        <button type="submit" class="btn btn-secondary" style="height: 42px;">Terapkan Filter</button>
    </form>
</div>

<!-- Active report header info (Visible on print) -->
<div class="print-header" style="display: none; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 24px;">
    <h2 style="font-weight:800; font-size:24px; color:#4f46e5; margin:0;"><?= htmlspecialchars($shop_name) ?></h2>
    <p style="margin:4px 0 0 0; font-size:13px; color:#64748b;"><?= htmlspecialchars($shop_address) ?></p>
    <h3 style="margin: 16px 0 4px 0; font-weight:800; font-size:18px; text-transform: uppercase; text-align:center;">LAPORAN KEUANGAN BULANAN</h3>
    <p style="margin:0; text-align:center; font-size:13px;">Periode: <strong><?= date('d/m/Y', strtotime($start_date)) ?></strong> s/d <strong><?= date('d/m/Y', strtotime($end_date)) ?></strong></p>
</div>

<!-- Layout reports in grid columns -->
<div class="tx-layout" style="grid-template-columns: 1fr 1fr; gap: 24px;">
    
    <!-- Cash Flow Report Column -->
    <div class="content-card" style="padding: 24px; background: #fff;">
        <h3 style="font-size: 18px; font-weight: 800; margin-bottom: 20px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px; color: #10b981; display:flex; align-items:center; gap:8px;">
            <span>💵</span> Laporan Arus Kas (Cash Flow)
        </h3>

        <div style="display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
            <!-- INFLOW -->
            <div>
                <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">Arus Kas Masuk (Penerimaan)</strong>
                <div style="display: flex; justify-content: space-between; margin: 8px 0 4px 10px;">
                    <span>Penjualan Kasir Tunai</span>
                    <span>Rp <?= number_format($kas_penjualan_tunai, 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin: 4px 0 4px 10px;">
                    <span>Angsuran Cicilan Piutang</span>
                    <span>Rp <?= number_format($kas_cicilan_piutang, 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin: 4px 0 8px 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">
                    <span>Pemasukan Lain-lain</span>
                    <span>Rp <?= number_format($kas_pemasukan_lain, 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-weight: 700; margin-left: 10px;">
                    <span>Total Penerimaan Kas</span>
                    <span style="border-bottom: 1px solid #000; padding-bottom:2px;">Rp <?= number_format($total_kas_masuk, 0, ',', '.') ?></span>
                </div>
            </div>
            
            <br>

            <!-- OUTFLOW -->
            <div>
                <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">Arus Kas Keluar (Pengeluaran)</strong>
                <div style="display: flex; justify-content: space-between; margin: 8px 0 4px 10px;">
                    <span>Pembelian Restock Supplier (Faktur)</span>
                    <span>Rp <?= number_format($kas_pembelian_supplier, 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin: 4px 0 8px 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">
                    <span>Pengeluaran Operasional / Lainnya</span>
                    <span>Rp <?= number_format($kas_pengeluaran_lain, 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; font-weight: 700; margin-left: 10px;">
                    <span>Total Pengeluaran Kas</span>
                    <span style="border-bottom: 1px solid #000; padding-bottom:2px;">Rp <?= number_format($total_kas_keluar, 0, ',', '.') ?></span>
                </div>
            </div>

            <br>
            <div style="border-top: 2px solid var(--border-color); padding-top: 14px; display: flex; justify-content: space-between; align-items: center; font-weight: 800; font-size: 16px;">
                <span>Kenaikan/Penurunan Kas Bersih</span>
                <span style="color: <?= $arus_kas_netto >= 0 ? '#10b981' : '#ef4444' ?>; border-bottom: 3px double #000; padding-bottom: 2px;">
                    Rp <?= number_format($arus_kas_netto, 0, ',', '.') ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Income Statement Report Column -->
    <div class="content-card" style="padding: 24px; background: #fff;">
        <h3 style="font-size: 18px; font-weight: 800; margin-bottom: 20px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px; color: #4f46e5; display:flex; align-items:center; gap:8px;">
            <span>📈</span> Laporan Laba Rugi (Profit & Loss)
        </h3>

        <div style="display: flex; flex-direction: column; gap: 14px; font-size: 14px;">
            <!-- SALES REVENUE -->
            <div>
                <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">Pendapatan Operasional</strong>
                <div style="display: flex; justify-content: space-between; margin: 8px 0 8px 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">
                    <span>Pendapatan Penjualan Bersih</span>
                    <span>Rp <?= number_format($pendapatan_penjualan, 0, ',', '.') ?></span>
                </div>
            </div>

            <!-- COST OF GOODS SOLD -->
            <div>
                <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">Harga Pokok Penjualan (HPP)</strong>
                <div style="display: flex; justify-content: space-between; margin: 8px 0 8px 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">
                    <span>Beban Pokok Modal Penjualan</span>
                    <span>(Rp <?= number_format($hpp_barang, 0, ',', '.') ?>)</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-weight: 700; margin-left: 10px;">
                    <span>Total Laba Kotor (Gross Profit)</span>
                    <span style="border-bottom: 1px solid #000; padding-bottom:2px;">Rp <?= number_format($laba_kotor, 0, ',', '.') ?></span>
                </div>
            </div>

            <br>

            <!-- GENERAL LEDGER ADJUSTMENT -->
            <div>
                <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">Pendapatan & Beban Lain-lain</strong>
                <div style="display: flex; justify-content: space-between; margin: 8px 0 4px 10px;">
                    <span>Pendapatan Non-Operasional (Pemasukan Lain)</span>
                    <span>Rp <?= number_format($pendapatan_non_ops, 0, ',', '.') ?></span>
                </div>
                <div style="display: flex; justify-content: space-between; margin: 4px 0 8px 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">
                    <span>Beban Operasional & Administrasi</span>
                    <span>(Rp <?= number_format($beban_operasional, 0, ',', '.') ?>)</span>
                </div>
            </div>

            <br>
            <div style="border-top: 2px solid var(--border-color); padding-top: 14px; display: flex; justify-content: space-between; align-items: center; font-weight: 800; font-size: 16px;">
                <span>Laba Bersih Usaha (Net Profit)</span>
                <span style="color: <?= $laba_bersih >= 0 ? '#4f46e5' : '#ef4444' ?>; border-bottom: 3px double #000; padding-bottom: 2px;">
                    Rp <?= number_format($laba_bersih, 0, ',', '.') ?>
                </span>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        body {
            background: white !important;
            color: black !important;
        }
        .sidebar, .bottom-nav, .no-print, .page-header {
            display: none !important;
        }
        .main-layout {
            margin: 0 !important;
            padding: 0 !important;
        }
        .content-card {
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            margin-bottom: 40px !important;
        }
        .tx-layout {
            grid-template-columns: 1fr !important;
            gap: 40px !important;
        }
        .print-header {
            display: block !important;
        }
    }
</style>

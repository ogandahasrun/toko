<?php
if (!defined('host')) { exit; }

$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

$esc_start = $koneksi->real_escape_string($start_date);
$esc_end = $koneksi->real_escape_string($end_date);

// Total Sales & Cost in Period
$total_omset = 0;
$total_modal = 0;
$total_laba = 0;
$total_sisa_piutang = 0;

$resP = $koneksi->query("
    SELECT SUM(harga_jual) as omset, SUM(harga_beli) as modal, SUM(sisa_piutang) as piutang 
    FROM penjualan 
    WHERE tanggal BETWEEN '$esc_start' AND '$esc_end'
");
if ($resP && $r = $resP->fetch_assoc()) {
    $total_omset = $r['omset'] ? (double)$r['omset'] : 0;
    $total_modal = $r['modal'] ? (double)$r['modal'] : 0;
    $total_laba = $total_omset - $total_modal;
    $total_sisa_piutang = $r['piutang'] ? (double)$r['piutang'] : 0;
}

// Cash Payments Received in Period
$kas_tunai = 0;
$resTunai = $koneksi->query("SELECT SUM(jumlah_dibayar) as total FROM penjualan WHERE tipe_pembayaran = 'tunai' AND tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($resTunai && $r = $resTunai->fetch_assoc()) {
    $kas_tunai = $r['total'] ? (double)$r['total'] : 0;
}

$kas_cicilan = 0;
$resCicilan = $koneksi->query("SELECT SUM(jumlah_bayar) as total FROM pembayaran_kredit WHERE tanggal BETWEEN '$esc_start' AND '$esc_end'");
if ($resCicilan && $r = $resCicilan->fetch_assoc()) {
    $kas_cicilan = $r['total'] ? (double)$r['total'] : 0;
}

$total_kas_diterima = $kas_tunai + $kas_cicilan;

// Fetch sales list in period
$report_sales = $koneksi->query("
    SELECT p.*, pel.nama as nama_pelanggan
    FROM penjualan p
    LEFT JOIN pelanggan pel ON p.pelanggan_id = pel.id
    WHERE p.tanggal BETWEEN '$esc_start' AND '$esc_end'
    ORDER BY p.tanggal DESC, p.created_at DESC
");
?>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">Laporan Keuangan & Penjualan</h1>
        <p class="text-secondary">Ringkasan modal, omset penjualan, keuntungan, dan penerimaan kas</p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="window.print()">
            Cetak Laporan
        </button>
    </div>
</div>

<!-- Date Filter Form -->
<div class="card mb-4 no-print" style="padding: 16px;">
    <form action="index.php" method="GET" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="page" value="laporan">
        
        <div style="flex: 1; min-width: 150px;">
            <label class="form-label">Tanggal Mulai</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" required>
        </div>

        <div style="flex: 1; min-width: 150px;">
            <label class="form-label">Tanggal Selesai</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" required>
        </div>

        <button type="submit" class="btn btn-secondary">Terapkan Filter</button>
    </form>
</div>

<!-- Financial Summary Cards -->
<div class="card-grid mb-4">
    <div class="stat-card">
        <div class="stat-info">
            <h3>Omset Penjualan</h3>
            <p style="color: #2563eb;">Rp <?= number_format($total_omset, 0, ',', '.') ?></p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Modal (Harga Beli)</h3>
            <p style="color: #64748b;">Rp <?= number_format($total_modal, 0, ',', '.') ?></p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Keuntungan (Laba Gross)</h3>
            <p style="color: #10b981;">+Rp <?= number_format($total_laba, 0, ',', '.') ?></p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Total Kas Masuk Diterima</h3>
            <p style="color: #059669;">Rp <?= number_format($total_kas_diterima, 0, ',', '.') ?></p>
        </div>
    </div>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Rincian Penjualan Periode <?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?></h3>
    </div>
    <div class="table-responsive">
        <table class="table" style="vertical-align: middle;">
            <thead>
                <tr>
                    <th style="width: 150px;">No. Transaksi</th>
                    <th>Nama Barang</th>
                    <th>Tanggal</th>
                    <th>Pelanggan</th>
                    <th style="text-align: right;">Harga Beli (Modal)</th>
                    <th style="text-align: right;">Harga Jual</th>
                    <th style="text-align: right;">Keuntungan</th>
                    <th style="text-align: right;">Sisa Piutang</th>
                    <th style="text-align: center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($report_sales && $report_sales->num_rows > 0): ?>
                    <?php while ($r = $report_sales->fetch_assoc()): ?>
                        <?php 
                            $laba = $r['harga_jual'] - $r['harga_beli'];
                            $is_tunai = ($r['sisa_piutang'] <= 0);
                        ?>
                        <tr>
                            <td>
                                <span class="badge" style="background: #e0e7ff; color: #3730a3; font-family: monospace; font-size: 12px; padding: 6px 10px; border-radius: 6px;">
                                    <?= htmlspecialchars($r['no_penjualan']) ?>
                                </span>
                            </td>
                            <td><strong style="color: #0f172a; font-size: 14px;"><?= htmlspecialchars($r['nama_barang']) ?></strong></td>
                            <td><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                            <td><?= htmlspecialchars($r['nama_pelanggan'] ?? 'Umum') ?></td>
                            <td style="text-align: right; color: #64748b;">Rp <?= number_format($r['harga_beli'], 0, ',', '.') ?></td>
                            <td style="text-align: right; font-weight: 700; color: #1e293b;">Rp <?= number_format($r['harga_jual'], 0, ',', '.') ?></td>
                            <td style="text-align: right;">
                                <span style="color: #059669; background: #ecfdf5; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 13px;">
                                    +Rp <?= number_format($laba, 0, ',', '.') ?>
                                </span>
                            </td>
                            <td style="text-align: right;" class="<?= $r['sisa_piutang'] > 0 ? 'text-danger font-bold' : 'text-muted' ?>">
                                Rp <?= number_format($r['sisa_piutang'], 0, ',', '.') ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($is_tunai): ?>
                                    <span class="badge badge-success" style="padding: 6px 12px;">Tunai</span>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="padding: 6px 12px;">Cicilan</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">Tidak ada data transaksi pada periode terpilih.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

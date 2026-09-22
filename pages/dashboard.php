<?php
if (!defined('host')) { exit; }

// Fetch statistics
$omset_total = 0;
$modal_total = 0;
$laba_total = 0;
$res = $koneksi->query("SELECT SUM(harga_jual) as total_jual, SUM(harga_beli) as total_beli FROM penjualan");
if ($res && $r = $res->fetch_assoc()) {
    $omset_total = $r['total_jual'] ? (double)$r['total_jual'] : 0;
    $modal_total = $r['total_beli'] ? (double)$r['total_beli'] : 0;
    $laba_total = $omset_total - $modal_total;
}

$penjualan_hari_ini = 0;
$res = $koneksi->query("SELECT SUM(harga_jual) as total FROM penjualan WHERE tanggal = CURDATE()");
if ($res && $r = $res->fetch_assoc()) {
    $penjualan_hari_ini = $r['total'] ? (double)$r['total'] : 0;
}

$total_piutang = 0;
$res = $koneksi->query("SELECT SUM(sisa_piutang) as total FROM penjualan WHERE status_kredit = 'belum_lunas'");
if ($res && $r = $res->fetch_assoc()) {
    $total_piutang = $r['total'] ? (double)$r['total'] : 0;
}

$total_pelanggan = 0;
$res = $koneksi->query("SELECT COUNT(*) as total FROM pelanggan");
if ($res) {
    $total_pelanggan = (int)$res->fetch_assoc()['total'];
}

// Fetch 5 latest sales transactions
$latest_sales = $koneksi->query("
    SELECT p.*, pel.nama as nama_pelanggan
    FROM penjualan p
    LEFT JOIN pelanggan pel ON p.pelanggan_id = pel.id
    ORDER BY p.tanggal DESC, p.created_at DESC
    LIMIT 5
");

// Fetch 5 unpaid credit transactions
$unpaid_credits = $koneksi->query("
    SELECT p.*, pel.nama as nama_pelanggan, pel.no_hp
    FROM penjualan p
    LEFT JOIN pelanggan pel ON p.pelanggan_id = pel.id
    WHERE p.status_kredit = 'belum_lunas'
    ORDER BY p.tanggal ASC
    LIMIT 5
");
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Ringkasan Ringkas Toko</h1>
        <p class="text-secondary">Informasi penjualan, modal, keuntungan, dan piutang pelanggan terkini</p>
    </div>
    <div>
        <span class="badge badge-primary" style="padding: 8px 16px; font-size: 13px;">
            Hari ini: <?= date('d M Y') ?>
        </span>
    </div>
</div>

<!-- Financial Summary Stat Cards -->
<div class="card-grid mb-4">
    <div class="stat-card">
        <div class="stat-info">
            <h3>Total Omset Penjualan</h3>
            <p style="color: #2563eb;">Rp <?= number_format($omset_total, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon primary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Total Modal (Harga Beli)</h3>
            <p style="color: #475569;">Rp <?= number_format($modal_total, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon warning">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Estimasi Keuntungan (Laba)</h3>
            <p style="color: #10b981;">+Rp <?= number_format($laba_total, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon success">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Total Piutang Berjalan</h3>
            <p style="color: #ef4444;">Rp <?= number_format($total_piutang, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon danger">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        </div>
    </div>
</div>

<div class="card-grid mb-4" style="grid-template-columns: 2fr 1fr;">
    <!-- Recent Sales Table -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Penjualan Terbaru</h3>
            <a href="index.php?page=penjualan" class="btn btn-sm btn-light">Lihat Semua</a>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>No. Transaksi - Nama Barang</th>
                        <th>Pelanggan</th>
                        <th>Harga Jual</th>
                        <th>Keuntungan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($latest_sales && $latest_sales->num_rows > 0): ?>
                        <?php while ($ls = $latest_sales->fetch_assoc()): ?>
                            <?php 
                                $keuntungan = $ls['harga_jual'] - $ls['harga_beli'];
                                $is_tunai = ($ls['sisa_piutang'] <= 0);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($ls['no_penjualan']) ?></strong> - <?= htmlspecialchars($ls['nama_barang']) ?>
                                </td>
                                <td><?= htmlspecialchars($ls['nama_pelanggan'] ?? 'Umum') ?></td>
                                <td>Rp <?= number_format($ls['harga_jual'], 0, ',', '.') ?></td>
                                <td class="text-success font-bold">+Rp <?= number_format($keuntungan, 0, ',', '.') ?></td>
                                <td>
                                    <?php if ($is_tunai): ?>
                                        <span class="badge badge-success">Tunai</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Cicilan</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-3 text-muted">Belum ada transaksi penjualan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Outstanding Credits Card -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Piutang Aktif</h3>
            <a href="index.php?page=cicilan" class="btn btn-sm btn-light">Bayar Cicilan</a>
        </div>
        <div style="padding: 16px;">
            <?php if ($unpaid_credits && $unpaid_credits->num_rows > 0): ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php while ($uc = $unpaid_credits->fetch_assoc()): ?>
                        <div style="padding: 12px; background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-weight: 700; font-size: 13px; color: #0f172a;">
                                    <?= htmlspecialchars($uc['no_penjualan']) ?> - <?= htmlspecialchars($uc['nama_barang']) ?>
                                </div>
                                <div style="font-size: 12px; color: #64748b;">
                                    Pelanggan: <?= htmlspecialchars($uc['nama_pelanggan'] ?? 'Umum') ?>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: #ef4444; font-weight: 800; font-size: 13px;">
                                    Rp <?= number_format($uc['sisa_piutang'], 0, ',', '.') ?>
                                </div>
                                <a href="index.php?page=cicilan&search=<?= urlencode($uc['no_penjualan']) ?>" class="text-primary" style="font-size: 11px; text-decoration: none; font-weight: 600;">
                                    Bayar &rarr;
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-4 text-muted" style="font-size: 13px;">Tidak ada tagihan piutang aktif saat ini.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

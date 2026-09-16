<?php
if (!defined('host')) { exit; }

// Fetch statistics
$total_barang = 0;
$res = $koneksi->query("SELECT COUNT(*) as total FROM barang");
if ($res) $total_barang = $res->fetch_assoc()['total'];

$stok_gudang = 0;
$res = $koneksi->query("SELECT SUM(stok) as total FROM gudang_barang WHERE lokasi_id = 1");
if ($res) {
    $val = $res->fetch_assoc()['total'];
    $stok_gudang = $val ? $val : 0;
}

$stok_etalase = 0;
$res = $koneksi->query("SELECT SUM(stok) as total FROM gudang_barang WHERE lokasi_id = 2");
if ($res) {
    $val = $res->fetch_assoc()['total'];
    $stok_etalase = $val ? $val : 0;
}

$penjualan_hari_ini = 0;
$res = $koneksi->query("SELECT SUM(total_jual) as total FROM penjualan WHERE tanggal = CURDATE()");
if ($res) {
    $val = $res->fetch_assoc()['total'];
    $penjualan_hari_ini = $val ? $val : 0;
}

$total_piutang = 0;
$res = $koneksi->query("SELECT SUM(sisa_piutang) as total FROM penjualan WHERE tipe_pembayaran = 'kredit' AND status_kredit = 'belum_lunas'");
if ($res) {
    $val = $res->fetch_assoc()['total'];
    $total_piutang = $val ? $val : 0;
}

// Low Stock Alerts (stok gabungan < min_stok)
$low_stock = [];
$resLow = $koneksi->query("
    SELECT b.kode_barang, b.nama_barang, bd.min_stok, 
           COALESCE(SUM(gb.stok), 0) as total_stok
    FROM barang b
    JOIN barang_detail bd ON b.kode_barang = bd.kode_barang
    LEFT JOIN gudang_barang gb ON b.kode_barang = gb.kode_barang
    GROUP BY b.kode_barang
    HAVING total_stok < bd.min_stok
    LIMIT 5
");
if ($resLow) {
    while ($row = $resLow->fetch_assoc()) {
        $low_stock[] = $row;
    }
}
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Ringkasan Toko</h1>
        <p class="text-secondary">Informasi stok, penjualan, dan kredit terkini</p>
    </div>
    <div>
        <span class="badge badge-primary" style="padding: 8px 16px; font-size: 13px;">
            Hari ini: <?= date('d M Y') ?>
        </span>
    </div>
</div>

<!-- Stat Cards -->
<div class="card-grid">
    <div class="stat-card">
        <div class="stat-info">
            <h3>Total Produk</h3>
            <p><?= number_format($total_barang) ?></p>
        </div>
        <div class="stat-icon primary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Stok di Gudang</h3>
            <p><?= number_format($stok_gudang) ?></p>
        </div>
        <div class="stat-icon warning">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Stok di Etalase</h3>
            <p><?= number_format($stok_etalase) ?></p>
        </div>
        <div class="stat-icon success">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Omset Hari Ini</h3>
            <p>Rp <?= number_format($penjualan_hari_ini, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon secondary">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Total Piutang</h3>
            <p style="color: #ef4444;">Rp <?= number_format($total_piutang, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon danger">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path></svg>
        </div>
    </div>
</div>

<div class="tx-layout">
    <!-- Recent Penjualan -->
    <div>
        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title">Penjualan Terakhir</h3>
                <a href="index.php?page=penjualan" class="btn btn-secondary btn-sm">Buka Kasir</a>
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>No Penjualan</th>
                            <th>Tanggal</th>
                            <th>Tipe</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $resPen = $koneksi->query("
                            SELECT p.*, c.nama as nama_pelanggan 
                            FROM penjualan p 
                            LEFT JOIN pelanggan c ON p.pelanggan_id = c.id 
                            ORDER BY p.created_at DESC LIMIT 5
                        ");
                        if ($resPen && $resPen->num_rows > 0):
                            while ($p = $resPen->fetch_assoc()):
                        ?>
                            <tr>
                                <td data-label="No Penjualan">
                                    <strong><?= htmlspecialchars($p['no_penjualan']) ?></strong><br>
                                    <span class="text-secondary"><?= htmlspecialchars($p['nama_pelanggan'] ? $p['nama_pelanggan'] : 'Umum') ?></span>
                                </td>
                                <td data-label="Tanggal"><?= date('d/m/Y', strtotime($p['tanggal'])) ?></td>
                                <td data-label="Tipe">
                                    <?php if ($p['tipe_pembayaran'] === 'tunai'): ?>
                                        <span class="badge badge-success">Tunai</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Kredit (<?= htmlspecialchars($p['tempo_tipe']) ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Total">Rp <?= number_format($p['total_jual'], 0, ',', '.') ?></td>
                            </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                            <tr>
                                <td colspan="4" class="text-center text-secondary py-4">Belum ada transaksi penjualan</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right Side: Low Stock & Shortcuts -->
    <div>
        <!-- Low Stock Warning Card -->
        <?php if (!empty($low_stock)): ?>
            <div class="content-card" style="border-color: rgba(239, 68, 68, 0.3); background: rgba(239, 68, 68, 0.02);">
                <div class="card-header">
                    <h3 class="card-title" style="color: #ef4444; display: flex; align-items: center; gap: 8px;">
                        <span>⚠️</span> Peringatan Stok Tipis
                    </h3>
                </div>
                <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 10px;">
                    <?php foreach ($low_stock as $ls): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                            <div>
                                <strong style="font-size: 13px;"><?= htmlspecialchars($ls['nama_barang']) ?></strong><br>
                                <span class="text-secondary" style="font-size: 12px;"><?= htmlspecialchars($ls['kode_barang']) ?></span>
                            </div>
                            <div style="text-align: right;">
                                <span style="color: #ef4444; font-weight: 700; font-size: 14px;"><?= $ls['total_stok'] ?></span> 
                                <span class="text-secondary" style="font-size: 12px;">/ min <?= $ls['min_stok'] ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <br>
                <a href="index.php?page=faktur" class="btn btn-primary btn-sm" style="width: 100%; justify-content: center;">
                    Restock via Faktur Baru
                </a>
            </div>
        <?php endif; ?>

        <!-- Quick actions -->
        <div class="content-card">
            <h3 class="card-title" style="margin-bottom: 16px;">Tindakan Cepat</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                <?php if ($_SESSION['akses_penjualan']): ?>
                    <a href="index.php?page=penjualan" class="btn btn-primary" style="justify-content: center; font-size: 13px; padding: 12px;">Kasir</a>
                <?php endif; ?>
                
                <?php if ($_SESSION['akses_mutasi']): ?>
                    <a href="index.php?page=mutasi" class="btn btn-secondary" style="justify-content: center; font-size: 13px; padding: 12px;">Mutasi Barang</a>
                <?php endif; ?>
                
                <?php if ($_SESSION['akses_barang']): ?>
                    <a href="index.php?page=barang" class="btn btn-secondary" style="justify-content: center; font-size: 13px; padding: 12px;">Tambah Barang</a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_pelanggan']): ?>
                    <a href="index.php?page=pelanggan" class="btn btn-secondary" style="justify-content: center; font-size: 13px; padding: 12px;">Kelola Kredit</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
if (!defined('host')) { exit; }

$alert = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Handle POST actions (Insert and Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'add') {
        $tanggal = $_POST['tanggal'];
        $tipe = $_POST['tipe'];
        $jumlah = (double)$_POST['jumlah'];
        $keterangan = trim($_POST['keterangan']);

        if (!empty($tanggal) && !empty($tipe) && $jumlah > 0 && !empty($keterangan)) {
            $stmt = $koneksi->prepare("INSERT INTO jurnal_kas (tanggal, tipe, jumlah, keterangan) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssds", $tanggal, $tipe, $jumlah, $keterangan);
            if ($stmt->execute()) {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Transaksi keuangan berhasil dicatat!', 'success'));</script>";
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal mencatat transaksi!', 'danger'));</script>";
            }
            $stmt->close();
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Harap isi semua kolom wajib dengan benar!', 'warning'));</script>";
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $koneksi->prepare("DELETE FROM jurnal_kas WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Transaksi berhasil dihapus!', 'success'));</script>";
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menghapus transaksi!', 'danger'));</script>";
        }
        $stmt->close();
    }
}

// Calculate summary stats for the current filter
$tot_pemasukan = 0;
$tot_pengeluaran = 0;

$esc_start = $koneksi->real_escape_string($start_date);
$esc_end = $koneksi->real_escape_string($end_date);

$resSum = $koneksi->query("SELECT tipe, SUM(jumlah) as total FROM jurnal_kas WHERE tanggal BETWEEN '$esc_start' AND '$esc_end' GROUP BY tipe");
if ($resSum) {
    while ($s = $resSum->fetch_assoc()) {
        if ($s['tipe'] === 'pemasukan') $tot_pemasukan = (double)$s['total'];
        if ($s['tipe'] === 'pengeluaran') $tot_pengeluaran = (double)$s['total'];
    }
}
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Jurnal Kas & Keuangan</h1>
        <p class="text-secondary">Pencatatan pendapatan non-penjualan dan pengeluaran operasional toko</p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Catat Keuangan
        </button>
    </div>
</div>

<!-- Keuangan stats inside active filter -->
<div class="card-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
    <div class="stat-card" style="padding: 16px 20px;">
        <div class="stat-info">
            <h3>Pemasukan Lainnya</h3>
            <p style="color:#10b981; font-size:22px;">Rp <?= number_format($tot_pemasukan, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon success" style="width: 38px; height:38px;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
        </div>
    </div>
    <div class="stat-card" style="padding: 16px 20px;">
        <div class="stat-info">
            <h3>Pengeluaran Operasional</h3>
            <p style="color:#ef4444; font-size:22px;">Rp <?= number_format($tot_pengeluaran, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon danger" style="width: 38px; height:38px;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
        </div>
    </div>
    <div class="stat-card" style="padding: 16px 20px;">
        <div class="stat-info">
            <h3>Selisih Bersih</h3>
            <?php $selisih = $tot_pemasukan - $tot_pengeluaran; ?>
            <p style="color: <?= $selisih >= 0 ? '#10b981' : '#ef4444' ?>; font-size:22px;">
                Rp <?= number_format($selisih, 0, ',', '.') ?>
            </p>
        </div>
        <div class="stat-icon primary" style="width: 38px; height:38px;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">Jurnal Transaksi</h3>
        <!-- Date filter form -->
        <form action="" method="GET" style="display: flex; gap: 8px; flex-wrap: wrap;">
            <input type="hidden" name="page" value="keuangan">
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($start_date) ?>" style="width:130px;">
            <span class="text-secondary" style="align-self: center;">s/d</span>
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($end_date) ?>" style="width:130px;">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Keterangan..." value="<?= htmlspecialchars($search) ?>" style="width:140px;">
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Tipe</th>
                    <th>Jumlah (Rp)</th>
                    <th>Keterangan / Catatan</th>
                    <th style="width: 80px; text-align: center;">Hapus</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $esc_search = $koneksi->real_escape_string($search);
                $q = "SELECT * FROM jurnal_kas WHERE tanggal BETWEEN '$esc_start' AND '$esc_end'";
                if (!empty($search)) {
                    $q .= " AND keterangan LIKE '%$esc_search%'";
                }
                $q .= " ORDER BY tanggal DESC, id DESC";

                $res = $koneksi->query($q);
                if ($res && $res->num_rows > 0):
                    while ($r = $res->fetch_assoc()):
                ?>
                    <tr>
                        <td data-label="Tanggal"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                        <td data-label="Tipe">
                            <?php if ($r['tipe'] === 'pemasukan'): ?>
                                <span class="badge badge-success">Pemasukan</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Pengeluaran</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Jumlah (Rp)" style="font-weight: 700; color: <?= $r['tipe'] === 'pemasukan' ? '#10b981' : '#ef4444' ?>;">
                            Rp <?= number_format($r['jumlah'], 0, ',', '.') ?>
                        </td>
                        <td data-label="Keterangan"><?= htmlspecialchars($r['keterangan']) ?></td>
                        <td class="text-center">
                            <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus catatan transaksi ini?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm" style="padding: 4px 8px;">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php 
                    endwhile;
                else:
                ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary py-4">Tidak ada data transaksi dalam rentang tanggal terpilih.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Transaksi Keuangan -->
<div id="modalKeuangan" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Catat Arus Keuangan</h3>
            <button class="btn-close" onclick="closeModal('modalKeuangan')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="add">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Tanggal Transaksi <span style="color:red;">*</span></label>
                    <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Jenis Transaksi <span style="color:red;">*</span></label>
                    <select name="tipe" class="form-control" required>
                        <option value="pengeluaran">Pengeluaran (Gaji, Listrik, Operasional, dll.)</option>
                        <option value="pemasukan">Pemasukan Lainnya (Bunga bank, jasa titip, dll.)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Jumlah Uang (Rp) <span style="color:red;">*</span></label>
                    <input type="number" name="jumlah" class="form-control" placeholder="0" inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Keterangan / Deskripsi Transaksi <span style="color:red;">*</span></label>
                    <textarea name="keterangan" class="form-control" rows="3" placeholder="Contoh: Pembayaran internet bulanan atau Penerimaan bonus penjualan supplier" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalKeuangan')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Transaksi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    openModal('modalKeuangan');
}
</script>

<?php
if (!defined('host')) { exit; }

$alert = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch locations list
$lokasi_list = [];
$resL = $koneksi->query("SELECT * FROM lokasi ORDER BY id ASC");
if ($resL) {
    while ($l = $resL->fetch_assoc()) {
        $lokasi_list[$l['id']] = $l;
    }
}

// Handle Location CRUD actions via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_lokasi') {
        $nama_lokasi = trim($_POST['nama_lokasi']);
        $keterangan = trim($_POST['keterangan']);

        if (!empty($nama_lokasi)) {
            $stmt = $koneksi->prepare("INSERT INTO lokasi (nama_lokasi, keterangan) VALUES (?, ?)");
            $stmt->bind_param("ss", $nama_lokasi, $keterangan);
            if ($stmt->execute()) {
                $new_id = $koneksi->insert_id;
                
                // Initialize stock tracking at 0 for all existing items in this new location
                $koneksi->query("INSERT IGNORE INTO gudang_barang (lokasi_id, kode_barang, stok) SELECT $new_id, kode_barang, 0 FROM barang");

                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Lokasi baru berhasil dibuat!', 'success'));</script>";
                
                // Refresh locations list
                $lokasi_list = [];
                $resL = $koneksi->query("SELECT * FROM lokasi ORDER BY id ASC");
                while ($l = $resL->fetch_assoc()) {
                    $lokasi_list[$l['id']] = $l;
                }
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal membuat lokasi!', 'danger'));</script>";
            }
            $stmt->close();
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Nama lokasi tidak boleh kosong!', 'warning'));</script>";
        }
    }

    if ($action === 'delete_lokasi') {
        $del_id = (int)$_POST['id'];
        
        if (count($lokasi_list) <= 1) {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal! Harus ada minimal 1 lokasi stok di aplikasi.', 'danger'));</script>";
        } else {
            $stmt = $koneksi->prepare("DELETE FROM lokasi WHERE id = ?");
            $stmt->bind_param("i", $del_id);
            if ($stmt->execute()) {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Lokasi berhasil dihapus!', 'success'));</script>";
                
                // Refresh locations list
                $lokasi_list = [];
                $resL = $koneksi->query("SELECT * FROM lokasi ORDER BY id ASC");
                while ($l = $resL->fetch_assoc()) {
                    $lokasi_list[$l['id']] = $l;
                }
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menghapus lokasi!', 'danger'));</script>";
            }
            $stmt->close();
        }
    }
}

// Select active location ID
$lokasi_id = isset($_GET['lokasi_id']) ? (int)$_GET['lokasi_id'] : (count($lokasi_list) > 0 ? array_keys($lokasi_list)[0] : 1);
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Gudang & Stok Barang</h1>
        <p class="text-secondary">Lihat posisi stok barang di berbagai lokasi</p>
    </div>
    <div>
        <button class="btn btn-secondary" onclick="openModal('modalLokasi')">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
            Kelola Lokasi
        </button>
    </div>
</div>

<div class="content-card">
    <div class="card-header">
        <!-- Location filter tabs -->
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <?php foreach ($lokasi_list as $id => $lok): ?>
                <a href="index.php?page=gudang&lokasi_id=<?= $id ?>&search=<?= urlencode($search) ?>" 
                   class="btn <?= $lokasi_id === $id ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
                    <?= htmlspecialchars($lok['nama_lokasi']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Search Form -->
        <form action="" method="GET" style="display: flex; gap: 8px;">
            <input type="hidden" name="page" value="gudang">
            <input type="hidden" name="lokasi_id" value="<?= $lokasi_id ?>">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari barang..." value="<?= htmlspecialchars($search) ?>" style="width: 180px;">
            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        </form>
    </div>

    <!-- Location Description -->
    <?php if (isset($lokasi_list[$lokasi_id])): ?>
        <div style="margin-bottom: 20px; padding: 12px; background: rgba(99, 102, 241, 0.05); border-left: 4px solid #6366f1; border-radius: 0 var(--radius-sm) var(--radius-sm) 0;">
            <span style="font-size: 13px; color: var(--text-secondary);">
                Keterangan Lokasi: <b><?= htmlspecialchars($lokasi_list[$lokasi_id]['keterangan'] ? $lokasi_list[$lokasi_id]['keterangan'] : 'Tidak ada keterangan') ?></b>
            </span>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Barang</th>
                    <th>Kategori</th>
                    <th>Stok saat ini</th>
                    <th>Satuan</th>
                    <th style="width: 120px; text-align: center;">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $esc = $koneksi->real_escape_string($search);
                $q = "SELECT b.kode_barang, b.nama_barang, b.satuan, b.kategori, COALESCE(gb.stok, 0) as stok
                      FROM barang b
                      LEFT JOIN gudang_barang gb ON b.kode_barang = gb.kode_barang AND gb.lokasi_id = $lokasi_id";
                if (!empty($search)) {
                    $q .= " WHERE b.kode_barang LIKE '%$esc%' OR b.nama_barang LIKE '%$esc%' OR b.kategori LIKE '%$esc%'";
                }
                $q .= " ORDER BY b.nama_barang ASC";

                $res = $koneksi->query($q);
                if ($res && $res->num_rows > 0):
                    while ($r = $res->fetch_assoc()):
                        $stok_class = $r['stok'] <= 0 ? 'color: #ef4444; font-weight: 700;' : 'font-weight: 700;';
                ?>
                    <tr>
                        <td data-label="Kode"><strong><?= htmlspecialchars($r['kode_barang']) ?></strong></td>
                        <td data-label="Nama Barang"><?= htmlspecialchars($r['nama_barang']) ?></td>
                        <td data-label="Kategori"><span class="badge badge-primary"><?= htmlspecialchars($r['kategori']) ?></span></td>
                        <td data-label="Stok saat ini" style="<?= $stok_class ?>"><?= number_format($r['stok']) ?></td>
                        <td data-label="Satuan"><?= htmlspecialchars($r['satuan']) ?></td>
                        <td class="text-center">
                            <?php if ($_SESSION['akses_mutasi'] && $r['stok'] > 0): ?>
                                <a href="index.php?page=mutasi&auto_kode=<?= urlencode($r['kode_barang']) ?>&dari_lokasi=<?= $lokasi_id ?>" class="btn btn-secondary btn-sm">
                                    Mutasi
                                </a>
                            <?php else: ?>
                                <span class="text-secondary" style="font-size: 12px;">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php 
                    endwhile;
                else:
                ?>
                    <tr>
                        <td colspan="6" class="text-center text-secondary py-4">Tidak ada barang yang terdaftar di lokasi ini.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Kelola Lokasi -->
<div id="modalLokasi" class="modal-overlay">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Manajemen Lokasi Stok</h3>
            <button class="btn-close" onclick="closeModal('modalLokasi')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <!-- Form Tambah Lokasi -->
            <form action="" method="POST" style="margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--border-color);">
                <input type="hidden" name="action" value="add_lokasi">
                <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 12px;">Buat Lokasi Baru</h4>
                <div style="display: grid; grid-template-columns: 1fr 2fr auto; gap: 10px; align-items: flex-end;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Nama Lokasi</label>
                        <input type="text" name="nama_lokasi" class="form-control form-control-sm" placeholder="Contoh: Depo B, Toko Depan" required>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label class="form-label">Keterangan / Deskripsi</label>
                        <input type="text" name="keterangan" class="form-control form-control-sm" placeholder="Lokasi depo stok...">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Tambah</button>
                </div>
            </form>

            <!-- Tabel Daftar Lokasi -->
            <h4 style="font-size: 14px; font-weight: 700; margin-bottom: 12px;">Daftar Lokasi Terdaftar</h4>
            <div class="table-responsive" style="max-height: 250px; overflow-y:auto;">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Nama Lokasi</th>
                            <th>Keterangan</th>
                            <th style="width: 80px; text-align: center;">Hapus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lokasi_list as $id => $lok): ?>
                            <tr>
                                <td data-label="Nama Lokasi"><strong><?= htmlspecialchars($lok['nama_lokasi']) ?></strong></td>
                                <td data-label="Keterangan"><?= htmlspecialchars($lok['keterangan'] ? $lok['keterangan'] : '-') ?></td>
                                <td class="text-center">
                                    <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus lokasi ini? Seluruh stok pada lokasi ini akan dihapus secara permanen.');">
                                        <input type="hidden" name="action" value="delete_lokasi">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="padding: 4px 8px;">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalLokasi')">Tutup</button>
        </div>
    </div>
</div>

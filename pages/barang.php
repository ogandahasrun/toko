<?php
if (!defined('host')) { exit; }

$alert = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'add' || $action === 'edit') {
        $kode_barang = trim($_POST['kode_barang']);
        $nama_barang = trim($_POST['nama_barang']);
        $satuan = trim($_POST['satuan']);
        $kategori = trim($_POST['kategori']);
        $harga_beli = (double)$_POST['harga_beli'];
        $harga_jual = (double)$_POST['harga_jual'];
        $min_stok = (int)$_POST['min_stok'];

        if (!empty($kode_barang) && !empty($nama_barang) && !empty($satuan) && !empty($kategori)) {
            $koneksi->begin_transaction();
            try {
                if ($action === 'add') {
                    // Check duplicate
                    $chk = $koneksi->prepare("SELECT kode_barang FROM barang WHERE kode_barang = ?");
                    $chk->bind_param("s", $kode_barang);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        throw new Exception("Kode barang sudah terdaftar!");
                    }
                    $chk->close();

                    // Insert Master
                    $stmt = $koneksi->prepare("INSERT INTO barang (kode_barang, nama_barang, satuan, kategori) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("ssss", $kode_barang, $nama_barang, $satuan, $kategori);
                    $stmt->execute();
                    $stmt->close();

                    // Insert Detail
                    $stmtD = $koneksi->prepare("INSERT INTO barang_detail (kode_barang, harga_beli, harga_jual, min_stok) VALUES (?, ?, ?, ?)");
                    $stmtD->bind_param("sddi", $kode_barang, $harga_beli, $harga_jual, $min_stok);
                    $stmtD->execute();
                    $stmtD->close();

                    // Init stock in Gudang (1) and Etalase (2) to 0
                    $koneksi->query("INSERT INTO gudang_barang (lokasi_id, kode_barang, stok) VALUES (1, '$kode_barang', 0), (2, '$kode_barang', 0)");

                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Barang berhasil ditambahkan!', 'success'));</script>";
                } else {
                    // Update Master (ON UPDATE CASCADE will automatically cascade kode_barang to all child tables)
                    $old_kode = $_POST['old_kode_barang'];

                    $stmt = $koneksi->prepare("UPDATE barang SET kode_barang = ?, nama_barang = ?, satuan = ?, kategori = ? WHERE kode_barang = ?");
                    $stmt->bind_param("sssss", $kode_barang, $nama_barang, $satuan, $kategori, $old_kode);
                    $stmt->execute();
                    $stmt->close();

                    // Update prices & min_stok in barang_detail
                    $stmtD = $koneksi->prepare("UPDATE barang_detail SET harga_beli = ?, harga_jual = ?, min_stok = ? WHERE kode_barang = ?");
                    $stmtD->bind_param("ddis", $harga_beli, $harga_jual, $min_stok, $kode_barang);
                    $stmtD->execute();
                    $stmtD->close();

                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Barang berhasil diperbarui!', 'success'));</script>";
                }
                $koneksi->commit();
            } catch (Exception $e) {
                $koneksi->rollback();
                $err_msg = addslashes($e->getMessage());
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
            }
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Harap isi semua bidang wajib!', 'warning'));</script>";
        }
    }

    if ($action === 'delete') {
        $kode_barang = $_POST['kode_barang'];
        $koneksi->begin_transaction();
        try {
            // Check if item is used in any transactions (faktur_detail, penjualan_detail, mutasi_detail)
            $resF = $koneksi->query("SELECT COUNT(*) as c FROM faktur_detail WHERE kode_barang = '" . $koneksi->real_escape_string($kode_barang) . "'")->fetch_assoc()['c'];
            $resP = $koneksi->query("SELECT COUNT(*) as c FROM penjualan_detail WHERE kode_barang = '" . $koneksi->real_escape_string($kode_barang) . "'")->fetch_assoc()['c'];
            $resM = $koneksi->query("SELECT COUNT(*) as c FROM mutasi_detail WHERE kode_barang = '" . $koneksi->real_escape_string($kode_barang) . "'")->fetch_assoc()['c'];

            if ($resF > 0 || $resP > 0 || $resM > 0) {
                throw new Exception("Barang '$kode_barang' tidak dapat dihapus karena sudah memiliki riwayat transaksi (Faktur/Penjualan/Mutasi)!");
            }

            // Clean up helper tables (gudang_barang, barang_detail) before deleting master
            $koneksi->query("DELETE FROM gudang_barang WHERE kode_barang = '" . $koneksi->real_escape_string($kode_barang) . "'");
            $koneksi->query("DELETE FROM barang_detail WHERE kode_barang = '" . $koneksi->real_escape_string($kode_barang) . "'");
            $stmt = $koneksi->prepare("DELETE FROM barang WHERE kode_barang = ?");
            $stmt->bind_param("s", $kode_barang);
            $stmt->execute();
            $stmt->close();

            $koneksi->commit();
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Barang berhasil dihapus!', 'success'));</script>";
        } catch (Exception $e) {
            $koneksi->rollback();
            $err_msg = addslashes($e->getMessage());
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
        }
    }

    if ($action === 'add_satuan') {
        $nama_satuan = trim($_POST['nama_satuan']);
        $keterangan = trim($_POST['keterangan']);
        if (!empty($nama_satuan)) {
            $stmtS = $koneksi->prepare("INSERT INTO satuan (nama_satuan, keterangan) VALUES (?, ?)");
            $stmtS->bind_param("ss", $nama_satuan, $keterangan);
            if ($stmtS->execute()) {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Satuan \"$nama_satuan\" berhasil ditambahkan!', 'success'));</script>";
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menambahkan satuan! (Mungkin sudah ada)', 'danger'));</script>";
            }
            $stmtS->close();
        }
    }

    if ($action === 'add_kategori') {
        $nama_kategori = trim($_POST['nama_kategori']);
        $keterangan = trim($_POST['keterangan']);
        if (!empty($nama_kategori)) {
            $stmtK = $koneksi->prepare("INSERT INTO kategori (nama_kategori, keterangan) VALUES (?, ?)");
            $stmtK->bind_param("ss", $nama_kategori, $keterangan);
            if ($stmtK->execute()) {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Kategori \"$nama_kategori\" berhasil ditambahkan!', 'success'));</script>";
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menambahkan kategori! (Mungkin sudah ada)', 'danger'));</script>";
            }
            $stmtK->close();
        }
    }
}

// Fetch active satuan & kategori list for dropdowns
$satuan_list = [];
$resS = $koneksi->query("SELECT nama_satuan FROM satuan ORDER BY nama_satuan ASC");
if ($resS) {
    while ($s = $resS->fetch_assoc()) {
        $satuan_list[] = $s;
    }
}

$kategori_list = [];
$resK = $koneksi->query("SELECT nama_kategori FROM kategori ORDER BY nama_kategori ASC");
if ($resK) {
    while ($k = $resK->fetch_assoc()) {
        $kategori_list[] = $k;
    }
}
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Master Data Barang</h1>
        <p class="text-secondary">Kelola daftar produk, harga, dan satuan</p>
    </div>
    <div style="display: flex; gap: 8px;">
        <button class="btn btn-secondary" onclick="openModal('modalAddSatuan')">
            + Satuan
        </button>
        <button class="btn btn-secondary" onclick="openModal('modalAddKategori')">
            + Kategori
        </button>
        <button class="btn btn-primary" onclick="openAddModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Tambah Barang
        </button>
    </div>
</div>

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">Daftar Barang</h3>
        <form action="" method="GET" style="display: flex; gap: 8px;">
            <input type="hidden" name="page" value="barang">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari kode / nama..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
            <button type="submit" class="btn btn-secondary btn-sm">Cari</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Kode</th>
                    <th>Nama Barang</th>
                    <th>Satuan / Kategori</th>
                    <th>Harga Beli</th>
                    <th>Harga Jual</th>
                    <th>Min. Stok</th>
                    <th style="width: 100px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $q = "SELECT b.*, bd.harga_beli, bd.harga_jual, bd.min_stok 
                      FROM barang b 
                      LEFT JOIN barang_detail bd ON b.kode_barang = bd.kode_barang";
                if (!empty($search)) {
                    $esc = $koneksi->real_escape_string($search);
                    $q .= " WHERE b.kode_barang LIKE '%$esc%' OR b.nama_barang LIKE '%$esc%' OR b.kategori LIKE '%$esc%'";
                }
                $q .= " ORDER BY b.kode_barang ASC";

                $res = $koneksi->query($q);
                if ($res && $res->num_rows > 0):
                    while ($r = $res->fetch_assoc()):
                ?>
                    <tr>
                        <td data-label="Kode"><strong><?= htmlspecialchars($r['kode_barang']) ?></strong></td>
                        <td data-label="Nama Barang"><?= htmlspecialchars($r['nama_barang']) ?></td>
                        <td data-label="Satuan / Kategori">
                            <span class="badge badge-primary"><?= htmlspecialchars($r['satuan']) ?></span>
                            <span class="badge badge-secondary"><?= htmlspecialchars($r['kategori']) ?></span>
                        </td>
                        <td data-label="Harga Beli">Rp <?= number_format($r['harga_beli'], 0, ',', '.') ?></td>
                        <td data-label="Harga Jual">Rp <?= number_format($r['harga_jual'], 0, ',', '.') ?></td>
                        <td data-label="Min. Stok"><?= number_format($r['min_stok']) ?></td>
                        <td class="text-center">
                            <div style="display: flex; gap: 4px; justify-content: center;">
                                <button class="btn btn-warning btn-sm" onclick='openEditModal(<?= json_encode($r) ?>)'>
                                    Edit
                                </button>
                                <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus barang ini?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="kode_barang" value="<?= htmlspecialchars($r['kode_barang']) ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php 
                    endwhile;
                else:
                ?>
                    <tr>
                        <td colspan="7" class="text-center text-secondary py-4">Belum ada data barang.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah/Edit Barang -->
<div id="modalBarang" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Tambah Barang Baru</h3>
            <button class="btn-close" onclick="closeModal('modalBarang')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="old_kode_barang" id="oldKodeBarang" value="">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Kode Barang <span style="color:red;">*</span></label>
                    <input type="text" name="kode_barang" id="inpKode" class="form-control" placeholder="Contoh: BRG001" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Barang <span style="color:red;">*</span></label>
                    <input type="text" name="nama_barang" id="inpNama" class="form-control" placeholder="Contoh: Indomie Goreng" required>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label">Satuan <span style="color:red;">*</span></label>
                            <a href="javascript:void(0)" onclick="openModal('modalAddSatuan')" style="font-size: 11px; text-decoration: underline; color: var(--primary-color);">+ Tambah Satuan</a>
                        </div>
                        <select name="satuan" id="inpSatuan" class="form-control" required>
                            <option value="">-- Pilih Satuan --</option>
                            <?php foreach ($satuan_list as $s): ?>
                                <option value="<?= htmlspecialchars($s['nama_satuan']) ?>"><?= htmlspecialchars($s['nama_satuan']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <label class="form-label">Kategori <span style="color:red;">*</span></label>
                            <a href="javascript:void(0)" onclick="openModal('modalAddKategori')" style="font-size: 11px; text-decoration: underline; color: var(--primary-color);">+ Tambah Kategori</a>
                        </div>
                        <select name="kategori" id="inpKategori" class="form-control" required>
                            <option value="">-- Pilih Kategori --</option>
                            <?php foreach ($kategori_list as $k): ?>
                                <option value="<?= htmlspecialchars($k['nama_kategori']) ?>"><?= htmlspecialchars($k['nama_kategori']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="form-group">
                        <label class="form-label">Harga Beli (Rp) <span style="color:red;">*</span></label>
                        <input type="number" name="harga_beli" id="inpHargaBeli" class="form-control" placeholder="0" inputmode="numeric" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Harga Jual (Rp) <span style="color:red;">*</span></label>
                        <input type="number" name="harga_jual" id="inpHargaJual" class="form-control" placeholder="0" inputmode="numeric" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Batas Minimum Stok untuk Peringatan</label>
                    <input type="number" name="min_stok" id="inpMinStok" class="form-control" placeholder="Contoh: 10" inputmode="numeric" value="0">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalBarang')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Satuan -->
<div id="modalAddSatuan" class="modal-overlay">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3>Tambah Satuan Baru</h3>
            <button class="btn-close" onclick="closeModal('modalAddSatuan')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="add_satuan">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Satuan <span style="color:red;">*</span></label>
                    <input type="text" name="nama_satuan" class="form-control" placeholder="Contoh: Galon, Rim, Slop" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Keterangan (Opsional)</label>
                    <input type="text" name="keterangan" class="form-control" placeholder="Contoh: Kemasan 19 Liter">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalAddSatuan')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Satuan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tambah Kategori -->
<div id="modalAddKategori" class="modal-overlay">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3>Tambah Kategori Baru</h3>
            <button class="btn-close" onclick="closeModal('modalAddKategori')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="add_kategori">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Kategori <span style="color:red;">*</span></label>
                    <input type="text" name="nama_kategori" class="form-control" placeholder="Contoh: Alat Tulis, Kosmetik" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Keterangan (Opsional)</label>
                    <input type="text" name="keterangan" class="form-control" placeholder="Deskripsi kategori">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalAddKategori')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Kategori</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Tambah Barang Baru';
    document.getElementById('formAction').value = 'add';
    document.getElementById('oldKodeBarang').value = '';
    document.getElementById('inpKode').value = '';
    document.getElementById('inpKode').readOnly = false;
    document.getElementById('inpNama').value = '';
    document.getElementById('inpSatuan').value = '';
    document.getElementById('inpKategori').value = '';
    document.getElementById('inpHargaBeli').value = '';
    document.getElementById('inpHargaJual').value = '';
    document.getElementById('inpMinStok').value = '0';
    openModal('modalBarang');
}

function openEditModal(data) {
    document.getElementById('modalTitle').innerText = 'Edit Data Barang';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('oldKodeBarang').value = data.kode_barang;
    document.getElementById('inpKode').value = data.kode_barang;
    // we allow code edits but handle updates accordingly
    document.getElementById('inpNama').value = data.nama_barang;
    document.getElementById('inpSatuan').value = data.satuan;
    document.getElementById('inpKategori').value = data.kategori;
    document.getElementById('inpHargaBeli').value = data.harga_beli;
    document.getElementById('inpHargaJual').value = data.harga_jual;
    document.getElementById('inpMinStok').value = data.min_stok;
    openModal('modalBarang');
}
</script>

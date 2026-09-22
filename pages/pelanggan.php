<?php
if (!defined('host')) { exit; }

$alert = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Handle CRUD operations for Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add_customer' || $action === 'edit_customer') {
        $nama = trim($_POST['nama']);
        $alamat = trim($_POST['alamat']);
        $no_hp = trim($_POST['no_hp']);
        $limit_kredit = (double)$_POST['limit_kredit'];

        if (!empty($nama)) {
            if ($action === 'add_customer') {
                $stmt = $koneksi->prepare("INSERT INTO pelanggan (nama, alamat, no_hp, limit_kredit) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssd", $nama, $alamat, $no_hp, $limit_kredit);
                if ($stmt->execute()) {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Pelanggan baru berhasil didaftarkan!', 'success'));</script>";
                } else {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal mendaftarkan pelanggan!', 'danger'));</script>";
                }
                $stmt->close();
            } else {
                $id = (int)$_POST['id'];
                $stmt = $koneksi->prepare("UPDATE pelanggan SET nama = ?, alamat = ?, no_hp = ?, limit_kredit = ? WHERE id = ?");
                $stmt->bind_param("sssdi", $nama, $alamat, $no_hp, $limit_kredit, $id);
                if ($stmt->execute()) {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Data pelanggan berhasil diperbarui!', 'success'));</script>";
                } else {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal memperbarui data pelanggan!', 'danger'));</script>";
                }
                $stmt->close();
            }
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Nama pelanggan wajib diisi!', 'warning'));</script>";
        }
    }

    if ($action === 'delete_customer') {
        $id = (int)$_POST['id'];
        
        // Check if customer has outstanding credit
        $chkPiutang = $koneksi->query("SELECT SUM(sisa_piutang) as total FROM penjualan WHERE pelanggan_id = $id AND status_kredit = 'belum_lunas'");
        $totalPiutang = 0;
        if ($chkPiutang && $rowP = $chkPiutang->fetch_assoc()) {
            $totalPiutang = (double)$rowP['total'];
        }
        
        if ($totalPiutang > 0) {
            $err_msg = "Pelanggan tidak dapat dihapus karena masih memiliki sisa piutang berjalan sebesar Rp " . number_format($totalPiutang, 0, ',', '.') . "!";
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
        } else {
            $stmt = $koneksi->prepare("DELETE FROM pelanggan WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Pelanggan berhasil dihapus!', 'success'));</script>";
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menghapus pelanggan!', 'danger'));</script>";
            }
            $stmt->close();
        }
    }
}

// Query Customers with Outstanding Credit Sum
$where = "1=1";
if (!empty($search)) {
    $esc = $koneksi->real_escape_string($search);
    $where .= " AND (c.nama LIKE '%$esc%' OR c.no_hp LIKE '%$esc%' OR c.alamat LIKE '%$esc%')";
}

$query = "
    SELECT c.*, 
           COALESCE(SUM(CASE WHEN p.status_kredit = 'belum_lunas' THEN p.sisa_piutang ELSE 0 END), 0) as total_piutang,
           COUNT(p.no_penjualan) as total_transaksi
    FROM pelanggan c
    LEFT JOIN penjualan p ON c.id = p.pelanggan_id
    WHERE $where
    GROUP BY c.id
    ORDER BY c.nama ASC
";
$customers_result = $koneksi->query($query);
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Data Pelanggan</h1>
        <p class="text-secondary">Kelola database pelanggan, limit kredit, dan informasi kontak</p>
    </div>
    <div>
        <button class="btn btn-primary btn-lg" onclick="openAddModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Tambah Pelanggan Baru
        </button>
    </div>
</div>

<!-- Search Card -->
<div class="card mb-4" style="padding: 16px;">
    <form method="GET" action="index.php" style="display: flex; gap: 12px;">
        <input type="hidden" name="page" value="pelanggan">
        <input type="text" name="search" class="form-control" placeholder="Cari nama pelanggan, nomor HP, atau alamat..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-secondary">Cari</button>
        <?php if (!empty($search)): ?>
            <a href="index.php?page=pelanggan" class="btn btn-light">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Table Card -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daftar Pelanggan Terdaftar</h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nama Pelanggan</th>
                    <th>No. HP</th>
                    <th>Alamat</th>
                    <th>Limit Kredit</th>
                    <th>Sisa Piutang Berjalan</th>
                    <th>Total Transaksi</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($customers_result && $customers_result->num_rows > 0): ?>
                    <?php $no = 1; while ($c = $customers_result->fetch_assoc()): ?>
                        <?php 
                            $piutang = (double)$c['total_piutang'];
                            $limit = (double)$c['limit_kredit'];
                            $is_over = ($limit > 0 && $piutang > $limit);
                        ?>
                        <tr>
                            <td data-label="No"><?= $no++ ?></td>
                            <td data-label="Nama Pelanggan"><strong><?= htmlspecialchars($c['nama']) ?></strong></td>
                            <td data-label="No. HP"><?= htmlspecialchars($c['no_hp'] ?: '-') ?></td>
                            <td data-label="Alamat"><?= htmlspecialchars($c['alamat'] ?: '-') ?></td>
                            <td data-label="Limit Kredit">Rp <?= number_format($limit, 0, ',', '.') ?></td>
                            <td data-label="Sisa Piutang" class="<?= $piutang > 0 ? 'text-danger font-bold' : 'text-muted' ?>">
                                Rp <?= number_format($piutang, 0, ',', '.') ?>
                                <?php if ($is_over): ?>
                                    <span class="badge badge-danger" style="margin-left: 6px;">Over Limit</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Total Transaksi"><?= $c['total_transaksi'] ?> Transaksi</td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button class="btn btn-sm btn-info" onclick="viewCustomerSales(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nama'])) ?>')">
                                    Riwayat Jual
                                </button>
                                <button class="btn btn-sm btn-light" onclick='openEditModal(<?= json_encode($c) ?>)'>
                                    Edit
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nama'])) ?>')">
                                    Hapus
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            Belum ada data pelanggan yang terdaftar.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Form Add/Edit Customer -->
<div id="modalCustomer" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalCustomerTitle">Tambah Pelanggan Baru</h3>
            <button class="modal-close" onclick="closeCustomerModal()">&times;</button>
        </div>
        <form method="POST" action="index.php?page=pelanggan">
            <input type="hidden" name="action" id="cust_action" value="add_customer">
            <input type="hidden" name="id" id="cust_id" value="">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">Nama Pelanggan <span class="text-danger">*</span></label>
                    <input type="text" name="nama" id="cust_nama" class="form-control" required placeholder="Masukkan nama lengkap">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Nomor Handphone / WA</label>
                    <input type="text" name="no_hp" id="cust_no_hp" class="form-control" placeholder="Contoh: 081234567890">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Alamat Lengkap</label>
                    <textarea name="alamat" id="cust_alamat" class="form-control" rows="2" placeholder="Masukkan alamat tempat tinggal/toko"></textarea>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Limit Kredit / Max Piutang (Rp)</label>
                    <input type="number" name="limit_kredit" id="cust_limit_kredit" class="form-control" min="0" placeholder="0 jika tidak ada batas limit">
                    <small class="text-muted">Batas maksimal hutang piutang yang diperbolehkan untuk pelanggan ini.</small>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeCustomerModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="cust_submit_btn">Simpan Pelanggan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Customer Sales History -->
<div id="modalCustHistory" class="modal">
    <div class="modal-content" style="max-width: 750px;">
        <div class="modal-header">
            <h3 class="modal-title" id="custHistoryTitle">Riwayat Transaksi Pelanggan</h3>
            <button class="modal-close" onclick="closeCustHistoryModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Transaksi - Barang</th>
                            <th>Tanggal</th>
                            <th>Harga Jual</th>
                            <th>Terbayar</th>
                            <th>Sisa Piutang</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="custHistoryBody">
                        <tr><td colspan="6" class="text-center">Memuat...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeCustHistoryModal()">Tutup</button>
        </div>
    </div>
</div>

<form id="formDeleteCust" method="POST" action="index.php?page=pelanggan" style="display: none;">
    <input type="hidden" name="action" value="delete_customer">
    <input type="hidden" name="id" id="delete_cust_id">
</form>

<script>
function openAddModal() {
    document.getElementById('modalCustomerTitle').innerText = 'Tambah Pelanggan Baru';
    document.getElementById('cust_action').value = 'add_customer';
    document.getElementById('cust_id').value = '';
    document.getElementById('cust_nama').value = '';
    document.getElementById('cust_no_hp').value = '';
    document.getElementById('cust_alamat').value = '';
    document.getElementById('cust_limit_kredit').value = '0';
    document.getElementById('cust_submit_btn').innerText = 'Simpan Pelanggan';
    const modal = document.getElementById('modalCustomer');
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
}

function openEditModal(cust) {
    document.getElementById('modalCustomerTitle').innerText = 'Edit Data Pelanggan';
    document.getElementById('cust_action').value = 'edit_customer';
    document.getElementById('cust_id').value = cust.id;
    document.getElementById('cust_nama').value = cust.nama;
    document.getElementById('cust_no_hp').value = cust.no_hp || '';
    document.getElementById('cust_alamat').value = cust.alamat || '';
    document.getElementById('cust_limit_kredit').value = cust.limit_kredit || 0;
    document.getElementById('cust_submit_btn').innerText = 'Perbarui Pelanggan';
    const modal = document.getElementById('modalCustomer');
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
}

function closeCustomerModal() {
    const modal = document.getElementById('modalCustomer');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function confirmDelete(id, nama) {
    if (confirm('Apakah Anda yakin ingin menghapus pelanggan "' + nama + '"?')) {
        document.getElementById('delete_cust_id').value = id;
        document.getElementById('formDeleteCust').submit();
    }
}

function viewCustomerSales(cust_id, cust_nama) {
    document.getElementById('custHistoryTitle').innerText = 'Riwayat Transaksi - ' + cust_nama;
    const body = document.getElementById('custHistoryBody');
    body.innerHTML = '<tr><td colspan="6" class="text-center py-3">Memuat riwayat transaksi...</td></tr>';
    const modal = document.getElementById('modalCustHistory');
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }

    fetch('index.php?ajax_customer_sales=1&customer_id=' + cust_id)
        .then(res => res.json())
        .then(data => {
            if (data.length === 0) {
                body.innerHTML = '<tr><td colspan="6" class="text-center py-3 text-muted">Belum ada riwayat transaksi penjualan.</td></tr>';
                return;
            }
            let html = '';
            data.forEach(row => {
                const is_lunas = (row.sisa_piutang <= 0);
                const badge = is_lunas ? '<span class="badge badge-success">Tunai / Lunas</span>' : '<span class="badge badge-warning">Cicilan</span>';
                const terbayar = row.harga_jual - row.sisa_piutang;
                html += `
                    <tr>
                        <td><strong>${row.no_penjualan}</strong> - ${row.nama_barang}</td>
                        <td>${row.tanggal}</td>
                        <td>Rp ${Number(row.harga_jual).toLocaleString('id-ID')}</td>
                        <td class="text-success">Rp ${Number(terbayar).toLocaleString('id-ID')}</td>
                        <td class="${row.sisa_piutang > 0 ? 'text-danger font-bold' : 'text-muted'}">Rp ${Number(row.sisa_piutang).toLocaleString('id-ID')}</td>
                        <td>${badge}</td>
                    </tr>
                `;
            });
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">Gagal memuat data transaksi.</td></tr>';
        });
}

function closeCustHistoryModal() {
    const modal = document.getElementById('modalCustHistory');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}
</script>

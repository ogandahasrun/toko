<?php
if (!defined('host')) { exit; }

$alert = '';
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

// Handle CRUD operations for Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

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
        $stmt = $koneksi->prepare("DELETE FROM pelanggan WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Pelanggan berhasil dihapus!', 'success'));</script>";
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menghapus pelanggan!', 'danger'));</script>";
        }
        $stmt->close();
    }

    // Handle Payment Installments (Cicilan)
    if ($action === 'pay_installment') {
        $no_penjualan = isset($_POST['no_penjualan']) ? trim($_POST['no_penjualan']) : '';
        $jumlah_bayar = (double)$_POST['jumlah_bayar'];
        $tanggal = $_POST['tanggal'];
        $keterangan = trim($_POST['keterangan']);

        if (!empty($no_penjualan) && $jumlah_bayar > 0 && !empty($tanggal)) {
            $koneksi->begin_transaction();
            try {
                // Fetch current outstanding
                $esc_pen = $koneksi->real_escape_string($no_penjualan);
                $resP = $koneksi->query("SELECT sisa_piutang, total_jual, no_penjualan FROM penjualan WHERE no_penjualan = '$esc_pen'");
                if (!$resP || $resP->num_rows === 0) {
                    throw new Exception("Faktur penjualan tidak ditemukan!");
                }
                $pData = $resP->fetch_assoc();
                $sisa = (double)$pData['sisa_piutang'];
                $no_trx = $pData['no_penjualan'];

                if ($jumlah_bayar > $sisa) {
                    throw new Exception("Jumlah pembayaran (Rp " . number_format($jumlah_bayar, 0, ',', '.') . ") melebihi sisa piutang (Rp " . number_format($sisa, 0, ',', '.') . ")!");
                }

                // Save installment row using no_penjualan
                $stmtInst = $koneksi->prepare("INSERT INTO pembayaran_kredit (no_penjualan, tanggal, jumlah_bayar, keterangan) VALUES (?, ?, ?, ?)");
                $stmtInst->bind_param("ssds", $no_penjualan, $tanggal, $jumlah_bayar, $keterangan);
                $stmtInst->execute();
                $stmtInst->close();

                // Calculate new outstanding balance
                $newSisa = $sisa - $jumlah_bayar;
                $statusKredit = ($newSisa <= 0) ? 'lunas' : 'belum_lunas';

                // Update invoice credit status
                $stmtUp = $koneksi->prepare("UPDATE penjualan SET sisa_piutang = ?, status_kredit = ? WHERE no_penjualan = ?");
                $stmtUp->bind_param("dss", $newSisa, $statusKredit, $no_penjualan);
                $stmtUp->execute();
                $stmtUp->close();

                $koneksi->commit();
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Cicilan berhasil dibayarkan!', 'success'));</script>";
            } catch (Exception $e) {
                $koneksi->rollback();
                $err_msg = addslashes($e->getMessage());
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
            }
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Mohon isi semua data cicilan dengan benar!', 'warning'));</script>";
        }
    }
}
?>

<?= $alert ?>

<?php if ($customer_id > 0): ?>
    <!-- Customer Credit Profile -->
    <?php
    $resCust = $koneksi->query("SELECT * FROM pelanggan WHERE id = $customer_id");
    if ($resCust && $resCust->num_rows > 0):
        $c = $resCust->fetch_assoc();
        
        // Sum current outstanding credit
        $resSum = $koneksi->query("SELECT SUM(sisa_piutang) as total FROM penjualan WHERE pelanggan_id = $customer_id AND status_kredit = 'belum_lunas'");
        $total_piutang = 0;
        if ($resSum) {
            $rowSum = $resSum->fetch_assoc();
            $total_piutang = $rowSum['total'] ? (double)$rowSum['total'] : 0;
        }
    ?>
        <div class="page-header">
            <div>
                <h1 class="page-title">Profil Piutang: <?= htmlspecialchars($c['nama']) ?></h1>
                <p class="text-secondary">Limit Kredit: Rp <?= number_format($c['limit_kredit'], 0, ',', '.') ?> | Total Berjalan: <strong style="color:#ef4444;">Rp <?= number_format($total_piutang, 0, ',', '.') ?></strong></p>
            </div>
            <div>
                <a href="index.php?page=pelanggan" class="btn btn-secondary">Kembali</a>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title">Riwayat Faktur Penjualan Kredit</h3>
            </div>

            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>No Penjualan</th>
                            <th>Tanggal Jual</th>
                            <th>Jatuh Tempo</th>
                            <th>Tempo</th>
                            <th>Nilai Transaksi</th>
                            <th>Sisa Piutang</th>
                            <th>Status</th>
                            <th style="width: 180px; text-align: center;">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $resFak = $koneksi->query("
                            SELECT * FROM penjualan 
                            WHERE pelanggan_id = $customer_id AND tipe_pembayaran = 'kredit' 
                            ORDER BY created_at DESC
                        ");
                        if ($resFak && $resFak->num_rows > 0):
                            while ($f = $resFak->fetch_assoc()):
                        ?>
                            <tr>
                                <td data-label="No Penjualan"><strong><?= htmlspecialchars($f['no_penjualan']) ?></strong></td>
                                <td data-label="Tanggal Jual"><?= date('d/m/Y', strtotime($f['tanggal'])) ?></td>
                                <td data-label="Jatuh Tempo"><?= date('d/m/Y', strtotime($f['jatuh_tempo'])) ?></td>
                                <td data-label="Tempo" class="text-capitalize"><?= htmlspecialchars($f['tempo_tipe']) ?></td>
                                <td data-label="Nilai Transaksi">Rp <?= number_format($f['total_jual'], 0, ',', '.') ?></td>
                                <td data-label="Sisa Piutang" style="color: #ef4444; font-weight: 700;">Rp <?= number_format($f['sisa_piutang'], 0, ',', '.') ?></td>
                                <td data-label="Status">
                                    <?php if ($f['status_kredit'] === 'lunas'): ?>
                                        <span class="badge badge-success">Lunas</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Belum Lunas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div style="display: flex; gap: 4px; justify-content: center;">
                                        <?php if ($f['status_kredit'] !== 'lunas'): ?>
                                            <button class="btn btn-primary btn-sm" onclick='openInstallmentModal(<?= json_encode($f) ?>)'>
                                                Bayar
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-secondary btn-sm" onclick="openHistoryModal('<?= $f['no_penjualan'] ?>')">
                                            Riwayat
                                        </button>
                                        <button class="btn btn-info btn-sm" onclick="openItemsModal('<?= $f['no_penjualan'] ?>')" style="background-color: #0ea5e9; border-color: #0ea5e9; color: #fff;">
                                            Barang
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php 
                            endwhile;
                        else:
                        ?>
                            <tr>
                                <td colspan="8" class="text-center text-secondary py-4">Belum ada transaksi kredit untuk pelanggan ini.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- List of Customers -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Pelanggan & Penjualan Kredit</h1>
            <p class="text-secondary">Kelola daftar pelanggan, batas limit kredit, dan cicilan piutang berjalan</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="openAddModal()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Registrasi Pelanggan
            </button>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">Daftar Pelanggan</h3>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Nama Pelanggan</th>
                        <th>Alamat</th>
                        <th>No HP</th>
                        <th>Limit Kredit</th>
                        <th>Total Piutang Berjalan</th>
                        <th>Kondisi Limit</th>
                        <th style="width: 200px; text-align: center;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $q = "
                        SELECT c.*, 
                               COALESCE((SELECT SUM(sisa_piutang) FROM penjualan WHERE pelanggan_id = c.id AND status_kredit = 'belum_lunas'), 0) as total_piutang
                        FROM pelanggan c 
                        ORDER BY c.nama ASC
                    ";
                    $res = $koneksi->query($q);
                    if ($res && $res->num_rows > 0):
                        while ($r = $res->fetch_assoc()):
                            $percent = $r['limit_kredit'] > 0 ? ($r['total_piutang'] / $r['limit_kredit']) * 100 : 0;
                            if ($percent >= 90) {
                                $limit_status = '<span class="badge badge-danger">Kritis ('.round($percent).'%)</span>';
                            } elseif ($percent >= 50) {
                                $limit_status = '<span class="badge badge-warning">Sedang ('.round($percent).'%)</span>';
                            } else {
                                $limit_status = '<span class="badge badge-success">Aman ('.round($percent).'%)</span>';
                            }
                    ?>
                        <tr>
                            <td data-label="Nama Pelanggan"><strong><?= htmlspecialchars($r['nama']) ?></strong></td>
                            <td data-label="Alamat"><?= htmlspecialchars($r['alamat'] ? $r['alamat'] : '-') ?></td>
                            <td data-label="No HP"><?= htmlspecialchars($r['no_hp'] ? $r['no_hp'] : '-') ?></td>
                            <td data-label="Limit Kredit">Rp <?= number_format($r['limit_kredit'], 0, ',', '.') ?></td>
                            <td data-label="Total Piutang Berjalan" style="font-weight: 700; color: <?= $r['total_piutang'] > 0 ? '#ef4444' : 'var(--text-primary)' ?>;">
                                Rp <?= number_format($r['total_piutang'], 0, ',', '.') ?>
                            </td>
                            <td data-label="Kondisi Limit"><?= $limit_status ?></td>
                            <td class="text-center">
                                <div style="display: flex; gap: 4px; justify-content: center; flex-wrap: wrap;">
                                    <a href="index.php?page=pelanggan&customer_id=<?= $r['id'] ?>" class="btn btn-primary btn-sm">
                                        Piutang
                                    </a>
                                    <button class="btn btn-warning btn-sm" onclick='openEditModal(<?= json_encode($r) ?>)'>
                                        Edit
                                    </button>
                                    <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pelanggan ini? Seluruh riwayat penjualan kredit akan dilepas dari profil pelanggan.');">
                                        <input type="hidden" name="action" value="delete_customer">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
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
                            <td colspan="7" class="text-center text-secondary py-4">Belum ada pelanggan terdaftar.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Modal Tambah/Edit Customer -->
<div id="modalCustomer" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Registrasi Pelanggan Baru</h3>
            <button class="btn-close" onclick="closeModal('modalCustomer')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" id="formAction" value="add_customer">
            <input type="hidden" name="id" id="customerId" value="">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Lengkap Pelanggan <span style="color:red;">*</span></label>
                    <input type="text" name="nama" id="inpNama" class="form-control" placeholder="Contoh: Budi Santoso" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Alamat Lengkap</label>
                    <textarea name="alamat" id="inpAlamat" class="form-control" rows="2" placeholder="Contoh: Jl. Diponegoro No 10"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Nomor Handphone / Kontak CP</label>
                    <input type="text" name="no_hp" id="inpHp" class="form-control" placeholder="Contoh: 0812XXXXXXXX">
                </div>
                <div class="form-group">
                    <label class="form-label">Batas Limit Kredit (Rp) <span style="color:red;">*</span></label>
                    <input type="number" name="limit_kredit" id="inpLimit" class="form-control" placeholder="Contoh: 5000000" inputmode="numeric" value="0" required>
                    <span style="font-size:12px; color:var(--text-secondary);">Membatasi total piutang yang belum terbayar pelanggan ini</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalCustomer')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Bayar Cicilan (Installment) -->
<div id="modalInstallment" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Bayar Cicilan Kredit</h3>
            <button class="btn-close" onclick="closeModal('modalInstallment')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="pay_installment">
            <input type="hidden" name="no_penjualan" id="instNoPenjualan" value="">
            
            <div class="modal-body">
                <div style="margin-bottom:16px; padding:12px; background:#f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    No. Nota: <strong id="lblInstNo"></strong><br>
                    Sisa Piutang: <strong id="lblInstSisa" style="color:#ef4444;"></strong>
                </div>

                <div class="form-group">
                    <label class="form-label">Tanggal Pembayaran <span style="color:red;">*</span></label>
                    <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Jumlah Pembayaran / Cicilan (Rp) <span style="color:red;">*</span></label>
                    <input type="number" name="jumlah_bayar" id="inpJumlahBayar" class="form-control" placeholder="0" inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Keterangan / Catatan</label>
                    <input type="text" name="keterangan" class="form-control" placeholder="Contoh: Pembayaran cicilan ke-2">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalInstallment')">Batal</button>
                <button type="submit" class="btn btn-primary">Posting Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Riwayat Cicilan (History) -->
<div id="modalHistory" class="modal-overlay">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Riwayat Pembayaran Cicilan</h3>
            <button class="btn-close" onclick="closeModal('modalHistory')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body" style="padding:0;">
            <div id="historyTableContainer" class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Urutan</th>
                            <th>Tanggal Bayar</th>
                            <th>Nilai Pembayaran</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <tr>
                            <td colspan="4" class="text-center py-4">Memuat data...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalHistory')">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Detail Barang yang Dibeli (Credit Items) -->
<div id="modalItems" class="modal-overlay">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3>Rincian Barang Belanja - Nota <span id="lblItemsNo"></span></h3>
            <button class="btn-close" onclick="closeModal('modalItems')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body" style="padding:0;">
            <div id="itemsTableContainer" class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Nama Barang</th>
                            <th>Harga Satuan</th>
                            <th>Jumlah</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <tr>
                            <td colspan="5" class="text-center py-4">Memuat data...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('modalItems')">Tutup</button>
        </div>
    </div>
</div>

<!-- Riwayat pembayaran dimuat secara dinamis via AJAX dari index.php -->

<script>
function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Registrasi Pelanggan Baru';
    document.getElementById('formAction').value = 'add_customer';
    document.getElementById('customerId').value = '';
    document.getElementById('inpNama').value = '';
    document.getElementById('inpAlamat').value = '';
    document.getElementById('inpHp').value = '';
    document.getElementById('inpLimit').value = '0';
    openModal('modalCustomer');
}

function openEditModal(data) {
    document.getElementById('modalTitle').innerText = 'Edit Data Pelanggan';
    document.getElementById('formAction').value = 'edit_customer';
    document.getElementById('customerId').value = data.id;
    document.getElementById('inpNama').value = data.nama;
    document.getElementById('inpAlamat').value = data.alamat;
    document.getElementById('inpHp').value = data.no_hp;
    document.getElementById('inpLimit').value = data.limit_kredit;
    openModal('modalCustomer');
}

function openInstallmentModal(data) {
    document.getElementById('instNoPenjualan').value = data.no_penjualan;
    document.getElementById('lblInstNo').innerText = data.no_penjualan;
    
    const sisa = parseFloat(data.sisa_piutang);
    document.getElementById('lblInstSisa').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(sisa);
    document.getElementById('inpJumlahBayar').value = sisa;
    document.getElementById('inpJumlahBayar').max = sisa;
    openModal('modalInstallment');
}

async function openHistoryModal(noPenjualan) {
    openModal('modalHistory');
    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-secondary">Sedang memuat data...</td></tr>';

    try {
        const res = await fetch(`index.php?page=pelanggan&ajax_payment_history=1&no_penjualan=${encodeURIComponent(noPenjualan)}`);
        const data = await res.json();
        
        tbody.innerHTML = '';
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-secondary">Belum ada riwayat pembayaran cicilan untuk nota ini.</td></tr>';
            return;
        }

        data.forEach((item, index) => {
            const tr = document.createElement('tr');
            const amt = parseFloat(item.jumlah_bayar);
            const formatAmt = new Intl.NumberFormat('id-ID').format(amt);
            
            // Convert MySQL date Y-m-d to d/m/Y
            const parts = item.tanggal.split('-');
            const dateStr = parts.length === 3 ? `${parts[2]}/${parts[1]}/${parts[0]}` : item.tanggal;

            tr.innerHTML = `
                <td data-label="Urutan"><strong>Cicilan ke-${index + 1}</strong></td>
                <td data-label="Tanggal Bayar">${dateStr}</td>
                <td data-label="Nilai Pembayaran" style="color:#10b981; font-weight:700;">Rp ${formatAmt}</td>
                <td data-label="Keterangan">${item.keterangan ? item.keterangan : '-'}</td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-danger">Gagal memuat data riwayat cicilan!</td></tr>';
    }
}

async function openItemsModal(noPenjualan) {
    openModal('modalItems');
    document.getElementById('lblItemsNo').innerText = noPenjualan;
    const tbody = document.getElementById('itemsTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-secondary">Sedang memuat data...</td></tr>';

    try {
        const res = await fetch(`index.php?page=pelanggan&ajax_sale_items=1&no_penjualan=${encodeURIComponent(noPenjualan)}`);
        const data = await res.json();
        
        tbody.innerHTML = '';
        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-secondary">Tidak ada data barang ditemukan untuk nota ini.</td></tr>';
            return;
        }

        data.forEach(item => {
            const tr = document.createElement('tr');
            const prc = parseFloat(item.harga_jual);
            const sub = parseFloat(item.subtotal);
            const formatPrc = new Intl.NumberFormat('id-ID').format(prc);
            const formatSub = new Intl.NumberFormat('id-ID').format(sub);

            tr.innerHTML = `
                <td data-label="Kode Barang"><strong>${item.kode_barang}</strong></td>
                <td data-label="Nama Barang">${item.nama_barang}</td>
                <td data-label="Harga Satuan">Rp ${formatPrc}</td>
                <td data-label="Jumlah">${item.jumlah} ${item.satuan}</td>
                <td data-label="Subtotal" style="font-weight:700; color:var(--primary-color);">Rp ${formatSub}</td>
            `;
            tbody.appendChild(tr);
        });
    } catch (e) {
        console.error(e);
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-danger">Gagal memuat data rincian barang!</td></tr>';
    }
}
</script>

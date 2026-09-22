<?php
if (!defined('host')) { exit; }

$alert = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$pelanggan_filter = isset($_GET['pelanggan_id']) ? (int)$_GET['pelanggan_id'] : 0;
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : 'belum_lunas';

// Handle POST action (Bayar Cicilan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_installment') {
    $no_penjualan = isset($_POST['no_penjualan']) ? trim($_POST['no_penjualan']) : '';
    $jumlah_bayar = (double)$_POST['jumlah_bayar'];
    $tanggal = $_POST['tanggal'];
    $keterangan = trim($_POST['keterangan']);

    if (!empty($no_penjualan) && $jumlah_bayar > 0 && !empty($tanggal)) {
        $koneksi->begin_transaction();
        try {
            $esc_pen = $koneksi->real_escape_string($no_penjualan);
            $resP = $koneksi->query("SELECT sisa_piutang, total_jual, status_kredit, no_penjualan, nama_barang FROM penjualan WHERE no_penjualan = '$esc_pen'");
            if (!$resP || $resP->num_rows === 0) {
                throw new Exception("Transaksi penjualan tidak ditemukan!");
            }
            $pData = $resP->fetch_assoc();
            $sisa = (double)$pData['sisa_piutang'];
            $nama_brg = $pData['nama_barang'];

            if ($sisa <= 0) {
                throw new Exception("Transaksi ini sudah lunas!");
            }

            if ($jumlah_bayar > $sisa) {
                throw new Exception("Jumlah pembayaran (Rp " . number_format($jumlah_bayar, 0, ',', '.') . ") melebihi sisa piutang (Rp " . number_format($sisa, 0, ',', '.') . ")!");
            }

            // Insert installment record
            $stmtInst = $koneksi->prepare("INSERT INTO pembayaran_kredit (no_penjualan, tanggal, jumlah_bayar, keterangan) VALUES (?, ?, ?, ?)");
            $stmtInst->bind_param("ssds", $no_penjualan, $tanggal, $jumlah_bayar, $keterangan);
            $stmtInst->execute();
            $stmtInst->close();

            // Calculate new remaining balance
            $newSisa = max(0, $sisa - $jumlah_bayar);
            $newStatus = ($newSisa <= 0) ? 'lunas' : 'belum_lunas';

            // Update sale record
            $stmtUpd = $koneksi->prepare("UPDATE penjualan SET sisa_piutang = ?, status_kredit = ? WHERE no_penjualan = ?");
            $stmtUpd->bind_param("dss", $newSisa, $newStatus, $no_penjualan);
            $stmtUpd->execute();
            $stmtUpd->close();

            $koneksi->commit();
            $msg = ($newStatus === 'lunas') ? "Pembayaran cicilan berhasil! Transaksi $no_penjualan ($nama_brg) sekarang TELAH LUNAS." : "Pembayaran cicilan berhasil disimpan! Sisa piutang: Rp " . number_format($newSisa, 0, ',', '.');
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$msg', 'success'));</script>";
        } catch (Exception $e) {
            $koneksi->rollback();
            $err = addslashes($e->getMessage());
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err', 'danger'));</script>";
        }
    } else {
        $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Mohon lengkapi semua data pembayaran!', 'warning'));</script>";
    }
}

// Statistics
$total_piutang_berjalan = 0;
$resPiutang = $koneksi->query("SELECT SUM(sisa_piutang) as total FROM penjualan WHERE status_kredit = 'belum_lunas'");
if ($resPiutang) {
    $r = $resPiutang->fetch_assoc();
    $total_piutang_berjalan = $r['total'] ? (double)$r['total'] : 0;
}

$count_kredit_aktif = 0;
$resCount = $koneksi->query("SELECT COUNT(*) as total FROM penjualan WHERE status_kredit = 'belum_lunas'");
if ($resCount) {
    $count_kredit_aktif = (int)$resCount->fetch_assoc()['total'];
}

$cicilan_bulan_ini = 0;
$resBulan = $koneksi->query("SELECT SUM(jumlah_bayar) as total FROM pembayaran_kredit WHERE MONTH(tanggal) = MONTH(CURRENT_DATE()) AND YEAR(tanggal) = YEAR(CURRENT_DATE())");
if ($resBulan) {
    $r = $resBulan->fetch_assoc();
    $cicilan_bulan_ini = $r['total'] ? (double)$r['total'] : 0;
}

// Fetch list of active customers for filter dropdown
$customers = [];
$resCust = $koneksi->query("SELECT id, nama FROM pelanggan ORDER BY nama ASC");
if ($resCust) {
    while ($c = $resCust->fetch_assoc()) {
        $customers[$c['id']] = $c['nama'];
    }
}

// Fetch sales records with credit filter
$where_clauses = ["p.tipe_pembayaran = 'kredit'"];
if ($status_filter === 'belum_lunas') {
    $where_clauses[] = "p.status_kredit = 'belum_lunas'";
} elseif ($status_filter === 'lunas') {
    $where_clauses[] = "p.status_kredit = 'lunas'";
}

if (!empty($search)) {
    $esc = $koneksi->real_escape_string($search);
    $where_clauses[] = "(p.no_penjualan LIKE '%$esc%' OR p.nama_barang LIKE '%$esc%' OR pel.nama LIKE '%$esc%')";
}

if ($pelanggan_filter > 0) {
    $where_clauses[] = "p.pelanggan_id = $pelanggan_filter";
}

$where_sql = implode(' AND ', $where_clauses);
$query_sales = "
    SELECT p.*, pel.nama as nama_pelanggan, pel.no_hp
    FROM penjualan p
    LEFT JOIN pelanggan pel ON p.pelanggan_id = pel.id
    WHERE $where_sql
    ORDER BY p.tanggal DESC, p.created_at DESC
";
$sales_list = $koneksi->query($query_sales);

// Fetch un-paid credit sales for installment modal
$resUnpaid = $koneksi->query("
    SELECT p.no_penjualan, p.nama_barang, p.sisa_piutang, pel.nama as nama_pelanggan 
    FROM penjualan p 
    LEFT JOIN pelanggan pel ON p.pelanggan_id = pel.id 
    WHERE p.status_kredit = 'belum_lunas' 
    ORDER BY p.no_penjualan DESC
");
$unpaid_sales = [];
if ($resUnpaid) {
    while ($u = $resUnpaid->fetch_assoc()) {
        $unpaid_sales[] = $u;
    }
}
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pembayaran Cicilan</h1>
        <p class="text-secondary">Kelola angsuran piutang dan riwayat pembayaran cicilan pelanggan</p>
    </div>
    <div>
        <button class="btn btn-primary btn-lg" onclick="openPayModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Bayar Cicilan Baru
        </button>
    </div>
</div>

<!-- Stat Cards -->
<div class="card-grid mb-4">
    <div class="stat-card">
        <div class="stat-info">
            <h3>Sisa Piutang Berjalan</h3>
            <p style="color: #ef4444;">Rp <?= number_format($total_piutang_berjalan, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon danger">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Transaksi Belum Lunas</h3>
            <p><?= number_format($count_kredit_aktif) ?> Transaksi</p>
        </div>
        <div class="stat-icon warning">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <h3>Cicilan Masuk (Bulan Ini)</h3>
            <p style="color: #10b981;">Rp <?= number_format($cicilan_bulan_ini, 0, ',', '.') ?></p>
        </div>
        <div class="stat-icon success">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-4" style="padding: 16px;">
    <form method="GET" action="index.php" class="search-filter-grid" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <input type="hidden" name="page" value="cicilan">
        
        <div style="flex: 1; min-width: 200px;">
            <input type="text" name="search" class="form-control" placeholder="Cari No. Transaksi / Barang / Pelanggan..." value="<?= htmlspecialchars($search) ?>">
        </div>

        <div style="width: 180px;">
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value="belum_lunas" <?= $status_filter === 'belum_lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                <option value="lunas" <?= $status_filter === 'lunas' ? 'selected' : '' ?>>Sudah Lunas</option>
                <option value="semua" <?= $status_filter === 'semua' ? 'selected' : '' ?>>Semua Status</option>
            </select>
        </div>

        <div style="width: 200px;">
            <select name="pelanggan_id" class="form-control" onchange="this.form.submit()">
                <option value="0">-- Semua Pelanggan --</option>
                <?php foreach ($customers as $cid => $cname): ?>
                    <option value="<?= $cid ?>" <?= $pelanggan_filter == $cid ? 'selected' : '' ?>><?= htmlspecialchars($cname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if (!empty($search) || $pelanggan_filter > 0 || $status_filter !== 'belum_lunas'): ?>
            <a href="index.php?page=cicilan" class="btn btn-light">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daftar Transaksi Cicilan & Piutang</h3>
    </div>
    <div class="table-responsive">
        <table class="table" style="vertical-align: middle;">
            <thead>
                <tr>
                    <th style="width: 150px;">No. Transaksi</th>
                    <th>Nama Barang</th>
                    <th>Tanggal</th>
                    <th>Pelanggan</th>
                    <th style="text-align: right;">Total Jual</th>
                    <th style="text-align: right;">Terbayar</th>
                    <th style="text-align: right;">Sisa Piutang</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($sales_list && $sales_list->num_rows > 0): ?>
                    <?php while ($s = $sales_list->fetch_assoc()): ?>
                        <?php 
                            $terbayar = $s['harga_jual'] - $s['sisa_piutang'];
                            $badge_class = ($s['status_kredit'] === 'lunas') ? 'badge-success' : 'badge-danger';
                            $status_label = ($s['status_kredit'] === 'lunas') ? 'Lunas' : 'Belum Lunas';
                        ?>
                        <tr>
                            <td data-label="No. Transaksi">
                                <span class="badge" style="background: #e0e7ff; color: #3730a3; font-family: monospace; font-size: 12px; padding: 6px 10px; border-radius: 6px;">
                                    <?= htmlspecialchars($s['no_penjualan']) ?>
                                </span>
                            </td>
                            <td data-label="Nama Barang">
                                <strong style="color: #0f172a; font-size: 14px;"><?= htmlspecialchars($s['nama_barang']) ?></strong>
                            </td>
                            <td data-label="Tanggal"><?= date('d/m/Y', strtotime($s['tanggal'])) ?></td>
                            <td data-label="Pelanggan">
                                <div><strong><?= htmlspecialchars($s['nama_pelanggan'] ?? 'Umum') ?></strong></div>
                                <?php if (!empty($s['no_hp'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars($s['no_hp']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Total Jual" style="text-align: right; font-weight: 700; color: #1e293b;">
                                Rp <?= number_format($s['harga_jual'], 0, ',', '.') ?>
                            </td>
                            <td data-label="Terbayar" style="text-align: right; color: #10b981; font-weight: 600;">
                                Rp <?= number_format($terbayar, 0, ',', '.') ?>
                            </td>
                            <td data-label="Sisa Piutang" style="text-align: right;" class="text-danger font-bold">
                                Rp <?= number_format($s['sisa_piutang'], 0, ',', '.') ?>
                            </td>
                            <td data-label="Status" style="text-align: center;">
                                <span class="badge <?= $badge_class ?>" style="padding: 6px 12px;"><?= $status_label ?></span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ($s['sisa_piutang'] > 0): ?>
                                    <button class="btn btn-sm btn-success" onclick="openPayModal('<?= htmlspecialchars($s['no_penjualan']) ?>', <?= $s['sisa_piutang'] ?>, '<?= htmlspecialchars(addslashes($s['nama_barang'])) ?>')">
                                        Bayar Cicilan
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-info" onclick="viewHistory('<?= htmlspecialchars($s['no_penjualan']) ?>')">
                                    Riwayat
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            Tidak ada data transaksi cicilan yang ditemukan.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Form Bayar Cicilan -->
<div id="modalPayInstallment" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Pembayaran Cicilan / Piutang</h3>
            <button class="modal-close" onclick="closePayModal()">&times;</button>
        </div>
        <form method="POST" action="index.php?page=cicilan">
            <input type="hidden" name="action" value="pay_installment">
            
            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">Pilih Transaksi Penjualan <span class="text-danger">*</span></label>
                    <select name="no_penjualan" id="pay_no_penjualan" class="form-control" required onchange="onSelectSaleChange()">
                        <option value="">-- Pilih Transaksi --</option>
                        <?php foreach ($unpaid_sales as $u): ?>
                            <option value="<?= htmlspecialchars($u['no_penjualan']) ?>" data-sisa="<?= $u['sisa_piutang'] ?>" data-barang="<?= htmlspecialchars($u['nama_barang']) ?>">
                                <?= htmlspecialchars($u['no_penjualan']) ?> - <?= htmlspecialchars($u['nama_barang']) ?> (Pelanggan: <?= htmlspecialchars($u['nama_pelanggan'] ?? 'Umum') ?> | Sisa: Rp <?= number_format($u['sisa_piutang'], 0, ',', '.') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Sisa Piutang Transaksi</label>
                    <input type="text" id="display_sisa_piutang" class="form-control" readonly style="background-color: #f1f5f9; font-weight: 700; color: #ef4444;" value="Rp 0">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Tanggal Pembayaran <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal" class="form-control" required value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Jumlah Pembayaran (Rp) <span class="text-danger">*</span></label>
                    <input type="number" name="jumlah_bayar" id="pay_jumlah_bayar" class="form-control" required min="1" placeholder="Contoh: 500000">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Keterangan / Catatan</label>
                    <input type="text" name="keterangan" class="form-control" placeholder="Contoh: Cicilan Ke-2 / Transfer Mandiri">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closePayModal()">Batal</button>
                <button type="submit" class="btn btn-success">Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Riwayat Cicilan -->
<div id="modalHistory" class="modal">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title" id="historyTitle">Riwayat Cicilan</h3>
            <button class="modal-close" onclick="closeHistoryModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>Jumlah Bayar</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <tr><td colspan="4" class="text-center">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeHistoryModal()">Tutup</button>
        </div>
    </div>
</div>

<script>
function openPayModal(noPenjualan = '', sisaPiutang = 0, namaBarang = '') {
    const modal = document.getElementById('modalPayInstallment');
    const select = document.getElementById('pay_no_penjualan');
    const sisaDisplay = document.getElementById('display_sisa_piutang');
    const inputJumlah = document.getElementById('pay_jumlah_bayar');

    if (noPenjualan) {
        select.value = noPenjualan;
        sisaDisplay.value = 'Rp ' + Number(sisaPiutang).toLocaleString('id-ID');
        inputJumlah.max = sisaPiutang;
        inputJumlah.value = '';
    } else {
        select.value = '';
        sisaDisplay.value = 'Rp 0';
        inputJumlah.value = '';
    }
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
}

function closePayModal() {
    const modal = document.getElementById('modalPayInstallment');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function onSelectSaleChange() {
    const select = document.getElementById('pay_no_penjualan');
    const sisaDisplay = document.getElementById('display_sisa_piutang');
    const inputJumlah = document.getElementById('pay_jumlah_bayar');

    const selectedOpt = select.options[select.selectedIndex];
    if (selectedOpt && selectedOpt.value) {
        const sisa = selectedOpt.getAttribute('data-sisa');
        sisaDisplay.value = 'Rp ' + Number(sisa).toLocaleString('id-ID');
        inputJumlah.max = sisa;
    } else {
        sisaDisplay.value = 'Rp 0';
        inputJumlah.max = '';
    }
}

function viewHistory(noPenjualan) {
    document.getElementById('historyTitle').innerText = 'Riwayat Cicilan - ' + noPenjualan;
    const body = document.getElementById('historyTableBody');
    body.innerHTML = '<tr><td colspan="4" class="text-center py-3">Memuat data riwayat...</td></tr>';
    const modal = document.getElementById('modalHistory');
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }

    fetch('index.php?ajax_payment_history=1&no_penjualan=' + encodeURIComponent(noPenjualan))
        .then(response => response.json())
        .then(data => {
            if (data.length === 0) {
                body.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-muted">Belum ada riwayat pembayaran cicilan.</td></tr>';
                return;
            }
            let html = '';
            data.forEach((row, index) => {
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${row.tanggal}</td>
                        <td class="text-success font-bold">Rp ${Number(row.jumlah_bayar).toLocaleString('id-ID')}</td>
                        <td>${row.keterangan || '-'}</td>
                    </tr>
                `;
            });
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-3">Gagal memuat data riwayat cicilan.</td></tr>';
        });
}

function closeHistoryModal() {
    const modal = document.getElementById('modalHistory');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}
</script>

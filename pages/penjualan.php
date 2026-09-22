<?php
if (!defined('host')) { exit; }

$alert = '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$pelanggan_filter = isset($_GET['pelanggan_id']) ? (int)$_GET['pelanggan_id'] : 0;
$tipe_filter = isset($_GET['tipe']) ? trim($_GET['tipe']) : '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Insert New Sale Transaction
    if ($action === 'add_penjualan') {
        $no_penjualan = trim($_POST['no_penjualan']);
        $tanggal = $_POST['tanggal'];
        $pelanggan_id = !empty($_POST['pelanggan_id']) ? (int)$_POST['pelanggan_id'] : null;
        $nama_barang = trim($_POST['nama_barang']);
        $harga_beli = (double)$_POST['harga_beli'];
        $harga_jual = (double)$_POST['harga_jual'];
        $jumlah_dibayar = (double)$_POST['jumlah_dibayar'];
        $tempo_tipe = isset($_POST['tempo_tipe']) ? $_POST['tempo_tipe'] : 'n/a';
        $jatuh_tempo = !empty($_POST['jatuh_tempo']) ? $_POST['jatuh_tempo'] : null;
        $catatan = trim($_POST['catatan']);

        // Determine payment type and credit status automatically based on paid amount
        $sisa_piutang = max(0, $harga_jual - $jumlah_dibayar);
        $tipe_pembayaran = ($sisa_piutang <= 0) ? 'tunai' : 'kredit';
        $status_kredit = ($sisa_piutang <= 0) ? 'lunas' : 'belum_lunas';

        if (!empty($no_penjualan) && !empty($tanggal) && !empty($nama_barang) && $harga_jual > 0) {
            $koneksi->begin_transaction();
            try {
                // Check for duplicate transaction number
                $chk = $koneksi->prepare("SELECT no_penjualan FROM penjualan WHERE no_penjualan = ?");
                $chk->bind_param("s", $no_penjualan);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    throw new Exception("Nomor transaksi '$no_penjualan' sudah digunakan! Silakan gunakan nomor lain.");
                }
                $chk->close();

                // Check credit limit if payment is credit
                if ($tipe_pembayaran === 'kredit' && $pelanggan_id > 0) {
                    $resCust = $koneksi->query("SELECT nama, limit_kredit FROM pelanggan WHERE id = $pelanggan_id");
                    if ($resCust && $resCust->num_rows > 0) {
                        $cust = $resCust->fetch_assoc();
                        $limit_kredit = (double)$cust['limit_kredit'];
                        $nama_pel = $cust['nama'];

                        $resOut = $koneksi->query("SELECT SUM(sisa_piutang) as total FROM penjualan WHERE pelanggan_id = $pelanggan_id AND status_kredit = 'belum_lunas'");
                        $curOut = 0;
                        if ($resOut) {
                            $r = $resOut->fetch_assoc();
                            $curOut = $r['total'] ? (double)$r['total'] : 0;
                        }

                        if ($limit_kredit > 0 && ($curOut + $sisa_piutang > $limit_kredit)) {
                            $fmtLimit = number_format($limit_kredit, 0, ',', '.');
                            $fmtOut = number_format($curOut, 0, ',', '.');
                            $fmtNew = number_format($sisa_piutang, 0, ',', '.');
                            throw new Exception("Peringatan Kredit! Pelanggan '$nama_pel' melebihi limit kredit. (Limit: Rp $fmtLimit, Piutang Berjalan: Rp $fmtOut, Sisa Kredit Transaksi Ini: Rp $fmtNew)");
                        }
                    }
                }

                // Insert into penjualan table
                $stmt = $koneksi->prepare("
                    INSERT INTO penjualan 
                    (no_penjualan, tanggal, pelanggan_id, nama_barang, harga_beli, harga_jual, jumlah_dibayar, sisa_piutang, tipe_pembayaran, status_kredit, tempo_tipe, jatuh_tempo, catatan) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("ssisddddsssss", $no_penjualan, $tanggal, $pelanggan_id, $nama_barang, $harga_beli, $harga_jual, $jumlah_dibayar, $sisa_piutang, $tipe_pembayaran, $status_kredit, $tempo_tipe, $jatuh_tempo, $catatan);
                $stmt->execute();
                $stmt->close();

                // If paid amount > 0 and transaction is credit, log initial payment / DP in pembayaran_kredit
                if ($tipe_pembayaran === 'kredit' && $jumlah_dibayar > 0) {
                    $ket = "Pembayaran Uang Muka / DP awal";
                    $stmtDP = $koneksi->prepare("INSERT INTO pembayaran_kredit (no_penjualan, tanggal, jumlah_bayar, keterangan) VALUES (?, ?, ?, ?)");
                    $stmtDP->bind_param("ssds", $no_penjualan, $tanggal, $jumlah_dibayar, $ket);
                    $stmtDP->execute();
                    $stmtDP->close();
                }

                $koneksi->commit();
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Transaksi penjualan berhasil disimpan!', 'success'));</script>";
            } catch (Exception $e) {
                $koneksi->rollback();
                $err = addslashes($e->getMessage());
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err', 'danger'));</script>";
            }
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Mohon lengkapi Nama Barang dan Harga Jual!', 'warning'));</script>";
        }
    }

    // Delete Sale Transaction
    if ($action === 'delete_penjualan') {
        $no_penjualan = trim($_POST['no_penjualan']);
        if (!empty($no_penjualan)) {
            $stmt = $koneksi->prepare("DELETE FROM penjualan WHERE no_penjualan = ?");
            $stmt->bind_param("s", $no_penjualan);
            if ($stmt->execute()) {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Transaksi penjualan berhasil dihapus!', 'success'));</script>";
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menghapus transaksi penjualan!', 'danger'));</script>";
            }
            $stmt->close();
        }
    }
}

// Fetch active customers for dropdown
$pelanggan_list = [];
$resPel = $koneksi->query("SELECT id, nama, limit_kredit FROM pelanggan ORDER BY nama ASC");
if ($resPel) {
    while ($p = $resPel->fetch_assoc()) {
        $pelanggan_list[$p['id']] = $p;
    }
}

// Generate auto transaction number TRX-YYYYMMDD-XXX
$today_str = date('Ymd');
$prefix = "TRX-" . $today_str . "-";
$resSeq = $koneksi->query("SELECT no_penjualan FROM penjualan WHERE no_penjualan LIKE '$prefix%' ORDER BY no_penjualan DESC LIMIT 1");
$seq = 1;
if ($resSeq && $resSeq->num_rows > 0) {
    $last_no = $resSeq->fetch_assoc()['no_penjualan'];
    $last_num = (int)substr($last_no, -3);
    $seq = $last_num + 1;
}
$auto_no_penjualan = $prefix . sprintf("%03d", $seq);

// Search & filter query
$where_clauses = ["1=1"];
if (!empty($search)) {
    $esc = $koneksi->real_escape_string($search);
    $where_clauses[] = "(p.no_penjualan LIKE '%$esc%' OR p.nama_barang LIKE '%$esc%' OR pel.nama LIKE '%$esc%')";
}

if ($pelanggan_filter > 0) {
    $where_clauses[] = "p.pelanggan_id = $pelanggan_filter";
}

if (!empty($tipe_filter)) {
    $where_clauses[] = "p.tipe_pembayaran = '" . $koneksi->real_escape_string($tipe_filter) . "'";
}

$where_sql = implode(' AND ', $where_clauses);
$sales_query = "
    SELECT p.*, pel.nama as nama_pelanggan, pel.no_hp
    FROM penjualan p
    LEFT JOIN pelanggan pel ON p.pelanggan_id = pel.id
    WHERE $where_sql
    ORDER BY p.tanggal DESC, p.created_at DESC
";
$sales_list = $koneksi->query($sales_query);
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Transaksi Penjualan</h1>
        <p class="text-secondary">Input transaksi jual tunai/cicilan langsung dengan modal dan keuntungan</p>
    </div>
    <div>
        <button class="btn btn-primary btn-lg" onclick="openSaleModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Tambah Transaksi Baru
        </button>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="card mb-4" style="padding: 16px;">
    <form method="GET" action="index.php" class="search-filter-grid" style="display: flex; gap: 12px; flex-wrap: wrap;">
        <input type="hidden" name="page" value="penjualan">
        
        <div style="flex: 1; min-width: 220px;">
            <input type="text" name="search" class="form-control" placeholder="Cari No. Transaksi / Nama Barang / Pelanggan..." value="<?= htmlspecialchars($search) ?>">
        </div>

        <div style="width: 180px;">
            <select name="tipe" class="form-control" onchange="this.form.submit()">
                <option value="">-- Semua Metode --</option>
                <option value="tunai" <?= $tipe_filter === 'tunai' ? 'selected' : '' ?>>Jual Tunai</option>
                <option value="kredit" <?= $tipe_filter === 'kredit' ? 'selected' : '' ?>>Cicilan / Kredit</option>
            </select>
        </div>

        <div style="width: 200px;">
            <select name="pelanggan_id" class="form-control" onchange="this.form.submit()">
                <option value="0">-- Semua Pelanggan --</option>
                <?php foreach ($pelanggan_list as $pid => $pinfo): ?>
                    <option value="<?= $pid ?>" <?= $pelanggan_filter == $pid ? 'selected' : '' ?>><?= htmlspecialchars($pinfo['nama']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if (!empty($search) || $pelanggan_filter > 0 || !empty($tipe_filter)): ?>
            <a href="index.php?page=penjualan" class="btn btn-light">Reset</a>
        <?php endif; ?>
    </form>
</div>

<!-- Sales Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daftar Penjualan</h3>
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
                            $laba = $s['harga_jual'] - $s['harga_beli'];
                            $terbayar = $s['harga_jual'] - $s['sisa_piutang'];
                            $is_tunai = ($s['tipe_pembayaran'] === 'tunai' || $s['sisa_piutang'] <= 0);
                            $badge_class = $is_tunai ? 'badge-success' : 'badge-warning';
                            $status_label = $is_tunai ? 'Tunai (Lunas)' : 'Cicilan (Kredit)';
                        ?>
                        <tr>
                            <td>
                                <span class="badge" style="background: #e0e7ff; color: #3730a3; font-family: monospace; font-size: 12px; padding: 6px 10px; border-radius: 6px;">
                                    <?= htmlspecialchars($s['no_penjualan']) ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color: #0f172a; font-size: 14px;"><?= htmlspecialchars($s['nama_barang']) ?></strong>
                            </td>
                            <td><?= date('d/m/Y', strtotime($s['tanggal'])) ?></td>
                            <td>
                                <div><strong><?= htmlspecialchars($s['nama_pelanggan'] ?? 'Umum') ?></strong></div>
                                <?php if (!empty($s['no_hp'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars($s['no_hp']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; color: #64748b;">
                                Rp <?= number_format($s['harga_beli'], 0, ',', '.') ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #1e293b;">
                                Rp <?= number_format($s['harga_jual'], 0, ',', '.') ?>
                            </td>
                            <td style="text-align: right;">
                                <span style="color: #059669; background: #ecfdf5; padding: 4px 8px; border-radius: 6px; font-weight: 700; font-size: 13px;">
                                    +Rp <?= number_format($laba, 0, ',', '.') ?>
                                </span>
                            </td>
                            <td style="text-align: right; color: #10b981; font-weight: 600;">
                                Rp <?= number_format($terbayar, 0, ',', '.') ?>
                            </td>
                            <td style="text-align: right;" class="<?= $s['sisa_piutang'] > 0 ? 'text-danger font-bold' : 'text-muted' ?>">
                                Rp <?= number_format($s['sisa_piutang'], 0, ',', '.') ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge <?= $badge_class ?>" style="padding: 6px 12px;"><?= $status_label ?></span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button class="btn btn-sm btn-light" onclick="printReceipt('<?= htmlspecialchars($s['no_penjualan']) ?>')">
                                    Cetak
                                </button>
                                <?php if ($s['sisa_piutang'] > 0): ?>
                                    <a href="index.php?page=cicilan&search=<?= urlencode($s['no_penjualan']) ?>" class="btn btn-sm btn-success">
                                        Bayar Cicilan
                                    </a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-danger" onclick="confirmDeleteSale('<?= htmlspecialchars($s['no_penjualan']) ?>', '<?= htmlspecialchars(addslashes($s['nama_barang'])) ?>')">
                                    Hapus
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">
                            Belum ada data transaksi penjualan.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Form Penjualan Baru -->
<div id="modalNewSale" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h3 class="modal-title">Input Transaksi Penjualan Baru</h3>
            <button class="modal-close" onclick="closeSaleModal()">&times;</button>
        </div>
        <form method="POST" action="index.php?page=penjualan">
            <input type="hidden" name="action" value="add_penjualan">
            
            <div class="modal-body">
                <div class="row-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group mb-3">
                        <label class="form-label">No. Transaksi <span class="text-danger">*</span></label>
                        <input type="text" name="no_penjualan" class="form-control font-bold" required value="<?= htmlspecialchars($auto_no_penjualan) ?>">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Tanggal Transaksi <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Pelanggan</label>
                    <select name="pelanggan_id" class="form-control">
                        <option value="">-- Pelanggan Umum / Tunai --</option>
                        <?php foreach ($pelanggan_list as $pid => $pinfo): ?>
                            <option value="<?= $pid ?>"><?= htmlspecialchars($pinfo['nama']) ?> (Limit Kredit: Rp <?= number_format($pinfo['limit_kredit'], 0, ',', '.') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Nama Barang <span class="text-danger">*</span></label>
                    <input type="text" name="nama_barang" class="form-control" required placeholder="Contoh: Kulkas Sharp 2 Pintu SJ-195MD" style="font-size: 15px; font-weight: 600;">
                </div>

                <div class="row-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Harga Beli (Modal) <span class="text-danger">*</span></label>
                        <input type="number" name="harga_beli" id="input_harga_beli" class="form-control" required min="0" placeholder="0" oninput="calculateSaleMargin()">
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Harga Jual <span class="text-danger">*</span></label>
                        <input type="number" name="harga_jual" id="input_harga_jual" class="form-control font-bold" required min="1" placeholder="0" oninput="calculateSaleMargin()">
                    </div>
                </div>

                <!-- Live Margin & Keuntungan Display -->
                <div class="p-3 mb-3" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span class="text-secondary" style="font-size: 13px;">Keuntungan / Laba Gross:</span>
                        <h4 id="display_keuntungan" style="margin: 0; color: #16a34a; font-weight: 800;">Rp 0</h4>
                    </div>
                    <div>
                        <span class="text-secondary" style="font-size: 13px;">Margin %:</span>
                        <h4 id="display_margin_pct" style="margin: 0; color: #16a34a; font-weight: 800;">0%</h4>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Jumlah Dibayar / DP (Rp) <span class="text-danger">*</span></label>
                    <input type="number" name="jumlah_dibayar" id="input_jumlah_dibayar" class="form-control" required min="0" placeholder="0" oninput="calculateSaleMargin()">
                    <small class="text-muted">Isi sama dengan Harga Jual jika Jual Tunai. Isi lebih kecil jika Cicilan/DP.</small>
                </div>

                <!-- Payment Status & Piutang Display -->
                <div class="p-3 mb-3" id="box_payment_summary" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <span class="text-secondary" style="font-size: 13px;">Metode Transaksi Otomatis:</span>
                            <h4 id="display_status_payment" style="margin: 0; font-weight: 800; color: #2563eb;">Jual Tunai (Lunas)</h4>
                        </div>
                        <div style="text-align: right;">
                            <span class="text-secondary" style="font-size: 13px;">Sisa Piutang:</span>
                            <h4 id="display_sisa_piutang_form" style="margin: 0; font-weight: 800; color: #64748b;">Rp 0</h4>
                        </div>
                    </div>
                </div>

                <div class="row-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group mb-3">
                        <label class="form-label">Tempo Pembayaran</label>
                        <select name="tempo_tipe" class="form-control">
                            <option value="n/a">Tidak Ada / Tunai</option>
                            <option value="1_minggu">1 Minggu</option>
                            <option value="2_minggu">2 Minggu</option>
                            <option value="1_bulan">1 Bulan</option>
                            <option value="3_bulan">3 Bulan</option>
                            <option value="manual">Manual / Tanggal Bebas</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="form-label">Jatuh Tempo (Jika Kredit)</label>
                        <input type="date" name="jatuh_tempo" class="form-control">
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Catatan Transaksi</label>
                    <textarea name="catatan" class="form-control" rows="2" placeholder="Catatan tambahan / keterangan garansi barang..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeSaleModal()">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Transaksi Penjualan</button>
            </div>
        </form>
    </div>
</div>

<!-- Form Submit Hidden for Delete -->
<form id="formDeleteSale" method="POST" action="index.php?page=penjualan" style="display: none;">
    <input type="hidden" name="action" value="delete_penjualan">
    <input type="hidden" name="no_penjualan" id="delete_no_penjualan">
</form>

<!-- Modal Print Receipt -->
<div id="modalReceipt" class="modal">
    <div class="modal-content" style="max-width: 480px; padding: 0;">
        <div class="modal-header" style="padding: 16px 24px;">
            <h3 class="modal-title">Nota Transaksi Penjualan</h3>
            <button class="modal-close" onclick="closeReceiptModal()">&times;</button>
        </div>
        <div id="receiptContent" style="padding: 24px; font-family: monospace, sans-serif; background: #fff;">
            <div style="text-align: center; border-bottom: 1px dashed #000; padding-bottom: 12px; margin-bottom: 12px;">
                <h3 style="margin: 0; font-size: 18px; font-weight: 800;"><?= htmlspecialchars($shop_name) ?></h3>
                <p style="margin: 4px 0 0 0; font-size: 12px;"><?= htmlspecialchars($shop_address) ?></p>
                <p style="margin: 2px 0 0 0; font-size: 12px;">Telp/CP: <?= htmlspecialchars($shop_cp) ?></p>
            </div>
            
            <div id="receiptBody">
                <div style="text-align: center;">Memuat nota...</div>
            </div>

            <div style="text-align: center; border-top: 1px dashed #000; margin-top: 16px; padding-top: 12px; font-size: 11px;">
                Terima Kasih Atas Kunjungan Anda!
            </div>
        </div>
        <div class="modal-footer" style="padding: 12px 24px;">
            <button type="button" class="btn btn-light" onclick="closeReceiptModal()">Tutup</button>
            <button type="button" class="btn btn-primary" onclick="printReceiptArea()">Cetak Nota</button>
        </div>
    </div>
</div>

<script>
function openSaleModal() {
    const modal = document.getElementById('modalNewSale');
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
        calculateSaleMargin();
    }
}

function closeSaleModal() {
    const modal = document.getElementById('modalNewSale');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function calculateSaleMargin() {
    const beli = parseFloat(document.getElementById('input_harga_beli').value) || 0;
    const jual = parseFloat(document.getElementById('input_harga_jual').value) || 0;
    const dibayar = parseFloat(document.getElementById('input_jumlah_dibayar').value) || 0;

    const laba = jual - beli;
    const marginPct = (beli > 0) ? ((laba / beli) * 100).toFixed(1) : 0;
    const sisa = Math.max(0, jual - dibayar);

    document.getElementById('display_keuntungan').innerText = 'Rp ' + laba.toLocaleString('id-ID');
    document.getElementById('display_margin_pct').innerText = marginPct + '%';
    document.getElementById('display_sisa_piutang_form').innerText = 'Rp ' + sisa.toLocaleString('id-ID');

    const statusBox = document.getElementById('box_payment_summary');
    const statusText = document.getElementById('display_status_payment');

    if (jual > 0 && dibayar >= jual) {
        statusText.innerText = 'Jual Tunai (Lunas)';
        statusText.style.color = '#16a34a';
        statusBox.style.background = '#f0fdf4';
        statusBox.style.borderColor = '#bbf7d0';
    } else {
        statusText.innerText = 'Cicilan / Kredit (Belum Lunas)';
        statusText.style.color = '#dc2626';
        statusBox.style.background = '#fef2f2';
        statusBox.style.borderColor = '#fecaca';
    }
}

function confirmDeleteSale(noPenjualan, namaBarang) {
    if (confirm('Apakah Anda yakin ingin menghapus transaksi ' + noPenjualan + ' (' + namaBarang + ')?')) {
        document.getElementById('delete_no_penjualan').value = noPenjualan;
        document.getElementById('formDeleteSale').submit();
    }
}

function printReceipt(noPenjualan) {
    const modal = document.getElementById('modalReceipt');
    const body = document.getElementById('receiptBody');
    body.innerHTML = '<div style="text-align: center; padding: 20px;">Memuat nota...</div>';
    modal.classList.add('active');

    fetch('index.php?ajax_sale_details=1&no_penjualan=' + encodeURIComponent(noPenjualan))
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = '<div style="text-align: center; color: red;">Gagal memuat nota.</div>';
                return;
            }
            const d = data.data;
            const terbayar = d.harga_jual - d.sisa_piutang;
            let html = `
                <div style="font-size: 12px; margin-bottom: 12px;">
                    <div><strong>No Trx:</strong> ${d.no_penjualan}</div>
                    <div><strong>Tanggal:</strong> ${d.tanggal}</div>
                    <div><strong>Pelanggan:</strong> ${d.nama_pelanggan || 'Umum'}</div>
                </div>
                <div style="border-top: 1px dashed #000; border-bottom: 1px dashed #000; padding: 8px 0; margin-bottom: 12px; font-size: 13px;">
                    <div><strong>${d.nama_barang}</strong></div>
                    <div style="display: flex; justify-content: space-between; margin-top: 4px;">
                        <span>1 x Rp ${Number(d.harga_jual).toLocaleString('id-ID')}</span>
                        <strong>Rp ${Number(d.harga_jual).toLocaleString('id-ID')}</strong>
                    </div>
                </div>
                <div style="font-size: 12px; line-height: 1.6;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>Total Harga Jual:</span>
                        <strong>Rp ${Number(d.harga_jual).toLocaleString('id-ID')}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Jumlah Dibayar:</span>
                        <span>Rp ${Number(terbayar).toLocaleString('id-ID')}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold; border-top: 1px solid #ccc; padding-top: 4px; margin-top: 4px;">
                        <span>Sisa Piutang:</span>
                        <span style="color: ${d.sisa_piutang > 0 ? '#d97706' : '#000'}">Rp ${Number(d.sisa_piutang).toLocaleString('id-ID')}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-top: 4px;">
                        <span>Status:</span>
                        <span style="text-transform: uppercase; font-weight: bold;">${d.sisa_piutang <= 0 ? 'LUNAS (TUNAI)' : 'KREDIT (CICILAN)'}</span>
                    </div>
                </div>
            `;
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<div style="text-align: center; color: red;">Gagal memuat nota transaksi.</div>';
        });
}

function closeReceiptModal() {
    document.getElementById('modalReceipt').classList.remove('active');
}

function printReceiptArea() {
    const printContents = document.getElementById('receiptContent').innerHTML;
    const originalContents = document.body.innerHTML;
    document.body.innerHTML = '<div style="width: 300px; margin: 0 auto;">' + printContents + '</div>';
    window.print();
    document.body.innerHTML = originalContents;
    window.location.reload();
}
</script>

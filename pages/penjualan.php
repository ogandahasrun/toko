<?php
if (!defined('host')) { exit; }

$alert = '';
$view_detail_id = isset($_GET['detail_id']) ? trim($_GET['detail_id']) : '';

// Fetch active locations for sourcing stock
$lokasi_list = [];
$resL = $koneksi->query("SELECT * FROM lokasi ORDER BY id ASC");
if ($resL) {
    while ($l = $resL->fetch_assoc()) {
        $lokasi_list[$l['id']] = $l;
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

// Handle POST action (Insert Penjualan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_penjualan') {
    $no_penjualan = trim($_POST['no_penjualan']);
    $tanggal = $_POST['tanggal'];
    $lokasi_id = (int)$_POST['lokasi_id'];
    $tipe_pembayaran = $_POST['tipe_pembayaran'];
    $pelanggan_id = ($tipe_pembayaran === 'kredit' || !empty($_POST['pelanggan_id'])) ? (int)$_POST['pelanggan_id'] : null;
    $total_jual = (double)$_POST['total_jual'];
    $uang_muka = isset($_POST['uang_muka']) ? (double)$_POST['uang_muka'] : 0;
    $tempo_tipe = isset($_POST['tempo_tipe']) ? $_POST['tempo_tipe'] : 'n/a';
    $jatuh_tempo = (!empty($_POST['jatuh_tempo']) && $tipe_pembayaran === 'kredit') ? $_POST['jatuh_tempo'] : null;
    $items = isset($_POST['items']) ? $_POST['items'] : [];

    $sisa_piutang = 0;
    $status_kredit = 'n/a';
    if ($tipe_pembayaran === 'kredit') {
        $sisa_piutang = max(0, $total_jual - $uang_muka);
        $status_kredit = ($sisa_piutang <= 0) ? 'lunas' : 'belum_lunas';
    }

    if (!empty($no_penjualan) && !empty($tanggal) && $lokasi_id > 0 && count($items) > 0) {
        $koneksi->begin_transaction();
        try {
            // Check duplicate no_penjualan
            $chk = $koneksi->prepare("SELECT no_penjualan FROM penjualan WHERE no_penjualan = ?");
            $chk->bind_param("s", $no_penjualan);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                throw new Exception("Nomor transaksi penjualan sudah terdaftar!");
            }
            $chk->close();

            // Check Customer Credit Limit if Credit
            if ($tipe_pembayaran === 'kredit') {
                if (empty($pelanggan_id)) {
                    throw new Exception("Penjualan kredit mewajibkan pemilihan Pelanggan terdaftar!");
                }

                $resCust = $koneksi->query("SELECT nama, limit_kredit FROM pelanggan WHERE id = $pelanggan_id");
                if (!$resCust || $resCust->num_rows === 0) {
                    throw new Exception("Data pelanggan tidak ditemukan!");
                }
                $cust = $resCust->fetch_assoc();
                $limit_kredit = (double)$cust['limit_kredit'];
                $nama_pel = $cust['nama'];

                // Calculate current unpaid credit outstanding
                $resOutstanding = $koneksi->query("SELECT SUM(sisa_piutang) as total FROM penjualan WHERE pelanggan_id = $pelanggan_id AND status_kredit = 'belum_lunas'");
                $curOutstanding = 0;
                if ($resOutstanding) {
                    $rowO = $resOutstanding->fetch_assoc();
                    $curOutstanding = $rowO['total'] ? (double)$rowO['total'] : 0;
                }

                if ($curOutstanding + $sisa_piutang > $limit_kredit) {
                    $formatLimit = number_format($limit_kredit, 0, ',', '.');
                    $formatCur = number_format($curOutstanding, 0, ',', '.');
                    $formatNew = number_format($sisa_piutang, 0, ',', '.');
                    throw new Exception("Kredit ditolak! Pelanggan '$nama_pel' melebihi limit kredit. (Limit: Rp $formatLimit, Piutang Berjalan: Rp $formatCur, Sisa Kredit Baru: Rp $formatNew)");
                }
            }

            // Insert Penjualan Header
            $lok_name = isset($lokasi_list[$lokasi_id]) ? $lokasi_list[$lokasi_id]['nama_lokasi'] : 'Lokasi Terpilih';
            $no_penjualan_full = $no_penjualan;

            $stmt = $koneksi->prepare("INSERT INTO penjualan (no_penjualan, tanggal, pelanggan_id, tipe_pembayaran, total_jual, status_kredit, tempo_tipe, jatuh_tempo, sisa_piutang) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisdsssd", $no_penjualan_full, $tanggal, $pelanggan_id, $tipe_pembayaran, $total_jual, $status_kredit, $tempo_tipe, $jatuh_tempo, $sisa_piutang);
            $stmt->execute();
            $stmt->close();

            // Record DP as first payment installment using no_penjualan
            if ($tipe_pembayaran === 'kredit' && $uang_muka > 0) {
                $stmtDP = $koneksi->prepare("INSERT INTO pembayaran_kredit (no_penjualan, tanggal, jumlah_bayar, keterangan) VALUES (?, ?, ?, 'Pembayaran Uang Muka / DP awal')");
                $stmtDP->bind_param("ssd", $no_penjualan_full, $tanggal, $uang_muka);
                $stmtDP->execute();
                $stmtDP->close();
            }

            // Insert detail items and deduct stock in the selected location
            foreach ($items as $item) {
                $kode_barang = $item['kode_barang'];
                $jumlah = (int)$item['jumlah'];
                $harga_jual = (double)$item['harga_jual'];
                $subtotal = $jumlah * $harga_jual;

                // 1. Check stock in selected location
                $stmtStock = $koneksi->prepare("SELECT stok FROM gudang_barang WHERE lokasi_id = ? AND kode_barang = ?");
                $stmtStock->bind_param("is", $lokasi_id, $kode_barang);
                $stmtStock->execute();
                $resStok = $stmtStock->get_result();
                $curStok = 0;
                if ($resStok && $resStok->num_rows > 0) {
                    $curStok = (int)$resStok->fetch_assoc()['stok'];
                }
                $stmtStock->close();

                if ($curStok < $jumlah) {
                    $resN = $koneksi->query("SELECT nama_barang FROM barang WHERE kode_barang = '$kode_barang'");
                    $nama_barang = $resN ? $resN->fetch_assoc()['nama_barang'] : $kode_barang;
                    throw new Exception("Stok '$nama_barang' di lokasi '$lok_name' tidak mencukupi (Tersedia: $curStok, Diminta: $jumlah). Silakan sesuaikan kuantitas atau lakukan Mutasi!");
                }

                // 2. Fetch current cost price (harga_beli) for HPP calculation
                $resCost = $koneksi->query("SELECT harga_beli FROM barang_detail WHERE kode_barang = '" . $koneksi->real_escape_string($kode_barang) . "'");
                $harga_beli = 0;
                if ($resCost && $resCost->num_rows > 0) {
                    $harga_beli = (double)$resCost->fetch_assoc()['harga_beli'];
                }

                // Insert details with cost price using no_penjualan
                $stmtD = $koneksi->prepare("INSERT INTO penjualan_detail (no_penjualan, kode_barang, harga_beli, jumlah, harga_jual, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtD->bind_param("ssdidd", $no_penjualan_full, $kode_barang, $harga_beli, $jumlah, $harga_jual, $subtotal);
                $stmtD->execute();
                $stmtD->close();

                // 3. Deduct stock from selected location
                $stmtS = $koneksi->prepare("UPDATE gudang_barang SET stok = stok - ? WHERE lokasi_id = ? AND kode_barang = ?");
                $stmtS->bind_param("iis", $jumlah, $lokasi_id, $kode_barang);
                $stmtS->execute();
                $stmtS->close();
            }

            $koneksi->commit();
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Penjualan berhasil disimpan!', 'success'));</script>";
        } catch (Exception $e) {
            $koneksi->rollback();
            $err_msg = addslashes($e->getMessage());
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
        }
    } else {
        $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Mohon lengkapi seluruh kolom dan barang!', 'warning'));</script>";
    }
}

// Generate automatic no_penjualan suggestion
$auto_penjualan = 'TRX/' . date('Ymd') . '/' . str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
?>

<?= $alert ?>

<?php if (!empty($view_detail_id)): ?>
    <!-- Receipt print view -->
    <?php
    $esc_vid = $koneksi->real_escape_string($view_detail_id);
    $resP = $koneksi->query("
        SELECT p.*, c.nama as nama_pelanggan, c.alamat as alamat_pelanggan, c.no_hp as hp_pelanggan 
        FROM penjualan p 
        LEFT JOIN pelanggan c ON p.pelanggan_id = c.id 
        WHERE p.no_penjualan = '$esc_vid'
    ");
    if ($resP && $resP->num_rows > 0):
        $p = $resP->fetch_assoc();
    ?>
        <div class="page-header no-print">
            <div>
                <h1 class="page-title">Faktur Penjualan: <?= htmlspecialchars($p['no_penjualan']) ?></h1>
                <p class="text-secondary">Detail dan cetak bukti transaksi penjualan</p>
            </div>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-primary" onclick="window.print()">Cetak Resi</button>
                <a href="index.php?page=penjualan" class="btn btn-secondary">Kembali</a>
            </div>
        </div>

        <!-- Printable Invoice -->
        <div class="content-card printable-invoice" style="background:#fff; border-radius: var(--radius-lg); padding:30px;">
            <div style="display: flex; justify-content: space-between; border-bottom: 2px solid var(--border-color); padding-bottom: 20px; margin-bottom: 20px; align-items: flex-start;">
                <div>
                    <h2 style="font-weight: 800; color: #4f46e5; margin-bottom: 4px;"><?= htmlspecialchars($shop_name) ?></h2>
                    <p class="text-secondary" style="font-size: 13px; max-width: 250px;"><?= htmlspecialchars($shop_address) ?></p>
                    <p class="text-secondary" style="font-size: 13px;">CP: <?= htmlspecialchars($shop_cp) ?></p>
                </div>
                <div style="text-align: right;">
                    <h4 style="font-size: 18px; font-weight: 700; margin-bottom: 6px;">NOTA PENJUALAN</h4>
                    <p style="font-size: 14px;"><strong>No: <?= htmlspecialchars($p['no_penjualan']) ?></strong></p>
                    <p class="text-secondary" style="font-size: 13px;">Tanggal: <?= date('d/m/Y', strtotime($p['tanggal'])) ?></p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; font-size: 14px;">
                <div>
                    <span class="text-secondary">Informasi Pembayaran:</span><br>
                    Tipe Pembayaran: <strong><?= strtoupper(htmlspecialchars($p['tipe_pembayaran'])) ?></strong><br>
                    <?php if ($p['tipe_pembayaran'] === 'kredit'): ?>
                        Tempo: <strong>Per <?= htmlspecialchars($p['tempo_tipe']) ?></strong><br>
                        Jatuh Tempo: <strong><?= date('d/m/Y', strtotime($p['jatuh_tempo'])) ?></strong><br>
                        Status Piutang: 
                        <?php if ($p['status_kredit'] === 'lunas'): ?>
                            <span class="badge badge-success" style="font-size: 10px;">Lunas</span>
                        <?php else: ?>
                            <span class="badge badge-danger" style="font-size: 10px;">Belum Lunas (Sisa: Rp <?= number_format($p['sisa_piutang'], 0, ',', '.') ?>)</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div style="text-align: right;">
                    <span class="text-secondary">Pelanggan:</span><br>
                    <strong><?= htmlspecialchars($p['nama_pelanggan'] ? $p['nama_pelanggan'] : 'Umum (Cash)') ?></strong><br>
                    <?= htmlspecialchars($p['alamat_pelanggan'] ? $p['alamat_pelanggan'] : '') ?><br>
                    <?= htmlspecialchars($p['hp_pelanggan'] ? 'Telp: ' . $p['hp_pelanggan'] : '') ?>
                </div>
            </div>

            <table class="table-custom" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-color);">
                        <th style="padding: 10px 0;">Barang</th>
                        <th style="text-align: right; padding: 10px 0;">Harga</th>
                        <th style="text-align: center; padding: 10px 0;">Jumlah</th>
                        <th style="text-align: right; padding: 10px 0;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $resPD = $koneksi->query("
                        SELECT pd.*, b.nama_barang, b.satuan 
                        FROM penjualan_detail pd 
                        JOIN barang b ON pd.kode_barang = b.kode_barang 
                        WHERE pd.no_penjualan = '$esc_vid'
                    ");
                    while ($item = $resPD->fetch_assoc()):
                    ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px 0;">
                                <strong><?= htmlspecialchars($item['kode_barang']) ?></strong><br>
                                <span class="text-secondary" style="font-size: 12px;"><?= htmlspecialchars($item['nama_barang']) ?></span>
                            </td>
                            <td style="text-align: right; padding: 12px 0;">Rp <?= number_format($item['harga_jual'], 0, ',', '.') ?></td>
                            <td style="text-align: center; padding: 12px 0;"><?= number_format($item['jumlah']) ?> <?= htmlspecialchars($item['satuan']) ?></td>
                            <td style="text-align: right; padding: 12px 0; font-weight: 600;">Rp <?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <div style="display: flex; justify-content: flex-end;">
                <div style="text-align: right; width: 250px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                        <span class="text-secondary">Subtotal:</span>
                        <strong>Rp <?= number_format($p['total_jual'], 0, ',', '.') ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-top: 2px dashed var(--border-color); padding-top: 10px; margin-top: 10px;">
                        <span style="font-size: 16px; font-weight: 700;">Grand Total:</span>
                        <strong style="font-size: 18px; color: #4f46e5;">Rp <?= number_format($p['total_jual'], 0, ',', '.') ?></strong>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 50px; text-align: center; font-size: 12px; color: var(--text-secondary);" class="no-print">
                <p>Terima kasih telah berbelanja di <?= htmlspecialchars($shop_name) ?>!</p>
            </div>
        </div>

        <style>
            @media print {
                body {
                    background: white;
                    color: black;
                }
                .sidebar, .bottom-nav, .no-print, .page-header {
                    display: none !important;
                }
                .main-layout {
                    margin: 0 !important;
                    padding: 0 !important;
                }
                .printable-invoice {
                    border: none !important;
                    box-shadow: none !important;
                    padding: 0 !important;
                }
            }
        </style>
    <?php endif; ?>

<?php elseif (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
    <!-- Creating New POS Sales Invoice -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Kasir (Penjualan)</h1>
            <p class="text-secondary">Pencatatan penjualan tunai & kredit langsung memotong stok dari lokasi pilihan Anda</p>
        </div>
        <div>
            <a href="index.php?page=penjualan" class="btn btn-secondary">Batal</a>
        </div>
    </div>

    <form id="penjualanForm" action="index.php?page=penjualan" method="POST">
        <input type="hidden" name="action" value="add_penjualan">
        <div class="tx-layout">
            <!-- Left Panel: Search & Items Table -->
            <div>
                <div class="content-card">
                    <div class="card-header">
                        <h3 class="card-title">Keranjang Belanja</h3>
                    </div>
                    <!-- Real-time autocomplete search -->
                    <div class="form-group autocomplete-container">
                        <label class="form-label">Cari Nama Barang / Scan Barcode</label>
                        <input type="text" class="form-control tx-search-input" placeholder="Scan barcode / ketik nama barang..." autocomplete="off" autofocus>
                        <div class="autocomplete-suggestions" style="display: none;"></div>
                    </div>

                    <div class="table-responsive" style="margin-top: 20px;">
                        <table class="table-custom tx-items-table">
                            <thead>
                                <tr>
                                    <th>Barang</th>
                                    <th>Harga Jual (Rp)</th>
                                    <th>Jumlah</th>
                                    <th>Subtotal</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-secondary">
                                        Keranjang belanja kosong. Cari barang di atas untuk menambahkan.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Sales checkout controls -->
            <div>
                <div class="tx-summary">
                    <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px;">Pengaturan Pembayaran</h3>
                    
                    <div class="form-group">
                        <label class="form-label">No. Penjualan <span style="color:red;">*</span></label>
                        <input type="text" name="no_penjualan" class="form-control" value="<?= htmlspecialchars($auto_penjualan) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Tanggal Penjualan <span style="color:red;">*</span></label>
                        <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Ambil Barang Dari <span style="color:red;">*</span></label>
                        <select name="lokasi_id" class="form-control" required>
                            <?php foreach ($lokasi_list as $id => $lok): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($lok['nama_lokasi']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Metode Pembayaran <span style="color:red;">*</span></label>
                        <select name="tipe_pembayaran" class="form-control" required>
                            <option value="tunai">Tunai / Cash</option>
                            <option value="kredit">Kredit / Piutang</option>
                        </select>
                    </div>

                    <!-- Credit parameters (only visible if kredit is selected) -->
                    <div class="credit-fields" style="display: none; padding: 12px; background: rgba(99, 102, 241, 0.05); border: 1px solid rgba(99, 102, 241, 0.15); border-radius: var(--radius-md); margin-bottom: 16px;">
                        <div class="form-group">
                            <label class="form-label">Pilih Pelanggan <span style="color:red;">*</span></label>
                            <select name="pelanggan_id" class="form-control">
                                <option value="">-- Pilih Pelanggan --</option>
                                <?php foreach ($pelanggan_list as $pel): ?>
                                    <option value="<?= $pel['id'] ?>">
                                        <?= htmlspecialchars($pel['nama']) ?> (Limit: Rp <?= number_format($pel['limit_kredit'], 0, ',', '.') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Periode Pembayaran <span style="color:red;">*</span></label>
                            <select name="tempo_tipe" class="form-control">
                                <option value="harian">Cicilan Harian</option>
                                <option value="mingguan">Cicilan Mingguan</option>
                                <option value="bulanan">Cicilan Bulanan</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tanggal Jatuh Tempo <span style="color:red;">*</span></label>
                            <input type="date" name="jatuh_tempo" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Uang Muka / DP (Rp)</label>
                            <input type="number" name="uang_muka" id="inpUangMuka" class="form-control" placeholder="0" value="0" min="0">
                        </div>
                    </div>

                    <div class="tx-summary-total">
                        <span style="font-size: 12px; color: var(--text-secondary); display: block; font-weight: 500; text-transform: uppercase;">Total Transaksi</span>
                        <span class="tx-total-val">Rp 0</span>
                        <input type="hidden" name="total_jual" class="tx-total-input" value="0">
                    </div>
                    <br>
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
                        Checkout Transaksi
                    </button>
                </div>
            </div>
        </div>
    </form>

    <script>
        // Init POS transaction behaviors
        window.addEventListener('DOMContentLoaded', () => {
            window.currentTx = new TransactionForm('penjualanForm', false);
        });
    </script>

<?php else: ?>
    <!-- Listing past sales transactions -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Riwayat Penjualan</h1>
            <p class="text-secondary">Daftar struk nota penjualan toko Anda</p>
        </div>
        <div>
            <a href="index.php?page=penjualan&action=new" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Transaksi Baru
            </a>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">Daftar Nota Penjualan</h3>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No Penjualan</th>
                        <th>Tanggal</th>
                        <th>Pelanggan</th>
                        <th>Metode</th>
                        <th>Total Jual</th>
                        <th>Status</th>
                        <th style="width: 150px; text-align: center;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = $koneksi->query("
                        SELECT p.*, c.nama as nama_pelanggan 
                        FROM penjualan p 
                        LEFT JOIN pelanggan c ON p.pelanggan_id = c.id 
                        ORDER BY p.created_at DESC
                    ");
                    if ($res && $res->num_rows > 0):
                        while ($r = $res->fetch_assoc()):
                    ?>
                        <tr>
                            <td data-label="No Penjualan"><strong><?= htmlspecialchars($r['no_penjualan']) ?></strong></td>
                            <td data-label="Tanggal"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                            <td data-label="Pelanggan"><?= htmlspecialchars($r['nama_pelanggan'] ? $r['nama_pelanggan'] : 'Umum (Cash)') ?></td>
                            <td data-label="Metode">
                                <?php if ($r['tipe_pembayaran'] === 'tunai'): ?>
                                     <span class="badge badge-success">Tunai</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Kredit (<?= htmlspecialchars($r['tempo_tipe']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Total Jual">Rp <?= number_format($r['total_jual'], 0, ',', '.') ?></td>
                            <td data-label="Status">
                                <?php if ($r['tipe_pembayaran'] === 'tunai'): ?>
                                    <span class="badge badge-success">Selesai</span>
                                <?php else: ?>
                                    <?php if ($r['status_kredit'] === 'lunas'): ?>
                                        <span class="badge badge-success">Lunas</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Belum Lunas</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div style="display: flex; gap: 4px; justify-content: center;">
                                    <a href="index.php?page=penjualan&detail_id=<?= urlencode($r['no_penjualan']) ?>" class="btn btn-secondary btn-sm">
                                        Nota
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="7" class="text-center text-secondary py-4">Belum ada riwayat transaksi penjualan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

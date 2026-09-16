<?php
if (!defined('host')) { exit; }

$alert = '';
$view_detail_id = isset($_GET['detail_id']) ? trim($_GET['detail_id']) : '';

// Fetch active locations for dropdown selection
$lokasi_list = [];
$resL = $koneksi->query("SELECT * FROM lokasi ORDER BY id ASC");
if ($resL) {
    while ($l = $resL->fetch_assoc()) {
        $lokasi_list[$l['id']] = $l;
    }
}

// Handle POST action (Insert Faktur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_faktur') {
    $no_faktur = trim($_POST['no_faktur']);
    $tanggal = $_POST['tanggal'];
    $supplier = trim($_POST['supplier']);
    $lokasi_id = (int)$_POST['lokasi_id'];
    $keterangan = trim($_POST['keterangan']);
    $total_beli = (double)$_POST['total_beli'];
    $items = isset($_POST['items']) ? $_POST['items'] : [];

    if (!empty($no_faktur) && !empty($supplier) && !empty($tanggal) && $lokasi_id > 0 && count($items) > 0) {
        $koneksi->begin_transaction();
        try {
            // Check duplicate
            $chk = $koneksi->prepare("SELECT no_faktur FROM faktur WHERE no_faktur = ?");
            $chk->bind_param("s", $no_faktur);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                throw new Exception("Nomor faktur sudah terdaftar!");
            }
            $chk->close();

            // Insert Faktur Header
            $lok_name = isset($lokasi_list[$lokasi_id]) ? $lokasi_list[$lokasi_id]['nama_lokasi'] : 'Lokasi Terpilih';
            $keterangan_full = "[Tujuan: $lok_name] " . $keterangan;

            $stmt = $koneksi->prepare("INSERT INTO faktur (no_faktur, tanggal, supplier, lokasi_id, total_beli, keterangan) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssids", $no_faktur, $tanggal, $supplier, $lokasi_id, $total_beli, $keterangan_full);
            $stmt->execute();
            $stmt->close();

            // Insert details & update stock/prices using no_faktur
            foreach ($items as $item) {
                $kode_barang = $item['kode_barang'];
                $jumlah = (int)$item['jumlah'];
                $harga_beli = (double)$item['harga_beli'];

                // Insert detail row
                $stmtD = $koneksi->prepare("INSERT INTO faktur_detail (no_faktur, kode_barang, jumlah, harga_beli) VALUES (?, ?, ?, ?)");
                $stmtD->bind_param("ssid", $no_faktur, $kode_barang, $jumlah, $harga_beli);
                $stmtD->execute();
                $stmtD->close();

                // Update harga_beli in barang_detail (dynamic update)
                $stmtU = $koneksi->prepare("UPDATE barang_detail SET harga_beli = ? WHERE kode_barang = ?");
                $stmtU->bind_param("ds", $harga_beli, $kode_barang);
                $stmtU->execute();
                $stmtU->close();

                // Update stock in the selected location
                $stmtS = $koneksi->prepare("
                    INSERT INTO gudang_barang (lokasi_id, kode_barang, stok) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE stok = stok + ?
                ");
                $stmtS->bind_param("isii", $lokasi_id, $kode_barang, $jumlah, $jumlah);
                $stmtS->execute();
                $stmtS->close();
            }

            $koneksi->commit();
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Faktur berhasil diinput & stok lokasi bertambah!', 'success'));</script>";
        } catch (Exception $e) {
            $koneksi->rollback();
            $err_msg = addslashes($e->getMessage());
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
        }
    } else {
        $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Mohon lengkapi seluruh kolom dan barang!', 'warning'));</script>";
    }
}

// Handle POST action (Delete Faktur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_faktur') {
    $no_faktur = isset($_POST['no_faktur']) ? trim($_POST['no_faktur']) : (isset($_POST['id']) ? trim($_POST['id']) : '');
    if (!empty($no_faktur)) {
        $koneksi->begin_transaction();
        try {
            $esc_faktur = $koneksi->real_escape_string($no_faktur);
            // Fetch faktur header to get lokasi_id
            $resF = $koneksi->query("SELECT lokasi_id, no_faktur FROM faktur WHERE no_faktur = '$esc_faktur'");
            if (!$resF || $resF->num_rows === 0) {
                throw new Exception("Faktur tidak ditemukan!");
            }
            $fakturData = $resF->fetch_assoc();
            $lokasi_id = $fakturData['lokasi_id'];

            if (empty($lokasi_id)) {
                throw new Exception("Faktur tidak dapat dihapus otomatis karena lokasi penyimpanan tidak tercatat secara terpisah. Silakan hubungi admin.");
            }

            // Fetch detail items to check if current stock is sufficient to deduct
            $resDetails = $koneksi->query("SELECT fd.kode_barang, fd.jumlah, b.nama_barang FROM faktur_detail fd JOIN barang b ON fd.kode_barang = b.kode_barang WHERE fd.no_faktur = '$esc_faktur'");
            $items_to_deduct = [];
            while ($d = $resDetails->fetch_assoc()) {
                $kode_barang = $d['kode_barang'];
                $jumlah = (int)$d['jumlah'];
                $nama_barang = $d['nama_barang'];

                // Query current stock in the location
                $resStok = $koneksi->query("SELECT stok FROM gudang_barang WHERE lokasi_id = $lokasi_id AND kode_barang = '$kode_barang'");
                $curStok = 0;
                if ($resStok && $resStok->num_rows > 0) {
                    $curStok = (int)$resStok->fetch_assoc()['stok'];
                }

                if ($curStok < $jumlah) {
                    throw new Exception("Gagal menghapus Faktur! Stok '$nama_barang' di lokasi terkait kurang dari jumlah faktur (Tersedia: $curStok, Dibutuhkan Revert: $jumlah). Barang kemungkinan sudah terjual atau dimutasi.");
                }

                $items_to_deduct[] = [
                    'kode_barang' => $kode_barang,
                    'jumlah' => $jumlah
                ];
            }

            // Deduct stock
            foreach ($items_to_deduct as $item) {
                $kode_barang = $item['kode_barang'];
                $jumlah = $item['jumlah'];
                $koneksi->query("UPDATE gudang_barang SET stok = stok - $jumlah WHERE lokasi_id = $lokasi_id AND kode_barang = '$kode_barang'");
            }

            // Delete detail rows first (respecting ON DELETE RESTRICT on the foreign key)
            $koneksi->query("DELETE FROM faktur_detail WHERE no_faktur = '$esc_faktur'");

            // Delete the faktur header row
            $koneksi->query("DELETE FROM faktur WHERE no_faktur = '$esc_faktur'");

            $koneksi->commit();
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Faktur $no_faktur berhasil dihapus dan stok telah dikurangi!', 'success'));</script>";
        } catch (Exception $e) {
            $koneksi->rollback();
            $err_msg = addslashes($e->getMessage());
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
        }
    }
}

// Generate automatic no_faktur suggestion
$auto_faktur = 'INV/' . date('Ymd') . '/' . str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
?>

<?= $alert ?>

<?php if (!empty($view_detail_id)): ?>
    <!-- Viewing Invoice Details -->
    <?php
    $esc_v = $koneksi->real_escape_string($view_detail_id);
    $resF = $koneksi->query("SELECT * FROM faktur WHERE no_faktur = '$esc_v'");
    if ($resF && $resF->num_rows > 0):
        $f = $resF->fetch_assoc();
    ?>
        <div class="page-header">
            <div>
                <h1 class="page-title">Detail Faktur: <?= htmlspecialchars($f['no_faktur']) ?></h1>
                <p class="text-secondary">Supplier: <?= htmlspecialchars($f['supplier']) ?> | Tanggal: <?= date('d/m/Y', strtotime($f['tanggal'])) ?></p>
            </div>
            <div>
                <a href="index.php?page=faktur" class="btn btn-secondary">Kembali</a>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title">Daftar Rincian Barang</h3>
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Nama Barang</th>
                            <th>Harga Beli</th>
                            <th>Jumlah Beli</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $esc_v = $koneksi->real_escape_string($view_detail_id);
                        $resFD = $koneksi->query("
                            SELECT fd.*, b.nama_barang, b.satuan 
                            FROM faktur_detail fd 
                            JOIN barang b ON fd.kode_barang = b.kode_barang 
                            WHERE fd.no_faktur = '$esc_v'
                        ");
                        while ($item = $resFD->fetch_assoc()):
                            $sub = $item['jumlah'] * $item['harga_beli'];
                        ?>
                            <tr>
                                <td data-label="Kode Barang"><strong><?= htmlspecialchars($item['kode_barang']) ?></strong></td>
                                <td data-label="Nama Barang"><?= htmlspecialchars($item['nama_barang']) ?></td>
                                <td data-label="Harga Beli">Rp <?= number_format($item['harga_beli'], 0, ',', '.') ?></td>
                                <td data-label="Jumlah Beli"><?= number_format($item['jumlah']) ?> <?= htmlspecialchars($item['satuan']) ?></td>
                                <td data-label="Subtotal"><strong>Rp <?= number_format($sub, 0, ',', '.') ?></strong></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 24px; padding-top: 16px; border-top: 2px dashed var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span class="text-secondary">Keterangan / Tujuan:</span><br>
                    <strong><?= htmlspecialchars($f['keterangan'] ? $f['keterangan'] : '-') ?></strong>
                </div>
                <div style="text-align: right;">
                    <span class="text-secondary">Total Nilai Faktur:</span><br>
                    <strong style="font-size: 24px; color: #4f46e5;">Rp <?= number_format($f['total_beli'], 0, ',', '.') ?></strong>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
    <!-- Creating New Purchase Invoice -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Input Faktur Baru</h1>
            <p class="text-secondary">Input pembelian barang masuk untuk menambah stok lokasi terpilih</p>
        </div>
        <div>
            <a href="index.php?page=faktur" class="btn btn-secondary">Batal</a>
        </div>
    </div>

    <form id="fakturForm" action="index.php?page=faktur" method="POST">
        <input type="hidden" name="action" value="add_faktur">
        <div class="tx-layout">
            <!-- Left Panel: Search & Items -->
            <div>
                <div class="content-card">
                    <div class="card-header">
                        <h3 class="card-title">Pilih Barang</h3>
                    </div>
                    <!-- Real-time autocomplete search -->
                    <div class="form-group autocomplete-container">
                        <label class="form-label">Cari Nama Barang / Kode Barcode</label>
                        <input type="text" class="form-control tx-search-input" placeholder="Ketik minimal 1 huruf..." autocomplete="off">
                        <div class="autocomplete-suggestions" style="display: none;"></div>
                    </div>

                    <div class="table-responsive" style="margin-top: 20px;">
                        <table class="table-custom tx-items-table">
                            <thead>
                                <tr>
                                    <th>Barang</th>
                                    <th>Harga Beli (Rp)</th>
                                    <th>Jumlah</th>
                                    <th>Subtotal</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-secondary">
                                        Belum ada barang dipilih. Silakan cari di atas.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Invoice Metadata -->
            <div>
                <div class="tx-summary">
                    <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px;">Ringkasan Faktur</h3>
                    
                    <div class="form-group">
                        <label class="form-label">No. Faktur <span style="color:red;">*</span></label>
                        <input type="text" name="no_faktur" class="form-control" value="<?= htmlspecialchars($auto_faktur) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Tanggal Faktur <span style="color:red;">*</span></label>
                        <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Supplier <span style="color:red;">*</span></label>
                        <input type="text" name="supplier" class="form-control" placeholder="Contoh: PT. Sumber Makmur" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lokasi Tujuan Masuk <span style="color:red;">*</span></label>
                        <select name="lokasi_id" class="form-control" required>
                            <?php foreach ($lokasi_list as $id => $lok): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($lok['nama_lokasi']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Keterangan</label>
                        <textarea name="keterangan" class="form-control" rows="2" placeholder="Catatan tambahan..."></textarea>
                    </div>

                    <div class="tx-summary-total">
                        <span style="font-size: 12px; color: var(--text-secondary); display: block; font-weight: 500; text-transform: uppercase;">Total Pembelian</span>
                        <span class="tx-total-val">Rp 0</span>
                        <input type="hidden" name="total_beli" class="tx-total-input" value="0">
                    </div>
                    <br>
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
                        Simpan Faktur Masuk
                    </button>
                </div>
            </div>
        </div>
    </form>

    <script>
        // Init dynamic calculations for Faktur
        window.addEventListener('DOMContentLoaded', () => {
            window.currentTx = new TransactionForm('fakturForm', true);
        });
    </script>

<?php else: ?>
    <!-- Listing existing invoices -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Riwayat Faktur Pembelian</h1>
            <p class="text-secondary">Daftar transaksi barang masuk dari supplier</p>
        </div>
        <div>
            <a href="index.php?page=faktur&action=new" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Faktur Baru
            </a>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">Daftar Faktur Masuk</h3>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No Faktur</th>
                        <th>Tanggal</th>
                        <th>Supplier / Tujuan</th>
                        <th>Total Nilai</th>
                        <th style="width: 100px; text-align: center;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = $koneksi->query("SELECT * FROM faktur ORDER BY created_at DESC");
                    if ($res && $res->num_rows > 0):
                        while ($r = $res->fetch_assoc()):
                    ?>
                        <tr>
                            <td data-label="No Faktur"><strong><?= htmlspecialchars($r['no_faktur']) ?></strong></td>
                            <td data-label="Tanggal"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                            <td data-label="Supplier / Tujuan">
                                <?= htmlspecialchars($r['supplier']) ?><br>
                                <span class="text-secondary" style="font-size:12px;"><?= htmlspecialchars($r['keterangan'] ? $r['keterangan'] : '-') ?></span>
                            </td>
                            <td data-label="Total Nilai">Rp <?= number_format($r['total_beli'], 0, ',', '.') ?></td>
                            <td class="text-center">
                                <div style="display: flex; gap: 4px; justify-content: center;">
                                    <a href="index.php?page=faktur&detail_id=<?= urlencode($r['no_faktur']) ?>" class="btn btn-secondary btn-sm">
                                        Detail
                                    </a>
                                    <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus faktur ini? Stok barang di lokasi tujuan akan berkurang otomatis.');" style="display:inline-block;">
                                        <input type="hidden" name="action" value="delete_faktur">
                                        <input type="hidden" name="no_faktur" value="<?= htmlspecialchars($r['no_faktur']) ?>">
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
                            <td colspan="5" class="text-center text-secondary py-4">Belum ada riwayat faktur pembelian.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

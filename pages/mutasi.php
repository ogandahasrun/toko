<?php
if (!defined('host')) { exit; }

// Intercept AJAX search for mutasi items (checking stock in specific location)
if (isset($_GET['ajax_search_mutasi'])) {
    header('Content-Type: application/json');
    $lokasi_id = isset($_GET['lok_id']) ? (int)$_GET['lok_id'] : 1;
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $search = "%" . $q . "%";
    
    $stmt = $koneksi->prepare("
        SELECT b.kode_barang, b.nama_barang, b.satuan, COALESCE(gb.stok, 0) as stok
        FROM barang b
        INNER JOIN gudang_barang gb ON b.kode_barang = gb.kode_barang AND gb.lokasi_id = ?
        WHERE (b.kode_barang LIKE ? OR b.nama_barang LIKE ?) AND gb.stok > 0
        LIMIT 10
    ");
    $stmt->bind_param("iss", $lokasi_id, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}

$alert = '';
$view_detail_id = isset($_GET['detail_id']) ? trim($_GET['detail_id']) : '';

// Fetch location mappings
$lokasi_list = [];
$resL = $koneksi->query("SELECT * FROM lokasi ORDER BY id ASC");
if ($resL) {
    while ($l = $resL->fetch_assoc()) {
        $lokasi_list[$l['id']] = $l;
    }
}

// Handle POST mutation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mutasi') {
    $no_mutasi = trim($_POST['no_mutasi']);
    $tanggal = $_POST['tanggal'];
    $dari_lokasi_id = (int)$_POST['dari_lokasi_id'];
    $ke_lokasi_id = (int)$_POST['ke_lokasi_id'];
    $keterangan = trim($_POST['keterangan']);
    $items = isset($_POST['items']) ? $_POST['items'] : [];

    if (!empty($no_mutasi) && $dari_lokasi_id > 0 && $ke_lokasi_id > 0 && count($items) > 0) {
        if ($dari_lokasi_id === $ke_lokasi_id) {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Lokasi asal dan tujuan tidak boleh sama!', 'danger'));</script>";
        } else {
            $koneksi->begin_transaction();
            try {
                // Check duplicate
                $chk = $koneksi->prepare("SELECT no_mutasi FROM mutasi WHERE no_mutasi = ?");
                $chk->bind_param("s", $no_mutasi);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    throw new Exception("Nomor mutasi sudah digunakan!");
                }
                $chk->close();

                // Save Header
                $stmt = $koneksi->prepare("INSERT INTO mutasi (no_mutasi, tanggal, dari_lokasi_id, ke_lokasi_id, keterangan) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("ssiis", $no_mutasi, $tanggal, $dari_lokasi_id, $ke_lokasi_id, $keterangan);
                $stmt->execute();
                $stmt->close();

                // Save details & transfer stocks using no_mutasi
                foreach ($items as $item) {
                    $kode_barang = $item['kode_barang'];
                    $jumlah = (int)$item['jumlah'];

                    if ($jumlah <= 0) continue;

                    // Verify source stock
                    $stmtS = $koneksi->prepare("SELECT stok FROM gudang_barang WHERE lokasi_id = ? AND kode_barang = ?");
                    $stmtS->bind_param("is", $dari_lokasi_id, $kode_barang);
                    $stmtS->execute();
                    $resStok = $stmtS->get_result();
                    $curStok = 0;
                    if ($resStok && $resStok->num_rows > 0) {
                        $curStok = (int)$resStok->fetch_assoc()['stok'];
                    }
                    $stmtS->close();

                    if ($curStok < $jumlah) {
                        // Get item name
                        $resN = $koneksi->query("SELECT nama_barang FROM barang WHERE kode_barang = '$kode_barang'");
                        $nama_barang = $resN ? $resN->fetch_assoc()['nama_barang'] : $kode_barang;
                        throw new Exception("Stok untuk '$nama_barang' tidak mencukupi (Tersedia: $curStok, Diminta: $jumlah)!");
                    }

                    // Save Detail Row
                    $stmtD = $koneksi->prepare("INSERT INTO mutasi_detail (no_mutasi, kode_barang, jumlah) VALUES (?, ?, ?)");
                    $stmtD->bind_param("ssi", $no_mutasi, $kode_barang, $jumlah);
                    $stmtD->execute();
                    $stmtD->close();

                    // Deduct source stock
                    $stmtSub = $koneksi->prepare("UPDATE gudang_barang SET stok = stok - ? WHERE lokasi_id = ? AND kode_barang = ?");
                    $stmtSub->bind_param("iis", $jumlah, $dari_lokasi_id, $kode_barang);
                    $stmtSub->execute();
                    $stmtSub->close();

                    // Add destination stock
                    $stmtAdd = $koneksi->prepare("
                        INSERT INTO gudang_barang (lokasi_id, kode_barang, stok) 
                        VALUES (?, ?, ?) 
                        ON DUPLICATE KEY UPDATE stok = stok + ?
                    ");
                    $stmtAdd->bind_param("isii", $ke_lokasi_id, $kode_barang, $jumlah, $jumlah);
                    $stmtAdd->execute();
                    $stmtAdd->close();
                }

                $koneksi->commit();
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Mutasi barang berhasil dilakukan!', 'success'));</script>";
            } catch (Exception $e) {
                $koneksi->rollback();
                $err_msg = addslashes($e->getMessage());
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('$err_msg', 'danger'));</script>";
            }
        }
    } else {
        $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Mohon isi semua kolom wajib dan tambahkan barang!', 'warning'));</script>";
    }
}

// Check auto parameter values
$auto_kode = isset($_GET['auto_kode']) ? $_GET['auto_kode'] : '';
$auto_dari = isset($_GET['dari_lokasi']) ? (int)$_GET['dari_lokasi'] : 1;
$auto_ke = $auto_dari === 1 ? 2 : 1; // Default swap: Gudang to Etalase or vice versa

$auto_no_mutasi = 'MUT/' . date('Ymd') . '/' . str_pad(rand(10, 99), 2, '0', STR_PAD_LEFT) . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
?>

<?= $alert ?>

<?php if (!empty($view_detail_id)): ?>
    <!-- Viewing Mutation details -->
    <?php
    $esc_m = $koneksi->real_escape_string($view_detail_id);
    $resM = $koneksi->query("SELECT * FROM mutasi WHERE no_mutasi = '$esc_m'");
    if ($resM && $resM->num_rows > 0):
        $m = $resM->fetch_assoc();
    ?>
        <div class="page-header">
            <div>
                <h1 class="page-title">Detail Mutasi: <?= htmlspecialchars($m['no_mutasi']) ?></h1>
                <p class="text-secondary">
                    Asal: <b><?= htmlspecialchars($lokasi_list[$m['dari_lokasi_id']]['nama_lokasi']) ?></b> ➡️ 
                    Tujuan: <b><?= htmlspecialchars($lokasi_list[$m['ke_lokasi_id']]['nama_lokasi']) ?></b>
                </p>
                <p class="text-secondary">Tanggal: <?= date('d/m/Y', strtotime($m['tanggal'])) ?></p>
            </div>
            <div>
                <a href="index.php?page=mutasi" class="btn btn-secondary">Kembali</a>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h3 class="card-title">Rincian Barang Bermutasi</h3>
            </div>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Nama Barang</th>
                            <th>Satuan</th>
                            <th>Jumlah Mutasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $resMD = $koneksi->query("
                            SELECT md.*, b.nama_barang, b.satuan 
                            FROM mutasi_detail md 
                            JOIN barang b ON md.kode_barang = b.kode_barang 
                            WHERE md.no_mutasi = '$esc_m'
                        ");
                        while ($item = $resMD->fetch_assoc()):
                        ?>
                            <tr>
                                <td data-label="Kode Barang"><strong><?= htmlspecialchars($item['kode_barang']) ?></strong></td>
                                <td data-label="Nama Barang"><?= htmlspecialchars($item['nama_barang']) ?></td>
                                <td data-label="Satuan"><?= htmlspecialchars($item['satuan']) ?></td>
                                <td data-label="Jumlah Mutasi" style="color: #4f46e5; font-weight: 700;"><?= number_format($item['jumlah']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($m['keterangan'])): ?>
                <div style="margin-top: 20px; padding: 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    <span class="text-secondary">Keterangan:</span><br>
                    <strong><?= htmlspecialchars($m['keterangan']) ?></strong>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php elseif (isset($_GET['action']) && $_GET['action'] === 'new'): ?>
    <!-- Creating New Mutation -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Mutasi Barang Baru</h1>
            <p class="text-secondary">Pindahkan stok antar Gudang Utama dan Etalase Jual</p>
        </div>
        <div>
            <a href="index.php?page=mutasi" class="btn btn-secondary">Batal</a>
        </div>
    </div>

    <form id="mutasiForm" action="index.php?page=mutasi" method="POST">
        <input type="hidden" name="action" value="add_mutasi">
        <div class="tx-layout">
            <!-- Left Panel: Search & Mutation Items -->
            <div>
                <div class="content-card">
                    <div class="card-header">
                        <h3 class="card-title">Daftar Barang Bermutasi</h3>
                    </div>

                    <div class="form-group autocomplete-container">
                        <label class="form-label">Cari Barang dengan Stok di Lokasi Asal</label>
                        <input type="text" id="mutasiSearch" class="form-control" placeholder="Ketik minimal 1 huruf..." autocomplete="off">
                        <div id="mutasiSuggestions" class="autocomplete-suggestions" style="display: none;"></div>
                    </div>

                    <div class="table-responsive" style="margin-top: 20px;">
                        <table class="table-custom" id="tblMutasiItems">
                            <thead>
                                <tr>
                                    <th>Barang</th>
                                    <th>Maks. Stok Asal</th>
                                    <th>Jumlah Mutasi</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-secondary">
                                        Belum ada barang dipilih. Silakan cari di atas.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Mutation metadata -->
            <div>
                <div class="tx-summary">
                    <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px;">Pengaturan Mutasi</h3>
                    
                    <div class="form-group">
                        <label class="form-label">No. Mutasi <span style="color:red;">*</span></label>
                        <input type="text" name="no_mutasi" class="form-control" value="<?= htmlspecialchars($auto_no_mutasi) ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Tanggal Mutasi <span style="color:red;">*</span></label>
                        <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lokasi Asal (Sumber) <span style="color:red;">*</span></label>
                        <select name="dari_lokasi_id" id="selDari" class="form-control" required>
                            <?php foreach ($lokasi_list as $id => $lok): ?>
                                <option value="<?= $id ?>" <?= $id === $auto_dari ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($lok['nama_lokasi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Lokasi Tujuan <span style="color:red;">*</span></label>
                        <select name="ke_lokasi_id" id="selKe" class="form-control" required>
                            <?php foreach ($lokasi_list as $id => $lok): ?>
                                <option value="<?= $id ?>" <?= $id === $auto_ke ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($lok['nama_lokasi']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Keterangan / Catatan</label>
                        <textarea name="keterangan" class="form-control" rows="2" placeholder="Catatan mutasi..."></textarea>
                    </div>
                    <br>
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 14px;">
                        Lakukan Mutasi Stok
                    </button>
                </div>
            </div>
        </div>
    </form>

    <script>
        const mutasiItems = [];

        function renderMutasiRows() {
            const tbody = document.querySelector('#tblMutasiItems tbody');
            tbody.innerHTML = '';

            if (mutasiItems.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center py-4 text-secondary">
                            Belum ada barang dipilih. Silakan cari di atas.
                        </td>
                    </tr>
                `;
                return;
            }

            mutasiItems.forEach((item, index) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td data-label="Barang">
                        <strong>${item.kode_barang}</strong><br>
                        <span class="text-secondary">${item.nama_barang}</span>
                        <input type="hidden" name="items[${index}][kode_barang]" value="${item.kode_barang}">
                    </td>
                    <td data-label="Maks. Stok Asal">${item.stok} ${item.satuan}</td>
                    <td data-label="Jumlah Mutasi">
                        <div style="display:flex; align-items:center; gap:4px;">
                            <input type="number" name="items[${index}][jumlah]" value="${item.jumlah}" min="1" max="${item.stok}" inputmode="numeric" class="form-control form-control-sm text-center" style="width:70px;" onchange="updateQty(${index}, this.value)" required>
                            <span class="text-secondary">${item.satuan}</span>
                        </div>
                    </td>
                    <td>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(${index})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function addItem(item) {
            const exist = mutasiItems.find(i => i.kode_barang === item.kode_barang);
            if (exist) {
                if (exist.jumlah < item.stok) {
                    exist.jumlah += 1;
                } else {
                    showToast(`Stok maksimal adalah ${item.stok}!`, 'warning');
                }
            } else {
                mutasiItems.push({
                    kode_barang: item.kode_barang,
                    nama_barang: item.nama_barang,
                    satuan: item.satuan,
                    stok: parseInt(item.stok),
                    jumlah: 1
                });
            }
            renderMutasiRows();
        }

        function updateQty(index, val) {
            const qty = parseInt(val);
            const item = mutasiItems[index];
            if (qty > item.stok) {
                showToast(`Stok tidak mencukupi! Maksimal: ${item.stok}`, 'warning');
                item.jumlah = item.stok;
            } else if (qty <= 0 || isNaN(qty)) {
                item.jumlah = 1;
            } else {
                item.jumlah = qty;
            }
            renderMutasiRows();
        }

        function removeItem(index) {
            mutasiItems.splice(index, 1);
            renderMutasiRows();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const searchInp = document.getElementById('mutasiSearch');
            const suggestBox = document.getElementById('mutasiSuggestions');
            const dariSel = document.getElementById('selDari');

            // Handle location changes -> wipe items list as stock contexts change!
            dariSel.addEventListener('change', () => {
                mutasiItems.length = 0;
                renderMutasiRows();
            });

            searchInp.addEventListener('input', async (e) => {
                const query = e.target.value;
                const lokId = dariSel.value;

                if (!query || query.length < 1) {
                    suggestBox.innerHTML = '';
                    suggestBox.style.display = 'none';
                    return;
                }

                try {
                    const res = await fetch(`pages/mutasi.php?ajax_search_mutasi=1&lok_id=${lokId}&q=${encodeURIComponent(query)}`);
                    const data = await res.json();
                    
                    suggestBox.innerHTML = '';
                    if (data.length === 0) {
                        suggestBox.style.display = 'none';
                        return;
                    }

                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item';
                        div.innerHTML = `
                            <div><strong>${item.kode_barang}</strong> - ${item.nama_barang}</div>
                            <div>Stok: ${item.stok} ${item.satuan}</div>
                        `;
                        div.addEventListener('click', () => {
                            addItem(item);
                            searchInp.value = '';
                            suggestBox.innerHTML = '';
                            suggestBox.style.display = 'none';
                        });
                        suggestBox.appendChild(div);
                    });

                    suggestBox.style.display = 'block';
                } catch (error) {
                    console.error('Error fetching mutation items:', error);
                }
            });

            // Handle auto-load from Stock page redirect
            const autoKode = "<?= htmlspecialchars($auto_kode) ?>";
            if (autoKode) {
                // Fetch the item details automatically
                const fetchAuto = async () => {
                    const res = await fetch(`pages/mutasi.php?ajax_search_mutasi=1&lok_id=${dariSel.value}&q=${encodeURIComponent(autoKode)}`);
                    const data = await res.json();
                    const found = data.find(i => i.kode_barang === autoKode);
                    if (found) {
                        addItem(found);
                    }
                };
                fetchAuto();
            }

            // Form validation
            document.getElementById('mutasiForm').addEventListener('submit', (e) => {
                if (mutasiItems.length === 0) {
                    e.preventDefault();
                    showToast('Silakan tambahkan barang yang ingin dimutasikan!', 'warning');
                }
            });
        });
    </script>

<?php else: ?>
    <!-- Listing past mutations -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Riwayat Mutasi Barang</h1>
            <p class="text-secondary">Daftar perpindahan stok antar lokasi</p>
        </div>
        <div>
            <a href="index.php?page=mutasi&action=new" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Mutasi Baru
            </a>
        </div>
    </div>

    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">Daftar Mutasi Stok</h3>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>No Mutasi</th>
                        <th>Tanggal</th>
                        <th>Asal</th>
                        <th>Tujuan</th>
                        <th>Keterangan</th>
                        <th style="width: 100px; text-align: center;">Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $res = $koneksi->query("SELECT * FROM mutasi ORDER BY created_at DESC");
                    if ($res && $res->num_rows > 0):
                        while ($r = $res->fetch_assoc()):
                    ?>
                        <tr>
                            <td data-label="No Mutasi"><strong><?= htmlspecialchars($r['no_mutasi']) ?></strong></td>
                            <td data-label="Tanggal"><?= date('d/m/Y', strtotime($r['tanggal'])) ?></td>
                            <td data-label="Asal"><span class="badge badge-warning"><?= htmlspecialchars($lokasi_list[$r['dari_lokasi_id']]['nama_lokasi']) ?></span></td>
                            <td data-label="Tujuan"><span class="badge badge-success"><?= htmlspecialchars($lokasi_list[$r['ke_lokasi_id']]['nama_lokasi']) ?></span></td>
                            <td data-label="Keterangan"><?= htmlspecialchars($r['keterangan'] ? $r['keterangan'] : '-') ?></td>
                            <td class="text-center">
                                <a href="index.php?page=mutasi&detail_id=<?= urlencode($r['no_mutasi']) ?>" class="btn btn-secondary btn-sm">
                                    Detail
                                </a>
                            </td>
                        </tr>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">Belum ada riwayat mutasi stok.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

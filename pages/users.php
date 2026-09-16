<?php
if (!defined('host')) { exit; }

$alert = '';

// Block non-admin users
if ($_SESSION['level'] !== 'admin') {
    echo "<div class='content-card' style='text-align: center; padding: 40px;'>
        <h2 style='color:#ef4444; margin-bottom: 12px;'>Akses Ditolak</h2>
        <p class='text-secondary'>Hanya Administrator Utama yang dapat mengelola manajemen pengguna.</p>
    </div>";
    exit;
}

// Handle CRUD operations for Users
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'add' || $action === 'edit') {
        $username = trim($_POST['username']);
        $nama_lengkap = trim($_POST['nama_lengkap']);
        $level = $_POST['level'];
        $password = $_POST['password'];
        
        // Privileges (1 or 0)
        $akses_barang = isset($_POST['akses_barang']) ? 1 : 0;
        $akses_faktur = isset($_POST['akses_faktur']) ? 1 : 0;
        $akses_mutasi = isset($_POST['akses_mutasi']) ? 1 : 0;
        $akses_penjualan = isset($_POST['akses_penjualan']) ? 1 : 0;
        $akses_pelanggan = isset($_POST['akses_pelanggan']) ? 1 : 0;
        $akses_keuangan = isset($_POST['akses_keuangan']) ? 1 : 0;
        $akses_laporan = isset($_POST['akses_laporan']) ? 1 : 0;
        $akses_pengaturan = isset($_POST['akses_pengaturan']) ? 1 : 0;

        if (!empty($username) && !empty($nama_lengkap)) {
            if ($action === 'add') {
                if (empty($password)) {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Password wajib diisi untuk pengguna baru!', 'warning'));</script>";
                } else {
                    // Check duplicate
                    $chk = $koneksi->prepare("SELECT id FROM users WHERE username = ?");
                    $chk->bind_param("s", $username);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Username sudah digunakan!', 'danger'));</script>";
                    } else {
                        $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $koneksi->prepare("
                            INSERT INTO users (username, password, nama_lengkap, level, akses_barang, akses_faktur, akses_mutasi, akses_penjualan, akses_pelanggan, akses_keuangan, akses_laporan, akses_pengaturan) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("ssssiiiiiiii", $username, $pass_hash, $nama_lengkap, $level, $akses_barang, $akses_faktur, $akses_mutasi, $akses_penjualan, $akses_pelanggan, $akses_keuangan, $akses_laporan, $akses_pengaturan);
                        if ($stmt->execute()) {
                            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Pengguna baru berhasil ditambahkan!', 'success'));</script>";
                        } else {
                            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menambahkan pengguna!', 'danger'));</script>";
                        }
                        $stmt->close();
                    }
                    $chk->close();
                }
            } else {
                $id = (int)$_POST['id'];
                
                // If password is changed, hash it
                if (!empty($password)) {
                    $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $koneksi->prepare("
                        UPDATE users SET username = ?, password = ?, nama_lengkap = ?, level = ?, akses_barang = ?, akses_faktur = ?, akses_mutasi = ?, akses_penjualan = ?, akses_pelanggan = ?, akses_keuangan = ?, akses_laporan = ?, akses_pengaturan = ? 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ssssiiiiiiiii", $username, $pass_hash, $nama_lengkap, $level, $akses_barang, $akses_faktur, $akses_mutasi, $akses_penjualan, $akses_pelanggan, $akses_keuangan, $akses_laporan, $akses_pengaturan, $id);
                } else {
                    $stmt = $koneksi->prepare("
                        UPDATE users SET username = ?, nama_lengkap = ?, level = ?, akses_barang = ?, akses_faktur = ?, akses_mutasi = ?, akses_penjualan = ?, akses_pelanggan = ?, akses_keuangan = ?, akses_laporan = ?, akses_pengaturan = ? 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("sssiiiiiiiiii", $username, $nama_lengkap, $level, $akses_barang, $akses_faktur, $akses_mutasi, $akses_penjualan, $akses_pelanggan, $akses_keuangan, $akses_laporan, $akses_pengaturan, $id);
                }

                if ($stmt->execute()) {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Data pengguna berhasil diperbarui!', 'success'));</script>";
                    
                    // If updating current logged in user, refresh session access flags instantly!
                    if ($id === (int)$_SESSION['user_id']) {
                        $_SESSION['nama_lengkap'] = $nama_lengkap;
                        $_SESSION['username'] = $username;
                        $_SESSION['level'] = $level;
                        $_SESSION['akses_barang'] = $akses_barang;
                        $_SESSION['akses_faktur'] = $akses_faktur;
                        $_SESSION['akses_mutasi'] = $akses_mutasi;
                        $_SESSION['akses_penjualan'] = $akses_penjualan;
                        $_SESSION['akses_pelanggan'] = $akses_pelanggan;
                        $_SESSION['akses_keuangan'] = $akses_keuangan;
                        $_SESSION['akses_laporan'] = $akses_laporan;
                        $_SESSION['akses_pengaturan'] = $akses_pengaturan;
                    }
                } else {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal memperbarui pengguna!', 'danger'));</script>";
                }
                $stmt->close();
            }
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Username dan nama lengkap wajib diisi!', 'warning'));</script>";
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === (int)$_SESSION['user_id']) {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Aksi ditolak! Anda tidak bisa menghapus akun Anda sendiri.', 'danger'));</script>";
        } else {
            $stmt = $koneksi->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Pengguna berhasil dihapus!', 'success'));</script>";
            } else {
                $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal menghapus pengguna!', 'danger'));</script>";
            }
            $stmt->close();
        }
    }
}
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Manajemen User & Hak Akses</h1>
        <p class="text-secondary">Kelola daftar pengguna aplikasi berserta batasan menunya</p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="openAddModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Tambah User
        </button>
    </div>
</div>

<div class="content-card">
    <div class="card-header">
        <h3 class="card-title">Daftar Pengguna</h3>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Nama Lengkap</th>
                    <th>Level</th>
                    <th>Hak Akses Menu</th>
                    <th style="width: 150px; text-align: center;">Tindakan</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $res = $koneksi->query("SELECT * FROM users ORDER BY level ASC, id DESC");
                if ($res && $res->num_rows > 0):
                    while ($r = $res->fetch_assoc()):
                ?>
                    <tr>
                        <td data-label="Username"><strong><?= htmlspecialchars($r['username']) ?></strong></td>
                        <td data-label="Nama Lengkap"><?= htmlspecialchars($r['nama_lengkap']) ?></td>
                        <td data-label="Level">
                            <span class="badge <?= $r['level'] === 'admin' ? 'badge-primary' : 'badge-secondary' ?>">
                                <?= htmlspecialchars($r['level']) ?>
                            </span>
                        </td>
                        <td data-label="Hak Akses Menu">
                            <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                <?= $r['akses_barang'] ? '<span class="badge badge-success">Barang</span>' : '' ?>
                                <?= $r['akses_faktur'] ? '<span class="badge badge-success">Faktur</span>' : '' ?>
                                <?= $r['akses_mutasi'] ? '<span class="badge badge-success">Mutasi</span>' : '' ?>
                                <?= $r['akses_penjualan'] ? '<span class="badge badge-success">Kasir</span>' : '' ?>
                                <?= $r['akses_pelanggan'] ? '<span class="badge badge-success">Pelanggan</span>' : '' ?>
                                <?= $r['akses_keuangan'] ? '<span class="badge badge-success">Keuangan</span>' : '' ?>
                                <?= $r['akses_laporan'] ? '<span class="badge badge-success">Laporan</span>' : '' ?>
                                <?= $r['akses_pengaturan'] ? '<span class="badge badge-success">Pengaturan</span>' : '' ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <div style="display: flex; gap: 4px; justify-content: center;">
                                <button class="btn btn-warning btn-sm" onclick='openEditModal(<?= json_encode($r) ?>)'>
                                    Edit
                                </button>
                                <?php if ($r['id'] !== (int)$_SESSION['user_id']): ?>
                                    <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus user ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php 
                    endwhile;
                else:
                ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary py-4">Belum ada pengguna terdaftar.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah / Edit User -->
<div id="modalUser" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Tambah Pengguna Baru</h3>
            <button class="btn-close" onclick="closeModal('modalUser')">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="userId" value="">
            
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Username <span style="color:red;">*</span></label>
                    <input type="text" name="username" id="inpUsername" class="form-control" placeholder="Contoh: kasir1" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label">Nama Lengkap Pengguna <span style="color:red;">*</span></label>
                    <input type="text" name="nama_lengkap" id="inpNama" class="form-control" placeholder="Contoh: Rian Dwi" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Level / Role <span style="color:red;">*</span></label>
                    <select name="level" id="inpLevel" class="form-control" required>
                        <option value="kasir">Kasir (Staf Penjualan)</option>
                        <option value="admin">Admin (Akses Penuh)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Password <span id="lblPassRequired" style="color:red;">*</span></label>
                    <input type="password" name="password" id="inpPass" class="form-control" placeholder="Isi untuk membuat/mengganti password">
                    <small style="font-size:11px; color:var(--text-secondary);" id="lblPassNote"></small>
                </div>

                <div class="form-group" style="margin-top:20px;">
                    <label class="form-label">Hak Akses Menu:</label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                        <div>
                            <input type="checkbox" name="akses_barang" id="chkBarang" value="1">
                            <label for="chkBarang" style="font-size:14px; margin-left: 6px; cursor:pointer;">Master Barang</label>
                        </div>
                        <div>
                            <input type="checkbox" name="akses_faktur" id="chkFaktur" value="1">
                            <label for="chkFaktur" style="font-size:14px; margin-left: 6px; cursor:pointer;">Faktur Pembelian</label>
                        </div>
                        <div>
                            <input type="checkbox" name="akses_mutasi" id="chkMutasi" value="1">
                            <label for="chkMutasi" style="font-size:14px; margin-left: 6px; cursor:pointer;">Mutasi Barang</label>
                        </div>
                        <div>
                            <input type="checkbox" name="akses_penjualan" id="chkPenjualan" value="1">
                            <label for="chkPenjualan" style="font-size:14px; margin-left: 6px; cursor:pointer;">Kasir (Penjualan)</label>
                        </div>
                        <div>
                            <input type="checkbox" name="akses_pelanggan" id="chkPelanggan" value="1">
                            <label for="chkPelanggan" style="font-size:14px; margin-left: 6px; cursor:pointer;">Pelanggan & Kredit</label>
                        </div>
                        <div>
                            <input type="checkbox" name="akses_keuangan" id="chkKeuangan" value="1">
                            <label for="chkKeuangan" style="font-size:14px; margin-left: 6px; cursor:pointer;">Kas & Keuangan</label>
                        </div>
                        <div>
                            <input type="checkbox" name="akses_laporan" id="chkLaporan" value="1">
                            <label for="chkLaporan" style="font-size:14px; margin-left: 6px; cursor:pointer;">Laporan Keuangan</label>
                        </div>
                        <div>
                            <input type="checkbox" name="akses_pengaturan" id="chkPengaturan" value="1">
                            <label for="chkPengaturan" style="font-size:14px; margin-left: 6px; cursor:pointer;">Pengaturan Toko</label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('modalUser')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').innerText = 'Tambah Pengguna Baru';
    document.getElementById('formAction').value = 'add';
    document.getElementById('userId').value = '';
    document.getElementById('inpUsername').value = '';
    document.getElementById('inpUsername').readOnly = false;
    document.getElementById('inpNama').value = '';
    document.getElementById('inpLevel').value = 'kasir';
    document.getElementById('inpPass').value = '';
    document.getElementById('inpPass').required = true;
    document.getElementById('lblPassRequired').style.display = 'inline';
    document.getElementById('lblPassNote').innerText = '';

    // Check all checkboxes by default for kasir or admin
    document.getElementById('chkBarang').checked = true;
    document.getElementById('chkFaktur').checked = true;
    document.getElementById('chkMutasi').checked = true;
    document.getElementById('chkPenjualan').checked = true;
    document.getElementById('chkPelanggan').checked = true;
    document.getElementById('chkKeuangan').checked = true;
    document.getElementById('chkLaporan').checked = true;
    document.getElementById('chkPengaturan').checked = false;

    openModal('modalUser');
}

function openEditModal(data) {
    document.getElementById('modalTitle').innerText = 'Edit Data Pengguna';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('userId').value = data.id;
    document.getElementById('inpUsername').value = data.username;
    document.getElementById('inpUsername').readOnly = true;
    document.getElementById('inpNama').value = data.nama_lengkap;
    document.getElementById('inpLevel').value = data.level;
    document.getElementById('inpPass').value = '';
    document.getElementById('inpPass').required = false;
    document.getElementById('lblPassRequired').style.display = 'none';
    document.getElementById('lblPassNote').innerText = 'Kosongkan jika tidak ingin mengganti password';

    // Set privileges
    document.getElementById('chkBarang').checked = parseInt(data.akses_barang) === 1;
    document.getElementById('chkFaktur').checked = parseInt(data.akses_faktur) === 1;
    document.getElementById('chkMutasi').checked = parseInt(data.akses_mutasi) === 1;
    document.getElementById('chkPenjualan').checked = parseInt(data.akses_penjualan) === 1;
    document.getElementById('chkPelanggan').checked = parseInt(data.akses_pelanggan) === 1;
    document.getElementById('chkKeuangan').checked = parseInt(data.akses_keuangan) === 1;
    document.getElementById('chkLaporan').checked = parseInt(data.akses_laporan) === 1;
    document.getElementById('chkPengaturan').checked = parseInt(data.akses_pengaturan) === 1;

    openModal('modalUser');
}
</script>

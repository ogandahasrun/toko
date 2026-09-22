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
        
        // Simplified Privileges (1 or 0)
        $akses_pelanggan = isset($_POST['akses_pelanggan']) ? 1 : 0;
        $akses_penjualan = isset($_POST['akses_penjualan']) ? 1 : 0;
        $akses_cicilan = isset($_POST['akses_cicilan']) ? 1 : 0;
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
                            INSERT INTO users (username, password, nama_lengkap, level, akses_pelanggan, akses_penjualan, akses_cicilan, akses_laporan, akses_pengaturan) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->bind_param("ssssiiiii", $username, $pass_hash, $nama_lengkap, $level, $akses_pelanggan, $akses_penjualan, $akses_cicilan, $akses_laporan, $akses_pengaturan);
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
                
                if (!empty($password)) {
                    $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $koneksi->prepare("
                        UPDATE users SET username = ?, password = ?, nama_lengkap = ?, level = ?, akses_pelanggan = ?, akses_penjualan = ?, akses_cicilan = ?, akses_laporan = ?, akses_pengaturan = ? 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ssssiiiiii", $username, $pass_hash, $nama_lengkap, $level, $akses_pelanggan, $akses_penjualan, $akses_cicilan, $akses_laporan, $akses_pengaturan, $id);
                } else {
                    $stmt = $koneksi->prepare("
                        UPDATE users SET username = ?, nama_lengkap = ?, level = ?, akses_pelanggan = ?, akses_penjualan = ?, akses_cicilan = ?, akses_laporan = ?, akses_pengaturan = ? 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("sssiiiiiii", $username, $nama_lengkap, $level, $akses_pelanggan, $akses_penjualan, $akses_cicilan, $akses_laporan, $akses_pengaturan, $id);
                }

                if ($stmt->execute()) {
                    $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Data pengguna berhasil diperbarui!', 'success'));</script>";
                    
                    if ($id === (int)$_SESSION['user_id']) {
                        $_SESSION['nama_lengkap'] = $nama_lengkap;
                        $_SESSION['username'] = $username;
                        $_SESSION['level'] = $level;
                        $_SESSION['akses_pelanggan'] = $akses_pelanggan;
                        $_SESSION['akses_penjualan'] = $akses_penjualan;
                        $_SESSION['akses_cicilan'] = $akses_cicilan;
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
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Anda tidak dapat menghapus akun Anda sendiri yang sedang aktif!', 'danger'));</script>";
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

$users_list = $koneksi->query("SELECT * FROM users ORDER BY level ASC, username ASC");
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Manajemen User</h1>
        <p class="text-secondary">Pengaturan pengguna sistem dan hak akses fitur</p>
    </div>
    <div>
        <button class="btn btn-primary btn-lg" onclick="openAddUserModal()">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Tambah User Baru
        </button>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daftar Pengguna Sistem</h3>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Nama Lengkap</th>
                    <th>Role Level</th>
                    <th>Hak Akses Modul</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users_list && $users_list->num_rows > 0): ?>
                    <?php while ($u = $users_list->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                            <td><?= htmlspecialchars($u['nama_lengkap']) ?></td>
                            <td>
                                <span class="badge <?= $u['level'] === 'admin' ? 'badge-primary' : 'badge-light' ?>">
                                    <?= strtoupper($u['level']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                    <?php if ($u['akses_pelanggan'] ?? 1): ?><span class="badge badge-sm badge-success">Pelanggan</span><?php endif; ?>
                                    <?php if ($u['akses_penjualan'] ?? 1): ?><span class="badge badge-sm badge-success">Penjualan</span><?php endif; ?>
                                    <?php if ($u['akses_cicilan'] ?? 1): ?><span class="badge badge-sm badge-success">Cicilan</span><?php endif; ?>
                                    <?php if ($u['akses_laporan'] ?? 1): ?><span class="badge badge-sm badge-success">Laporan</span><?php endif; ?>
                                    <?php if ($u['akses_pengaturan'] ?? 1): ?><span class="badge badge-sm badge-success">Pengaturan</span><?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button class="btn btn-sm btn-light" onclick='openEditUserModal(<?= json_encode($u) ?>)'>
                                    Edit
                                </button>
                                <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDeleteUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')">
                                        Hapus
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">Belum ada user.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Form Add/Edit User -->
<div id="modalUser" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalUserTitle">Tambah Pengguna Baru</h3>
            <button class="modal-close" onclick="closeUserModal()">&times;</button>
        </div>
        <form method="POST" action="index.php?page=users">
            <input type="hidden" name="action" id="user_action" value="add">
            <input type="hidden" name="id" id="user_id" value="">

            <div class="modal-body">
                <div class="form-group mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" id="u_username" class="form-control" required placeholder="Contoh: kasir1">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                    <input type="text" name="nama_lengkap" id="u_nama_lengkap" class="form-control" required placeholder="Nama lengkap staf">
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Level Role</label>
                    <select name="level" id="u_level" class="form-control">
                        <option value="kasir">Kasir</option>
                        <option value="admin">Administrator (Full Control)</option>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label">Password <span id="pass_req_star" class="text-danger">*</span></label>
                    <input type="password" name="password" id="u_password" class="form-control" placeholder="Kosongkan jika tidak ingin mengubah password">
                </div>

                <hr style="margin: 16px 0; border-color: #f1f5f9;">

                <label class="form-label" style="font-weight: 700;">Hak Akses Modul:</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px;">
                        <input type="checkbox" name="akses_pelanggan" id="u_akses_pelanggan" value="1" checked> Data Pelanggan
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px;">
                        <input type="checkbox" name="akses_penjualan" id="u_akses_penjualan" value="1" checked> Transaksi Penjualan
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px;">
                        <input type="checkbox" name="akses_cicilan" id="u_akses_cicilan" value="1" checked> Pembayaran Cicilan
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px;">
                        <input type="checkbox" name="akses_laporan" id="u_akses_laporan" value="1" checked> Laporan Keuangan
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px;">
                        <input type="checkbox" name="akses_pengaturan" id="u_akses_pengaturan" value="1" checked> Pengaturan Toko
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" onclick="closeUserModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="user_submit_btn">Simpan User</button>
            </div>
        </form>
    </div>
</div>

<form id="formDeleteUser" method="POST" action="index.php?page=users" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_user_id">
</form>

<script>
function openAddUserModal() {
    document.getElementById('modalUserTitle').innerText = 'Tambah Pengguna Baru';
    document.getElementById('user_action').value = 'add';
    document.getElementById('user_id').value = '';
    document.getElementById('u_username').value = '';
    document.getElementById('u_nama_lengkap').value = '';
    document.getElementById('u_level').value = 'kasir';
    document.getElementById('u_password').value = '';
    document.getElementById('pass_req_star').style.display = 'inline';

    document.getElementById('u_akses_pelanggan').checked = true;
    document.getElementById('u_akses_penjualan').checked = true;
    document.getElementById('u_akses_cicilan').checked = true;
    document.getElementById('u_akses_laporan').checked = true;
    document.getElementById('u_akses_pengaturan').checked = true;

    const modal = document.getElementById('modalUser');
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
}

function openEditUserModal(u) {
    document.getElementById('modalUserTitle').innerText = 'Edit Data Pengguna';
    document.getElementById('user_action').value = 'edit';
    document.getElementById('user_id').value = u.id;
    document.getElementById('u_username').value = u.username;
    document.getElementById('u_nama_lengkap').value = u.nama_lengkap;
    document.getElementById('u_level').value = u.level;
    document.getElementById('u_password').value = '';
    document.getElementById('pass_req_star').style.display = 'none';

    document.getElementById('u_akses_pelanggan').checked = parseInt(u.akses_pelanggan ?? 1) === 1;
    document.getElementById('u_akses_penjualan').checked = parseInt(u.akses_penjualan ?? 1) === 1;
    document.getElementById('u_akses_cicilan').checked = parseInt(u.akses_cicilan ?? 1) === 1;
    document.getElementById('u_akses_laporan').checked = parseInt(u.akses_laporan ?? 1) === 1;
    document.getElementById('u_akses_pengaturan').checked = parseInt(u.akses_pengaturan ?? 1) === 1;

    const modal = document.getElementById('modalUser');
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
}

function closeUserModal() {
    const modal = document.getElementById('modalUser');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function confirmDeleteUser(id, username) {
    if (confirm('Apakah Anda yakin ingin menghapus user "' + username + '"?')) {
        document.getElementById('delete_user_id').value = id;
        document.getElementById('formDeleteUser').submit();
    }
}
</script>

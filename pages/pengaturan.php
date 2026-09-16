<?php
if (!defined('host')) { exit; }

$alert = '';

// Handle POST save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_toko = trim($_POST['nama_toko']);
    $alamat = trim($_POST['alamat']);
    $cp = trim($_POST['cp']);

    if (!empty($nama_toko)) {
        $stmt = $koneksi->prepare("UPDATE pengaturan SET nama_toko = ?, alamat = ?, cp = ? WHERE id = 1");
        $stmt->bind_param("sss", $nama_toko, $alamat, $cp);
        if ($stmt->execute()) {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Pengaturan toko berhasil diperbarui!', 'success'));</script>";
            // Update current page values dynamically
            $shop_name = $nama_toko;
            $shop_address = $alamat;
            $shop_cp = $cp;
        } else {
            $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Gagal memperbarui pengaturan!', 'danger'));</script>";
        }
        $stmt->close();
    } else {
        $alert = "<script>window.addEventListener('DOMContentLoaded', () => showToast('Nama toko tidak boleh kosong!', 'warning'));</script>";
    }
}

// Reload setting
$resSetting = $koneksi->query("SELECT * FROM pengaturan WHERE id = 1");
$sett = $resSetting->fetch_assoc();
?>

<?= $alert ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pengaturan Toko</h1>
        <p class="text-secondary">Atur profil nama toko, alamat, dan nomor kontak yang tertera di nota</p>
    </div>
</div>

<div style="max-width: 600px;">
    <div class="content-card">
        <div class="card-header">
            <h3 class="card-title">Profil Toko</h3>
        </div>

        <form action="" method="POST">
            <div class="form-group">
                <label class="form-label">Nama Toko <span style="color:red;">*</span></label>
                <input type="text" name="nama_toko" class="form-control" value="<?= htmlspecialchars($sett['nama_toko']) ?>" placeholder="Contoh: Toko Barokah Jaya" required>
            </div>

            <div class="form-group">
                <label class="form-label">Alamat Lengkap Toko</label>
                <textarea name="alamat" class="form-control" rows="3" placeholder="Contoh: Jl. Ahmad Yani No. 25, Jakarta Selatan"><?= htmlspecialchars($sett['alamat']) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Nomor Kontak Toko (CP)</label>
                <input type="text" name="cp" class="form-control" value="<?= htmlspecialchars($sett['cp']) ?>" placeholder="Contoh: 0812-3456-7890">
            </div>

            <br>
            <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center;">
                Simpan Perubahan
            </button>
        </form>
    </div>
</div>

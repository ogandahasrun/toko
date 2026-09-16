<?php
session_start();
define('host', true); // Definisikan konstanta host untuk keamanan sub-halaman
require_once 'koneksi.php';

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Refresh session access privileges dynamically from the database to avoid logout lag
$userId = (int)$_SESSION['user_id'];
$resUser = $koneksi->query("SELECT * FROM users WHERE id = $userId");
if ($resUser && $resUser->num_rows > 0) {
    $u = $resUser->fetch_assoc();
    $_SESSION['nama_lengkap'] = $u['nama_lengkap'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['level'] = $u['level'];
    $_SESSION['akses_barang'] = (int)$u['akses_barang'];
    $_SESSION['akses_faktur'] = (int)$u['akses_faktur'];
    $_SESSION['akses_mutasi'] = (int)$u['akses_mutasi'];
    $_SESSION['akses_penjualan'] = (int)$u['akses_penjualan'];
    $_SESSION['akses_pelanggan'] = (int)$u['akses_pelanggan'];
    $_SESSION['akses_keuangan'] = (int)$u['akses_keuangan'];
    $_SESSION['akses_laporan'] = (int)$u['akses_laporan'];
    $_SESSION['akses_pengaturan'] = (int)$u['akses_pengaturan'];
}

// Fetch general setting
$shop_name = "Toko Kita";
$shop_address = "Alamat Toko";
$shop_cp = "08123456789";
$resSetting = $koneksi->query("SELECT * FROM pengaturan WHERE id = 1");
if ($resSetting && $resSetting->num_rows > 0) {
    $rowSetting = $resSetting->fetch_assoc();
    $shop_name = $rowSetting['nama_toko'];
    $shop_address = $rowSetting['alamat'];
    $shop_cp = $rowSetting['cp'];
}

// AJAX Search handler for auto-complete in POS and Invoice forms
if (isset($_GET['ajax_search_barang'])) {
    header('Content-Type: application/json');
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $exact = isset($_GET['exact']) ? (int)$_GET['exact'] : 0;
    $is_purchase = isset($_GET['is_purchase']) ? (int)$_GET['is_purchase'] : 0;
    
    // Read the dynamic location ID passed from the client, fallback if not specified
    $lokasi_id = isset($_GET['lokasi_id']) ? (int)$_GET['lokasi_id'] : ($is_purchase ? 1 : 2);

    if ($exact) {
        $stmt = $koneksi->prepare("
            SELECT b.kode_barang, b.nama_barang, b.satuan, b.kategori,
                   COALESCE(bd.harga_beli, 0) as harga_beli, 
                   COALESCE(bd.harga_jual, 0) as harga_jual,
                   COALESCE(gb.stok, 0) as stok
            FROM barang b
            LEFT JOIN barang_detail bd ON b.kode_barang = bd.kode_barang
            LEFT JOIN gudang_barang gb ON b.kode_barang = gb.kode_barang AND gb.lokasi_id = ?
            WHERE b.kode_barang = ?
        ");
        $stmt->bind_param("is", $lokasi_id, $q);
    } else {
        $search = "%" . $q . "%";
        $stmt = $koneksi->prepare("
            SELECT b.kode_barang, b.nama_barang, b.satuan, b.kategori,
                   COALESCE(bd.harga_beli, 0) as harga_beli, 
                   COALESCE(bd.harga_jual, 0) as harga_jual,
                   COALESCE(gb.stok, 0) as stok
            FROM barang b
            LEFT JOIN barang_detail bd ON b.kode_barang = bd.kode_barang
            LEFT JOIN gudang_barang gb ON b.kode_barang = gb.kode_barang AND gb.lokasi_id = ?
            WHERE b.kode_barang LIKE ? OR b.nama_barang LIKE ? OR b.kategori LIKE ?
            LIMIT 10
        ");
        $stmt->bind_param("isss", $lokasi_id, $search, $search, $search);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}

// AJAX Payment history handler to fetch installment records in JSON format cleanly
if (isset($_GET['ajax_payment_history'])) {
    header('Content-Type: application/json');
    $no_pen = isset($_GET['no_penjualan']) ? trim($_GET['no_penjualan']) : (isset($_GET['pen_id']) ? trim($_GET['pen_id']) : '');
    $esc_pen = $koneksi->real_escape_string($no_pen);
    $resH = $koneksi->query("SELECT tanggal, jumlah_bayar, keterangan FROM pembayaran_kredit WHERE no_penjualan = '$esc_pen' ORDER BY id ASC");
    $history = [];
    if ($resH) {
        while ($h = $resH->fetch_assoc()) {
            $history[] = $h;
        }
    }
    echo json_encode($history);
    exit;
}

// AJAX Sale Items handler to fetch item list of a specific sale in JSON format cleanly
if (isset($_GET['ajax_sale_items'])) {
    header('Content-Type: application/json');
    $no_pen = isset($_GET['no_penjualan']) ? trim($_GET['no_penjualan']) : (isset($_GET['pen_id']) ? trim($_GET['pen_id']) : '');
    $esc_pen = $koneksi->real_escape_string($no_pen);
    $resItems = $koneksi->query("
        SELECT pd.kode_barang, b.nama_barang, pd.jumlah, pd.harga_jual, pd.subtotal, b.satuan 
        FROM penjualan_detail pd 
        JOIN barang b ON pd.kode_barang = b.kode_barang 
        WHERE pd.no_penjualan = '$esc_pen'
    ");
    $items = [];
    if ($resItems) {
        while ($item = $resItems->fetch_assoc()) {
            $items[] = $item;
        }
    }
    echo json_encode($items);
    exit;
}

// Router Page Configuration
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'barang', 'gudang', 'faktur', 'mutasi', 'penjualan', 'pelanggan', 'keuangan', 'laporan', 'pengaturan', 'users'];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Access Control Verification
$has_access = true;
if ($page === 'barang' && !($_SESSION['akses_barang'] ?? 0)) $has_access = false;
if ($page === 'gudang' && !(($_SESSION['akses_barang'] ?? 0) || ($_SESSION['akses_mutasi'] ?? 0) || ($_SESSION['akses_faktur'] ?? 0))) $has_access = false;
if ($page === 'faktur' && !($_SESSION['akses_faktur'] ?? 0)) $has_access = false;
if ($page === 'mutasi' && !($_SESSION['akses_mutasi'] ?? 0)) $has_access = false;
if ($page === 'penjualan' && !($_SESSION['akses_penjualan'] ?? 0)) $has_access = false;
if ($page === 'pelanggan' && !($_SESSION['akses_pelanggan'] ?? 0)) $has_access = false;
if ($page === 'keuangan' && !($_SESSION['akses_keuangan'] ?? 0)) $has_access = false;
if ($page === 'laporan' && !($_SESSION['akses_laporan'] ?? 0)) $has_access = false;
if ($page === 'pengaturan' && !($_SESSION['akses_pengaturan'] ?? 0)) $has_access = false;
if ($page === 'users' && ($_SESSION['level'] ?? '') !== 'admin') $has_access = false;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($shop_name) ?> - POS & Mutasi</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Main Style Sheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Inline CSS for Mobile Menu Bottom Drawer -->
    <style>
        .drawer-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: none;
            align-items: flex-end;
        }
        .drawer-overlay.active {
            display: flex;
        }
        .drawer-content {
            background: #fff;
            width: 100%;
            border-radius: 24px 24px 0 0;
            padding: 24px;
            box-sizing: border-box;
            transform: translateY(100%);
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 -10px 25px -5px rgba(0, 0, 0, 0.1), 0 -8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        .drawer-overlay.active .drawer-content {
            transform: translateY(0);
        }
        .drawer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
        }
        .drawer-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
        }
        .drawer-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding-bottom: 20px;
        }
        .drawer-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 14px 8px;
            border-radius: 16px;
            background: #f8fafc;
            color: #334155;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.2s ease;
            border: 1px solid #f1f5f9;
        }
        .drawer-item:active {
            background: #e2e8f0;
            transform: scale(0.95);
        }
        .drawer-item svg {
            width: 24px;
            height: 24px;
            margin-bottom: 6px;
            color: #4f46e5;
        }
    </style>
</head>
<body>

    <!-- Sidebar Left (Visible on Desktop Screen) -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <?= substr($shop_name, 0, 1) ?>
            </div>
            <div class="sidebar-title">
                <?= htmlspecialchars(strlen($shop_name) > 14 ? substr($shop_name, 0, 12) . '..' : $shop_name) ?>
            </div>
        </div>

        <nav class="sidebar-menu">
            <a href="index.php?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                <span>Dashboard</span>
            </a>

            <?php if ($_SESSION['akses_barang'] ?? 0): ?>
            <a href="index.php?page=barang" class="<?= $page === 'barang' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                <span>Master Barang</span>
            </a>
            <?php endif; ?>

            <?php if (($_SESSION['akses_barang'] ?? 0) || ($_SESSION['akses_mutasi'] ?? 0) || ($_SESSION['akses_faktur'] ?? 0)): ?>
            <a href="index.php?page=gudang" class="<?= $page === 'gudang' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                <span>Gudang & Stok</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_faktur'] ?? 0): ?>
            <a href="index.php?page=faktur" class="<?= $page === 'faktur' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <span>Faktur Pembelian</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_mutasi'] ?? 0): ?>
            <a href="index.php?page=mutasi" class="<?= $page === 'mutasi' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                <span>Mutasi Barang</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_penjualan'] ?? 0): ?>
            <a href="index.php?page=penjualan" class="<?= $page === 'penjualan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span>Kasir (Penjualan)</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_pelanggan'] ?? 0): ?>
            <a href="index.php?page=pelanggan" class="<?= $page === 'pelanggan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Pelanggan & Kredit</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_keuangan'] ?? 0): ?>
            <a href="index.php?page=keuangan" class="<?= $page === 'keuangan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <span>Kas & Keuangan</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_laporan'] ?? 0): ?>
            <a href="index.php?page=laporan" class="<?= $page === 'laporan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                <span>Laporan Keuangan</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_pengaturan'] ?? 0): ?>
            <a href="index.php?page=pengaturan" class="<?= $page === 'pengaturan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                <span>Pengaturan Toko</span>
            </a>
            <?php endif; ?>

            <?php if (($_SESSION['level'] ?? '') === 'admin'): ?>
            <a href="index.php?page=users" class="<?= $page === 'users' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Manajemen User</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-profile">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['username'], 0, 2)) ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></div>
                    <div class="user-role"><?= htmlspecialchars($_SESSION['level']) ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn btn-danger btn-sm btn-logout" style="width: 100%; display: flex; justify-content: center;">
                Keluar
            </a>
        </div>
    </aside>

    <!-- Bottom Navigation Bar (Visible on Mobile Screens) -->
    <nav class="bottom-nav">
        <a href="index.php?page=dashboard" class="bottom-nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            <span>Dashboard</span>
        </a>

        <?php if ($_SESSION['akses_penjualan'] ?? 0): ?>
        <a href="index.php?page=penjualan" class="bottom-nav-item <?= $page === 'penjualan' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            <span>Kasir</span>
        </a>
        <?php endif; ?>

        <?php if (($_SESSION['akses_barang'] ?? 0) || ($_SESSION['akses_mutasi'] ?? 0) || ($_SESSION['akses_faktur'] ?? 0)): ?>
        <a href="index.php?page=gudang" class="bottom-nav-item <?= $page === 'gudang' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            <span>Stok</span>
        </a>
        <?php endif; ?>

        <?php if ($_SESSION['akses_pelanggan'] ?? 0): ?>
        <a href="index.php?page=pelanggan" class="bottom-nav-item <?= $page === 'pelanggan' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            <span>Kredit</span>
        </a>
        <?php endif; ?>

        <a href="#" class="bottom-nav-item" onclick="toggleDrawerMenu(); return false;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
            <span>Lainnya</span>
        </a>
    </nav>

    <!-- Main Content Container -->
    <main class="main-layout">
        <?php 
        if (!$has_access) {
            echo "
            <div class='content-card' style='text-align: center; padding: 40px;'>
                <div style='color: #ef4444; font-size: 48px; margin-bottom: 16px;'>🚫</div>
                <h2 style='margin-bottom: 8px;'>Akses Ditolak</h2>
                <p class='text-secondary'>Anda tidak memiliki hak akses untuk membuka halaman ini. Silakan hubungi Administrator.</p>
                <br>
                <a href='index.php?page=dashboard' class='btn btn-primary'>Kembali ke Dashboard</a>
            </div>";
        } else {
            include "pages/{$page}.php"; 
        }
        ?>
    </main>

    <!-- Drawer Overlay Menu Lainnya (Mobile Only) -->
    <div id="drawerMenu" class="drawer-overlay" onclick="closeDrawerMenu(event)">
        <div class="drawer-content" onclick="event.stopPropagation()">
            <div class="drawer-header">
                <span class="drawer-title">Semua Menu Utama</span>
                <button class="btn-close" onclick="closeDrawerMenu(event)" style="background:none; border:none; padding:4px; cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:#64748b;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="drawer-grid">
                <a href="index.php?page=dashboard" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    <span>Dashboard</span>
                </a>
                
                <?php if ($_SESSION['akses_barang'] ?? 0): ?>
                <a href="index.php?page=barang" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                    <span>Data Barang</span>
                </a>
                <?php endif; ?>

                <?php if (($_SESSION['akses_barang'] ?? 0) || ($_SESSION['akses_mutasi'] ?? 0) || ($_SESSION['akses_faktur'] ?? 0)): ?>
                <a href="index.php?page=gudang" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    <span>Stok Gudang</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_faktur'] ?? 0): ?>
                <a href="index.php?page=faktur" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <span>Faktur Beli</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_mutasi'] ?? 0): ?>
                <a href="index.php?page=mutasi" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
                    <span>Mutasi Stok</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_penjualan'] ?? 0): ?>
                <a href="index.php?page=penjualan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                    <span>Kasir Jual</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_pelanggan'] ?? 0): ?>
                <a href="index.php?page=pelanggan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <span>Kredit & Piutang</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_keuangan'] ?? 0): ?>
                <a href="index.php?page=keuangan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                    <span>Kas & Keuangan</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_laporan'] ?? 0): ?>
                <a href="index.php?page=laporan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    <span>Lap. Keuangan</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_pengaturan'] ?? 0): ?>
                <a href="index.php?page=pengaturan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    <span>Profil Toko</span>
                </a>
                <?php endif; ?>

                <?php if (($_SESSION['level'] ?? '') === 'admin'): ?>
                <a href="index.php?page=users" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <span>User Akses</span>
                </a>
                <?php endif; ?>

                <a href="logout.php" class="drawer-item" style="color: #ef4444;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    <span>Log Keluar</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Common Javascript Scripts -->
    <script src="assets/js/app.js"></script>
    <script>
    function toggleDrawerMenu() {
        const drawer = document.getElementById('drawerMenu');
        drawer.classList.toggle('active');
    }
    function closeDrawerMenu(event) {
        const drawer = document.getElementById('drawerMenu');
        drawer.classList.remove('active');
    }
    </script>
</body>
</html>

<?php
session_start();
define('host', true); // Definisikan konstanta host untuk keamanan sub-halaman
require_once 'koneksi.php';

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Refresh session access privileges dynamically from database
$userId = (int)$_SESSION['user_id'];
$resUser = $koneksi->query("SELECT * FROM users WHERE id = $userId");
if ($resUser && $resUser->num_rows > 0) {
    $u = $resUser->fetch_assoc();
    $_SESSION['nama_lengkap'] = $u['nama_lengkap'];
    $_SESSION['username'] = $u['username'];
    $_SESSION['level'] = $u['level'];
    $_SESSION['akses_penjualan'] = (int)($u['akses_penjualan'] ?? 1);
    $_SESSION['akses_cicilan'] = (int)($u['akses_cicilan'] ?? 1);
    $_SESSION['akses_pelanggan'] = (int)($u['akses_pelanggan'] ?? 1);
    $_SESSION['akses_laporan'] = (int)($u['akses_laporan'] ?? 1);
    $_SESSION['akses_pengaturan'] = (int)($u['akses_pengaturan'] ?? 1);
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

// AJAX Payment history handler
if (isset($_GET['ajax_payment_history'])) {
    header('Content-Type: application/json');
    $no_pen = isset($_GET['no_penjualan']) ? trim($_GET['no_penjualan']) : '';
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

// AJAX Sale Details handler for receipts & modals
if (isset($_GET['ajax_sale_details'])) {
    header('Content-Type: application/json');
    $no_pen = isset($_GET['no_penjualan']) ? trim($_GET['no_penjualan']) : '';
    $esc_pen = $koneksi->real_escape_string($no_pen);
    $resS = $koneksi->query("
        SELECT p.*, pel.nama as nama_pelanggan, pel.no_hp 
        FROM penjualan p 
        LEFT JOIN pelanggan pel ON p.pelanggan_id = pel.id 
        WHERE p.no_penjualan = '$esc_pen'
    ");
    if ($resS && $resS->num_rows > 0) {
        $data = $resS->fetch_assoc();
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Transaksi tidak ditemukan']);
    }
    exit;
}

// AJAX Customer Sales history handler
if (isset($_GET['ajax_customer_sales'])) {
    header('Content-Type: application/json');
    $cid = (int)$_GET['customer_id'];
    $resCS = $koneksi->query("
        SELECT no_penjualan, tanggal, nama_barang, harga_jual, sisa_piutang, tipe_pembayaran 
        FROM penjualan 
        WHERE pelanggan_id = $cid 
        ORDER BY tanggal DESC, created_at DESC
    ");
    $sales = [];
    if ($resCS) {
        while ($s = $resCS->fetch_assoc()) {
            $sales[] = $s;
        }
    }
    echo json_encode($sales);
    exit;
}

// Router Page Configuration
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages = ['dashboard', 'penjualan', 'cicilan', 'pelanggan', 'laporan', 'pengaturan', 'users'];

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

// Access Control Verification
$has_access = true;
if ($page === 'penjualan' && !($_SESSION['akses_penjualan'] ?? 0)) $has_access = false;
if ($page === 'cicilan' && !($_SESSION['akses_cicilan'] ?? 0)) $has_access = false;
if ($page === 'pelanggan' && !($_SESSION['akses_pelanggan'] ?? 0)) $has_access = false;
if ($page === 'laporan' && !($_SESSION['akses_laporan'] ?? 0)) $has_access = false;
if ($page === 'pengaturan' && !($_SESSION['akses_pengaturan'] ?? 0)) $has_access = false;
if ($page === 'users' && ($_SESSION['level'] ?? '') !== 'admin') $has_access = false;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($shop_name) ?> - POS & Piutang Sederhana</title>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Main Style Sheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    
    <!-- Dynamic Responsive Navigation Styles -->
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
            box-shadow: 0 -10px 25px -5px rgba(0, 0, 0, 0.1);
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

    <!-- Sidebar Left (Desktop) -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <?= strtoupper(substr($shop_name, 0, 1)) ?>
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

            <?php if ($_SESSION['akses_pelanggan'] ?? 0): ?>
            <a href="index.php?page=pelanggan" class="<?= $page === 'pelanggan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                <span>Data Pelanggan</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_penjualan'] ?? 0): ?>
            <a href="index.php?page=penjualan" class="<?= $page === 'penjualan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                <span>Transaksi Penjualan</span>
            </a>
            <?php endif; ?>

            <?php if ($_SESSION['akses_cicilan'] ?? 0): ?>
            <a href="index.php?page=cicilan" class="<?= $page === 'cicilan' ? 'active' : '' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                <span>Pembayaran Cicilan</span>
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
            <a href="logout.php" class="btn btn-danger btn-sm btn-logout" style="width: 100%; display: flex; justify-content: center; margin-top: 10px;">
                Keluar
            </a>
        </div>
    </aside>

    <!-- Bottom Navigation Bar (Mobile) -->
    <nav class="bottom-nav">
        <a href="index.php?page=dashboard" class="bottom-nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            <span>Dashboard</span>
        </a>

        <?php if ($_SESSION['akses_penjualan'] ?? 0): ?>
        <a href="index.php?page=penjualan" class="bottom-nav-item <?= $page === 'penjualan' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
            <span>Penjualan</span>
        </a>
        <?php endif; ?>

        <?php if ($_SESSION['akses_cicilan'] ?? 0): ?>
        <a href="index.php?page=cicilan" class="bottom-nav-item <?= $page === 'cicilan' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
            <span>Cicilan</span>
        </a>
        <?php endif; ?>

        <?php if ($_SESSION['akses_pelanggan'] ?? 0): ?>
        <a href="index.php?page=pelanggan" class="bottom-nav-item <?= $page === 'pelanggan' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
            <span>Pelanggan</span>
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

    <!-- Drawer Overlay Menu Lainnya (Mobile) -->
    <div id="drawerMenu" class="drawer-overlay" onclick="closeDrawerMenu(event)">
        <div class="drawer-content" onclick="event.stopPropagation()">
            <div class="drawer-header">
                <span class="drawer-title">Menu Utama</span>
                <button class="btn-close" onclick="closeDrawerMenu(event)" style="background:none; border:none; padding:4px; cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:#64748b;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            <div class="drawer-grid">
                <a href="index.php?page=dashboard" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    <span>Dashboard</span>
                </a>
                
                <?php if ($_SESSION['akses_pelanggan'] ?? 0): ?>
                <a href="index.php?page=pelanggan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
                    <span>Pelanggan</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_penjualan'] ?? 0): ?>
                <a href="index.php?page=penjualan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>
                    <span>Penjualan</span>
                </a>
                <?php endif; ?>

                <?php if ($_SESSION['akses_cicilan'] ?? 0): ?>
                <a href="index.php?page=cicilan" class="drawer-item" onclick="closeDrawerMenu(event)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                    <span>Cicilan</span>
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
                    <span>Pengaturan Toko</span>
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

    <!-- Common JS -->
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

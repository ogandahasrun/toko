<?php
// Konfigurasi MySQL / MariaDB di XAMPP
$host = '103.140.189.19';
$user = 'bpjsfktl';
$pass = 'bpjsfktl';
$db   = 'toko';

// 103.140.189.19

// Membuat koneksi ke database
$koneksi = new mysqli($host, $user, $pass, $db);
if ($koneksi->connect_errno) {
    die("Koneksi database gagal: " . $koneksi->connect_error);
}

// Menyetel encoding karakter
$koneksi->set_charset('utf8mb4');
?>
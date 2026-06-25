<?php
declare(strict_types=1);
// Menyajikan foto plang dari data/uploads/ (di luar web root) lewat PHP,
// sehingga berkas tetap privat & tahan deploy. Akses: /foto.php?f=namafile
require_once __DIR__ . '/../lib/config.php';

$f = (string) ($_GET['f'] ?? '');
// Cegah path traversal: hanya nama berkas sederhana yang diizinkan.
if ($f === '' || basename($f) !== $f || !preg_match('/^[A-Za-z0-9._-]+$/', $f)) {
    http_response_code(400);
    exit('Permintaan tidak valid.');
}

$path = UPLOAD_DIR . '/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit('Foto tidak ditemukan.');
}

$info = @getimagesize($path);
$mime = $info['mime'] ?? 'application/octet-stream';
if (!in_array($mime, array_keys(FOTO_MIME_EXT), true)) {
    http_response_code(415);
    exit('Tipe tidak didukung.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=86400');
readfile($path);

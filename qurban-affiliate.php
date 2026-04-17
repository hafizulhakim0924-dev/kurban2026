<?php
session_start();

header('Content-Type: text/html; charset=utf-8');

/** URL publik halaman qurban (tanpa slash di akhir). Dipakai untuk menampilkan link afiliasi lengkap. */
define('QURBAN_SITE_BASE', 'https://qurban.kolaboraksi.com');

/**
 * true — link mitra tampil: https://qurban.kolaboraksi.com/namaaffiliator (wajib pasang .htaccess di root subdomain).
 * false — fallback tanpa mod_rewrite: https://.../index.php?ref=namaaffiliator
 */
define('QURBAN_AFFILIATE_PRETTY_LINK', true);

/** File entry halaman qurban di hosting Anda (untuk tautan relatif & fallback ?ref=). Samakan target RewriteRule di .htaccess. */
define('QURBAN_ENTRY_SCRIPT', 'index.php');

function qurban_affiliate_public_url(string $slug): string {
    $base = rtrim(QURBAN_SITE_BASE, '/');
    if (defined('QURBAN_AFFILIATE_PRETTY_LINK') && QURBAN_AFFILIATE_PRETTY_LINK) {
        return $base . '/' . $slug;
    }
    $entry = (defined('QURBAN_ENTRY_SCRIPT') && QURBAN_ENTRY_SCRIPT !== '') ? QURBAN_ENTRY_SCRIPT : 'index.php';
    return $base . '/' . $entry . '?ref=' . rawurlencode($slug);
}

/* ——— koneksi database (file standalone; sesuaikan nilai berikut) ——— */
$db_host = 'localhost';
$db_user = 'rank3598_apk';
$db_pass = 'Hakim123!';
$db_name = 'rank3598_apk';

$conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
} else {
    $conn = null;
}

function qurban_affiliate_ensure_table(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS `qurban_affiliates` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nama` VARCHAR(120) NOT NULL,
        `kontak` VARCHAR(80) NOT NULL,
        `kota` VARCHAR(100) NULL,
        `catatan` TEXT NULL,
        `affiliate_slug` VARCHAR(64) NULL DEFAULT NULL,
        `approval_status` VARCHAR(16) NOT NULL DEFAULT 'pending',
        `approved_at` DATETIME NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_affiliate_slug` (`affiliate_slug`),
        KEY `idx_aff_approval` (`approval_status`),
        KEY `idx_aff_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return (bool) mysqli_query($conn, $sql);
}

function qurban_affiliate_ensure_slug_column(mysqli $conn): void {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `qurban_affiliates` LIKE 'affiliate_slug'");
    if ($r && mysqli_num_rows($r) > 0) {
        mysqli_free_result($r);
        return;
    }
    if ($r) {
        mysqli_free_result($r);
    }
    mysqli_query($conn, "ALTER TABLE `qurban_affiliates` ADD COLUMN `affiliate_slug` VARCHAR(64) NULL DEFAULT NULL AFTER `catatan`");
    mysqli_query($conn, "ALTER TABLE `qurban_affiliates` ADD UNIQUE KEY `uq_affiliate_slug` (`affiliate_slug`)");
}

function qurban_affiliate_ensure_approval_columns(mysqli $conn): void {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `qurban_affiliates` LIKE 'approval_status'");
    if (!$r || mysqli_num_rows($r) === 0) {
        if ($r) {
            mysqli_free_result($r);
        }
        mysqli_query($conn, "ALTER TABLE `qurban_affiliates` ADD COLUMN `approval_status` VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER `affiliate_slug`");
        mysqli_query($conn, "ALTER TABLE `qurban_affiliates` ADD KEY `idx_aff_approval` (`approval_status`)");
        mysqli_query($conn, "UPDATE `qurban_affiliates` SET `approval_status` = 'approved' WHERE `affiliate_slug` IS NOT NULL AND `affiliate_slug` != ''");
    } else {
        mysqli_free_result($r);
    }
    mysqli_query($conn, "UPDATE `qurban_affiliates` SET `approval_status` = 'approved' WHERE (`approval_status` = '' OR `approval_status` IS NULL) AND `affiliate_slug` IS NOT NULL AND `affiliate_slug` != ''");

    $r2 = mysqli_query($conn, "SHOW COLUMNS FROM `qurban_affiliates` LIKE 'approved_at'");
    if (!$r2 || mysqli_num_rows($r2) === 0) {
        if ($r2) {
            mysqli_free_result($r2);
        }
        mysqli_query($conn, "ALTER TABLE `qurban_affiliates` ADD COLUMN `approved_at` DATETIME NULL DEFAULT NULL AFTER `approval_status`");
        mysqli_query($conn, "UPDATE `qurban_affiliates` SET `approved_at` = `created_at` WHERE `approval_status` = 'approved' AND `approved_at` IS NULL");
    } else {
        mysqli_free_result($r2);
    }
}

/** @return string error message or '' if valid */
function qurban_affiliate_validate_slug(string $slug): string {
    $slug = strtolower(trim($slug));
    if (strlen($slug) < 3 || strlen($slug) > 40) {
        return 'Panjang tautan 3–40 karakter (huruf kecil, angka, tanda hubung).';
    }
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return 'Hanya huruf kecil, angka, dan tanda hubung (tidak di awal/akhir).';
    }
    $reserved = ['qurban', 'affiliate', 'admin', 'api', 'assets', 'www', 'mail', 'cdn', 'static', 'index', 'kolaboraksi', 'setup', 'pencarian', 'jelajah', 'beranda'];
    if (in_array($slug, $reserved, true)) {
        return 'Kata ini dicadangkan; pilih nama lain.';
    }
    return '';
}

function qurban_affiliate_scan_top_media(): array {
    $root = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'affiliate';
    if (!is_dir($root)) {
        return [];
    }
    $rootLen = strlen($root) + 1;
    $out = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
    } catch (Throwable $e) {
        return [];
    }
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $relInner = str_replace('\\', '/', substr($path, $rootLen));
        $base = $file->getFilename();
        if ($base !== '' && $base[0] === '.') {
            continue;
        }
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $type = 'image';
        } elseif (in_array($ext, ['mp4', 'webm'], true)) {
            $type = 'video';
        } else {
            continue;
        }
        $out[] = ['type' => $type, 'src' => 'assets/affiliate/' . $relInner];
    }
    usort($out, static function ($a, $b) {
        return strnatcasecmp($a['src'], $b['src']);
    });
    return $out;
}

if ($conn) {
    qurban_affiliate_ensure_table($conn);
    qurban_affiliate_ensure_slug_column($conn);
    qurban_affiliate_ensure_approval_columns($conn);
}

$aff_error = '';
$aff_slug_error = '';
$aff_show_slug_modal = false;
$aff_show_pending_modal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_affiliate_slug']) && $conn) {
    $setupId = isset($_SESSION['affiliate_slug_setup_id']) ? (int)$_SESSION['affiliate_slug_setup_id'] : 0;
    $slugInput = strtolower(trim((string)($_POST['affiliate_slug_input'] ?? '')));
    if ($setupId <= 0) {
        $aff_slug_error = 'Sesi berakhir. Silakan daftar ulang atau hubungi admin.';
    } else {
        $slugErr = qurban_affiliate_validate_slug($slugInput);
        if ($slugErr !== '') {
            $aff_slug_error = $slugErr;
        } else {
            $chk = mysqli_prepare($conn, 'SELECT `id` FROM `qurban_affiliates` WHERE `affiliate_slug` = ? AND `id` != ? LIMIT 1');
            $taken = false;
            if ($chk) {
                mysqli_stmt_bind_param($chk, 'si', $slugInput, $setupId);
                mysqli_stmt_execute($chk);
                $res = mysqli_stmt_get_result($chk);
                if ($res && mysqli_fetch_assoc($res)) {
                    $taken = true;
                }
                if ($res) {
                    mysqli_free_result($res);
                }
                mysqli_stmt_close($chk);
            }
            if ($taken) {
                $aff_slug_error = 'Tautan ini sudah dipakai mitra lain. Pilih nama lain.';
            } else {
                $upd = mysqli_prepare($conn, "UPDATE `qurban_affiliates` SET `affiliate_slug` = ?, `approval_status` = 'pending', `approved_at` = NULL WHERE `id` = ? AND (`affiliate_slug` IS NULL OR `affiliate_slug` = '')");
                if ($upd) {
                    mysqli_stmt_bind_param($upd, 'si', $slugInput, $setupId);
                    mysqli_stmt_execute($upd);
                    $changed = mysqli_stmt_affected_rows($upd) > 0;
                    mysqli_stmt_close($upd);
                    if ($changed) {
                        unset($_SESSION['affiliate_slug_setup_id']);
                        $_SESSION['affiliate_pending_notice'] = '1';
                        header('Location: qurban-affiliate.php?pending_ok=1', true, 303);
                        exit;
                    }
                    $aff_slug_error = 'Tautan tidak bisa disimpan (mungkin sudah pernah diatur). Hubungi admin.';
                } else {
                    $aff_slug_error = 'Gagal menyimpan. Silakan coba lagi.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daftar_affiliate'])) {
    if (!$conn) {
        $aff_error = 'Database tidak terhubung. Periksa pengaturan $db_host, $db_user, $db_pass, dan $db_name di awal file ini.';
    } else {
        $aff_nama = trim((string)($_POST['aff_nama'] ?? ''));
        $aff_kontak = trim((string)($_POST['aff_kontak'] ?? ''));
        $aff_kota = trim((string)($_POST['aff_kota'] ?? ''));
        $aff_catatan = trim((string)($_POST['aff_catatan'] ?? ''));
        if ($aff_nama === '' || $aff_kontak === '') {
            $aff_error = 'Mohon lengkapi nama dan kontak WhatsApp/telepon.';
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO `qurban_affiliates` (`nama`, `kontak`, `kota`, `catatan`, `approval_status`) VALUES (?, ?, ?, ?, 'pending')");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssss', $aff_nama, $aff_kontak, $aff_kota, $aff_catatan);
                if (mysqli_stmt_execute($stmt)) {
                    $newId = (int) mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt);
                    $_SESSION['affiliate_slug_setup_id'] = $newId;
                    header('Location: qurban-affiliate.php?setup_link=1', true, 303);
                    exit;
                }
                $aff_error = 'Gagal menyimpan. Silakan coba lagi.';
                mysqli_stmt_close($stmt);
            } else {
                $aff_error = 'Gagal menyiapkan permintaan. Silakan coba lagi.';
            }
        }
    }
}

if (isset($_GET['pending_ok']) && isset($_SESSION['affiliate_pending_notice'])) {
    $aff_show_pending_modal = true;
    unset($_SESSION['affiliate_pending_notice']);
}

if (isset($_GET['setup_link']) && $conn && !empty($_SESSION['affiliate_slug_setup_id'])) {
    $sid = (int)$_SESSION['affiliate_slug_setup_id'];
    $stmt = mysqli_prepare($conn, 'SELECT `id`, `affiliate_slug`, `approval_status` FROM `qurban_affiliates` WHERE `id` = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $sid);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        mysqli_stmt_close($stmt);
        if ($row && ($row['affiliate_slug'] === null || $row['affiliate_slug'] === '')) {
            $aff_show_slug_modal = true;
        } elseif ($row && $row['approval_status'] === 'pending') {
            $aff_show_pending_modal = true;
            unset($_SESSION['affiliate_slug_setup_id']);
        } else {
            unset($_SESSION['affiliate_slug_setup_id']);
        }
    }
}

if ($aff_slug_error !== '') {
    $aff_show_slug_modal = true;
}

$aff_slug_prefill = '';
if ($aff_slug_error !== '' && isset($_POST['affiliate_slug_input'])) {
    $aff_slug_prefill = (string)$_POST['affiliate_slug_input'];
}
$aff_top_media = qurban_affiliate_scan_top_media();

if ($conn) {
    mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Qurban Affiliate — KolaborAksi</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(180deg, #F0FAF8 0%, #FFFFFF 45%, #F6FAF9 100%);
            color: #1a1a1a;
            min-height: 100vh;
            padding-bottom: calc(68px + env(safe-area-inset-bottom, 0px));
        }
        .wrap { max-width: 430px; margin: 0 auto; min-height: 100vh; }
        .app-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: #fff;
            border-bottom: 1px solid #EAEAEA;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 1px 6px rgba(0,0,0,.06);
        }
        .app-header-back {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 12px;
            background: #F3F6F5;
            color: #333;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .app-header-logo {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: inherit;
        }
        .app-header-logo-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, #17a697, #0f7a6e);
            color: #fff;
            font-weight: 800;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .app-header-logo-text { font-weight: 700; font-size: 15px; color: #1a1a1a; }
        .app-header-logo-img { max-height: 36px; width: auto; display: block; }
        .aff-top-slideshow {
            position: relative;
            width: 100%;
            background: #111;
        }
        .aff-top-viewport {
            display: flex;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            border-radius: 0 0 18px 18px;
            aspect-ratio: 16 / 10;
            max-height: 48vh;
        }
        .aff-top-viewport::-webkit-scrollbar { display: none; }
        .aff-top-slide {
            flex: 0 0 100%;
            width: 100%;
            scroll-snap-align: start;
            position: relative;
            background: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .aff-top-slide img,
        .aff-top-slide video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .aff-top-slide video { background: #000; }
        .aff-top-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 2;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 5px 10px;
            border-radius: 8px;
            background: rgba(0,0,0,.55);
            color: #fff;
            backdrop-filter: blur(6px);
        }
        .aff-top-badge.is-video { background: rgba(23, 166, 151, .86); }
        .aff-top-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 3;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,.92);
            color: #17a697;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,.2);
        }
        .aff-top-nav-prev { left: 8px; }
        .aff-top-nav-next { right: 8px; }
        .aff-top-dots {
            position: absolute;
            bottom: 10px;
            left: 0;
            right: 0;
            z-index: 3;
            display: flex;
            justify-content: center;
            gap: 6px;
            pointer-events: none;
        }
        .aff-top-dot {
            pointer-events: auto;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            border: none;
            padding: 0;
            background: rgba(255,255,255,.45);
            cursor: pointer;
            transition: transform .2s, background .2s;
        }
        .aff-top-dot.is-active {
            background: #17a697;
            transform: scale(1.15);
        }
        .aff-hero {
            margin: 16px 16px 12px;
            padding: 20px 18px;
            background: linear-gradient(135deg, #17a697 0%, #0f7a6e 100%);
            border-radius: 18px;
            color: #fff;
            box-shadow: 0 8px 28px rgba(23, 166, 151, .35);
        }
        .aff-hero h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            line-height: 1.25;
        }
        .aff-hero p { font-size: 13px; opacity: .95; line-height: 1.55; }
        .section { padding: 8px 16px 16px; }
        .section-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #1a1a1a;
        }
        .section-title i { color: #17a697; }
        .aff-detail-card {
            background: #fff;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid #E2EDED;
            box-shadow: 0 4px 16px rgba(0,0,0,.04);
        }
        .aff-detail-card ul {
            margin: 0;
            padding-left: 18px;
            font-size: 13px;
            color: #444;
            line-height: 1.65;
        }
        .aff-detail-card li { margin-bottom: 8px; }
        .aff-detail-card li:last-child { margin-bottom: 0; }
        .form-card {
            background: #fff;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid #E2EDED;
            box-shadow: 0 4px 16px rgba(0,0,0,.04);
        }
        .aff-intro {
            font-size: 12px;
            color: #666;
            line-height: 1.55;
            margin-bottom: 14px;
        }
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #E2EDED;
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: #FAFAFA;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #17a697;
            background: #fff;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #17a697, #0f7a6e);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
        }
        .btn-submit:active { transform: scale(0.99); }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 14px;
        }
        .alert.ok { background: #DCFCE7; color: #166534; border: 1px solid #BBF7D0; }
        .alert.err { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
        .qurban-cta-bar {
            position: fixed;
            left: 50%;
            transform: translateX(-50%);
            bottom: 0;
            width: 100%;
            max-width: 430px;
            display: flex;
            gap: 8px;
            padding: 8px 12px max(10px, env(safe-area-inset-bottom, 0px)) 12px;
            background: #fff;
            border-top: 1px solid #E8EEEC;
            box-shadow: 0 -2px 14px rgba(0,0,0,.06);
            z-index: 999;
            box-sizing: border-box;
        }
        .qurban-cta {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 8px;
            border-radius: 10px;
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.25;
            cursor: pointer;
            transition: transform .15s, box-shadow .2s;
            text-align: center;
            text-decoration: none;
            -webkit-tap-highlight-color: transparent;
            border: none;
        }
        .qurban-cta:active { transform: scale(0.98); }
        .qurban-cta-primary {
            background: linear-gradient(135deg, #17a697, #0f7a6e);
            color: #fff;
            box-shadow: 0 2px 10px rgba(23, 166, 151, .28);
        }
        .qurban-cta-secondary {
            background: #fff;
            color: #0f7a6e;
            border: 1px solid #17a697;
            box-shadow: 0 1px 6px rgba(23, 166, 151, .08);
        }
        .kolaborasi-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 9999;
            align-items: flex-end;
            justify-content: center;
        }
        .kolaborasi-modal-overlay.show { display: flex; }
        .kolaborasi-modal-box {
            background: #fff;
            width: 100%;
            max-width: 430px;
            border-radius: 20px 20px 0 0;
            padding: 24px 20px 32px;
            animation: slideUp .28s ease;
        }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        .kolaborasi-modal-handle { width: 36px; height: 4px; background: #DDD; border-radius: 4px; margin: 0 auto 20px; }
        .kolaborasi-modal-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, #17a697, #0f7a6e);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #fff;
            margin: 0 auto 12px;
        }
        .kolaborasi-modal-title { font-size: 15px; font-weight: 700; text-align: center; margin-bottom: 8px; }
        .kolaborasi-modal-text { font-size: 13px; color: #666; text-align: center; line-height: 1.6; margin-bottom: 18px; }
        .kolaborasi-cancel-btn {
            display: block;
            width: 100%;
            padding: 11px;
            background: #F5F5F5;
            color: #555;
            border: none;
            border-radius: 11px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }
        #affiliateSlugModal .aff-slug-modal-text {
            font-size: 13px;
            color: #555;
            line-height: 1.55;
            margin-bottom: 12px;
            text-align: left;
        }
        #affiliateSlugModal .aff-link-preview-box {
            background: #F0FAF8;
            border: 1px solid #C5E8E0;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 12px;
            word-break: break-all;
            margin-bottom: 14px;
            color: #0f4f47;
        }
        #affiliateSlugModal .aff-link-preview-box code { font-size: 11px; }
        #affiliateSlugModal .btn-copy-slug {
            margin-top: 8px;
            width: 100%;
            padding: 10px;
            background: #E8F5F2;
            color: #0f7a6e;
            border: 1px solid #17a697;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="app-header">
        <a href="<?php echo htmlspecialchars(QURBAN_ENTRY_SCRIPT); ?>" class="app-header-back" aria-label="Kembali ke Qurban"><i class="fas fa-arrow-left"></i></a>
        <a class="app-header-logo" href="index.php">
            <?php if (file_exists(__DIR__ . '/assets/logo.png')): ?>
                <img src="assets/logo.png" alt="KolaborAksi" class="app-header-logo-img" width="220" height="44">
            <?php else: ?>
                <div class="app-header-logo-icon">K</div>
                <span class="app-header-logo-text">KolaborAksi</span>
            <?php endif; ?>
        </a>
        <span style="width:38px"></span>
    </header>

    <?php if (count($aff_top_media) > 0): ?>
    <div class="aff-top-slideshow" id="affTopSlideshow">
        <div class="aff-top-viewport" id="affTopViewport">
            <?php foreach ($aff_top_media as $m): ?>
                <div class="aff-top-slide" data-type="<?php echo htmlspecialchars((string)$m['type']); ?>">
                    <span class="aff-top-badge<?php echo $m['type'] === 'video' ? ' is-video' : ''; ?>"><?php echo $m['type'] === 'video' ? 'Video' : 'Foto'; ?></span>
                    <?php if ($m['type'] === 'video'): ?>
                        <video src="<?php echo htmlspecialchars((string)$m['src']); ?>" playsinline controls preload="metadata"></video>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars((string)$m['src']); ?>" alt="" loading="lazy" decoding="async">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($aff_top_media) > 1): ?>
            <button type="button" class="aff-top-nav aff-top-nav-prev" aria-label="Slide sebelumnya"><i class="fas fa-chevron-left"></i></button>
            <button type="button" class="aff-top-nav aff-top-nav-next" aria-label="Slide berikutnya"><i class="fas fa-chevron-right"></i></button>
            <div class="aff-top-dots" id="affTopDots" role="tablist" aria-label="Indikator slide"></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="aff-hero">
        <h1><i class="fas fa-handshake" style="opacity:.9;margin-right:6px"></i>Qurban Affiliate</h1>
        <p>Jadikan jaringan Anda amal berkelanjutan: ajak keluarga, komunitas, dan pengikut untuk ikut qurban bersama KolaborAksi, sambil mendapat insentif mitra yang transparan.</p>
    </div>

    <div class="section">
        <div class="section-title"><i class="fas fa-circle-info"></i> Tentang program</div>
        <div class="aff-detail-card">
            <ul>
                <li><strong>Mitra promosi</strong> — Sebarkan informasi qurban (lokasi penyaluran, paket 1/7 atau utuh) melalui kanal Anda.</li>
                <li><strong>Dukungan materi</strong> — Tim kami membantu dengan ringkasan teks, visual, dan panduan penyampaian yang sopan.</li>
                <li><strong>Komisi &amp; pelaporan</strong> — Skema mitra dan rekonsiliasi dijelaskan setelah pendaftaran; tidak ada biaya gabung.</li>
                <li><strong>Transparansi penyaluran</strong> — Selaras dengan spirit halaman qurban: peserta dan arah penyaluran dapat dipantau publik sesuai kebijakan kami.</li>
                <li><strong>Privasi</strong> — Data kontak Anda hanya dipakai untuk koordinasi program, bukan untuk spam.</li>
            </ul>
        </div>
    </div>

    <div class="section" id="affiliate-form">
        <div class="section-title"><i class="fas fa-user-plus"></i> Daftar mitra afiliasi</div>
        <div class="form-card">
            <p class="aff-intro">Isi formulir di bawah ini. Setelah terkirim, Anda akan diminta membuat <strong>tautan afiliasi khusus</strong>. Siapa pun yang mendaftar qurban melalui tautan itu akan tercatat sebagai jaringan Anda.</p>
            <?php if ($aff_error !== ''): ?>
                <div class="alert err"><?php echo htmlspecialchars($aff_error); ?></div>
            <?php endif; ?>
            <form method="post" action="qurban-affiliate.php#affiliate-form" id="formAffiliate">
                <input type="hidden" name="daftar_affiliate" value="1">
                <div class="form-group">
                    <label for="aff_nama">Nama lengkap</label>
                    <input type="text" id="aff_nama" name="aff_nama" required maxlength="120"
                        value="<?php echo ($aff_error !== '' && isset($_POST['aff_nama'])) ? htmlspecialchars((string)$_POST['aff_nama']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="aff_kontak">WhatsApp / telepon</label>
                    <input type="tel" id="aff_kontak" name="aff_kontak" required maxlength="80" inputmode="tel" placeholder="08xxxxxxxxxx"
                        value="<?php echo ($aff_error !== '' && isset($_POST['aff_kontak'])) ? htmlspecialchars((string)$_POST['aff_kontak']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="aff_kota">Kota / wilayah promosi (opsional)</label>
                    <input type="text" id="aff_kota" name="aff_kota" maxlength="100" placeholder="Contoh: Jakarta, Online"
                        value="<?php echo ($aff_error !== '' && isset($_POST['aff_kota'])) ? htmlspecialchars((string)$_POST['aff_kota']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="aff_catatan">Pengalaman / link sosial (opsional)</label>
                    <textarea id="aff_catatan" name="aff_catatan" maxlength="2000" placeholder="Akun Instagram, komunitas, dll."><?php echo ($aff_error !== '' && isset($_POST['aff_catatan'])) ? htmlspecialchars((string)$_POST['aff_catatan']) : ''; ?></textarea>
                </div>
                <button type="submit" class="btn-submit">Kirim pendaftaran afiliasi</button>
            </form>
        </div>
    </div>
</div>

<div class="qurban-cta-bar" role="toolbar" aria-label="Aksi">
    <a href="<?php echo htmlspecialchars(QURBAN_ENTRY_SCRIPT); ?>" class="qurban-cta qurban-cta-primary">Qurban</a>
    <a href="#affiliate-form" class="qurban-cta qurban-cta-secondary">Daftar mitra</a>
</div>

<div class="kolaborasi-modal-overlay<?php echo $aff_show_slug_modal ? ' show' : ''; ?>" id="affiliateSlugModal" style="z-index:10080" onclick="if(event.target===this) document.getElementById('affiliateSlugModal').classList.remove('show')">
    <div class="kolaborasi-modal-box" onclick="event.stopPropagation()" style="max-height:90vh;overflow-y:auto">
        <div class="kolaborasi-modal-handle"></div>
        <div class="kolaborasi-modal-icon"><i class="fas fa-link"></i></div>
        <div class="kolaborasi-modal-title">Tautan afiliasi Anda</div>
        <p class="aff-slug-modal-text">Buat akhiran tautan unik (huruf kecil &amp; angka). Orang yang membuka <strong>halaman qurban yang sama</strong> lewat link Anda dan melakukan pendaftaran akan tercatat sebagai hasil jaringan Anda.</p>
        <div class="aff-link-preview-box">
            <span style="opacity:.85;font-size:11px">Contoh link penuh:</span><br>
            <code id="affFullLinkPreview"><?php echo htmlspecialchars(rtrim(QURBAN_SITE_BASE, '/') . '/'); ?><span id="affSlugPreview">namamu</span></code>
        </div>
        <?php if ($aff_slug_error !== ''): ?>
            <div class="alert err" style="margin-bottom:12px"><?php echo htmlspecialchars($aff_slug_error); ?></div>
        <?php endif; ?>
        <form method="post" action="qurban-affiliate.php?setup_link=1#affiliate-form" id="formAffiliateSlug">
            <input type="hidden" name="simpan_affiliate_slug" value="1">
            <div class="form-group">
                <label for="affiliate_slug_input">Akhiran link (contoh: <strong>budi-qurban</strong>)</label>
                <input type="text" id="affiliate_slug_input" name="affiliate_slug_input" required maxlength="40" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocomplete="off" placeholder="misalnya: yatim-jakarta"
                    value="<?php echo htmlspecialchars($aff_slug_prefill); ?>">
            </div>
            <button type="submit" class="btn-submit">Simpan &amp; ajukan aktivasi link</button>
        </form>
        <button type="button" class="kolaborasi-cancel-btn" style="margin-top:10px" onclick="document.getElementById('affiliateSlugModal').classList.remove('show')">Tutup dulu</button>
        <p class="aff-slug-modal-text" style="margin-top:12px;font-size:11px;color:#888">Link dibagikan: <?php echo htmlspecialchars(rtrim(QURBAN_SITE_BASE, '/') . '/namaaffiliator'); ?> — pengunjung melihat halaman qurban biasa; pendaftaran tercatat untuk mitra yang ada di path tersebut.</p>
    </div>
</div>

<div class="kolaborasi-modal-overlay<?php echo $aff_show_pending_modal ? ' show' : ''; ?>" id="affiliatePendingModal" style="z-index:10090" onclick="if(event.target===this) document.getElementById('affiliatePendingModal').classList.remove('show')">
    <div class="kolaborasi-modal-box" onclick="event.stopPropagation()">
        <div class="kolaborasi-modal-handle"></div>
        <div class="kolaborasi-modal-icon"><i class="fas fa-hourglass-half"></i></div>
        <div class="kolaborasi-modal-title">Pendaftaran berhasil dikirim</div>
        <p class="kolaborasi-modal-text">Link Anda belum diaktifkan. Hubungi admin atau tunggu hingga 1x24 jam untuk pengaktifan link afiliasi oleh tim Kolaboraksi.</p>
        <button type="button" class="btn-submit" onclick="document.getElementById('affiliatePendingModal').classList.remove('show')">Mengerti</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    (function () {
        var root = document.getElementById('affTopSlideshow');
        var vp = document.getElementById('affTopViewport');
        if (!root || !vp || vp.children.length === 0) return;
        var slides = vp.querySelectorAll('.aff-top-slide');
        var n = slides.length;
        var dotsWrap = document.getElementById('affTopDots');
        var idx = 0;
        var timer;
        function slideW() { return vp.clientWidth || 1; }
        function go(i) {
            i = ((i % n) + n) % n;
            idx = i;
            vp.scrollTo({ left: i * slideW(), behavior: 'smooth' });
            updateDots();
            syncVideo();
        }
        function updateDots() {
            if (!dotsWrap) return;
            var d = Math.min(n - 1, Math.round(vp.scrollLeft / slideW()));
            dotsWrap.querySelectorAll('.aff-top-dot').forEach(function (dot, i) {
                dot.classList.toggle('is-active', i === d);
            });
            idx = d;
        }
        function syncVideo() {
            slides.forEach(function (sl, i) {
                var v = sl.querySelector('video');
                if (!v) return;
                if (i !== idx) {
                    v.pause();
                    try { v.currentTime = 0; } catch (e) {}
                }
            });
        }
        if (dotsWrap && n > 1) {
            for (var d = 0; d < n; d++) {
                (function (j) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'aff-top-dot' + (j === 0 ? ' is-active' : '');
                    b.setAttribute('aria-label', 'Tampilkan slide ' + (j + 1));
                    b.addEventListener('click', function () { go(j); });
                    dotsWrap.appendChild(b);
                })(d);
            }
        }
        var prev = root.querySelector('.aff-top-nav-prev');
        var next = root.querySelector('.aff-top-nav-next');
        if (prev) prev.addEventListener('click', function () { go(idx - 1); });
        if (next) next.addEventListener('click', function () { go(idx + 1); });
        var scrollT;
        vp.addEventListener('scroll', function () {
            if (scrollT) clearTimeout(scrollT);
            scrollT = setTimeout(function () {
                var d = Math.round(vp.scrollLeft / slideW());
                if (d !== idx) {
                    idx = Math.max(0, Math.min(n - 1, d));
                    updateDots();
                    syncVideo();
                }
            }, 50);
        }, { passive: true });
        function autoAdvance() {
            if (n <= 1) return;
            var cur = slides[idx];
            var vid = cur && cur.querySelector('video');
            if (vid && !vid.paused) return;
            go(idx + 1);
        }
        function startAuto() {
            if (timer) clearInterval(timer);
            if (n > 1) timer = setInterval(autoAdvance, 6000);
        }
        startAuto();
        root.addEventListener('mouseenter', function () { if (timer) clearInterval(timer); });
        root.addEventListener('mouseleave', startAuto);
        vp.addEventListener('touchstart', function () { if (timer) clearInterval(timer); }, { passive: true });
        vp.addEventListener('touchend', function () { setTimeout(startAuto, 3500); }, { passive: true });
    })();

    <?php if ($aff_show_slug_modal): ?>
    var m = document.getElementById('affiliateSlugModal');
    if (m) m.classList.add('show');
    <?php endif; ?>
    var inp = document.getElementById('affiliate_slug_input');
    var prev = document.getElementById('affSlugPreview');
    function syncSlugPreview() {
        if (!inp || !prev) return;
        var v = (inp.value || '').toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/^-+|-+$/g, '') || 'namamu';
        prev.textContent = v;
    }
    if (inp) {
        inp.addEventListener('input', syncSlugPreview);
        syncSlugPreview();
    }
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var t = btn.getAttribute('data-copy');
            if (!t) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(t).then(function () { btn.textContent = 'Tersalin'; setTimeout(function () { btn.textContent = 'Salin tautan'; }, 2000); });
            }
        });
    });
    <?php if ($aff_error !== '' || $aff_show_pending_modal): ?>
    var el = document.getElementById('affiliate-form');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    <?php endif; ?>
});
</script>
</body>
</html>

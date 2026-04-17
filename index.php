<?php
session_start();

header('Content-Type: text/html; charset=utf-8');

/** Samakan dengan qurban-affiliate.php — dipakai untuk atribut cookie / konsistensi domain. */
define('QURBAN_SITE_BASE', 'https://qurban.kolaboraksi.com');

/*
 * URL afiliasi: https://qurban.kolaboraksi.com/namaaffiliator — pakai file .htaccess di root (RewriteRule -> index.php?ref=...).
 * Tanpa mod_rewrite tetap bisa: index.php?ref=namaaffiliator
 */

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

$qurban_locations = [
    [
        'id' => 'qurban-afrika-1per7',
        'title' => 'Qurban Afrika',
        'desc' => '1/7 Sapi - 1,6 Jt',
        'image' => 'assets/lokasi_qurban/afrika.png',
        'detail_secondary' => '1 Sapi - 11,2 Juta',
    ],
    [
        'id' => 'pengungsi-palestina-yaman-domba',
        'title' => 'Pengungsi Palestina (Yaman)',
        'desc' => 'Domba - 3 Jt',
        'image' => 'assets/lokasi_qurban/yaman.png',
        'detail_secondary' => 'Berat kisaran 30 Kg',
    ],
    [
        'id' => 'pengungsi-palestina-mesir-domba',
        'title' => 'Pengungsi Palestina (Mesir)',
        'desc' => 'Domba - 5 Jt',
        'image' => 'assets/lokasi_qurban/mesir.png',
        'detail_secondary' => 'Berat kisaran 45 Kg',
    ],
    [
        'id' => 'qurban-mentawai-1per7',
        'title' => 'Qurban Mentawai',
        'desc' => '1/7 Sapi - 3,5 Juta',
        'image' => 'assets/lokasi_qurban/mentawai.png',
        'detail_secondary' => '1 Sapi - 24,5 Juta',
    ],
];
$qurban_location_map = [];
foreach ($qurban_locations as $loc) {
    $qurban_location_map[(string)$loc['id']] = (string)$loc['title'] . ' - ' . (string)$loc['desc'];
}

$qurban_jenis_options = [
    '1per7' => 'Qurban 1/7 (satu bagian)',
    '1ekor' => '1 ekor utuh',
];

/** Tambah slide manual: ['type'=>'image'|'video', 'src'=>'assets/...', 'caption'=>'opsional'] */
$qurban_slides_manual = [
    // Contoh: ['type' => 'image', 'src' => 'assets/qurban/slides/galeri1.jpg', 'caption' => 'Qurban 2024'],
];

function qurban_build_slides(array $manual): array {
    $byPath = [];
    foreach ($manual as $s) {
        if (empty($s['src']) || empty($s['type'])) {
            continue;
        }
        $type = $s['type'] === 'video' ? 'video' : 'image';
        $p = $s['src'];
        $byPath[$p] = ['type' => $type, 'src' => $p, 'caption' => trim((string)($s['caption'] ?? ''))];
    }
    $dir = __DIR__ . '/assets/qurban/slides';
    if (is_dir($dir)) {
        $files = glob($dir . '/*') ?: [];
        usort($files, 'strnatcasecmp');
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $base = basename($path);
            $rel = 'assets/qurban/slides/' . $base;
            if (isset($byPath[$rel])) {
                continue;
            }
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $byPath[$rel] = ['type' => 'image', 'src' => $rel, 'caption' => ''];
            } elseif (in_array($ext, ['mp4', 'webm'], true)) {
                $byPath[$rel] = ['type' => 'video', 'src' => $rel, 'caption' => ''];
            }
        }
    }
    return array_values($byPath);
}

function qurban_scan_home_main_images(): array {
    $dir = __DIR__ . '/assets/halaman_utama';
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.png') ?: [];
    usort($files, 'strnatcasecmp');
    $out = [];
    foreach ($files as $path) {
        if (!is_file($path)) {
            continue;
        }
        $out[] = 'assets/halaman_utama/' . basename($path);
        if (count($out) >= 4) {
            break;
        }
    }
    return $out;
}

function qurban_jenis_label(string $code): string {
    $m = ['1per7' => 'Qurban 1/7', '1ekor' => '1 ekor'];
    return $m[$code] ?? $code;
}

function qurban_lokasi_label(string $code, array $map): string {
    return $map[$code] ?? $code;
}

function qurban_ensure_table(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS `qurban_registrations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `nama` VARCHAR(120) NOT NULL,
        `kontak` VARCHAR(80) NOT NULL,
        `lokasi` VARCHAR(64) NOT NULL,
        `jenis_qurban` VARCHAR(16) NOT NULL DEFAULT '1per7',
        `catatan` TEXT NULL,
        `affiliate_ref` VARCHAR(64) NULL DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_created` (`created_at`),
        KEY `idx_affiliate_ref` (`affiliate_ref`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return (bool) mysqli_query($conn, $sql);
}

function qurban_ensure_jenis_column(mysqli $conn): void {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `qurban_registrations` LIKE 'jenis_qurban'");
    if ($r && mysqli_num_rows($r) > 0) {
        mysqli_free_result($r);
        return;
    }
    mysqli_query($conn, "ALTER TABLE `qurban_registrations` ADD COLUMN `jenis_qurban` VARCHAR(16) NOT NULL DEFAULT '1per7' AFTER `lokasi`");
}

function qurban_ensure_affiliate_ref_column(mysqli $conn): void {
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `qurban_registrations` LIKE 'affiliate_ref'");
    if ($r && mysqli_num_rows($r) > 0) {
        mysqli_free_result($r);
        return;
    }
    if ($r) {
        mysqli_free_result($r);
    }
    mysqli_query($conn, "ALTER TABLE `qurban_registrations` ADD COLUMN `affiliate_ref` VARCHAR(64) NULL DEFAULT NULL AFTER `catatan`");
    mysqli_query($conn, "ALTER TABLE `qurban_registrations` ADD KEY `idx_affiliate_ref` (`affiliate_ref`)");
}

function qurban_affiliates_table_has_slug(mysqli $conn): bool {
    $r = mysqli_query($conn, "SHOW TABLES LIKE 'qurban_affiliates'");
    if (!$r || mysqli_num_rows($r) === 0) {
        if ($r) {
            mysqli_free_result($r);
        }
        return false;
    }
    mysqli_free_result($r);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `qurban_affiliates` LIKE 'affiliate_slug'");
    $ok = $r && mysqli_num_rows($r) > 0;
    if ($r) {
        mysqli_free_result($r);
    }
    return $ok;
}

function qurban_affiliates_has_approval_status(mysqli $conn): bool {
    $r = mysqli_query($conn, "SHOW TABLES LIKE 'qurban_affiliates'");
    if (!$r || mysqli_num_rows($r) === 0) {
        if ($r) {
            mysqli_free_result($r);
        }
        return false;
    }
    mysqli_free_result($r);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `qurban_affiliates` LIKE 'approval_status'");
    $ok = $r && mysqli_num_rows($r) > 0;
    if ($r) {
        mysqli_free_result($r);
    }
    return $ok;
}

if ($conn) {
    qurban_ensure_table($conn);
    qurban_ensure_jenis_column($conn);
    qurban_ensure_affiliate_ref_column($conn);
}

$active_referral = '';
$inactive_referral_notice = '';
$affiliate_requires_approval = $conn ? qurban_affiliates_has_approval_status($conn) : false;
if ($conn && qurban_affiliates_table_has_slug($conn)) {
    $refGet = isset($_GET['ref']) ? trim((string)$_GET['ref']) : '';
    if ($refGet !== '') {
        $refNorm = strtolower($refGet);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $refNorm) && strlen($refNorm) >= 3 && strlen($refNorm) <= 40) {
            $refSql = $affiliate_requires_approval
                ? "SELECT `id`, `approval_status` FROM `qurban_affiliates` WHERE `affiliate_slug` = ? LIMIT 1"
                : 'SELECT `id` FROM `qurban_affiliates` WHERE `affiliate_slug` = ? LIMIT 1';
            $stRef = mysqli_prepare($conn, $refSql);
            if ($stRef) {
                mysqli_stmt_bind_param($stRef, 's', $refNorm);
                mysqli_stmt_execute($stRef);
                $resRef = mysqli_stmt_get_result($stRef);
                $rowRef = $resRef ? mysqli_fetch_assoc($resRef) : null;
                if ($rowRef) {
                    $isApproved = !$affiliate_requires_approval || !isset($rowRef['approval_status']) || $rowRef['approval_status'] === 'approved';
                    if ($isApproved) {
                        $_SESSION['qurban_referral_slug'] = $refNorm;
                        $httpsOn = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                        setcookie('qurban_ref', $refNorm, time() + 86400 * 90, '/', '', $httpsOn, true);
                        $active_referral = $refNorm;
                    } else {
                        $inactive_referral_notice = 'Link Anda belum diaktifkan. Hubungi admin atau tunggu hingga 1x24 jam.';
                    }
                }
                if ($resRef) {
                    mysqli_free_result($resRef);
                }
                mysqli_stmt_close($stRef);
            }
        }
    }
}
if ($active_referral === '' && !empty($_SESSION['qurban_referral_slug']) && is_string($_SESSION['qurban_referral_slug'])) {
    $active_referral = $_SESSION['qurban_referral_slug'];
}
if ($active_referral === '' && !empty($_COOKIE['qurban_ref'])) {
    $c = strtolower(trim((string)$_COOKIE['qurban_ref']));
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $c) && strlen($c) >= 3 && strlen($c) <= 40) {
        $active_referral = $c;
    }
}

$success = false;
$error = '';
$prefill_lokasi = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['daftar_qurban'])) {
    if (!$conn) {
        $error = 'Database tidak terhubung. Periksa pengaturan $db_host, $db_user, $db_pass, dan $db_name di awal file ini.';
    } else {
        $nama = trim((string)($_POST['nama'] ?? ''));
        $kontak = trim((string)($_POST['kontak'] ?? ''));
        $lokasi = trim((string)($_POST['lokasi'] ?? ''));
        $jenis_qurban = trim((string)($_POST['jenis_qurban'] ?? ''));
        $catatan = trim((string)($_POST['catatan'] ?? ''));

        if ($nama === '' || $kontak === '' || $lokasi === '' || $jenis_qurban === '') {
            $error = 'Mohon lengkapi nama, kontak, lokasi, dan jenis qurban.';
        } elseif (!isset($qurban_location_map[$lokasi])) {
            $error = 'Lokasi tidak valid.';
        } elseif (!isset($qurban_jenis_options[$jenis_qurban])) {
            $error = 'Jenis qurban tidak valid.';
        } else {
            $postRef = strtolower(trim((string)($_POST['affiliate_ref'] ?? '')));
            $affiliateStored = '';
            if ($postRef !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $postRef) && strlen($postRef) <= 40 && qurban_affiliates_table_has_slug($conn)) {
                $refSql = $affiliate_requires_approval
                    ? "SELECT `id` FROM `qurban_affiliates` WHERE `affiliate_slug` = ? AND `approval_status` = 'approved' LIMIT 1"
                    : 'SELECT `id` FROM `qurban_affiliates` WHERE `affiliate_slug` = ? LIMIT 1';
                $stA = mysqli_prepare($conn, $refSql);
                if ($stA) {
                    mysqli_stmt_bind_param($stA, 's', $postRef);
                    mysqli_stmt_execute($stA);
                    $resA = mysqli_stmt_get_result($stA);
                    if ($resA && mysqli_fetch_assoc($resA)) {
                        $affiliateStored = $postRef;
                    }
                    if ($resA) {
                        mysqli_free_result($resA);
                    }
                    mysqli_stmt_close($stA);
                }
            }
            $stmt = mysqli_prepare($conn, 'INSERT INTO `qurban_registrations` (`nama`, `kontak`, `lokasi`, `jenis_qurban`, `catatan`, `affiliate_ref`) VALUES (?, ?, ?, ?, ?, ?)');
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ssssss', $nama, $kontak, $lokasi, $jenis_qurban, $catatan, $affiliateStored);
                if (mysqli_stmt_execute($stmt)) {
                    $success = true;
                } else {
                    $error = 'Gagal menyimpan data. Silakan coba lagi.';
                }
                mysqli_stmt_close($stmt);
            } else {
                $error = 'Gagal menyiapkan permintaan. Silakan coba lagi.';
            }
        }
    }
}

if (!$success && isset($_POST['lokasi']) && is_string($_POST['lokasi'])) {
    $cand = trim($_POST['lokasi']);
    if ($cand !== '' && isset($qurban_location_map[$cand])) {
        $prefill_lokasi = $cand;
    }
}
if ($prefill_lokasi === '' && isset($_GET['lokasi']) && is_string($_GET['lokasi'])) {
    $cand = trim($_GET['lokasi']);
    if ($cand !== '' && isset($qurban_location_map[$cand])) {
        $prefill_lokasi = $cand;
    }
}

$peserta_list = [];
if ($conn) {
    $pRes = mysqli_query($conn, "SELECT `nama`, `lokasi`, `jenis_qurban`, `affiliate_ref`, `created_at` FROM `qurban_registrations` ORDER BY `created_at` DESC LIMIT 200");
    if ($pRes) {
        while ($prow = mysqli_fetch_assoc($pRes)) {
            $peserta_list[] = $prow;
        }
        mysqli_free_result($pRes);
    }
}

$qurban_slides = qurban_build_slides($qurban_slides_manual);
$home_main_images = qurban_scan_home_main_images();
/** Urutan slot 2x2: 1 Afrika, 2 Yaman, 3 Mesir, 4 Mentawai */
$home_main_program_hrefs = [
    'detail_qurban.php?program=' . rawurlencode('qurban-afrika-1per7'),
    'detail_qurban.php?program=' . rawurlencode('pengungsi-palestina-yaman-domba'),
    'detail_qurban.php?program=' . rawurlencode('pengungsi-palestina-mesir-domba'),
    'detail_qurban.php?program=' . rawurlencode('qurban-mentawai-1per7'),
];

if ($conn) {
    mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Qurban — KolaborAksi</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background:
                radial-gradient(circle at 12% 10%, rgba(11, 107, 97, 0.12) 0, rgba(11, 107, 97, 0) 38%),
                radial-gradient(circle at 88% 24%, rgba(15, 93, 143, 0.10) 0, rgba(15, 93, 143, 0) 40%),
                linear-gradient(180deg, #eef8f6 0%, #ffffff 42%, #f3f8fb 100%);
            color: #111827;
            font-size: 16px;
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: calc(68px + env(safe-area-inset-bottom, 0px));
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(15, 93, 143, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15, 93, 143, 0.03) 1px, transparent 1px);
            background-size: 24px 24px;
            mask-image: radial-gradient(circle at center, black 30%, transparent 90%);
            z-index: 0;
        }
        body::after {
            content: "";
            position: fixed;
            width: 280px;
            height: 280px;
            right: -70px;
            bottom: 90px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(11, 107, 97, 0.18), rgba(11, 107, 97, 0.02) 65%, transparent 75%);
            filter: blur(2px);
            pointer-events: none;
            z-index: 0;
        }
        .wrap { max-width: 430px; margin: 0 auto; min-height: 100vh; position: relative; z-index: 1; }
        /* App header — sama nuansa index */
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
        .app-header--home {
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
        .app-header-logo-img {
            height: 44px;
            width: auto;
            max-width: 220px;
            object-fit: contain;
            object-position: left center;
            display: block;
            flex-shrink: 0;
        }
        /* Slideshow foto & video */
        .qurban-slideshow {
            position: relative;
            width: 100%;
            background: #111;
        }
        .qs-viewport {
            display: flex;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            border-radius: 0 0 18px 18px;
            aspect-ratio: 16 / 10;
            max-height: 52vh;
        }
        .qs-viewport::-webkit-scrollbar { display: none; }
        .qs-slide {
            flex: 0 0 100%;
            width: 100%;
            scroll-snap-align: start;
            scroll-snap-stop: always;
            position: relative;
            background: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qs-slide img,
        .qs-slide video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .qs-slide video { background: #000; }
        .qs-caption {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 28px 14px 14px;
            font-size: 12px;
            font-weight: 600;
            color: #fff;
            line-height: 1.35;
            background: linear-gradient(transparent, rgba(0,0,0,.72));
            pointer-events: none;
        }
        .qs-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 2;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            padding: 5px 10px;
            border-radius: 8px;
            background: rgba(0,0,0,.55);
            color: #fff;
            backdrop-filter: blur(6px);
        }
        .qs-badge.is-video { background: rgba(23, 166, 151, .85); }
        .qs-nav {
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
        .qs-nav:active { transform: translateY(-50%) scale(0.94); }
        .qs-nav-prev { left: 8px; }
        .qs-nav-next { right: 8px; }
        .qs-dots {
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
        .qs-dot {
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
        .qs-dot.is-active {
            background: #17a697;
            transform: scale(1.15);
        }
        .qs-empty {
            aspect-ratio: 16 / 10;
            max-height: 52vh;
            border-radius: 0 0 18px 18px;
            background: linear-gradient(145deg, #0f7a6e 0%, #17a697 50%, #13907f 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 20px;
            text-align: center;
            color: rgba(255,255,255,.95);
        }
        .qs-empty i { font-size: 36px; margin-bottom: 12px; opacity: .9; }
        .qs-empty strong { font-size: 14px; font-weight: 700; margin-bottom: 6px; }
        .qs-empty span { font-size: 12px; opacity: .88; line-height: 1.5; max-width: 280px; }
        .home-main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }
        .home-intro {
            background: #fff;
            border: 1px solid #e2eded;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 6px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }
        .home-intro h3 {
            margin: 0 0 6px;
            font-size: 18px;
            font-weight: 700;
            color: #0f5d8f;
        }
        .home-intro-lead {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: #0b5f56;
        }
        .home-intro-quote {
            margin: 0;
            font-size: 13px;
            color: #334155;
            line-height: 1.6;
        }
        .qurban-steps {
            margin-top: 8px;
            background: #fff;
            border: 1px solid #e2eded;
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }
        .qurban-steps h4 {
            margin: 0 0 10px;
            font-size: 17px;
            font-weight: 700;
            color: #1f78ad;
        }
        .qurban-step-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .qurban-step-item:last-child { margin-bottom: 0; }
        .qurban-step-no {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #0f5d8f;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }
        .qurban-step-text {
            font-size: 13px;
            color: #1f2937;
            line-height: 1.5;
        }
        .qurban-video-wrap {
            margin-top: 14px;
            background: #fff;
            border: 1px solid #e2eded;
            border-radius: 14px;
            padding: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }
        .qurban-video-group {
            margin-bottom: 14px;
        }
        .qurban-video-group:last-child { margin-bottom: 0; }
        .qurban-video-title {
            margin: 0 0 10px;
            font-size: 14px;
            font-weight: 700;
            color: #0f5d8f;
        }
        .qurban-video-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .qurban-video-grid-single {
            display: flex;
            justify-content: center;
        }
        .qurban-video-card {
            background: #f8fbfd;
            border: 1px solid #deebf4;
            border-radius: 10px;
            padding: 6px;
        }
        .qurban-video-grid-single .qurban-video-card {
            width: min(48%, 230px);
        }
        .qurban-video-label {
            margin: 0 0 6px;
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
        }
        .qurban-video-frame {
            position: relative;
            width: 100%;
            border-radius: 10px;
            overflow: hidden;
            background: #111;
            aspect-ratio: 16 / 9;
        }
        .qurban-video-frame iframe {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
        }
        .qurban-report-wrap {
            margin-top: 14px;
            background: #fff;
            border: 1px solid #e2eded;
            border-radius: 14px;
            padding: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }
        .qurban-report-title {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 700;
            color: #0f5d8f;
        }
        .qurban-report-image {
            width: 100%;
            border-radius: 10px;
            display: block;
            height: auto;
            background: transparent;
        }
        .home-main-item {
            border-radius: 14px;
            overflow: visible;
            background: transparent;
            min-height: 120px;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .home-main-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 112px;
            padding: 0;
            margin: 0;
            border: 0;
            background: transparent;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            font: inherit;
            border-radius: 14px;
        }
        .home-main-link:focus-visible {
            outline: 3px solid #0f5d8f;
            outline-offset: 2px;
        }
        .home-main-item img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
            animation: homePulse 2.6s ease-in-out infinite;
            will-change: transform;
            transform-origin: center center;
        }
        .home-main-item:nth-child(2) img { animation-delay: .2s; }
        .home-main-item:nth-child(3) img { animation-delay: .35s; }
        .home-main-item:nth-child(4) img { animation-delay: .5s; }
        .home-main-single {
            margin-top: 4px;
            display: flex;
            justify-content: center;
        }
        .home-main-single .home-main-item {
            width: min(62%, 230px);
        }
        @keyframes homePulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.08); }
        }
        .home-sumbar-modal {
            position: fixed;
            inset: 0;
            z-index: 120;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(12, 21, 31, 0.58);
        }
        .home-sumbar-modal.is-open { display: flex; }
        .home-sumbar-dialog {
            position: relative;
            width: 100%;
            max-width: 340px;
            background: #fff;
            border-radius: 14px;
            padding: 16px 16px 12px;
            border: 1px solid #dce6ef;
            box-shadow: 0 10px 26px rgba(0,0,0,.18);
        }
        .home-sumbar-dialog h3 {
            margin: 0 0 6px;
            font-size: 17px;
            font-weight: 700;
            color: #0f5d8f;
        }
        .home-sumbar-dialog p {
            margin: 0 0 12px;
            font-size: 13px;
            color: #334155;
            line-height: 1.5;
        }
        .home-sumbar-actions { display: flex; flex-direction: column; gap: 8px; }
        .home-sumbar-actions a {
            display: block;
            text-align: center;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            line-height: 1.35;
        }
        .home-sumbar-actions a.primary {
            background: #0b6b61;
            color: #fff;
        }
        .home-sumbar-actions a.primary:focus-visible {
            outline: 3px solid #0f5d8f;
            outline-offset: 2px;
        }
        .home-sumbar-actions button.ghost {
            width: 100%;
            margin-top: 4px;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .home-sumbar-actions button.ghost:focus-visible {
            outline: 3px solid #0f5d8f;
            outline-offset: 2px;
        }
        .section {
            padding: 16px;
        }
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
        .loc-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
        .loc-card {
            background: #fff;
            border: 2px solid #E2EDED;
            border-radius: 14px;
            padding: 10px;
            cursor: pointer;
            transition: border-color .2s, box-shadow .2s;
            display: flex;
            align-items: center;
            gap: 12px;
            text-align: left;
        }
        .loc-card:hover { box-shadow: 0 4px 12px rgba(23, 166, 151, .12); }
        .loc-card.selected {
            border-color: #17a697;
            background: #F0FAF8;
            box-shadow: 0 4px 12px rgba(23, 166, 151, .15);
        }
        .loc-thumb {
            width: 126px;
            height: 82px;
            border-radius: 10px;
            overflow: hidden;
            background: #111;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .loc-thumb img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        .loc-thumb-fallback {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .03em;
            text-transform: uppercase;
            color: rgba(255,255,255,.9);
            text-align: center;
            padding: 8px;
            line-height: 1.25;
        }
        .loc-content { min-width: 0; }
        .loc-card h3 { font-size: 14px; font-weight: 700; margin-bottom: 4px; color: #1a1a1a; }
        .loc-card p { font-size: 12px; color: #374151; line-height: 1.5; }
        .form-card {
            background: #fff;
            border-radius: 16px;
            padding: 16px;
            border: 1px solid #E2EDED;
            box-shadow: 0 4px 16px rgba(0,0,0,.04);
        }
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group select,
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
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #17a697;
            background: #fff;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .jenis-heading {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .jenis-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        @media (max-width: 340px) {
            .jenis-grid { grid-template-columns: 1fr; }
        }
        .jenis-option {
            position: relative;
            cursor: pointer;
            margin: 0;
            display: block;
            border-radius: 14px;
            border: 2px solid #E2EDED;
            background: linear-gradient(180deg, #FAFAFA 0%, #F3F6F5 100%);
            transition: border-color .22s, box-shadow .22s, transform .15s;
            overflow: hidden;
        }
        .jenis-option:active { transform: scale(0.98); }
        .jenis-option.is-selected {
            border-color: #17a697;
            background: linear-gradient(180deg, #F0FAF8 0%, #E4F5F2 100%);
            box-shadow: 0 6px 20px rgba(23, 166, 151, .2);
        }
        .jenis-input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            pointer-events: none;
        }
        .jenis-card-inner {
            padding: 14px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 8px;
            min-height: 128px;
        }
        .jenis-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(23, 166, 151, .12);
            color: #17a697;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            transition: background .22s, color .22s;
        }
        .jenis-option.is-selected .jenis-icon {
            background: linear-gradient(135deg, #17a697, #0f7a6e);
            color: #fff;
        }
        .jenis-title {
            font-size: 13px;
            font-weight: 700;
            color: #1a1a1a;
            line-height: 1.25;
        }
        .jenis-desc {
            font-size: 12px;
            font-weight: 500;
            color: #374151;
            line-height: 1.4;
        }
        .jenis-check {
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: 16px;
            color: #E2EDED;
            transition: color .2s;
        }
        .jenis-option.is-selected .jenis-check {
            color: #17a697;
        }
        .peserta-box {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #E2EDED;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,.04);
        }
        .peserta-box-header {
            padding: 14px 16px;
            background: linear-gradient(135deg, #17a697 0%, #0f7a6e 100%);
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .peserta-count {
            margin-left: auto;
            font-size: 12px;
            font-weight: 600;
            opacity: .95;
            background: rgba(255,255,255,.2);
            padding: 4px 10px;
            border-radius: 20px;
        }
        .peserta-list { max-height: 320px; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .peserta-row {
            padding: 12px 16px;
            border-bottom: 1px solid #F0F0F0;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px 12px;
            align-items: start;
        }
        .peserta-row:last-child { border-bottom: none; }
        .peserta-nama { font-weight: 600; font-size: 13px; color: #1a1a1a; }
        .peserta-meta { font-size: 12px; color: #374151; line-height: 1.5; grid-column: 1 / -1; }
        .peserta-badge {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 8px;
            background: #E8F5F2;
            color: #0f7a6e;
            white-space: nowrap;
            justify-self: end;
            align-self: start;
        }
        .peserta-empty {
            padding: 28px 16px;
            text-align: center;
            color: #374151;
            font-size: 13px;
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #0b6b61, #084c45);
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
        /* Bilah tombol tetap di bawah + area aman (tanpa tab navigasi) */
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
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.25;
            cursor: pointer;
            transition: transform .15s, box-shadow .2s;
            text-align: center;
            text-decoration: none;
            -webkit-tap-highlight-color: transparent;
        }
        .qurban-cta:active { transform: scale(0.98); }
        .qurban-cta-primary {
            background: linear-gradient(135deg, #0b6b61, #084c45);
            color: #fff;
            box-shadow: 0 2px 10px rgba(11, 107, 97, .32);
        }
        .qurban-cta-secondary {
            background: #fff;
            color: #0b5f56;
            border: 1px solid #0b6b61;
            box-shadow: 0 1px 6px rgba(23, 166, 151, .08);
        }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        /* Modal form (bottom sheet) */
        .qurban-sheet-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 10050;
            align-items: flex-end;
            justify-content: center;
        }
        .qurban-sheet-modal.show { display: flex; }
        .qurban-sheet-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,.52);
        }
        .qurban-sheet-panel {
            position: relative;
            width: 100%;
            max-width: 430px;
            max-height: 92vh;
            background: #fff;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 -12px 40px rgba(0,0,0,.18);
            display: flex;
            flex-direction: column;
            animation: slideUp .3s ease;
        }
        .qurban-sheet-header {
            flex-shrink: 0;
            padding: 12px 14px 12px 16px;
            border-bottom: 1px solid #EAEAEA;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border-radius: 20px 20px 0 0;
        }
        .qurban-sheet-header h2 {
            flex: 1;
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0;
        }
        .qurban-sheet-close {
            width: 40px;
            height: 40px;
            border: none;
            background: #F3F6F5;
            color: #1f2937;
            border-radius: 12px;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .qurban-sheet-scroll {
            overflow-y: auto;
            padding: 16px 16px 28px;
            -webkit-overflow-scrolling: touch;
        }
        .qurban-sheet-scroll .form-card {
            border: none;
            box-shadow: none;
            padding: 0;
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="app-header app-header--home">
        <a class="app-header-logo" href="index.php">
            <?php if (file_exists(__DIR__ . '/assets/logo.png')): ?>
                <img src="assets/logo.png" alt="KolaborAksi" class="app-header-logo-img" width="220" height="44">
            <?php else: ?>
                <div class="app-header-logo-icon">K</div>
                <span class="app-header-logo-text">KolaborAksi</span>
            <?php endif; ?>
        </a>
    </header>

    <?php if (count($qurban_slides) > 0): ?>
    <div class="qurban-slideshow" id="qurbanSlideshow">
        <div class="qs-viewport" id="qsViewport">
            <?php foreach ($qurban_slides as $s): ?>
                <div class="qs-slide" data-type="<?php echo htmlspecialchars($s['type']); ?>">
                    <span class="qs-badge<?php echo $s['type'] === 'video' ? ' is-video' : ''; ?>"><?php echo $s['type'] === 'video' ? 'Video' : 'Foto'; ?></span>
                    <?php if ($s['type'] === 'video'): ?>
                        <video src="<?php echo htmlspecialchars($s['src']); ?>" playsinline controls preload="metadata"></video>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($s['src']); ?>" alt="" loading="lazy" decoding="async">
                    <?php endif; ?>
                    <?php if (($s['caption'] ?? '') !== ''): ?>
                        <div class="qs-caption"><?php echo htmlspecialchars($s['caption']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($qurban_slides) > 1): ?>
        <button type="button" class="qs-nav qs-nav-prev" aria-label="Slide sebelumnya"><i class="fas fa-chevron-left"></i></button>
        <button type="button" class="qs-nav qs-nav-next" aria-label="Slide berikutnya"><i class="fas fa-chevron-right"></i></button>
        <div class="qs-dots" id="qsDots" role="tablist" aria-label="Indikator slide"></div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="qs-empty">
        <i class="fas fa-photo-video" aria-hidden="true"></i>
        <strong>Galeri Qurban</strong>
        <span>Letakkan foto atau video (jpg, png, webp, mp4, webm) di folder <code style="background:rgba(0,0,0,.2);padding:2px 6px;border-radius:4px;font-size:12px">assets/qurban/slides/</code>, atau isi array <code style="background:rgba(0,0,0,.2);padding:2px 6px;border-radius:4px;font-size:12px">$qurban_slides_manual</code> di bagian atas file ini.</span>
    </div>
    <?php endif; ?>

    <?php if (count($home_main_images) > 0): ?>
    <div class="section">
        <div class="home-intro">
            <h3>Pilih Qurban Terbaik Mu</h3>
            <p class="home-intro-lead">Berkah bagi saudara, jalan kita ke Surga</p>
            <p class="home-intro-quote">“Sesungguhnya kami telah memberikan kepadamu nikmat yang banyak. Maka dirikanlah sholat karena Tuhanmu, dan berqurbanlah”. (QS Al Kautsar : 1-2).</p>
        </div>
        <div class="home-main-grid" aria-label="Galeri halaman utama">
            <?php foreach ($home_main_images as $idx => $img): ?>
                <?php $hmHref = $home_main_program_hrefs[$idx] ?? ''; ?>
                <div class="home-main-item">
                    <?php if ($hmHref !== ''): ?>
                        <a class="home-main-link" href="<?php echo htmlspecialchars($hmHref); ?>">
                            <img src="<?php echo htmlspecialchars($img); ?>" alt="" loading="lazy" decoding="async">
                        </a>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($img); ?>" alt="" loading="lazy" decoding="async">
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (file_exists(__DIR__ . '/assets/halaman_utama/qurban_bencana.png')): ?>
        <div class="home-main-single">
            <div class="home-main-item">
                <button type="button" class="home-main-link js-home-sumbar-open" aria-haspopup="dialog" aria-controls="sumbarChoiceModal" aria-expanded="false">
                    <img src="assets/halaman_utama/qurban_bencana.png" alt="Qurban Sumbar" loading="lazy" decoding="async">
                </button>
            </div>
        </div>
        <div class="home-sumbar-modal" id="sumbarChoiceModal" aria-hidden="true">
            <div class="home-sumbar-dialog" role="dialog" aria-modal="true" aria-labelledby="sumbarChoiceTitle">
                <h3 id="sumbarChoiceTitle">Pilih qurban Sumbar</h3>
                <p>Pilih salah satu program, lalu lanjut isi data di halaman detail.</p>
                <div class="home-sumbar-actions">
                    <a class="primary" href="detail_qurban.php?program=<?php echo rawurlencode('qurban-sumbar-1per7'); ?>">1/7 Sapi — Rp 2.600.000</a>
                    <a class="primary" href="detail_qurban.php?program=<?php echo rawurlencode('qurban-sumbar-1ekor'); ?>">1 Ekor Sapi — Rp 18.200.000</a>
                    <button type="button" class="ghost js-home-sumbar-close">Batal</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="qurban-steps">
            <h4>Cara ber Qurban</h4>
            <div class="qurban-step-item">
                <span class="qurban-step-no">1</span>
                <p class="qurban-step-text">Tentukan jumlah hewan qurban yang ingin anda sumbangkan.</p>
            </div>
            <div class="qurban-step-item">
                <span class="qurban-step-no">2</span>
                <p class="qurban-step-text">Daftarkan identitas anda serta nama pequrban.</p>
            </div>
            <div class="qurban-step-item">
                <span class="qurban-step-no">3</span>
                <p class="qurban-step-text">Lakukan pembayaran sesuai dengan jenis dan jumlah hewan qurban.</p>
            </div>
            <div class="qurban-step-item">
                <span class="qurban-step-no">4</span>
                <p class="qurban-step-text">Dapatkan notifikasi dari Wa setelah melakukan pembayaran untuk mendapatkan tanda bukti Qurban.</p>
            </div>
            <div class="qurban-step-item">
                <span class="qurban-step-no">6</span>
                <p class="qurban-step-text">Anda akan menerima sertifikat &amp; laporan hasil qurban melalu no Wa yang terdaftar paling lambat 14 Hari kerja.</p>
            </div>
            <div class="qurban-step-item">
                <span class="qurban-step-no">5</span>
                <p class="qurban-step-text">Anda akan diberitahu melalui WA jika hewan Qurban anda telah di semblih.</p>
            </div>
        </div>
        <div class="qurban-video-wrap">
            <div class="qurban-video-group">
                <p class="qurban-video-title">Palestina</p>
                <div class="qurban-video-grid">
                    <div class="qurban-video-card">
                        <p class="qurban-video-label">Idul Adha 1445</p>
                        <div class="qurban-video-frame">
                            <iframe
                                src="https://www.youtube.com/embed/QaOfQwbCiuU"
                                title="Palestina Idul Adha 1445"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen>
                            </iframe>
                        </div>
                    </div>
                    <div class="qurban-video-card">
                        <p class="qurban-video-label">Idul Adha 1446</p>
                        <div class="qurban-video-frame">
                            <iframe
                                src="https://www.youtube.com/embed/Zd5jd_N7Q-A"
                                title="Palestina Idul Adha 1446"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen>
                            </iframe>
                        </div>
                    </div>
                </div>
            </div>
            <div class="qurban-video-group">
                <p class="qurban-video-title">Afrika</p>
                <div class="qurban-video-grid">
                    <div class="qurban-video-card">
                        <p class="qurban-video-label">Idul Adha 1445</p>
                        <div class="qurban-video-frame">
                            <iframe
                                src="https://www.youtube.com/embed/nNMl7lzb1OI"
                                title="Afrika Idul Adha 1445"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen>
                            </iframe>
                        </div>
                    </div>
                    <div class="qurban-video-card">
                        <p class="qurban-video-label">Idul Adha 1446</p>
                        <div class="qurban-video-frame">
                            <iframe
                                src="https://www.youtube.com/embed/hWGI-yZDUEE"
                                title="Afrika Idul Adha 1446"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen>
                            </iframe>
                        </div>
                    </div>
                </div>
            </div>
            <div class="qurban-video-group">
                <p class="qurban-video-title">Mentawai</p>
                <div class="qurban-video-grid-single">
                    <div class="qurban-video-card">
                        <p class="qurban-video-label">Idul Adha Mentawai 1446</p>
                        <div class="qurban-video-frame">
                            <iframe
                                src="https://www.youtube.com/embed/CtJvfRawugc"
                                title="Mentawai Idul Adha 1446"
                                loading="lazy"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                referrerpolicy="strict-origin-when-cross-origin"
                                allowfullscreen>
                            </iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php if (file_exists(__DIR__ . '/assets/laporan/laporan_data.jpeg')): ?>
        <div class="qurban-report-wrap">
            <p class="qurban-report-title">Laporan Qurban</p>
            <img src="assets/laporan/laporan_data.jpeg" alt="Laporan Qurban" class="qurban-report-image" loading="lazy" decoding="async">
        </div>
        <?php endif; ?>
        <?php if (file_exists(__DIR__ . '/assets/sertifikat.png')): ?>
        <div class="qurban-report-wrap">
            <p class="qurban-report-title">Sertifikat Qurban</p>
            <img src="assets/sertifikat.png" alt="Sertifikat Qurban" class="qurban-report-image" loading="lazy" decoding="async">
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<!-- Modal: Form pendaftaran qurban -->
<div class="qurban-sheet-modal" id="modalDaftarQurban" role="dialog" aria-modal="true" aria-labelledby="titleDaftarQurban">
    <div class="qurban-sheet-backdrop" onclick="closeModalDaftarQurban()"></div>
    <div class="qurban-sheet-panel" onclick="event.stopPropagation()">
        <div class="qurban-sheet-header">
            <h2 id="titleDaftarQurban">Form pendaftaran</h2>
            <button type="button" class="qurban-sheet-close" onclick="closeModalDaftarQurban()" aria-label="Tutup"><i class="fas fa-times"></i></button>
        </div>
        <div class="qurban-sheet-scroll">
            <div class="form-card">
                <?php if ($success): ?>
                    <div class="alert ok">Terima kasih, pendaftaran qurban Anda telah kami terima. Tim akan segera menghubungi Anda.</div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert err"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($inactive_referral_notice !== ''): ?>
                    <div class="alert err"><?php echo htmlspecialchars($inactive_referral_notice); ?></div>
                <?php endif; ?>
                <?php if ($active_referral !== ''): ?>
                    <div class="alert ok" style="font-size:12px;line-height:1.5">Anda membuka halaman lewat tautan mitra. Pendaftaran Anda akan tercatat untuk mitra <strong><?php echo htmlspecialchars($active_referral); ?></strong> (tanpa mengubah isi halaman qurban).</div>
                <?php endif; ?>

                <form method="post" action="index.php" id="formDaftarQurban">
                    <input type="hidden" name="daftar_qurban" value="1">
                    <input type="hidden" name="affiliate_ref" value="<?php echo htmlspecialchars($active_referral); ?>">
                    <div class="form-group">
                        <label for="nama">Nama lengkap</label>
                        <input type="text" id="nama" name="nama" required maxlength="120"
                            value="<?php echo (!$success && isset($_POST['nama'])) ? htmlspecialchars((string)$_POST['nama']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="kontak">WhatsApp / telepon</label>
                        <input type="tel" id="kontak" name="kontak" required maxlength="80" inputmode="tel" placeholder="08xxxxxxxxxx"
                            value="<?php echo (!$success && isset($_POST['kontak'])) ? htmlspecialchars((string)$_POST['kontak']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="lokasi">Lokasi qurban</label>
                        <select id="lokasi" name="lokasi" required>
                            <option value="">— Pilih —</option>
                            <?php foreach ($qurban_locations as $loc): ?>
                                <?php $lokVal = (string)$loc['id']; ?>
                                <option value="<?php echo htmlspecialchars($lokVal); ?>"
                                    <?php echo $prefill_lokasi === $lokVal ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$loc['title'] . ' - ' . (string)$loc['desc']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <span class="jenis-heading" id="jenis-label">Jenis qurban</span>
                        <?php $pj = (!$success && isset($_POST['jenis_qurban'])) ? (string)$_POST['jenis_qurban'] : ''; ?>
                        <div class="jenis-grid" role="radiogroup" aria-labelledby="jenis-label">
                            <label class="jenis-option">
                                <input class="jenis-input" type="radio" name="jenis_qurban" value="1per7" required<?php echo ($pj === '1per7' || $pj === '') ? ' checked' : ''; ?>>
                                <span class="jenis-check" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                                <span class="jenis-card-inner">
                                    <span class="jenis-icon"><i class="fas fa-chart-pie"></i></span>
                                    <span class="jenis-title">Qurban 1/7</span>
                                    <span class="jenis-desc">Satu bagian patungan — lebih ringan di kantong</span>
                                </span>
                            </label>
                            <label class="jenis-option">
                                <input class="jenis-input" type="radio" name="jenis_qurban" value="1ekor" required<?php echo $pj === '1ekor' ? ' checked' : ''; ?>>
                                <span class="jenis-check" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                                <span class="jenis-card-inner">
                                    <span class="jenis-icon"><i class="fas fa-drumstick-bite"></i></span>
                                    <span class="jenis-title">1 ekor utuh</span>
                                    <span class="jenis-desc">Satu hewan lengkap khusus untuk Anda</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="catatan">Catatan (opsional)</label>
                        <textarea id="catatan" name="catatan" maxlength="2000" placeholder="Jumlah hewan, preferensi panti, dll."><?php echo isset($_POST['catatan']) ? htmlspecialchars((string)$_POST['catatan']) : ''; ?></textarea>
                    </div>
                    <button type="submit" class="btn-submit">Kirim pendaftaran</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="qurban-cta-bar" role="toolbar" aria-label="Aksi qurban">
    <a href="pilih_qurban.php" class="qurban-cta qurban-cta-primary">Daftar Qurban</a>
    <a href="qurban-affiliate.php" class="qurban-cta qurban-cta-secondary">Qurban Affiliate</a>
</div>

<script>
(function () {
    var root = document.getElementById('qurbanSlideshow');
    var vp = document.getElementById('qsViewport');
    if (root && vp && vp.children.length > 0) {
        var slides = vp.querySelectorAll('.qs-slide');
        var n = slides.length;
        var dotsWrap = document.getElementById('qsDots');
        var idx = 0;
        var timer;
        function slideW() {
            return vp.clientWidth || 1;
        }
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
            dotsWrap.querySelectorAll('.qs-dot').forEach(function (dot, i) {
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
                    b.className = 'qs-dot' + (j === 0 ? ' is-active' : '');
                    b.setAttribute('aria-label', 'Tampilkan slide ' + (j + 1));
                    b.addEventListener('click', function () { go(j); });
                    dotsWrap.appendChild(b);
                })(d);
            }
        }
        var prev = root.querySelector('.qs-nav-prev');
        var next = root.querySelector('.qs-nav-next');
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
    }

    document.querySelectorAll('.jenis-input').forEach(function (inp) {
        function syncJenis() {
            document.querySelectorAll('.jenis-option').forEach(function (lab) {
                var r = lab.querySelector('.jenis-input');
                if (r) lab.classList.toggle('is-selected', r.checked);
            });
        }
        inp.addEventListener('change', syncJenis);
        syncJenis();
    });

    (function () {
        var m = document.getElementById('sumbarChoiceModal');
        var openBtn = document.querySelector('.js-home-sumbar-open');
        if (!m || !openBtn) return;
        function openSumbarChoiceModal() {
            m.classList.add('is-open');
            m.setAttribute('aria-hidden', 'false');
            openBtn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        function closeSumbarChoiceModal() {
            m.classList.remove('is-open');
            m.setAttribute('aria-hidden', 'true');
            openBtn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }
        openBtn.addEventListener('click', openSumbarChoiceModal);
        m.addEventListener('click', function (e) {
            if (e.target === m) closeSumbarChoiceModal();
        });
        m.querySelectorAll('.js-home-sumbar-close').forEach(function (b) {
            b.addEventListener('click', closeSumbarChoiceModal);
        });
        window.closeSumbarChoiceModal = closeSumbarChoiceModal;
    })();
})();

function openModalDaftarQurban() {
    var el = document.getElementById('modalDaftarQurban');
    if (el) {
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}
function closeModalDaftarQurban() {
    var el = document.getElementById('modalDaftarQurban');
    if (el) {
        el.classList.remove('show');
        document.body.style.overflow = '';
    }
}
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var sm = document.getElementById('sumbarChoiceModal');
    if (sm && sm.classList.contains('is-open')) {
        if (typeof window.closeSumbarChoiceModal === 'function') {
            window.closeSumbarChoiceModal();
        }
        return;
    }
    closeModalDaftarQurban();
});

<?php if ($success || $error !== '' || $inactive_referral_notice !== '' || isset($_GET['open_daftar'])): ?>
document.addEventListener('DOMContentLoaded', function () { openModalDaftarQurban(); });
<?php endif; ?>
</script>
</body>
</html>

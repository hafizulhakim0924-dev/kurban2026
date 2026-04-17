<?php
session_start();

header('Content-Type: text/html; charset=utf-8');

define('QURBAN_SITE_BASE', 'https://qurban.kolaboraksi.com');

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

function admin_qurban_ensure_table(mysqli $conn): void {
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
    mysqli_query($conn, $sql);

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

$flash_ok = '';
$flash_err = '';

if ($conn) {
    admin_qurban_ensure_table($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_affiliate']) && $conn) {
    $id = (int)($_POST['affiliate_id'] ?? 0);
    if ($id <= 0) {
        $flash_err = 'ID mitra tidak valid.';
    } else {
        $cek = mysqli_prepare($conn, "SELECT `id`, `affiliate_slug`, `approval_status` FROM `qurban_affiliates` WHERE `id` = ? LIMIT 1");
        if ($cek) {
            mysqli_stmt_bind_param($cek, 'i', $id);
            mysqli_stmt_execute($cek);
            $res = mysqli_stmt_get_result($cek);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            if ($res) {
                mysqli_free_result($res);
            }
            mysqli_stmt_close($cek);

            if (!$row) {
                $flash_err = 'Data mitra tidak ditemukan.';
            } elseif ((string)($row['approval_status'] ?? '') === 'approved') {
                $flash_ok = 'Mitra ini sudah berstatus aktif sebelumnya.';
            } elseif (trim((string)($row['affiliate_slug'] ?? '')) === '') {
                $flash_err = 'Belum bisa ACC: mitra belum membuat slug/link afiliasi.';
            } else {
                $q = mysqli_prepare($conn, "UPDATE `qurban_affiliates` SET `approval_status` = 'approved', `approved_at` = NOW() WHERE `id` = ? AND `approval_status` != 'approved'");
                if ($q) {
                    mysqli_stmt_bind_param($q, 'i', $id);
                    mysqli_stmt_execute($q);
                    $sqlErr = mysqli_stmt_error($q);
                    mysqli_stmt_close($q);
                    if ($sqlErr === '') {
                        $flash_ok = 'Pendaftaran berhasil dikonfirmasi. Link afiliasi sudah aktif.';
                    } else {
                        $flash_err = 'Gagal konfirmasi. Silakan coba lagi.';
                    }
                } else {
                    $flash_err = 'Gagal menyiapkan konfirmasi.';
                }
            }
        } else {
            $flash_err = 'Gagal membaca data mitra.';
        }
    }
}

$pending = [];
$approved = [];

if ($conn) {
    $res1 = mysqli_query($conn, "SELECT `id`, `nama`, `kontak`, `kota`, `affiliate_slug`, `created_at` FROM `qurban_affiliates` WHERE `approval_status` = 'pending' OR `approval_status` = '' OR `approval_status` IS NULL ORDER BY `created_at` DESC LIMIT 300");
    if ($res1) {
        while ($row = mysqli_fetch_assoc($res1)) {
            $pending[] = $row;
        }
        mysqli_free_result($res1);
    }

    $res2 = mysqli_query($conn, "SELECT `id`, `nama`, `affiliate_slug`, `approved_at` FROM `qurban_affiliates` WHERE `approval_status` = 'approved' ORDER BY `approved_at` DESC, `created_at` DESC LIMIT 200");
    if ($res2) {
        while ($row = mysqli_fetch_assoc($res2)) {
            $approved[] = $row;
        }
        mysqli_free_result($res2);
    }

    mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Qurban - Konfirmasi Affiliate</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: #f4f7f7; color: #1a1a1a; }
        .wrap { max-width: 920px; margin: 0 auto; padding: 20px; }
        .card { background: #fff; border: 1px solid #e3ecea; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .top-logo { height: 46px; width: auto; display: block; margin-bottom: 10px; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .muted { color: #667; font-size: 13px; }
        .ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
        .err { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 10px 12px; border-radius: 10px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #eef2f2; font-size: 13px; text-align: left; vertical-align: top; }
        th { font-size: 12px; color: #445; background: #f8fbfb; }
        .btn {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            background: #0f7a6e;
            color: #fff;
        }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .badge.pending { background: #fff7db; color: #946200; }
        .badge.ok { background: #e8f5f2; color: #0f7a6e; border: none; margin: 0; }
        code { background: #f2f6f6; padding: 2px 6px; border-radius: 6px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <?php if (file_exists(__DIR__ . '/assets/logo.png')): ?>
                <img src="assets/logo.png" alt="KolaborAksi" class="top-logo" width="220" height="46">
            <?php endif; ?>
            <h1>Admin Qurban - Konfirmasi Pendaftaran Affiliate</h1>
            <div class="muted">Halaman ini untuk mengaktifkan link affiliate setelah tim memverifikasi pendaftar.</div>
        </div>

        <?php if ($flash_ok !== ''): ?>
            <div class="ok"><?php echo htmlspecialchars($flash_ok); ?></div>
        <?php endif; ?>
        <?php if ($flash_err !== ''): ?>
            <div class="err"><?php echo htmlspecialchars($flash_err); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 style="margin-top:0">Menunggu konfirmasi (<?php echo count($pending); ?>)</h2>
            <?php if (count($pending) === 0): ?>
                <div class="muted">Tidak ada pendaftaran pending.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Kontak</th>
                            <th>Wilayah</th>
                            <th>Link</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$p['nama']); ?></td>
                                <td><?php echo htmlspecialchars((string)$p['kontak']); ?></td>
                                <td><?php echo htmlspecialchars((string)$p['kota']); ?></td>
                                <td>
                                    <?php if (!empty($p['affiliate_slug'])): ?>
                                        <code><?php echo htmlspecialchars(rtrim(QURBAN_SITE_BASE, '/') . '/' . (string)$p['affiliate_slug']); ?></code>
                                    <?php else: ?>
                                        <span class="muted">Belum mengisi slug</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge pending">Pending</span></td>
                                <td>
                                    <form method="post" action="admin_qurban.php">
                                        <input type="hidden" name="approve_affiliate" value="1">
                                        <input type="hidden" name="affiliate_id" value="<?php echo (int)$p['id']; ?>">
                                        <button type="submit" class="btn">Konfirmasi</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0">Sudah aktif (<?php echo count($approved); ?>)</h2>
            <?php if (count($approved) === 0): ?>
                <div class="muted">Belum ada link affiliate yang diaktifkan.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Link</th>
                            <th>Status</th>
                            <th>Waktu aktif</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$a['nama']); ?></td>
                                <td>
                                    <?php if (!empty($a['affiliate_slug'])): ?>
                                        <code><?php echo htmlspecialchars(rtrim(QURBAN_SITE_BASE, '/') . '/' . (string)$a['affiliate_slug']); ?></code>
                                    <?php else: ?>
                                        <span class="muted">Tanpa slug</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge ok">Approved</span></td>
                                <td><?php echo !empty($a['approved_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string)$a['approved_at']))) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

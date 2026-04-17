<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * Prioritas config:
 * 1) tripay_config.php (kompatibilitas callback lama)
 * 2) config.secrets.php (project ini)
 */
$legacyConfig = __DIR__ . '/tripay_config.php';
if (is_file($legacyConfig)) {
    require_once $legacyConfig;
}
$localSecrets = __DIR__ . '/config.secrets.php';
if (is_file($localSecrets)) {
    require_once $localSecrets;
}

function cfg(string $key, string $default = ''): string
{
    if (defined($key)) {
        $v = constant($key);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    $env = getenv($key);
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
        return trim($_ENV[$key]);
    }
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key]) !== '') {
        return trim($_SERVER[$key]);
    }
    return $default;
}

function callback_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_callback_db(): ?mysqli
{
    if (function_exists('getDBConnection')) {
        $conn = getDBConnection();
        return $conn instanceof mysqli ? $conn : null;
    }

    $dbHost = cfg('DB_HOST');
    $dbUser = cfg('DB_USER');
    $dbPass = cfg('DB_PASS');
    $dbName = cfg('DB_NAME');

    if ($dbHost === '' || $dbUser === '' || $dbName === '') {
        return null;
    }

    $conn = @mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
    if (!$conn) {
        return null;
    }
    mysqli_set_charset($conn, 'utf8mb4');
    return $conn;
}

function table_exists(mysqli $conn, string $table): bool
{
    $sql = "SHOW TABLES LIKE ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res && mysqli_num_rows($res) > 0;
    if ($res) {
        mysqli_free_result($res);
    }
    mysqli_stmt_close($stmt);
    return $ok;
}

function append_callback_log(string $signature, string $json): void
{
    $target = __DIR__ . '/callback_log.txt';
    @file_put_contents(
        $target,
        date('Y-m-d H:i:s') . "\n" .
        'Signature: ' . $signature . "\n" .
        'Data: ' . $json . "\n\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Menjalankan callback lama persis alur dasarnya:
 * - payment_logs
 * - donations
 * - campaigns
 * Return true jika callback lama memproses transaksi.
 */
function process_legacy_donation_callback(mysqli $conn, array $data, string $json): bool
{
    if (!table_exists($conn, 'donations')) {
        return false;
    }

    $tripayReference = (string)($data['reference'] ?? '');
    $status = (string)($data['status'] ?? '');
    if ($tripayReference === '') {
        return false;
    }

    if (table_exists($conn, 'payment_logs')) {
        $logSql = "INSERT INTO payment_logs (tripay_reference, event_type, status, payload) VALUES (?, ?, ?, ?)";
        $logStmt = $conn->prepare($logSql);
        if ($logStmt) {
            $eventType = 'payment_status';
            $logStmt->bind_param('ssss', $tripayReference, $eventType, $status, $json);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    $sql = "SELECT * FROM donations WHERE tripay_reference = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $tripayReference);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        if ($result) {
            $result->free();
        }
        $stmt->close();
        return false;
    }
    $donation = $result->fetch_assoc();
    $result->free();
    $stmt->close();

    $donationId = (int)$donation['id'];
    $campaignId = (int)$donation['campaign_id'];
    $prevStatus = (string)($donation['status'] ?? '');
    $donationAmount = (int)($donation['amount'] ?? 0);

    $newStatus = 'UNPAID';
    $paidAt = null;
    if ($status === 'PAID') {
        $newStatus = 'PAID';
        $paidAt = date('Y-m-d H:i:s');
    } elseif ($status === 'EXPIRED') {
        $newStatus = 'EXPIRED';
    } elseif ($status === 'FAILED') {
        $newStatus = 'FAILED';
    }

    if ($paidAt !== null) {
        $updateSql = "UPDATE donations SET status = ?, paid_at = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        if ($updateStmt) {
            $updateStmt->bind_param('ssi', $newStatus, $paidAt, $donationId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        // Hindari double-count jika callback PAID masuk ulang.
        if ($prevStatus !== 'PAID' && $donationAmount > 0 && table_exists($conn, 'campaigns')) {
            $campSql = "UPDATE campaigns SET donasi_terkumpul = donasi_terkumpul + ? WHERE id = ?";
            $campStmt = $conn->prepare($campSql);
            if ($campStmt) {
                $campStmt->bind_param('ii', $donationAmount, $campaignId);
                $campStmt->execute();
                $campStmt->close();
            }
        }
    } else {
        $updateSql = "UPDATE donations SET status = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        if ($updateStmt) {
            $updateStmt->bind_param('si', $newStatus, $donationId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }

    return true;
}

/**
 * Fallback qurban:
 * simpan raw callback agar tidak hilang saat reference bukan donations lama.
 */
function process_qurban_fallback(array $data, string $json): void
{
    $dir = __DIR__ . '/uploads/qurban_bukti';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $row = [
        'waktu' => date('c'),
        'reference' => (string)($data['reference'] ?? ''),
        'merchant_ref' => (string)($data['merchant_ref'] ?? ''),
        'status' => (string)($data['status'] ?? ''),
        'payment_method' => (string)($data['payment_method'] ?? ''),
        'payment_name' => (string)($data['payment_name'] ?? ''),
        'amount_received' => isset($data['amount_received']) ? (int)$data['amount_received'] : 0,
        'payload' => $json,
    ];
    @file_put_contents(
        $dir . '/tripay_callback.log',
        json_encode($row, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

$privateKey = cfg('TRIPAY_PRIVATE_KEY');
if ($privateKey === '') {
    callback_fail(500, 'TRIPAY_PRIVATE_KEY belum diatur');
}

$callbackSignature = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? (string)$_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';
$json = file_get_contents('php://input');
if (!is_string($json) || trim($json) === '' || $callbackSignature === '') {
    callback_fail(400, 'Invalid request');
}

append_callback_log($callbackSignature, $json);

$computedSignature = hash_hmac('sha256', $json, $privateKey);
if (!hash_equals($computedSignature, $callbackSignature)) {
    callback_fail(403, 'Invalid signature');
}

$data = json_decode($json, true);
if (!is_array($data)) {
    callback_fail(400, 'Invalid JSON data');
}

$conn = get_callback_db();
if ($conn instanceof mysqli) {
    $processedLegacy = process_legacy_donation_callback($conn, $data, $json);
    $conn->close();
    if (!$processedLegacy) {
        process_qurban_fallback($data, $json);
    }
} else {
    process_qurban_fallback($data, $json);
}

http_response_code(200);
echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
exit;


<?php
header('Content-Type: text/html; charset=utf-8');

/** Opsional: simpan kredensial di file lokal yang tidak dipublikasikan. */
$qurbanSecretFile = __DIR__ . '/config.secrets.php';
if (is_file($qurbanSecretFile)) {
    require_once $qurbanSecretFile;
}

function rupiah(int $n): string {
    return 'Rp' . number_format($n, 0, ',', '.');
}

function qurban_secret(string $key, string $default = ''): string {
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

function qurban_normalize_phone_wa(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '62') === 0) {
        return $digits;
    }
    if ($digits[0] === '0') {
        return '62' . substr($digits, 1);
    }
    if ($digits[0] === '8') {
        return '62' . $digits;
    }
    return $digits;
}

/**
 * Kirim notifikasi ke Watzap.
 * Return: ['ok' => bool, 'status_code' => int, 'response' => string, 'error' => string]
 */
function qurban_send_watzap_message(string $phoneWa, string $message): array {
    $apiKey = qurban_secret('WATZAP_API_KEY');
    $numberKey = qurban_secret('WATZAP_NUMBER_KEY');
    if ($apiKey === '' || $numberKey === '') {
        return ['ok' => false, 'status_code' => 0, 'response' => '', 'error' => 'Kredensial Watzap belum diatur'];
    }
    if ($phoneWa === '') {
        return ['ok' => false, 'status_code' => 0, 'response' => '', 'error' => 'Nomor tujuan kosong'];
    }

    $payload = [
        'api_key' => $apiKey,
        'number_key' => $numberKey,
        'phone_no' => $phoneWa,
        'message' => $message,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return ['ok' => false, 'status_code' => 0, 'response' => '', 'error' => 'Gagal membentuk payload JSON'];
    }

    $ch = curl_init('https://api.watzap.id/v1/waba_send_message');
    if ($ch === false) {
        return ['ok' => false, 'status_code' => 0, 'response' => '', 'error' => 'Gagal inisialisasi cURL'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'status_code' => $statusCode, 'response' => '', 'error' => $err !== '' ? $err : 'Request Watzap gagal'];
    }

    $ok = $statusCode >= 200 && $statusCode < 300;
    return ['ok' => $ok, 'status_code' => $statusCode, 'response' => (string)$response, 'error' => $ok ? '' : 'HTTP ' . $statusCode];
}

function qurban_tripay_methods(): array {
    return [
        'QRIS' => 'Tripay - QRIS',
        'BRIVA' => 'Tripay - BRI Virtual Account',
        'BNIVA' => 'Tripay - BNI Virtual Account',
        'BSIVA' => 'Tripay - BSI Virtual Account',
        'MANDIRIVA' => 'Tripay - Mandiri Virtual Account',
        'PERMATAVA' => 'Tripay - Permata Virtual Account',
    ];
}

function qurban_current_base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/detail_qurban.php'));
    $dir = rtrim(str_replace('/detail_qurban.php', '', $script), '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * Return: ['ok' => bool, 'checkout_url' => string, 'reference' => string, 'merchant_ref' => string, 'response' => string, 'error' => string]
 */
function qurban_create_tripay_transaction(array $params): array {
    $apiKey = qurban_secret('TRIPAY_API_KEY');
    $privateKey = qurban_secret('TRIPAY_PRIVATE_KEY');
    $merchantCode = qurban_secret('TRIPAY_MERCHANT_CODE');
    $apiBase = rtrim(qurban_secret('TRIPAY_API_BASE', 'https://tripay.co.id/api'), '/');
    if ($apiKey === '' || $privateKey === '' || $merchantCode === '') {
        return ['ok' => false, 'checkout_url' => '', 'reference' => '', 'merchant_ref' => '', 'response' => '', 'error' => 'Konfigurasi Tripay belum lengkap'];
    }

    $method = (string)($params['method'] ?? '');
    $amount = (int)($params['amount'] ?? 0);
    $name = trim((string)($params['name'] ?? ''));
    $phone = trim((string)($params['phone'] ?? ''));
    $programTitle = trim((string)($params['program_title'] ?? 'Qurban'));
    $qty = max(1, (int)($params['qty'] ?? 1));
    if ($method === '' || $amount <= 0 || $name === '' || $phone === '') {
        return ['ok' => false, 'checkout_url' => '', 'reference' => '', 'merchant_ref' => '', 'response' => '', 'error' => 'Parameter Tripay tidak valid'];
    }

    $merchantRef = 'QURBAN-' . date('YmdHis') . '-' . random_int(100, 999);
    $signature = hash_hmac('sha256', $merchantCode . $merchantRef . $amount, $privateKey);
    $baseUrl = qurban_current_base_url();
    $callbackUrl = qurban_secret('TRIPAY_CALLBACK_URL', $baseUrl . '/tripay_callback.php');
    $returnUrl = qurban_secret('TRIPAY_RETURN_URL', $baseUrl . '/detail_qurban.php');

    $payload = [
        'method' => $method,
        'merchant_ref' => $merchantRef,
        'amount' => $amount,
        'customer_name' => $name,
        'customer_email' => qurban_secret('TRIPAY_CUSTOMER_EMAIL', 'qurban@kolaboraksi.com'),
        'customer_phone' => qurban_normalize_phone_wa($phone),
        'order_items' => [
            [
                'name' => $programTitle,
                'price' => (int)($amount / $qty),
                'quantity' => $qty,
            ],
        ],
        'return_url' => $returnUrl,
        'callback_url' => $callbackUrl,
        'expired_time' => time() + 24 * 60 * 60,
        'signature' => $signature,
    ];

    $ch = curl_init($apiBase . '/transaction/create');
    if ($ch === false) {
        return ['ok' => false, 'checkout_url' => '', 'reference' => '', 'merchant_ref' => $merchantRef, 'response' => '', 'error' => 'Gagal inisialisasi cURL'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'checkout_url' => '', 'reference' => '', 'merchant_ref' => $merchantRef, 'response' => '', 'error' => $err !== '' ? $err : 'Request Tripay gagal'];
    }
    $decoded = json_decode((string)$response, true);
    $ok = is_array($decoded) && !empty($decoded['success']) && $statusCode >= 200 && $statusCode < 300;
    $data = is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
    $checkoutUrl = (string)($data['checkout_url'] ?? '');
    $reference = (string)($data['reference'] ?? '');
    $errMsg = '';
    if (!$ok) {
        $apiMsg = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
        $errMsg = $apiMsg !== '' ? $apiMsg : ('HTTP ' . $statusCode);
    }
    return [
        'ok' => $ok,
        'checkout_url' => $checkoutUrl,
        'reference' => $reference,
        'merchant_ref' => $merchantRef,
        'response' => (string)$response,
        'error' => $errMsg,
    ];
}

$qurban_programs = [
    'qurban-afrika-1per7' => [
        'id' => 'qurban-afrika-1per7',
        'title' => 'Qurban 1/7 Sapi | Tipe Afrika',
        'price' => 'Rp 1.600.000',
        'price_int' => 1600000,
        'secondary' => '1 Sapi Rp 11.200.000',
        'image' => 'assets/lokasi_qurban/afrika.png',
        'collected' => 'Rp13.300.000',
        'disbursed' => 'Rp0',
        'donors' => '7',
        'target' => 'Rp250.000.000',
        'progress' => 5,
    ],
    'pengungsi-palestina-yaman-domba' => [
        'id' => 'pengungsi-palestina-yaman-domba',
        'title' => 'Pengungsi Palestina (Yaman) | Domba',
        'price' => 'Rp 3.000.000',
        'price_int' => 3000000,
        'secondary' => 'Berat kisaran 30 Kg',
        'image' => 'assets/lokasi_qurban/yaman.png',
        'collected' => 'Rp2.000.000',
        'disbursed' => 'Rp0',
        'donors' => '1',
        'target' => 'Rp120.000.000',
        'progress' => 1,
    ],
    'pengungsi-palestina-mesir-domba' => [
        'id' => 'pengungsi-palestina-mesir-domba',
        'title' => 'Pengungsi Palestina (Mesir) | Domba',
        'price' => 'Rp 5.000.000',
        'price_int' => 5000000,
        'secondary' => 'Berat kisaran 45 Kg',
        'image' => 'assets/lokasi_qurban/mesir.png',
        'collected' => 'Rp0',
        'disbursed' => 'Rp0',
        'donors' => '0',
        'target' => 'Rp180.000.000',
        'progress' => 0,
    ],
    'qurban-mentawai-1per7' => [
        'id' => 'qurban-mentawai-1per7',
        'title' => 'Qurban 1/7 Sapi | Tipe Mentawai',
        'price' => 'Rp 3.500.000',
        'price_int' => 3500000,
        'secondary' => '1 Sapi Rp 24.500.000',
        'image' => 'assets/lokasi_qurban/mentawai.png',
        'collected' => 'Rp0',
        'disbursed' => 'Rp0',
        'donors' => '0',
        'target' => 'Rp200.000.000',
        'progress' => 0,
    ],
    'qurban-sumbar-1per7' => [
        'id' => 'qurban-sumbar-1per7',
        'title' => 'Qurban 1/7 Sapi | Tipe Sumbar',
        'price' => 'Rp 2.600.000',
        'price_int' => 2600000,
        'secondary' => '1 Sapi Rp 18.200.000',
        'image' => 'assets/lokasi_qurban/sumbar.png',
        'collected' => 'Rp0',
        'disbursed' => 'Rp0',
        'donors' => '0',
        'target' => 'Rp160.000.000',
        'progress' => 0,
    ],
    'qurban-sumbar-1ekor' => [
        'id' => 'qurban-sumbar-1ekor',
        'title' => 'Qurban 1 Ekor Sapi | Tipe Sumbar',
        'price' => 'Rp 18.200.000',
        'price_int' => 18200000,
        'secondary' => 'Program Qurban 1 Ekor Sapi',
        'image' => 'assets/lokasi_qurban/sumbar.png',
        'collected' => 'Rp0',
        'disbursed' => 'Rp0',
        'donors' => '0',
        'target' => 'Rp350.000.000',
        'progress' => 0,
    ],
];

$programId = isset($_GET['program']) ? trim((string)$_GET['program']) : '';
if (!isset($qurban_programs[$programId])) {
    $keys = array_keys($qurban_programs);
    $programId = $keys[0];
}
$program = $qurban_programs[$programId];

$step = (isset($_GET['step']) && $_GET['step'] === 'biodata') ? 'biodata' : 'detail';
$qty = isset($_GET['qty']) ? (int)$_GET['qty'] : 1;
if ($qty < 1) {
    $qty = 1;
}
if ($qty > 20) {
    $qty = 20;
}
$pekurbanNames = [];
for ($i = 1; $i <= $qty; $i++) {
    $v = isset($_GET['nama_hewan_' . $i]) ? trim((string)$_GET['nama_hewan_' . $i]) : '';
    $pekurbanNames[$i] = mb_substr($v, 0, 80);
}
$total = $qty * (int)$program['price_int'];

$tripay_methods = qurban_tripay_methods();
$konfirmasi_error = '';
$repop = [
    'full_name' => '',
    'phone' => '',
    'doa' => '',
    'payment_method' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buat_pembayaran_tripay']) && (string)$_POST['buat_pembayaran_tripay'] === '1') {
    $postProg = isset($_POST['program']) ? trim((string)$_POST['program']) : '';
    $pQty = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;
    if ($pQty < 1) {
        $pQty = 1;
    }
    if ($pQty > 20) {
        $pQty = 20;
    }
    $pay = isset($_POST['payment_method']) ? trim((string)$_POST['payment_method']) : '';

    $repop['full_name'] = trim((string)($_POST['full_name'] ?? ''));
    $repop['phone'] = trim((string)($_POST['phone'] ?? ''));
    $repop['doa'] = trim((string)($_POST['doa'] ?? ''));
    $repop['payment_method'] = $pay;

    $postPekurban = [];
    for ($i = 1; $i <= $pQty; $i++) {
        $pv = isset($_POST['nama_hewan_' . $i]) ? trim((string)$_POST['nama_hewan_' . $i]) : '';
        $postPekurban[$i] = mb_substr($pv, 0, 80);
    }

    if ($postProg === '' || !isset($qurban_programs[$postProg])) {
        $konfirmasi_error = 'Program tidak valid. Silakan ulangi dari halaman detail.';
    } elseif (!isset($tripay_methods[$pay])) {
        $konfirmasi_error = 'Pilih channel pembayaran Tripay yang tersedia.';
    } elseif ($repop['full_name'] === '' || $repop['phone'] === '') {
        $konfirmasi_error = 'Nama lengkap dan nomor HP wajib diisi.';
    } else {
        $amount = $pQty * (int)$qurban_programs[$postProg]['price_int'];
        $tripay = qurban_create_tripay_transaction([
            'method' => $pay,
            'amount' => $amount,
            'name' => $repop['full_name'],
            'phone' => $repop['phone'],
            'program_title' => $qurban_programs[$postProg]['title'],
            'qty' => $pQty,
        ]);
        if (!$tripay['ok'] || $tripay['checkout_url'] === '') {
            $konfirmasi_error = 'Gagal membuat transaksi Tripay. ' . ($tripay['error'] !== '' ? $tripay['error'] : 'Silakan coba lagi.');
        } else {
            $waPhone = qurban_normalize_phone_wa($repop['phone']);
            $waMessage = "Assalamu'alaikum " . $repop['full_name'] . ", invoice qurban Anda sudah dibuat.\n"
                . 'Program: ' . $qurban_programs[$postProg]['title'] . "\n"
                . 'Jumlah paket: ' . $pQty . "\n"
                . 'Total: ' . rupiah($amount) . "\n"
                . 'Silakan lanjut pembayaran di: ' . $tripay['checkout_url'];
            $waSend = qurban_send_watzap_message($waPhone, $waMessage);

            $logDir = __DIR__ . '/uploads/qurban_bukti';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $logPath = $logDir . '/tripay_create.log';
            $logRow = [
                'waktu' => date('c'),
                'program' => $postProg,
                'qty' => $pQty,
                'nama' => $repop['full_name'],
                'hp' => $repop['phone'],
                'doa' => mb_substr($repop['doa'], 0, 200),
                'payment_method' => $pay,
                'tripay_reference' => $tripay['reference'],
                'tripay_merchant_ref' => $tripay['merchant_ref'],
                'tripay_checkout_url' => $tripay['checkout_url'],
                'tripay_response' => mb_substr($tripay['response'], 0, 1500),
                'watzap_send_ok' => $waSend['ok'],
                'watzap_status_code' => $waSend['status_code'],
                'watzap_error' => $waSend['error'],
                'watzap_response' => mb_substr($waSend['response'], 0, 1000),
            ];
            @file_put_contents($logPath, json_encode($logRow, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

            header('Location: ' . $tripay['checkout_url']);
            exit;
        }
    }

    if ($konfirmasi_error !== '') {
        $programId = $postProg !== '' && isset($qurban_programs[$postProg]) ? $postProg : $programId;
        $program = $qurban_programs[$programId];
        $step = 'biodata';
        $qty = $pQty;
        $pekurbanNames = [];
        for ($i = 1; $i <= $qty; $i++) {
            $pekurbanNames[$i] = $postPekurban[$i] ?? '';
        }
        $total = $qty * (int)$program['price_int'];
    }
}

$biodata_query_params = ['program' => $programId, 'step' => 'biodata', 'qty' => $qty];
for ($i = 1; $i <= $qty; $i++) {
    $biodata_query_params['nama_hewan_' . $i] = $pekurbanNames[$i] ?? '';
}
$biodata_query = http_build_query($biodata_query_params);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Qurban - KolaborAksi</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Poppins', Arial, sans-serif;
            background: #f3f6f8;
            color: #1a1a1a;
            font-size: 15px;
            line-height: 1.55;
            letter-spacing: 0.01em;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            padding-bottom: 20px;
        }
        .wrap { max-width: 430px; margin: 0 auto; min-height: 100vh; background: #f3f6f8; }
        .app-header { position: sticky; top: 0; z-index: 20; background: #fff; border-bottom: 1px solid #e7ecef; padding: 10px 12px; display: flex; align-items: center; gap: 10px; }
        .back-btn { width: 36px; height: 36px; border-radius: 10px; border: none; background: #f2f5f7; color: #333; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; }
        .logo { height: 38px; width: auto; display: block; margin: 0 auto; }
        .card { margin: 12px; background: #fff; border-radius: 12px; border: 1px solid #e2e8ed; overflow: hidden; box-shadow: 0 3px 14px rgba(0,0,0,.05); }
        .hero img { width: 100%; display: block; object-fit: cover; max-height: 240px; background: #111; }
        .hero-body { padding: 14px; }
        .title { color: #0b5f56; font-size: 21px; font-weight: 800; line-height: 1.3; margin-bottom: 8px; letter-spacing: 0; }
        .price { color: #0f7a6e; font-size: 19px; font-weight: 700; margin-bottom: 4px; line-height: 1.35; }
        .subprice { color: #334155; font-size: 14px; margin-bottom: 12px; line-height: 1.55; }
        .progress-wrap { margin-bottom: 10px; }
        .progress-head { display: flex; justify-content: space-between; font-size: 13px; color: #334155; margin-bottom: 7px; }
        .bar { height: 10px; border-radius: 999px; background: #e5ecf2; overflow: hidden; }
        .bar > span { display: block; height: 100%; background: #0b6b61; width: <?php echo (int)$program['progress']; ?>%; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
        .stat { background: #f7fafc; border: 1px solid #e3ebf2; border-radius: 10px; padding: 8px 7px; }
        .stat strong { display: block; color: #0b5f56; font-size: 13px; margin-bottom: 3px; }
        .stat span { color: #334155; font-size: 11px; line-height: 1.45; }
        .section-card { margin: 12px; background: #fff; border-radius: 12px; border: 1px solid #e2e8ed; box-shadow: 0 3px 14px rgba(0,0,0,.05); padding: 14px; }
        .section-heading { margin: 0 0 12px; font-size: 17px; font-weight: 700; color: #0b6b61; letter-spacing: 0; }
        .qty-wrap { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .qty-btn { width: 44px; height: 44px; border-radius: 10px; border: 1px solid #0b6b61; background: #eef8f6; color: #0b6b61; font-size: 24px; line-height: 1; cursor: pointer; }
        .qty-value { width: 52px; height: 44px; border: 1px solid #d7e2ec; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #335; background: #fafcff; }
        .total-live { margin-left: auto; color: #0b6b61; font-size: 15px; font-weight: 700; letter-spacing: 0; }
        .person-item { border: 1px solid #e3ebf2; border-radius: 10px; background: #f9fbfd; padding: 10px; margin-bottom: 8px; }
        .person-item label, .field label { display: block; font-size: 12px; font-weight: 600; color: #1f2937; margin-bottom: 7px; }
        .person-item input, .field input, .field textarea, .field select { width: 100%; border: 1px solid #d8e3ed; border-radius: 10px; padding: 11px 12px; font-size: 14px; line-height: 1.45; font-family: inherit; background: #fff; }
        .field { margin-bottom: 13px; }
        .note { font-size: 11px; color: #475569; margin-top: 5px; line-height: 1.5; }
        .rekening-block { margin-top: 4px; }
        .copy-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }
        .copy-row:last-of-type { margin-bottom: 8px; }
        .copy-row-main { flex: 1; min-width: 0; font-size: 12px; color: #334155; line-height: 1.45; }
        .copy-muted { display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 2px; }
        .copy-row-main .js-copy-source strong { color: #0b5f56; font-size: 13px; }
        .btn-copy-one {
            flex-shrink: 0;
            margin-top: 14px;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid #0b6b61;
            background: #eef8f6;
            color: #0b5f56;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-copy-one:hover { background: #dff5f0; }
        .btn-copy-one:focus-visible { outline: 3px solid #0f5d8f; outline-offset: 2px; }
        .tf-rek-line {
            margin: 0 0 10px;
            font-size: 13px;
            color: #334155;
            line-height: 1.45;
        }
        .tf-rek-line:last-of-type { margin-bottom: 0; }
        .copy-row--norek .btn-copy-one { margin-top: 18px; }
        .order-summary {
            border: 1px solid #dfe8f1;
            border-radius: 12px;
            padding: 10px;
            background: #fcfdff;
            margin-bottom: 12px;
        }
        .order-summary h4 {
            margin: 0 0 9px;
            font-size: 14px;
            color: #1f2937;
        }
        .order-line {
            font-size: 12px;
            color: #334155;
            margin-bottom: 6px;
            line-height: 1.55;
        }
        .order-line:last-child { margin-bottom: 0; }
        .cta { width: 100%; border: none; border-radius: 10px; padding: 12px; font-size: 15px; font-weight: 700; background: #0b6b61; color: #fff; cursor: pointer; letter-spacing: 0; }
        .cart-box { border: 1px solid #dfe8f1; border-radius: 12px; padding: 10px; background: #fcfdff; }
        .cart-title { font-size: 14px; font-weight: 700; color: #334; margin: 0 0 8px; }
        .cart-line { font-size: 12px; color: #445; margin-bottom: 6px; line-height: 1.4; }
        .cart-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .cart-table td { font-size: 12px; padding: 7px 0; border-top: 1px solid #ebf1f6; vertical-align: top; }
        .cart-table td:last-child { text-align: right; font-weight: 700; color: #1f7db9; }
        .confirm-btn { width: 100%; margin-top: 12px; border: none; border-radius: 10px; padding: 12px; font-size: 14px; font-weight: 700; background: #0b6b61; color: #fff; cursor: pointer; }
        .qris-modal {
            position: fixed;
            inset: 0;
            background: rgba(12, 21, 31, 0.62);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            z-index: 100;
        }
        .qris-modal.is-open { display: flex; }
        .qris-modal-card {
            width: 100%;
            max-width: 360px;
            background: #fff;
            border-radius: 14px;
            border: 1px solid #dce6ef;
            box-shadow: 0 10px 26px rgba(0,0,0,.17);
            padding: 14px;
        }
        .qris-modal-title {
            margin: 0 0 6px;
            color: #0b5f56;
            font-size: 16px;
            font-weight: 700;
        }
        .qris-modal-text {
            margin: 0 0 10px;
            color: #334155;
            font-size: 12px;
            line-height: 1.5;
        }
        .qris-modal-image {
            width: 100%;
            display: block;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            margin-bottom: 10px;
        }
        .qris-modal-close {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 11px 12px;
            font-size: 14px;
            font-weight: 700;
            background: #0b6b61;
            color: #fff;
            cursor: pointer;
        }
        .tf-modal-ghost {
            width: 100%;
            margin-top: 8px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            color: #334155;
            cursor: pointer;
        }
        .tf-modal-rek {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 10px;
            background: #f8fbff;
            margin-bottom: 12px;
        }
        .tf-modal-rek p { margin: 0 0 4px; font-size: 13px; color: #334155; line-height: 1.45; }
        .tf-modal-rek p:last-child { margin-bottom: 0; }
        .tf-modal-rek strong { color: #0b5f56; }
        .tf-modal-file { margin-bottom: 12px; }
        .tf-modal-file label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 6px;
        }
        .tf-modal-file input[type="file"] {
            width: 100%;
            font-size: 13px;
            color: #334155;
        }
        .alert-konfirmasi {
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 12px;
            font-size: 13px;
            line-height: 1.45;
        }
        .alert-konfirmasi.ok {
            background: #ecfdf5;
            border: 1px solid #6ee7b7;
            color: #065f46;
        }
        .alert-konfirmasi.err {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        @media (max-width: 380px) {
            .title { font-size: 19px; }
            .price { font-size: 17px; }
            .section-heading { font-size: 16px; }
            .qty-btn { width: 40px; height: 40px; font-size: 22px; }
            .qty-value { width: 46px; height: 40px; font-size: 16px; }
            .total-live { font-size: 14px; }
            .qris-modal-title { font-size: 15px; }
        }
        .list { margin: 12px; display: grid; gap: 10px; }
        .item { background: #fff; border: 1px solid #e2e8ed; border-radius: 12px; overflow: hidden; display: grid; grid-template-columns: 130px 1fr; gap: 10px; text-decoration: none; color: inherit; }
        .item.active { border-color: #0b6b61; box-shadow: 0 4px 14px rgba(11,107,97,.16); }
        .item img { width: 100%; height: 100%; max-height: 110px; object-fit: cover; background: #111; }
        .item-body { padding: 10px 10px 10px 0; }
        .item-title { font-size: 15px; font-weight: 700; color: #0b5f56; line-height: 1.25; margin-bottom: 6px; }
        .item-price { font-size: 13px; font-weight: 700; color: #0f7a6e; margin-bottom: 5px; }
        .item-meta { font-size: 11px; color: #667; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="app-header">
        <a class="back-btn" href="index.php" aria-label="Kembali">&#8592;</a>
        <?php if (file_exists(__DIR__ . '/assets/logo.png')): ?>
            <img src="assets/logo.png" alt="KolaborAksi" class="logo" width="200" height="38">
        <?php else: ?>
            <strong style="font-size:15px">KolaborAksi</strong>
        <?php endif; ?>
    </header>

    <?php if ($step === 'detail'): ?>
        <section class="card hero">
            <?php if (file_exists(__DIR__ . '/' . $program['image'])): ?>
                <img src="<?php echo htmlspecialchars($program['image']); ?>" alt="<?php echo htmlspecialchars($program['title']); ?>">
            <?php endif; ?>
            <div class="hero-body">
                <div class="title"><?php echo htmlspecialchars($program['title']); ?></div>
                <div class="price"><?php echo htmlspecialchars($program['price']); ?></div>
                <div class="subprice"><?php echo htmlspecialchars($program['secondary']); ?></div>
                <div class="progress-wrap">
                    <div class="progress-head">
                        <span>Progress <?php echo (int)$program['progress']; ?>%</span>
                        <span>Update realtime</span>
                    </div>
                    <div class="bar"><span></span></div>
                </div>
                <div class="stats">
                    <div class="stat"><strong><?php echo htmlspecialchars($program['collected']); ?></strong><span>Donasi Terkumpul</span></div>
                    <div class="stat"><strong><?php echo htmlspecialchars($program['disbursed']); ?></strong><span>Donasi Tersalurkan</span></div>
                    <div class="stat"><strong><?php echo htmlspecialchars($program['donors']); ?></strong><span>Donatur</span></div>
                    <div class="stat"><strong><?php echo htmlspecialchars($program['target']); ?></strong><span>Total Kebutuhan</span></div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($step === 'detail'): ?>
        <section class="section-card">
            <h2 class="section-heading">Isi Nama Pekurban</h2>
            <form method="get" action="detail_qurban.php" id="formPekurban">
                <input type="hidden" name="program" value="<?php echo htmlspecialchars($programId); ?>">
                <input type="hidden" name="step" value="biodata">
                <input type="hidden" name="qty" id="qtyInput" value="1">
                <div class="qty-wrap">
                    <button type="button" class="qty-btn" id="btnMinus">-</button>
                    <div class="qty-value" id="qtyValue">1</div>
                    <button type="button" class="qty-btn" id="btnPlus">+</button>
                    <div class="total-live" id="totalLive">Total: <?php echo rupiah((int)$program['price_int']); ?></div>
                </div>
                <div id="personForms"></div>
                <button type="submit" class="cta">Qurban Sekarang</button>
            </form>
        </section>
    <?php else: ?>
        <section class="section-card">
            <form id="formBiodata" method="post" action="detail_qurban.php?<?php echo htmlspecialchars($biodata_query, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="buat_pembayaran_tripay" value="1">
                <input type="hidden" name="program" value="<?php echo htmlspecialchars($programId); ?>">
                <input type="hidden" name="qty" value="<?php echo (int)$qty; ?>">
                <?php for ($hi = 1; $hi <= $qty; $hi++): ?>
                    <input type="hidden" name="nama_hewan_<?php echo $hi; ?>" value="<?php echo htmlspecialchars($pekurbanNames[$hi] ?? ''); ?>">
                <?php endfor; ?>

                <h2 class="section-heading">Biodata Diri</h2>
                <?php if ($konfirmasi_error !== ''): ?>
                    <div class="alert-konfirmasi err" role="alert"><?php echo htmlspecialchars($konfirmasi_error); ?></div>
                <?php endif; ?>
                <div class="field">
                    <label for="full_name">Nama Lengkap</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Nama lengkap" maxlength="120" required
                        value="<?php echo htmlspecialchars($repop['full_name']); ?>">
                </div>
                <div class="field">
                    <label for="phone">No. HP, cth : 812345678910</label>
                    <input type="tel" id="phone" name="phone" placeholder="812345678910" pattern="[0-9]{8,15}" maxlength="15" inputmode="numeric" required
                        value="<?php echo htmlspecialchars($repop['phone']); ?>">
                    <div class="note">No. HP. tanpa angka nol di depan, contoh: 812345678910</div>
                    <div class="note">Kami menjamin keamanan &amp; kerahasiaan data Anda.</div>
                </div>
                <div class="field">
                    <label for="doa">Doa dan Harapan</label>
                    <textarea id="doa" name="doa" maxlength="90" placeholder="Tidak lebih dari 90 karakter"><?php echo htmlspecialchars($repop['doa']); ?></textarea>
                    <div class="note">Tidak lebih dari 90 karakter</div>
                </div>
                <div class="field">
                    <label for="payment_method">Pilih Metode Pembayaran (Tripay Only)</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value=""<?php echo $repop['payment_method'] === '' ? ' selected' : ''; ?>>Pilih Metode Tripay</option>
                        <?php foreach ($tripay_methods as $methodCode => $methodLabel): ?>
                            <option value="<?php echo htmlspecialchars($methodCode); ?>"<?php echo $repop['payment_method'] === $methodCode ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($methodLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="note">Tidak ada lagi pembayaran manual. Semua pembayaran diproses melalui gateway Tripay.</div>
                </div>
                <div class="order-summary">
                    <h4>Detail Pesanan</h4>
                    <div class="order-line"><?php echo htmlspecialchars($program['title']); ?> - <?php echo htmlspecialchars($program['price']); ?></div>
                    <div class="order-line">Jumlah hewan: <?php echo $qty; ?> paket</div>
                    <?php for ($i = 1; $i <= $qty; $i++): ?>
                        <div class="order-line">Hewan <?php echo $i; ?>: <?php echo htmlspecialchars($pekurbanNames[$i] !== '' ? $pekurbanNames[$i] : '-'); ?></div>
                    <?php endfor; ?>
                    <div class="order-line"><strong>Total: <?php echo rupiah($total); ?></strong></div>
                </div>
                <button type="submit" class="confirm-btn" id="btnKirimKonfirmasi">Lanjut Pembayaran Tripay</button>
            </form>
        </section>
    <?php endif; ?>

</div>

<?php if ($step === 'detail'): ?>
<script>
(function () {
    var priceInt = <?php echo (int)$program['price_int']; ?>;
    var qty = 1;
    var qtyInput = document.getElementById('qtyInput');
    var qtyValue = document.getElementById('qtyValue');
    var totalLive = document.getElementById('totalLive');
    var personForms = document.getElementById('personForms');

    function rupiah(n) {
        return 'Rp' + (n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function buildForms() {
        var html = '';
        for (var i = 1; i <= qty; i++) {
            html += '<div class="person-item">';
            html += '<label for="nama_hewan_' + i + '">Hewan ' + i + ' - Untuk siapa saja</label>';
            html += '<input type="text" id="nama_hewan_' + i + '" name="nama_hewan_' + i + '" required maxlength="80" placeholder="Maks. 3 kata : Putra bin Fulan / Putra Fulan Sekeluarga">';
            html += '</div>';
        }
        personForms.innerHTML = html;
        qtyInput.value = String(qty);
        qtyValue.textContent = String(qty);
        totalLive.textContent = 'Total: ' + rupiah(qty * priceInt);
    }

    document.getElementById('btnMinus').addEventListener('click', function () {
        if (qty <= 1) return;
        qty--;
        buildForms();
    });
    document.getElementById('btnPlus').addEventListener('click', function () {
        if (qty >= 20) return;
        qty++;
        buildForms();
    });

    document.getElementById('formPekurban').addEventListener('submit', function (e) {
        var valid = true;
        var firstInvalid = null;
        personForms.querySelectorAll('input[name^="nama_hewan_"]').forEach(function (inp) {
            if (inp.value.trim() === '') {
                valid = false;
                if (!firstInvalid) firstInvalid = inp;
            }
        });
        if (!valid) {
            e.preventDefault();
            alert('Nama pekurban wajib diisi untuk setiap hewan sebelum lanjut.');
            if (firstInvalid) firstInvalid.focus();
        }
    });

    buildForms();
})();
</script>
<?php else: ?>
<script>
(function () {
    var formBiodata = document.getElementById('formBiodata');
    var btnKirim = document.getElementById('btnKirimKonfirmasi');
    if (!formBiodata || !btnKirim) return;
    btnKirim.addEventListener('click', function () {
        if (typeof formBiodata.checkValidity === 'function' && !formBiodata.checkValidity()) {
            formBiodata.reportValidity();
            return;
        }
        btnKirim.disabled = true;
        btnKirim.textContent = 'Membuat transaksi Tripay...';
    });
})();
</script>
<?php endif; ?>
</body>
</html>

<?php
header('Content-Type: text/html; charset=utf-8');

$qurban_locations = [
    [
        'id' => 'qurban-afrika-1per7',
        'title' => 'Qurban Afrika',
        'desc' => '1/7 Sapi - 1,6 Jt',
        'image' => 'assets/lokasi_qurban/afrika.png',
    ],
    [
        'id' => 'pengungsi-palestina-yaman-domba',
        'title' => 'Pengungsi Palestina (Yaman)',
        'desc' => 'Domba - 3 Jt',
        'image' => 'assets/lokasi_qurban/yaman.png',
    ],
    [
        'id' => 'pengungsi-palestina-mesir-domba',
        'title' => 'Pengungsi Palestina (Mesir)',
        'desc' => 'Domba - 5 Jt',
        'image' => 'assets/lokasi_qurban/mesir.png',
    ],
    [
        'id' => 'qurban-mentawai-1per7',
        'title' => 'Qurban Mentawai',
        'desc' => '1/7 Sapi - 3,5 Juta',
        'image' => 'assets/lokasi_qurban/mentawai.png',
    ],
    [
        'id' => 'qurban-sumbar-1per7',
        'title' => 'Qurban Sumbar',
        'desc' => '1/7 Sapi - 2,6 Jt',
        'image' => 'assets/lokasi_qurban/sumbar.png',
    ],
    [
        'id' => 'qurban-sumbar-1ekor',
        'title' => 'Qurban Sumbar',
        'desc' => '1 Ekor Sapi - 18,2 Jt',
        'image' => 'assets/lokasi_qurban/sumbar.png',
    ],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Qurban - KolaborAksi</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f3f6f8; color: #1a1a1a; }
        .wrap { max-width: 430px; margin: 0 auto; min-height: 100vh; padding-bottom: 18px; }
        .app-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: #fff;
            border-bottom: 1px solid #e7ecef;
            padding: 10px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .back-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: #f2f5f7;
            color: #333;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
        }
        .logo { height: 38px; width: auto; display: block; margin: 0 auto; }
        .section { padding: 14px 12px; }
        .section h1 {
            font-size: 19px;
            color: #217fbd;
            margin-bottom: 6px;
        }
        .section p {
            font-size: 12px;
            color: #667;
            line-height: 1.5;
            margin-bottom: 12px;
        }
        .loc-grid { display: grid; gap: 10px; }
        .loc-card {
            background: #fff;
            border: 1px solid #e2e8ed;
            border-radius: 14px;
            padding: 10px;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }
        .loc-card:active { transform: scale(0.995); }
        .loc-thumb {
            width: 126px;
            height: 82px;
            border-radius: 10px;
            overflow: hidden;
            background: #e9f1f7;
            flex-shrink: 0;
        }
        .loc-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }
        .loc-title {
            font-size: 14px;
            font-weight: 700;
            color: #1f78ad;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        .loc-desc {
            font-size: 12px;
            color: #555;
            line-height: 1.4;
        }
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

    <section class="section">
        <h1>Pilih Lokasi Penyaluran</h1>
        <p>Pilih program qurban yang ingin Anda lanjutkan untuk melihat detail dan mengisi data pekurban.</p>
        <div class="loc-grid">
            <?php foreach ($qurban_locations as $loc): ?>
                <a class="loc-card" href="detail_qurban.php?program=<?php echo rawurlencode((string)$loc['id']); ?>">
                    <div class="loc-thumb">
                        <?php if (file_exists(__DIR__ . '/' . (string)$loc['image'])): ?>
                            <img src="<?php echo htmlspecialchars((string)$loc['image']); ?>" alt="<?php echo htmlspecialchars((string)$loc['title']); ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="loc-title"><?php echo htmlspecialchars((string)$loc['title']); ?></div>
                        <div class="loc-desc"><?php echo htmlspecialchars((string)$loc['desc']); ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</div>
</body>
</html>

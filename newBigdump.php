<?php
declare(strict_types=1);

/**
 * Modern Staggered MySQL Dump Importer (PHP 8.x & MySQL 8.x Uyumlu)
 * Orijinal BigDump 0.36b mantığı üzerine güvenlik, UTF8MB4 ve Fetch JSON API ile yeniden yazılmıştır.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
@set_time_limit(0);

// =========================================================================
// 1. VERİTABANI VE GÜVENLİK AYARLARI (BURAYI DÜZENLEYİN)
// =========================================================================

$access_password       = 'CokGucluBirSifre123!'; // Betiğe erişim şifresi (BOŞ BIRAKMAYIN!)

$db_server             = 'localhost';
$db_name               = '';
$db_username           = '';
$db_password           = '';
$db_connection_charset = 'utf8mb4';

// İçe Aktarma Performans Ayarları
$lines_per_session     = 3000;   // Her oturumda işlenecek maksimum satır sayısı
$delay_per_session     = 0;      // Oturumlar arası bekleme süresi (milisaniye)
$max_query_lines       = 2000;   // Tek bir SQL sorgusunun maksimum satır limiti (Extended inserts için artırıldı)
$disable_fk_checks     = true;   // İçe aktarma sırasında Foreign Key kontrollerini geçici kapat

// =========================================================================
// 2. SABİTLER VE TEMEL KONTROLLER
// =========================================================================

define('APP_VERSION', '2.0-PHP8');
define('DATA_CHUNK_LENGTH', 65536); // 64KB okuma tamponu
define('UPLOAD_DIR', __DIR__);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

if (!extension_loaded('mysqli')) {
    die('HATA: Sunucuda PHP mysqli eklentisi aktif değil.');
}

// =========================================================================
// 3. KİMLİK DOĞRULAMA (ACCESS PASSWORD & CSRF)
// =========================================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_password'])) {
    if (!empty($access_password) && hash_equals($access_password, (string)$_POST['login_password'])) {
        $_SESSION['bigdump_authenticated'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = 'Hatalı erişim şifresi!';
    }
}

// Giriş yapılmamışsa Login Ekranını Göster
if (empty($_SESSION['bigdump_authenticated'])) {
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Güvenli Veritabanı İçe Aktarıcı</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card { background: #1e293b; padding: 2rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); width: 100%; max-width: 380px; border: 1px solid #334155; }
            h2 { margin-top: 0; font-size: 1.25rem; color: #38bdf8; }
            input[type="password"] { width: 100%; padding: 0.75rem; margin: 1rem 0; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; }
            button { width: 100%; padding: 0.75rem; background: #0284c7; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; }
            button:hover { background: #0369a1; }
            .err { color: #f87171; font-size: 0.875rem; margin-bottom: 0.5rem; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>Veritabanı Yükleyici (v<?= APP_VERSION ?>)</h2>
            <p style="font-size: 0.875rem; color: #94a3b8;">Devam etmek için betik içinde tanımladığınız erişim şifresini girin.</p>
            <?php if ($login_error): ?><div class="err"><?= htmlspecialchars($login_error) ?></div><?php endif; ?>
            <form method="POST">
                <input type="password" name="login_password" placeholder="Erişim Şifresi" required autofocus>
                <button type="submit">Giriş Yap</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =========================================================================
// 4. YARDIMCI FONKSİYONLAR
// =========================================================================

function sanitizeFilename(string $filename): string {
    $base = basename($filename);
    if (!preg_match('/^[a-zA-Z0-9._-]+\.(sql|gz)$/i', $base)) {
        return '';
    }
    return $base;
}

function formatBytes(int|float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// =========================================================================
// 5. AJAX / FETCH İŞLEM OTURUMU (JSON API)
// =========================================================================

if (isset($_GET['action']) && $_GET['action'] === 'import_step') {
    header('Content-Type: application/json; charset=utf-8');

    $filename     = sanitizeFilename((string)($_GET['fn'] ?? ''));
    $start_line   = max(1, (int)($_GET['start'] ?? 1));
    $foffset      = max(0, (int)($_GET['foffset'] ?? 0));
    $totalqueries = max(0, (int)($_GET['totalqueries'] ?? 0));
    $delimiter    = (string)($_GET['delimiter'] ?? ';');

    if ($filename === '' || !file_exists(UPLOAD_DIR . '/' . $filename)) {
        echo json_encode(['status' => 'error', 'message' => 'Geçersiz veya bulunamayan dosya.']);
        exit;
    }

    $filepath = UPLOAD_DIR . '/' . $filename;
    $gzipmode = (bool)preg_match('/\.gz$/i', $filename);
    $filesize = $gzipmode ? 0 : (int)filesize($filepath);

    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @new mysqli($db_server, $db_username, $db_password, $db_name);
    if ($mysqli->connect_error) {
        echo json_encode(['status' => 'error', 'message' => 'Veritabanı bağlantı hatası: ' . $mysqli->connect_error]);
        exit;
    }

    $mysqli->set_charset($db_connection_charset);
    if ($disable_fk_checks) {
        $mysqli->query('SET foreign_key_checks = 0');
    }

    $file = $gzipmode ? @gzopen($filepath, 'r') : @fopen($filepath, 'r');
    if (!$file) {
        echo json_encode(['status' => 'error', 'message' => 'Dosya okuma için açılamadı.']);
        exit;
    }

    if ($foffset > 0) {
        $seek_ok = $gzipmode ? (gzseek($file, $foffset) === 0) : (fseek($file, $foffset) === 0);
        if (!$seek_ok) {
            echo json_encode(['status' => 'error', 'message' => 'Dosya konumlandırma (seek) hatası.']);
            exit;
        }
    }

    $query       = '';
    $queries     = 0;
    $linenumber  = $start_line;
    $querylines  = 0;
    $inparents   = false;
    $eof_reached = false;

    $comments = ['#', '-- ', 'DELIMITER', '/*!'];

    while ($linenumber < ($start_line + $lines_per_session) || $query !== '') {
        $dumpline = '';
        while (!($gzipmode ? gzeof($file) : feof($file)) && substr($dumpline, -1) !== "\n" && substr($dumpline, -1) !== "\r") {
            $chunk = $gzipmode ? gzgets($file, DATA_CHUNK_LENGTH) : fgets($file, DATA_CHUNK_LENGTH);
            if ($chunk === false) {
                break;
            }
            $dumpline .= $chunk;
        }

        if ($dumpline === '') {
            $eof_reached = true;
            break;
        }

        // UTF-8 BOM temizliği
        if ($foffset === 0 && $linenumber === 1) {
            $dumpline = preg_replace('/^\xEF\xBB\xBF/', '', $dumpline);
        }

        $dumpline = str_replace(["\r\n", "\r"], "\n", $dumpline);

        // DELIMITER kontrolü
        if (!$inparents && str_starts_with(trim($dumpline), 'DELIMITER ')) {
            $delimiter = trim(str_replace('DELIMITER ', '', trim($dumpline)));
            $linenumber++;
            continue;
        }

        // Yorum ve boş satırları atla
        if (!$inparents) {
            $trimmed = trim($dumpline);
            if ($trimmed === '') {
                $linenumber++;
                continue;
            }
            $is_comment = false;
            foreach ($comments as $c) {
                if (str_starts_with($trimmed, $c)) {
                    $is_comment = true;
                    break;
                }
            }
            if ($is_comment) {
                $linenumber++;
                continue;
            }
        }

        // Tırnak içi kontrolü
        $deslashed = str_replace('\\\\', '', $dumpline);
        $quotes_count = substr_count($deslashed, "'") - substr_count($deslashed, "\\'");
        if ($quotes_count % 2 !== 0) {
            $inparents = !$inparents;
        }

        $query .= $dumpline;
        if (!$inparents) {
            $querylines++;
        }

        if ($querylines > $max_query_lines) {
            echo json_encode([
                'status'  => 'error',
                'message' => "Satır $linenumber konumunda tek sorgu maksimum satır limitini ($max_query_lines) aştı."
            ]);
            exit;
        }

        // Sorgu sonu tespiti
        if (!$inparents && ($delimiter === '' || str_ends_with(trim($dumpline), $delimiter))) {
            $sql = trim($query);
            if ($delimiter !== '') {
                $sql = substr($sql, 0, -strlen($delimiter));
            }

            if ($sql !== '' && !$mysqli->query($sql)) {
                echo json_encode([
                    'status'  => 'error',
                    'message' => "SQL Hatası (Satır $linenumber): " . $mysqli->error,
                    'query'   => mb_substr($sql, 0, 300) . '...'
                ]);
                exit;
            }

            $queries++;
            $totalqueries++;
            $query      = '';
            $querylines = 0;
        }

        $linenumber++;
    }

    $new_offset = $gzipmode ? gztell($file) : ftell($file);
    $gzipmode ? gzclose($file) : fclose($file);
    $mysqli->close();

    $percent = (!$gzipmode && $filesize > 0) ? min(100, round(($new_offset / $filesize) * 100, 1)) : -1;
    if ($eof_reached) {
        $percent = 100;
    }

    echo json_encode([
        'status'       => 'success',
        'finished'     => $eof_reached,
        'next_start'   => $linenumber,
        'next_offset'  => $new_offset,
        'totalqueries' => $totalqueries,
        'delimiter'    => $delimiter,
        'percent'      => $percent,
        'bytes_done'   => formatBytes($new_offset),
        'bytes_total'  => $gzipmode ? 'GZip (Bilinmiyor)' : formatBytes($filesize)
    ]);
    exit;
}

// =========================================================================
// 6. DOSYA YÜKLEME VE SİLME İŞLEMLERİ
// =========================================================================

$status_msg = '';
$status_type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    // Dosya Yükleme
    if (isset($_FILES['dumpfile']) && $_FILES['dumpfile']['error'] === UPLOAD_ERR_OK) {
        $clean_name = sanitizeFilename((string)$_FILES['dumpfile']['name']);
        if ($clean_name === '') {
            $status_msg = 'Hata: Sadece .sql veya .gz uzantılı, geçerli karakterler içeren dosyalar yüklenebilir.';
            $status_type = 'error';
        } elseif (file_exists(UPLOAD_DIR . '/' . $clean_name)) {
            $status_msg = "Hata: '$clean_name' dosyası zaten mevcut. Önce silin.";
            $status_type = 'error';
        } elseif (move_uploaded_file($_FILES['dumpfile']['tmp_name'], UPLOAD_DIR . '/' . $clean_name)) {
            $status_msg = "Dosya başarıyla yüklendi: $clean_name";
            $status_type = 'success';
        } else {
            $status_msg = 'Dosya dizine taşınamadı. Klasör yazma izinlerini kontrol edin.';
            $status_type = 'error';
        }
    }

    // Dosya Silme
    if (isset($_POST['delete_file'])) {
        $del_name = sanitizeFilename((string)$_POST['delete_file']);
        if ($del_name !== '' && file_exists(UPLOAD_DIR . '/' . $del_name) && @unlink(UPLOAD_DIR . '/' . $del_name)) {
            $status_msg = "Dosya silindi: $del_name";
            $status_type = 'success';
        } else {
            $status_msg = 'Dosya silinemedi.';
            $status_type = 'error';
        }
    }
}

// Dizindeki .sql ve .gz dosyalarını listele
$dump_files = [];
foreach (scandir(UPLOAD_DIR) as $item) {
    if (sanitizeFilename($item) !== '' && is_file(UPLOAD_DIR . '/' . $item)) {
        $dump_files[] = [
            'name' => $item,
            'size' => formatBytes((int)filesize(UPLOAD_DIR . '/' . $item)),
            'date' => date('Y-m-d H:i:s', (int)filemtime(UPLOAD_DIR . '/' . $item)),
            'type' => str_ends_with(strtolower($item), '.gz') ? 'GZip SQL' : 'SQL'
        ];
    }
}

// Veritabanı Bağlantı Durumu Testi
$db_ok = false;
$db_err_msg = '';
if ($db_name !== '' && $db_username !== '') {
    mysqli_report(MYSQLI_REPORT_OFF);
    $test_conn = @new mysqli($db_server, $db_username, $db_password, $db_name);
    if (!$test_conn->connect_error) {
        $db_ok = true;
        $test_conn->close();
    } else {
        $db_err_msg = $test_conn->connect_error;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modern BigDump Importer v<?= APP_VERSION ?></title>
    <style>
        :root { --bg: #f1f5f9; --card: #ffffff; --text: #1e293b; --border: #cbd5e1; --primary: #2563eb; --danger: #dc2626; --success: #16a34a; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 2rem 1rem; }
        .container { max-width: 860px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card { background: var(--card); border-radius: 10px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--border); margin-bottom: 1.5rem; }
        .badge { padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .badge-ok { background: #dcfce7; color: #166534; }
        .badge-err { background: #fee2e2; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.75rem; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        th { background: #f8fafc; font-weight: 600; }
        .btn { padding: 0.45rem 0.9rem; border-radius: 6px; border: none; font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-danger { background: var(--danger); color: #fff; }
        .btn-secondary { background: #64748b; color: #fff; }
        .alert { padding: 0.85rem 1rem; border-radius: 6px; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .progress-wrap { width: 100%; background: #e2e8f0; border-radius: 8px; height: 22px; overflow: hidden; margin: 1rem 0; }
        .progress-bar { height: 100%; background: var(--primary); width: 0%; transition: width 0.3s ease; text-align: center; color: #fff; font-size: 0.75rem; line-height: 22px; font-weight: bold; }
        #import-panel { display: none; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1 style="margin:0; font-size:1.4rem;">MySQL Parçalı Yedek Yükleyici</h1>
            <small style="color:#64748b;">PHP <?= PHP_VERSION ?> • Charset: <b><?= htmlspecialchars($db_connection_charset) ?></b></small>
        </div>
        <div>
            <?php if ($db_ok): ?>
                <span class="badge badge-ok">DB Bağlı: <?= htmlspecialchars($db_name) ?></span>
            <?php else: ?>
                <span class="badge badge-err">DB Bağlı Değil <?= $db_err_msg ? '(' . htmlspecialchars($db_err_msg) . ')' : '' ?></span>
            <?php endif; ?>
            <a href="?logout=1" class="btn btn-secondary" style="margin-left:0.5rem;">Çıkış</a>
        </div>
    </div>

    <?php if ($status_msg): ?>
        <div class="alert alert-<?= $status_type === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($status_msg) ?></div>
    <?php endif; ?>

    <!-- İlerleme Paneli -->
    <div class="card" id="import-panel">
        <h3 style="margin-top:0;">İçe Aktarma İlerlemesi: <span id="imp-filename"></span></h3>
        <div class="progress-wrap"><div class="progress-bar" id="imp-bar">0%</div></div>
        <div style="display:flex; justify-content:space-between; font-size:0.875rem; color:#475569;">
            <span>İşlenen Satır: <b id="imp-lines">0</b></span>
            <span>Çalıştırılan Sorgu: <b id="imp-queries">0</b></span>
            <span>Veri: <b id="imp-bytes">0 B</b></span>
        </div>
        <div id="imp-status" style="margin-top:1rem; font-weight:600;"></div>
        <div style="margin-top:1rem;">
            <button class="btn btn-danger" id="btn-stop" onclick="stopImport()">Durdur</button>
        </div>
    </div>

    <!-- Dosya Listesi -->
    <div class="card">
        <h3 style="margin-top:0;">Sunucudaki Yedek Dosyaları</h3>
        <?php if (empty($dump_files)): ?>
            <p style="color:#64748b; font-size:0.9rem;">Dizinde henüz <code>.sql</code> veya <code>.gz</code> dosyası bulunmuyor.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Dosya Adı</th>
                        <th>Boyut</th>
                        <th>Tür</th>
                        <th>Tarih</th>
                        <th style="text-align:right;">İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dump_files as $f): ?>
                    <tr>
                        <td><b><?= htmlspecialchars($f['name']) ?></b></td>
                        <td><?= $f['size'] ?></td>
                        <td><?= $f['type'] ?></td>
                        <td><?= $f['date'] ?></td>
                        <td style="text-align:right;">
                            <?php if ($db_ok): ?>
                                <button class="btn btn-primary" onclick="startImport('<?= htmlspecialchars($f['name'], ENT_QUOTES) ?>')">İçe Aktar</button>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Dosya kalıcı olarak silinsin mi?');">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="delete_file" value="<?= htmlspecialchars($f['name']) ?>">
                                <button type="submit" class="btn btn-danger">Sil</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Yükleme Formu -->
    <div class="card">
        <h3 style="margin-top:0;">Yeni Yedek Yükle (.sql / .gz)</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="file" name="dumpfile" accept=".sql,.gz" required>
            <button type="submit" class="btn btn-primary">Sunucuya Yükle</button>
        </form>
        <p style="font-size:0.8rem; color:#64748b; margin-bottom:0;">
            Maksimum yükleme limiti: <b><?= ini_get('upload_max_filesize') ?></b>. Daha büyük dosyaları FTP/SFTP ile doğrudan bu klasöre atabilirsiniz.
        </p>
    </div>

    <div class="alert alert-error" style="font-size:0.82rem;">
        <b>GÜVENLİK UYARISI:</b> İçe aktarma işleminiz tamamlandıktan sonra bu PHP dosyasını ve yüklediğiniz SQL yedeklerini sunucudan mutlaka silin!
    </div>
</div>

<script>
let isRunning = false;
const delayMs = <?= (int)$delay_per_session ?>;

function startImport(filename) {
    if (!confirm(filename + " dosyası veritabanına aktarılacak. Mevcut tablolar varsa üzerine yazılabilir. Devam edilsin mi?")) return;
    isRunning = true;
    document.getElementById('import-panel').style.display = 'block';
    document.getElementById('imp-filename').innerText = filename;
    document.getElementById('imp-status').innerHTML = '<span style="color:#2563eb;">İçe aktarma sürüyor, pencereyi kapatmayın...</span>';
    document.getElementById('btn-stop').style.display = 'inline-block';
    runStep(filename, 1, 0, 0, ';');
}

function stopImport() {
    isRunning = false;
    document.getElementById('imp-status').innerHTML = '<span style="color:#dc2626;">İşlem kullanıcı tarafından durduruldu.</span>';
}

async function runStep(filename, start, foffset, totalqueries, delimiter) {
    if (!isRunning) return;

    const params = new URLSearchParams({
        action: 'import_step',
        fn: filename,
        start: start,
        foffset: foffset,
        totalqueries: totalqueries,
        delimiter: delimiter
    });

    try {
        const res = await fetch('?' + params.toString());
        const data = await res.json();

        if (data.status !== 'success') {
            isRunning = false;
            document.getElementById('imp-status').innerHTML = '<span style="color:#dc2626;">HATA: ' + data.message + '</span>';
            return;
        }

        document.getElementById('imp-lines').innerText = data.next_start - 1;
        document.getElementById('imp-queries').innerText = data.totalqueries;
        document.getElementById('imp-bytes').innerText = data.bytes_done + ' / ' + data.bytes_total;

        const bar = document.getElementById('imp-bar');
        if (data.percent >= 0) {
            bar.style.width = data.percent + '%';
            bar.innerText = data.percent + '%';
        } else {
            bar.style.width = '100%';
            bar.innerText = 'GZip İşleniyor...';
        }

        if (data.finished) {
            isRunning = false;
            document.getElementById('btn-stop').style.display = 'none';
            document.getElementById('imp-status').innerHTML = '<span style="color:#16a34a;">TAMAMLANDI! Tüm sorgular başarıyla çalıştırıldı. Lütfen şimdi dosyaları sunucudan silin.</span>';
            return;
        }

        setTimeout(() => {
            runStep(filename, data.next_start, data.next_offset, data.totalqueries, data.delimiter);
        }, delayMs);

    } catch (err) {
        isRunning = false;
        document.getElementById('imp-status').innerHTML = '<span style="color:#dc2626;">Bağlantı veya sunucu hatası: ' + err.message + '</span>';
    }
}
</script>
</body>
</html>

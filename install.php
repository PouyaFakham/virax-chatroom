<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

$LOCK_FILE = __DIR__ . '/data/veyrax_installed.php';
@ini_set('json.encode_unicode', '0');

function veyrax_inst_locked() {
    global $LOCK_FILE;
    if (!file_exists($LOCK_FILE)) return false;
    $c = @file_get_contents($LOCK_FILE);
    return is_string($c) && strpos($c, 'veyrax-installed') !== false;
}

if (veyrax_inst_locked()) {
    header('Content-Type: text/html; charset=utf-8');

    // A fresh source upload over a live installation must hand over to the
    // running app automatically — with the existing data — instead of showing
    // a "locked" wall. Only a genuinely broken DB config deserves an error.
    $cfgFile = __DIR__ . '/data/config.php';
    $dbState = file_exists($cfgFile) ? 'fail' : 'noconfig';
    $friendly = 'فایل پیکربندی یافت نشد.';
    if (file_exists($cfgFile)) {
        try {
            include $cfgFile;
            $h = isset($db_host) ? $db_host : 'localhost';
            $pt = isset($db_port) ? $db_port : '3306';
            $n = isset($db_name) ? $db_name : '';
            $u = isset($db_user) ? $db_user : '';
            $pw = isset($db_pass) ? $db_pass : '';
            new PDO("mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4", $u, $pw, [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $dbState = 'ok';
        } catch (Exception $e) {
            $dbState = 'fail';
            $msg = $e->getMessage();
            $friendly = 'اتصال به سرور دیتابیس برقرار نشد.';
            if (strpos($msg, '1045') !== false || stripos($msg, 'Access denied') !== false) $friendly = 'اطلاعات اتصال دیتابیس اشتباه است.';
            elseif (strpos($msg, '1049') !== false || stripos($msg, 'Unknown database') !== false) $friendly = 'پایگاه داده یافت نشد.';
            elseif (strpos($msg, '2002') !== false) $friendly = 'سرور دیتابیس در دسترس نیست.';
        }
    }

    $fontDir = 'fonts/';
    $loading = ($dbState === 'ok');
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $loading ? 'در حال بارگذاری…' : 'خطای پایگاه داده'; ?></title>
<?php if ($loading): ?><meta http-equiv="refresh" content="1.7;url=index.php"><?php endif; ?>
<style>
    @font-face { font-family: 'AppFont'; src: url('<?php echo $fontDir; ?>Kalameh.ttf') format('truetype'); font-weight: normal; font-display: swap; }
    @font-face { font-family: 'AppBold'; src: url('<?php echo $fontDir; ?>Kalameh1.ttf') format('truetype'); font-weight: bold; font-display: swap; }
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body {
        font-family: 'AppFont', 'Vazirmatn', Tahoma, sans-serif;
        background:
            radial-gradient(120% 80% at 50% -10%, rgba(99,102,241,0.22), transparent 60%),
            radial-gradient(90% 70% at 100% 110%, rgba(34,211,238,0.10), transparent 65%),
            linear-gradient(165deg, #101828 0%, #0b1220 60%, #080d18 100%);
        color: #e2e8f0; display: flex; align-items: center; justify-content: center;
        min-height: 100vh; padding: 22px;
    }
    .card {
        width: 100%; max-width: 400px; text-align: center;
        background: linear-gradient(168deg, rgba(30,41,59,0.72), rgba(15,23,42,0.85));
        border: 1px solid rgba(255,255,255,0.08); border-radius: 26px;
        padding: 34px 26px 26px; position: relative; overflow: hidden;
        box-shadow: 0 40px 80px -30px rgba(0,0,0,0.9), inset 0 1px 0 rgba(255,255,255,0.06);
    }
    .card::before {
        content: ''; position: absolute; inset: 0 0 auto; height: 150px; pointer-events: none;
        background: radial-gradient(60% 100% at 50% 0%, rgba(255,255,255,0.09), transparent 70%);
    }
    .mark {
        width: 64px; height: 64px; border-radius: 20px; margin: 0 auto 18px;
        display: flex; align-items: center; justify-content: center; position: relative;
        background: linear-gradient(145deg, #6366f1, #8b5cf6 60%, #22d3ee);
        box-shadow: 0 18px 40px -16px rgba(99,102,241,0.9), inset 0 1px 0 rgba(255,255,255,0.35);
        font-size: 28px; color: #fff;
    }
    .mark.warn { background: linear-gradient(145deg, #f59e0b, #ef4444 70%); box-shadow: 0 18px 40px -16px rgba(245,158,11,0.8), inset 0 1px 0 rgba(255,255,255,0.3); }
    .title { font-family: 'AppBold', 'AppFont', Tahoma, sans-serif; font-size: 18.5px; color: #fff; margin: 0 0 8px; }
    .sub { font-size: 13px; color: #94a3b8; line-height: 2; margin: 0 0 20px; }
    .sub b { color: #cbd5e1; font-weight: normal; }
    .ring { width: 46px; height: 46px; margin: 2px auto 16px; border-radius: 50%;
        background: conic-gradient(from 90deg, transparent 0 25%, #818cf8 60%, #22d3ee 100%);
        -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 5px), #000 calc(100% - 4px));
        mask: radial-gradient(farthest-side, transparent calc(100% - 5px), #000 calc(100% - 4px));
        animation: spin 0.9s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .bar { height: 3px; border-radius: 3px; background: rgba(148,163,184,0.16); overflow: hidden; margin: 4px 8px 18px; }
    .bar i { display: block; height: 100%; width: 45%; border-radius: 3px;
        background: linear-gradient(90deg, #6366f1, #22d3ee);
        animation: slide 1.25s cubic-bezier(.4,0,.2,1) infinite; }
    @keyframes slide { 0% { transform: translateX(120%); } 100% { transform: translateX(-260%); } }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        width: 100%; padding: 12px 16px; border-radius: 14px; border: 0; cursor: pointer;
        font-family: 'AppBold', 'AppFont', Tahoma, sans-serif; font-size: 14px; color: #fff;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        box-shadow: 0 14px 30px -14px rgba(99,102,241,0.85); text-decoration: none;
        transition: transform .2s ease, box-shadow .2s ease; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 18px 36px -14px rgba(99,102,241,0.95); }
    .btn.ghost { background: rgba(148,163,184,0.12); border: 1px solid rgba(148,163,184,0.22); box-shadow: none; color: #cbd5e1; }
    .btn.ghost:hover { background: rgba(148,163,184,0.18); }
    .actions { display: flex; flex-direction: column; gap: 9px; margin-top: 6px; }
    .hint { margin-top: 16px; padding-top: 14px; border-top: 1px dashed rgba(148,163,184,0.18);
        font-size: 11.5px; color: #64748b; line-height: 2; }
    .hint code { background: rgba(148,163,184,0.12); padding: 1px 6px; border-radius: 6px; color: #94a3b8; font-family: Consolas, monospace; direction: ltr; display: inline-block; }
    .link { color: #818cf8; text-decoration: none; font-size: 12px; }
    .link:hover { text-decoration: underline; }
</style>
</head>
<body>
    <div class="card">
    <?php if ($loading): ?>
        <div class="mark">✦</div>
        <h1 class="title">همه‌چیز آماده است</h1>
        <p class="sub">سورس جدید همگام شد — <b>در حال بارگذاری با همان داده‌های قبلی…</b></p>
        <div class="ring" aria-hidden="true"></div>
        <div class="bar"><i></i></div>
        <a class="btn ghost" href="index.php">ورود به سایت</a>
        <div class="hint">اگر صفحه منتقل نشد، دکمه بالا را بزنید.</div>
        <script>setTimeout(function () { location.replace('index.php'); }, 1700);</script>
    <?php else: ?>
        <div class="mark warn">⚠</div>
        <h1 class="title"><?php echo htmlspecialchars($friendly, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="sub">
            نصب قبلاً انجام شده است، اما اتصال به پایگاه داده برقرار نشد.<br>
            <b>داده‌های شما دست‌نخورده‌اند.</b>
        </p>
        <div class="actions">
            <a class="btn" href="install.php">تلاش مجدد</a>
            <a class="btn ghost" href="index.php">ورود به سایت</a>
        </div>
        <div class="hint">
            اگر سورس جدید آپلود کرده‌اید، از وجود <code>data/config.php</code> و دیتابیس قبلی مطمئن شوید.<br>
            برای نصب مجدد، فایل <code>data/veyrax_installed.php</code> را حذف کنید.
        </div>
    <?php endif; ?>
    </div>
</body>
</html>
    <?php
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    @session_start();
}
if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['installer_csrf'];

$JSON = (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'VeyraxInstaller');

if ($JSON) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    function inst_json_error($msg) {
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // "http://example.com:8080/veyrax" or "example.com:8080" -> "example.com"
    function inst_host_name($value) {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (strpos($value, '://') === false) $value = 'http://' . $value;
        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') return '';
        return strtolower(trim($host, '[]'));
    }

    // Same-origin guard for browser calls (the CSRF token is still required on top of this).
    function inst_require_same_origin() {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') return; // curl / CLI clients send no Origin header
        $originHost = inst_host_name($origin);
        $allowed = [];
        foreach ([$_SERVER['HTTP_HOST'] ?? '', $_SERVER['SERVER_NAME'] ?? ''] as $candidate) {
            foreach (explode(',', (string)$candidate) as $one) {
                $h = inst_host_name($one);
                if ($h !== '') $allowed[] = $h;
            }
        }
        if ($originHost === '' || empty($allowed) || !in_array($originHost, $allowed, true)) {
            inst_json_error('منشأ درخواست نامعتبر است');
        }
    }

    function inst_db_friendly(PDOException $e) {
        $m = $e->getMessage();
        if (strpos($m, '1045') !== false || stripos($m, 'Access denied') !== false) return 'نام کاربری یا رمز دیتابیس اشتباه است';
        if (strpos($m, '1044') !== false) return 'این کاربر اجازه اتصال به این پایگاه داده را ندارد';
        if (strpos($m, '1049') !== false || stripos($m, 'Unknown database') !== false) return 'پایگاه داده وجود ندارد';
        if (strpos($m, '2002') !== false || stripos($m, 'Connection refused') !== false) return 'اتصال به سرور دیتابیس برقرار نشد (هاست/پورت اشتباه یا سرویس خاموش است)';
        if (strpos($m, '2006') !== false || stripos($m, 'gone away') !== false) return 'ارتباط با سرور دیتابیس قطع شد؛ دوباره تلاش کنید';
        return 'خطای اتصال دیتابیس: ' . $m;
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) inst_json_error('درخواست نامعتبر');
    inst_require_same_origin();
    if (!hash_equals($CSRF, (string)($in['csrf'] ?? ''))) inst_json_error('نشست نصب منقضی شده؛ صفحه را رفرش کنید');
    if (veyrax_inst_locked()) inst_json_error('نصب قفل شده است');

    function inst_write_test($dir) {
        if (!function_exists('file_put_contents')) return false;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
        $tmp = rtrim(str_replace('\\', '/', $dir), '/') . '/.wtest_' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, 'x') === false) return false;
        @unlink($tmp);
        return true;
    }

    function inst_precheck() {
        $items = [];
        $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
        $items[] = ['label' => 'نسخه PHP', 'ok' => $phpOk, 'detail' => PHP_VERSION . ($phpOk ? ' — پشتیبانی می‌شود' : ' — حداقل 7.4 لازم است')];
        $missing = [];
        foreach (['pdo_mysql', 'openssl', 'json', 'mbstring', 'session'] as $ext) {
            if (!extension_loaded($ext)) $missing[] = $ext;
        }
        $items[] = ['label' => 'افزونه‌های PHP', 'ok' => empty($missing), 'detail' => empty($missing) ? 'pdo_mysql، openssl، json، mbstring و session فعال‌اند' : 'غیرفعال: ' . implode('، ', $missing)];
        $fnOk = function_exists('file_put_contents');
        $items[] = ['label' => 'امکان نوشتن فایل', 'ok' => $fnOk, 'detail' => $fnOk ? 'توابع فایل روی سرور در دسترس‌اند' : 'نوشتن فایل روی سرور مسدود شده است'];
        $dataOk = inst_write_test(__DIR__ . '/data');
        $items[] = ['label' => 'پوشه data (پیکربندی)', 'ok' => $dataOk, 'detail' => $dataOk ? 'قابل نوشتن است' : 'ساخت یا نوشتن در پوشه data ممکن نیست؛ دسترسی پوشه اصلی را بررسی کنید'];
        $upOk = inst_write_test(__DIR__ . '/uploads');
        $items[] = ['label' => 'پوشه uploads (فایل‌ها)', 'ok' => $upOk, 'detail' => $upOk ? 'قابل نوشتن است' : 'پوشه uploads قابل نوشتن نیست'];
        return $items;
    }

    function inst_precheck_problems($items) {
        $problems = [];
        foreach ($items as $it) {
            if (empty($it['ok'])) $problems[] = $it['label'] . ' — ' . $it['detail'];
        }
        return $problems;
    }

    function inst_validate_db_input($in) {
        $h = trim((string)($in['db_host'] ?? ''));
        $p = trim((string)($in['db_port'] ?? '3306'));
        $n = trim((string)($in['db_name'] ?? ''));
        $u = trim((string)($in['db_user'] ?? ''));
        $pw = (string)($in['db_pass'] ?? '');
        $prefix = trim((string)($in['db_prefix'] ?? ''));
        if ($h === '') inst_json_error('میزبان دیتابیس الزامی است');
        if ($n === '') inst_json_error('نام دیتابیس الزامی است');
        if ($u === '') inst_json_error('نام کاربری دیتابیس الزامی است');
        if (!preg_match('/^\d{1,5}$/', $p) || (int)$p < 1 || (int)$p > 65535) inst_json_error('پورت دیتابیس نامعتبر است');
        if (!filter_var($h, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9._\-]+$/', $h)) inst_json_error('میزبان دیتابیس نامعتبر است');
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $n)) inst_json_error('نام دیتابیس شامل کاراکتر مجاز نیست');
        if ($prefix !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,15}$/', $prefix)) inst_json_error('پیشوند جدول‌ها فقط حروف، اعداد و زیرخط و حداکثر ۱۶ کاراکتر');
        return [$h, $p, $n, $u, $pw, $prefix];
    }

    $act = (string)($in['action'] ?? '');

    if ($act === 'check') {
        $items = inst_precheck();
        echo json_encode([
            'status' => 'success',
            'items' => $items,
            'problems' => inst_precheck_problems($items),
            'php' => PHP_VERSION
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'test_db') {
        $problems = inst_precheck_problems(inst_precheck());
        if ($problems) inst_json_error('پیش‌نیازها کامل نیست: ' . implode(' | ', $problems));
        list($h, $p, $n, $u, $pw, $prefix) = inst_validate_db_input($in);
        try {
            $pdo = new PDO("mysql:host=$h;port=$p;charset=utf8mb4", $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
        } catch (PDOException $e) {
            inst_json_error(inst_db_friendly($e));
        }
        $created = false;
        $q = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $q->execute([$n]);
        if (!$q->fetchColumn()) {
            try {
                $pdo->exec("CREATE DATABASE `" . str_replace('`', '', $n) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $created = true;
            } catch (PDOException $e) {
                inst_json_error("پایگاه داده «$n» وجود ندارد و ساخت خودکار آن ممکن نیست. ابتدا آن را از پنل هاست بسازید و دسترسی CREATE بدهید.");
            }
        }
        try {
            $pdo->exec("USE `" . str_replace('`', '', $n) . "`");
        } catch (PDOException $e) {
            inst_json_error('انتخاب پایگاه داده ممکن نشد: ' . $e->getMessage());
        }
        try {
            $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
        } catch (PDOException $e) {
            inst_json_error('خواندن نسخه دیتابیس ممکن نشد: ' . $e->getMessage());
        }
        $isMaria = stripos($ver, 'MariaDB') !== false;
        $clean = preg_replace('/[^0-9.].*$/', '', (string)$ver);
        $minOk = $isMaria ? version_compare($clean, '10.2', '>=') : version_compare($clean, '5.7', '>=');
        if (!$minOk) inst_json_error("نسخه دیتابیس ($ver) قدیمی است؛ برای پشتیبانی کامل فارسی و ایموجی حداقل MySQL 5.7 یا MariaDB 10.2 لازم است");
        $checks = [];
        $checks[] = ['label' => 'اتصال به سرور دیتابیس', 'ok' => true, 'detail' => $h . ':' . $p];
        $checks[] = ['label' => 'نسخه سرور', 'ok' => true, 'detail' => ($isMaria ? 'MariaDB' : 'MySQL') . ' ' . $clean];
        $checks[] = ['label' => 'پایگاه داده', 'ok' => true, 'detail' => $created ? '«' . $n . '» ساخته شد' : '«' . $n . '» از قبل موجود است'];

        // The application itself connects with dbname in the DSN, so test exactly that.
        try {
            $appPdo = new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
            $appPdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $appPdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
            if ((int)$appPdo->query("SELECT 1")->fetchColumn() !== 1) {
                inst_json_error('اتصال برنامه به پایگاه داده تأیید نشد');
            }
        } catch (PDOException $e) {
            inst_json_error('اتصال برنامه به پایگاه داده «' . $n . '» ممکن نشد — ' . inst_db_friendly($e));
        }
        $checks[] = ['label' => 'اتصال با نام پایگاه داده', 'ok' => true, 'detail' => 'همان روشی که خود برنامه استفاده می‌کند'];

        try {
            $stmtCs = $pdo->prepare("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmtCs->execute([$n]);
            $csRow = $stmtCs->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $csRow = null;
        }
        $dbCharset = $csRow ? (string)$csRow['DEFAULT_CHARACTER_SET_NAME'] : '';
        if ($dbCharset !== '' && strtolower($dbCharset) !== 'utf8mb4') {
            try {
                $pdo->exec("ALTER DATABASE `" . str_replace('`', '', $n) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $checks[] = ['label' => 'کدگذاری پایگاه داده', 'ok' => true, 'detail' => 'از ' . $dbCharset . ' به utf8mb4 تغییر کرد'];
            } catch (PDOException $e) {
                inst_json_error('پایگاه داده کدگذاری utf8mb4 ندارد و تغییر آن ممکن نشد' . ($dbCharset !== '' ? ' (کدگذاری فعلی: ' . $dbCharset . ')' : '') . ' — ' . $e->getMessage());
            }
        } else {
            $checks[] = ['label' => 'کدگذاری پایگاه داده', 'ok' => true, 'detail' => 'utf8mb4' . ($csRow ? ' / ' . $csRow['DEFAULT_COLLATION_NAME'] : '')];
        }

        try {
            $appPdo->exec("DROP TABLE IF EXISTS `__veyrax_probe`");
            $appPdo->exec("CREATE TABLE `__veyrax_probe` (id INT PRIMARY KEY, t VARCHAR(120)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (PDOException $e) {
            inst_json_error('کاربر دیتابیس اجازه ساخت جدول (CREATE) ندارد: ' . $e->getMessage());
        }
        $checks[] = ['label' => 'دسترسی ساخت و حذف جدول', 'ok' => true, 'detail' => 'CREATE / DROP تأیید شد'];

        $probeText = 'سلام دنیا 🌍 VIRAX';
        try {
            $ins = $appPdo->prepare("INSERT INTO `__veyrax_probe` (id, t) VALUES (1, ?)");
            $ins->execute([$probeText]);
            $sel = $appPdo->prepare("SELECT t FROM `__veyrax_probe` WHERE id = ?");
            $sel->execute([1]);
            $round = $sel->fetchColumn();
            $appPdo->exec("DROP TABLE `__veyrax_probe`");
            if (!is_string($round) || $round !== $probeText) {
                inst_json_error('ذخیره و بازیابی متن فارسی/ایموجی با اتصال برنامه تأیید نشد؛ کدگذاری دیتابیس را بررسی کنید');
            }
        } catch (PDOException $e) {
            try { $appPdo->exec("DROP TABLE IF EXISTS `__veyrax_probe`"); } catch (Exception $e2) {}
            inst_json_error('نوشتن و خواندن داده در پایگاه داده ممکن نشد: ' . $e->getMessage());
        }
        $checks[] = ['label' => 'ذخیره و بازیابی فارسی و ایموجی', 'ok' => true, 'detail' => 'متن آزمایشی بدون تغییر بازگشت'];

        // Credentials that passed all checks, so a later install cannot silently use different ones.
        $_SESSION['inst_db_ok'] = hash('sha256', implode("\0", [$h, $p, $n, $u, $pw, $prefix]));
        $_SESSION['inst_db_time'] = time();
        $appPdo = null;
        $pdo = null;
        echo json_encode([
            'status' => 'success',
            'server' => ($isMaria ? 'MariaDB' : 'MySQL') . ' ' . $clean,
            'database' => $n,
            'endpoint' => $h . ':' . $p,
            'created' => $created,
            'checks' => $checks,
            'message' => $created ? 'اتصال کامل تأیید شد؛ پایگاه داده ساخته شد' : 'اتصال کامل تأیید شد؛ پایگاه داده از قبل موجود است'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($act === 'install') {
        $problems = inst_precheck_problems(inst_precheck());
        if ($problems) inst_json_error('پیش‌نیازها کامل نیست: ' . implode(' | ', $problems));
        list($h, $p, $n, $u, $pw, $prefix) = inst_validate_db_input($in);
        $fingerprint = hash('sha256', implode("\0", [$h, $p, $n, $u, $pw, $prefix]));
        if (empty($_SESSION['inst_db_ok']) || !hash_equals((string)$_SESSION['inst_db_ok'], $fingerprint)) {
            inst_json_error('ابتدا «تست اتصال» را با همین اطلاعات دیتابیس با موفقیت انجام دهید');
        }
        $adminU = trim((string)($in['admin_user'] ?? ''));
        $adminDisplayInput = trim((string)($in['admin_display'] ?? ''));
        $adminP = (string)($in['admin_pass'] ?? '');
        $adminP2 = (string)($in['admin_pass2'] ?? '');
        $tz = trim((string)($in['timezone'] ?? 'Asia/Tehran'));
        $siteName = trim((string)($in['site_name'] ?? ''));
        $appUrl = trim((string)($in['app_url'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\x{0600}-\x{06FF}]{3,20}$/u', $adminU)) inst_json_error('نام کاربری مدیر باید ۳ تا ۲۰ کاراکتر و فقط شامل حروف فارسی/انگلیسی و اعداد باشد');
        if (strlen($adminP) < 8) inst_json_error('رمز مدیر حداقل ۸ کاراکتر باشد');
        if ($adminDisplayInput !== '' && mb_strlen($adminDisplayInput, 'UTF-8') > 50) inst_json_error('نام نمایشی مدیر حداکثر ۵۰ کاراکتر باشد');
        if ($adminP !== $adminP2) inst_json_error('تکرار رمز مدیر مطابقت ندارد');
        if (!in_array($tz, timezone_identifiers_list(), true)) inst_json_error('منطقه زمانی نامعتبر است');
        if ($siteName === '' || mb_strlen($siteName, 'UTF-8') > 50) inst_json_error('نام نمایشی سایت نامعتبر است');
        if ($appUrl !== '' && !filter_var($appUrl, FILTER_VALIDATE_URL)) inst_json_error('آدرس برنامه نامعتبر است');

        try {
            $pdo = new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        } catch (PDOException $e) {
            inst_json_error(inst_db_friendly($e) . ' — مرحله «تست اتصال» را با همین اطلاعات دوباره اجرا کنید');
        }
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
        try {
            $pdo->exec("ALTER DATABASE `" . str_replace('`', '', $n) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        } catch (Exception $e) {}

        require_once __DIR__ . '/schema.php';
        try {
            veyrax_schema($pdo, $prefix, function ($label) {});
            if (function_exists('veyrax_upgrade')) veyrax_upgrade($pdo, $prefix);
        } catch (Exception $e) {
            inst_json_error('خطا در ساخت ساختار دیتابیس: ' . $e->getMessage());
        }

        $adminHash = password_hash($adminP, PASSWORD_BCRYPT);
        try {
            $pdo->prepare("INSERT INTO `{$prefix}users` (username, password_hash, sticker, is_online) VALUES (?, ?, '👑', 0)")
                ->execute([$adminU, $adminHash]);
        } catch (Exception $e) {
            inst_json_error('خطا در ساخت حساب مدیر (کاربران): ' . $e->getMessage());
        }
        try {
            $pdo->prepare("INSERT INTO `{$prefix}admin_users` (username, password_hash, display_name, created_by) VALUES (?, ?, 'مدیر سیستم', 'installer')")
                ->execute([$adminU, $adminHash]);
        } catch (Exception $e) {
            inst_json_error('خطا در ساخت حساب مدیر (پنل): ' . $e->getMessage());
        }

        $errors = [];
        try {
            $stmt = $pdo->prepare("SELECT id FROM `{$prefix}users` WHERE username = ? AND password_hash IS NOT NULL");
            $stmt->execute([$adminU]);
            if (!$stmt->fetch()) $errors[] = 'حساب مدیر تأیید نشد';
        } catch (Exception $e) {
            $errors[] = 'بررسی حساب مدیر ناموفق بود';
        }
        $expected = ['users','rooms','messages','settings','system_settings','user_sessions','banned_ips','admin_users','room_invites','user_reports','global_alerts','update_notifications','update_notif_seen','pinned_messages','message_reactions','channel_members'];
        foreach ($expected as $tbl) {
            try {
                $chk = $pdo->query("SHOW TABLES LIKE '" . str_replace(['%', '_'], ['\\%', '\\_'], $prefix . $tbl) . "'");
                if (!$chk->fetch()) $errors[] = 'جدول ' . $tbl . ' ساخته نشد';
            } catch (Exception $e) {
                $errors[] = 'بررسی جدول ' . $tbl . ' ناموفق بود';
            }
        }
        if ($errors) inst_json_error('نصب ناقص ماند: ' . implode(' | ', $errors));

        $encKey = 'veyrax_' . bin2hex(random_bytes(24));
        $wsSecret = 'ws_' . bin2hex(random_bytes(24));
        $adminDisplay = ($adminDisplayInput !== '') ? $adminDisplayInput : 'مدیر سیستم';

        // The admin password is stored as a bcrypt hash, never as plaintext.
        // Before 1.9.0 a single leaked data/config.php handed over the panel
        // directly; a hash makes that read worthless for logging in.
        $adminHashForCfg = password_hash($adminP, PASSWORD_BCRYPT);

        // Same-origin by default for the realtime server. '*' is what allowed a
        // page on any other domain to open a socket against this install.
        $wsOrigin = '';
        if ($appUrl !== '') {
            $parts = @parse_url($appUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $wsOrigin = ($parts['scheme'] ?? 'https') . '://' . $parts['host']
                    . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        $lines = [
            '<?php',
            "define('ENCRYPTION_KEY', " . var_export($encKey, true) . ");",
            "define('VEYRAX_DB_PREFIX', " . var_export($prefix, true) . ");",
            '$db_host = ' . var_export($h, true) . ';',
            '$db_port = ' . var_export($p, true) . ';',
            '$db_name = ' . var_export($n, true) . ';',
            '$db_user = ' . var_export($u, true) . ';',
            '$db_pass = ' . var_export($pw, true) . ';',
            '$admin_username = ' . var_export($adminU, true) . ';',
            '$admin_display_name = ' . var_export($adminDisplay, true) . ';',
            '// bcrypt hash — verify with verify_admin_password()',
            '$admin_password = ' . var_export($adminHashForCfg, true) . ';',
            '$app_name = ' . var_export($siteName, true) . ';',
            '$app_url = ' . var_export($appUrl, true) . ';',
            '$app_timezone = ' . var_export($tz, true) . ';',
            '$ws_enabled = true;',
            "\$ws_url = '';",
            "\$ws_internal_url = 'http://127.0.0.1:3000';",
            '$ws_secret = ' . var_export($wsSecret, true) . ';',
            '$ws_allowed_origins = ' . var_export($wsOrigin, true) . ';',
            '$ws_reconnect_interval = 5;',
            '',
            '// ── Hardening (1.9.0) ────────────────────────────────────────────────',
            '$admin_idle_timeout = 3600;',
            '// CIDR or exact IPs; empty = allow anywhere.',
            '$admin_ip_allowlist = [];',
            '$download_ticket_ttl = 120;',
            '$backup_encryption = true;',
        ];
        $cfg = implode("\n", $lines) . "\n";
        if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0755, true);
        $cfgPath = __DIR__ . '/data/config.php';
        if (@file_put_contents($cfgPath, $cfg) === false) {
            inst_json_error('نوشتن فایل data/config.php ممکن نشد؛ دسترسی نوشتن پوشه data را بررسی کنید');
        }
        @chmod($cfgPath, 0640);
        @file_put_contents(__DIR__ . '/data/index.html', '');

        // The directory guards (.htaccess / web.config / index.php) ship with
        // the package. Report anything missing rather than creating it: the
        // installer should not be in the business of writing server config.
        foreach (['data', 'data/backups', 'data/security', 'websocket', 'uploads'] as $guardDir) {
            $abs = __DIR__ . '/' . $guardDir;
            if (is_dir($abs) && !is_file($abs . '/.htaccess')) {
                error_log('[VIRAX] guard missing: ' . $guardDir . '/.htaccess — re-upload it from the package');
            }
        }

        $lockBody = '<?php exit; ?>' . "\n" . 'veyrax-installed' . "\n";
        if (@file_put_contents($LOCK_FILE, $lockBody) === false) {
            inst_json_error('نوشتن فایل قفل نصب ممکن نشد؛ نصب کامل شد اما قفل نشد. فایل data/veyrax_installed.php را دستی بسازید');
        }
        @chmod($LOCK_FILE, 0644);
        unset($_SESSION['inst_db_ok']);
        echo json_encode(['status' => 'success', 'message' => 'نصب با موفقیت انجام شد']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'عملیات ناشناخته']);
    exit;
}

$hasJson = (function_exists('json_encode') && extension_loaded('pdo_mysql') && extension_loaded('openssl'));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نصب ویراکس</title>
<style>
    @font-face { font-family: 'AppFont'; src: url('fonts/Kalameh1.ttf') format('truetype'); font-display: swap; }
    @font-face { font-family: 'AppBold'; src: url('fonts/Kalameh.ttf') format('truetype'); font-weight: bold; font-display: swap; }

    :root {
        --bg-0: #070b14;
        --bg-1: #0c1322;
        --card: rgba(19, 27, 44, 0.86);
        --card-2: rgba(255, 255, 255, 0.035);
        --stroke: rgba(255, 255, 255, 0.085);
        --stroke-2: rgba(255, 255, 255, 0.16);
        --ink: #e9eefc;
        --muted: #8b9ab8;
        --brand: #6366f1;
        --brand-2: #8b5cf6;
        --ok: #10b981;
        --err: #ef4444;
        --warn: #f59e0b;
        --r-lg: 26px;
        --r-md: 14px;
        --r-sm: 11px;
        --shadow: 0 30px 70px -20px rgba(0, 0, 0, 0.85), 0 2px 0 rgba(255, 255, 255, 0.04) inset;
    }

    * { box-sizing: border-box; outline: none; }
    html { -webkit-text-size-adjust: 100%; }
    body {
        margin: 0;
        min-height: 100vh;
        min-height: 100dvh;
        color: var(--ink);
        font-family: 'AppFont', 'Segoe UI', Tahoma, sans-serif;
        background: var(--bg-0);
        overflow-x: hidden;
        display: flex;
        justify-content: center;
        align-items: center;
        /* "safe" keeps the top of the card reachable when the content is
           taller than the viewport (plain centring clips it). */
        align-items: safe center;
        padding: clamp(14px, 3vw, 40px);
        padding-bottom: calc(clamp(14px, 3vw, 40px) + env(safe-area-inset-bottom, 0px));
    }

    /* ── ambient background ───────────────────────────────────────────── */
    .aurora { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
    .aurora span { position: absolute; border-radius: 50%; filter: blur(90px); opacity: 0.55; }
    .aurora .a1 { width: 46vw; height: 46vw; min-width: 340px; min-height: 340px; top: -14vw; right: -8vw; background: radial-gradient(circle, #6366f1, transparent 68%); }
    .aurora .a2 { width: 38vw; height: 38vw; min-width: 280px; min-height: 280px; bottom: -16vw; left: -10vw; background: radial-gradient(circle, #0ea5e9, transparent 68%); opacity: 0.34; }
    .aurora .a3 { width: 30vw; height: 30vw; min-width: 220px; min-height: 220px; top: 34%; left: 42%; background: radial-gradient(circle, #8b5cf6, transparent 70%); opacity: 0.28; }
    .aurora::after {
        content: '';
        position: absolute; inset: 0;
        background-image: linear-gradient(rgba(255, 255, 255, 0.028) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.028) 1px, transparent 1px);
        background-size: 54px 54px;
        mask-image: radial-gradient(circle at 50% 40%, #000 0%, transparent 78%);
        -webkit-mask-image: radial-gradient(circle at 50% 40%, #000 0%, transparent 78%);
    }

    /* ── shell ────────────────────────────────────────────────────────── */
    .shell {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 1080px;
        display: grid;
        grid-template-columns: 1fr 1.02fr;
        gap: clamp(20px, 3vw, 46px);
        align-items: center;
    }

    /* ── brand column ─────────────────────────────────────────────────── */
    .brand { display: flex; flex-direction: column; gap: 18px; }
    .brand-mark {
        width: 58px; height: 58px;
        display: grid; place-items: center;
        border-radius: 19px;
        background: linear-gradient(145deg, var(--brand), var(--brand-2));
        box-shadow: 0 16px 34px -12px rgba(99, 102, 241, 0.85), 0 1px 0 rgba(255, 255, 255, 0.28) inset;
    }
    .brand-mark svg { width: 30px; height: 30px; }
    .brand-title { font-family: 'AppBold'; font-size: clamp(27px, 3.4vw, 38px); margin: 0; letter-spacing: -0.4px; }
    .brand-sub { margin: 0; color: var(--muted); font-size: clamp(12.5px, 1.3vw, 14px); line-height: 2; max-width: 34ch; }
    .brand-feats { list-style: none; margin: 6px 0 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
    .brand-feats li { display: flex; align-items: flex-start; gap: 11px; }
    .brand-feats .fi {
        flex: 0 0 34px; width: 34px; height: 34px;
        display: grid; place-items: center;
        border-radius: 11px;
        background: var(--card-2);
        border: 1px solid var(--stroke);
        font-size: 15px;
    }
    .brand-feats b { display: block; font-family: 'AppBold'; font-size: 13px; font-weight: normal; }
    .brand-feats small { display: block; color: var(--muted); font-size: 11.5px; line-height: 1.9; margin-top: 1px; }
    .brand-meta { display: flex; flex-wrap: wrap; gap: 7px; margin-top: 4px; }
    .pill {
        font-size: 11px; color: #b9c6e2;
        background: rgba(255, 255, 255, 0.045);
        border: 1px solid var(--stroke);
        border-radius: 999px;
        padding: 5px 11px;
        direction: ltr;
    }

    /* ── wizard card ──────────────────────────────────────────────────── */
    .card {
        background: var(--card);
        backdrop-filter: blur(22px) saturate(140%);
        -webkit-backdrop-filter: blur(22px) saturate(140%);
        border: 1px solid var(--stroke);
        border-radius: var(--r-lg);
        box-shadow: var(--shadow);
        padding: clamp(20px, 3vw, 32px);
        position: relative;
        overflow: hidden;
    }
    .card::before {
        content: '';
        position: absolute; inset-inline: 0; top: 0; height: 1px;
        background: linear-gradient(90deg, transparent, rgba(139, 92, 246, 0.75), rgba(99, 102, 241, 0.75), transparent);
    }

    /* ── stepper ──────────────────────────────────────────────────────── */
    .stepper { position: relative; display: flex; justify-content: space-between; gap: 6px; margin: 0 0 26px; }
    .stepper::before {
        content: '';
        position: absolute;
        /* 4 equal columns → dot centres sit at 12.5% and 87.5% */
        inset-inline: 12.5%;
        top: 15px;
        height: 2px;
        background: rgba(255, 255, 255, 0.08);
        border-radius: 2px;
    }
    .step-dot {
        position: relative; z-index: 1;
        display: flex; flex-direction: column; align-items: center; gap: 7px;
        flex: 1; min-width: 0;
        color: #64748b; font-size: 11px; text-align: center;
        transition: color 0.25s;
    }
    .step-dot .dot {
        width: 32px; height: 32px; border-radius: 50%;
        display: grid; place-items: center;
        font-family: 'AppBold'; font-size: 12.5px;
        background: #131c2e;
        border: 1.5px solid rgba(255, 255, 255, 0.11);
        color: #7c8aa8;
        transition: 0.28s;
        position: relative;
    }
    .step-dot .lbl { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
    .step-dot.active { color: #e9eefc; }
    .step-dot.active .dot {
        background: linear-gradient(145deg, var(--brand), var(--brand-2));
        border-color: transparent;
        color: #fff;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.16), 0 10px 22px -8px rgba(99, 102, 241, 0.9);
    }
    .step-dot.done { color: #6ee7b7; }
    .step-dot.done .dot { background: rgba(16, 185, 129, 0.16); border-color: rgba(16, 185, 129, 0.55); color: transparent; }
    .step-dot.done .dot::after {
        content: '✓';
        position: absolute; inset: 0;
        display: grid; place-items: center;
        color: #6ee7b7; font-size: 14px;
    }

    /* ── panes ────────────────────────────────────────────────────────── */
    .step-pane { display: none; }
    .step-pane.active { display: block; animation: fadeUp 0.34s cubic-bezier(0.22, 0.61, 0.36, 1); }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(9px); } to { opacity: 1; transform: none; } }

    h2.pane-title { font-family: 'AppBold'; font-size: clamp(16.5px, 2vw, 19px); margin: 0 0 6px; font-weight: normal; }
    .pane-desc { font-size: 12.5px; color: var(--muted); margin: 0 0 20px; line-height: 2; }

    /* ── fields ───────────────────────────────────────────────────────── */
    .field { margin-top: 13px; }
    .field > label.fl { display: block; font-size: 12px; color: #c3cfe6; margin: 0 0 7px; }
    .in, select.in {
        width: 100%; height: 48px;
        background: rgba(8, 13, 24, 0.72);
        border: 1px solid #2a3550;
        color: var(--ink);
        border-radius: var(--r-md);
        padding: 0 15px;
        font-family: inherit;
        font-size: 14px;
        transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
        -webkit-appearance: none;
        appearance: none;
    }
    select.in {
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238b9ab8' stroke-width='2.4' stroke-linecap='round'><path d='M6 9l6 6 6-6'/></svg>");
        background-repeat: no-repeat;
        background-position: left 14px center;
        padding-left: 40px;
    }
    .in:hover { border-color: #3a4767; }
    .in:focus { border-color: var(--brand); background: rgba(8, 13, 24, 0.95); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.16); }
    .in::placeholder { color: #55627c; }
    .in:-webkit-autofill { -webkit-text-fill-color: var(--ink); -webkit-box-shadow: 0 0 0 60px #0a1120 inset; }
    /* Technical values (host, port, db name, password, url) stay in a neutral
       Latin face so digits are never ambiguous and never bidi-reordered. */
    .in[dir="ltr"], select.in {
        direction: ltr;
        text-align: left;
        font-family: 'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', sans-serif;
        font-size: 13.5px;
        letter-spacing: 0.2px;
    }

    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 11px; }

    /* password field with a reveal toggle */
    .pw-wrap { position: relative; }
    .pw-wrap .in { padding-left: 46px; }
    .pw-eye {
        position: absolute; left: 6px; top: 6px;
        width: 36px; height: 36px;
        border: 0; border-radius: 9px;
        background: transparent; color: #7c8aa8;
        cursor: pointer; display: grid; place-items: center;
        transition: 0.16s;
    }
    .pw-eye:hover { background: rgba(255, 255, 255, 0.07); color: #cbd5e1; }
    .pw-eye svg { width: 17px; height: 17px; }
    .pw-meter { display: flex; gap: 4px; margin-top: 7px; }
    .pw-meter i { flex: 1; height: 3px; border-radius: 3px; background: rgba(255, 255, 255, 0.09); transition: 0.2s; }
    .pw-meter.s1 i:nth-child(1) { background: var(--err); }
    .pw-meter.s2 i:nth-child(-n+2) { background: var(--warn); }
    .pw-meter.s3 i:nth-child(-n+3) { background: #3b82f6; }
    .pw-meter.s4 i { background: var(--ok); }
    .pw-note { font-size: 10.5px; color: var(--muted); margin-top: 5px; min-height: 14px; }

    /* ── buttons ──────────────────────────────────────────────────────── */
    .btn {
        position: relative;
        width: 100%; height: 50px; margin-top: 20px;
        background: linear-gradient(135deg, var(--brand), #4f46e5);
        color: #fff; border: none; border-radius: var(--r-md);
        font-family: 'AppBold'; font-size: 14.5px; font-weight: normal;
        cursor: pointer;
        overflow: hidden;
        transition: transform 0.18s, box-shadow 0.18s, opacity 0.18s;
        box-shadow: 0 12px 26px -14px rgba(99, 102, 241, 0.95);
    }
    .btn:hover:not(:disabled) { transform: translateY(-1.5px); box-shadow: 0 18px 34px -14px rgba(99, 102, 241, 1); }
    .btn:active:not(:disabled) { transform: translateY(0); }
    .btn:focus-visible { box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.35); }
    .btn:disabled { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
    .btn.is-busy { cursor: progress; opacity: 0.92; }
    .btn.is-busy::after {
        content: '';
        position: absolute; inset: 0;
        background: linear-gradient(100deg, transparent 20%, rgba(255, 255, 255, 0.28) 50%, transparent 80%);
        animation: btnSweep 1.1s linear infinite;
    }
    @keyframes btnSweep { from { transform: translateX(-100%); } to { transform: translateX(100%); } }
    .btn-ghost {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--stroke-2);
        font-family: 'AppFont';
        box-shadow: none;
        margin-top: 10px;
    }
    .btn-ghost:hover:not(:disabled) { box-shadow: none; background: rgba(255, 255, 255, 0.09); }
    .btn-ok { background: linear-gradient(135deg, var(--ok), #059669); box-shadow: 0 12px 26px -14px rgba(16, 185, 129, 0.95); }

    /* ── alerts ───────────────────────────────────────────────────────── */
    .alert {
        border-radius: var(--r-md); padding: 12px 15px;
        font-size: 12.5px; line-height: 1.95; margin: 14px 0 2px;
        display: none; white-space: pre-line;
        border: 1px solid transparent;
    }
    .alert.err { background: rgba(239, 68, 68, 0.09); border-color: rgba(239, 68, 68, 0.32); color: #fca5a5; }
    .alert.ok { background: rgba(16, 185, 129, 0.09); border-color: rgba(16, 185, 129, 0.32); color: #6ee7b7; }
    .alert.warn { background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.3); color: #fcd34d; }

    /* ── requirement rows ─────────────────────────────────────────────── */
    .chk {
        display: flex; justify-content: space-between; align-items: center; gap: 12px;
        background: rgba(255, 255, 255, 0.028);
        border: 1px solid var(--stroke);
        border-radius: var(--r-sm);
        padding: 12px 14px; font-size: 12.5px; margin-bottom: 8px;
        transition: 0.2s;
    }
    .chk .st { font-size: 14px; color: var(--ok); flex-shrink: 0; }
    .chk.bad { border-color: rgba(239, 68, 68, 0.35); background: rgba(239, 68, 68, 0.05); }
    .chk.bad .st { color: var(--err); }
    .hint { font-size: 10.8px; color: #64748b; margin-top: 4px; line-height: 1.85; }

    .checks { display: flex; flex-direction: column; gap: 6px; margin-top: 14px; }
    .checks .row {
        display: flex; justify-content: space-between; align-items: center; gap: 10px;
        background: rgba(16, 185, 129, 0.07);
        border: 1px solid rgba(16, 185, 129, 0.26);
        border-radius: var(--r-sm);
        padding: 9px 12px; font-size: 11.5px; color: #d1fae5;
    }
    .checks .row span:last-child { color: #8b9ab8; text-align: left; direction: ltr; }
    .checks .row span:first-child::before { content: '✔ '; color: #34d399; }
    .checks .checks-note { font-size: 10.5px; color: #64748b; margin-top: 2px; line-height: 1.8; }

    /* ── finish ───────────────────────────────────────────────────────── */
    .finish-box { text-align: center; padding: 12px 0 2px; }
    .finish-box .big {
        width: 76px; height: 76px; margin: 0 auto;
        display: grid; place-items: center;
        border-radius: 50%;
        background: radial-gradient(circle at 32% 28%, rgba(16, 185, 129, 0.35), rgba(16, 185, 129, 0.08));
        border: 1px solid rgba(16, 185, 129, 0.4);
        font-size: 32px;
        animation: popIn 0.5s cubic-bezier(0.22, 1.2, 0.36, 1);
    }
    @keyframes popIn { 0% { transform: scale(0.5); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
    .finish-box h3 { font-family: 'AppBold'; font-size: 19px; margin: 16px 0 8px; font-weight: normal; }
    .finish-box p { font-size: 12.5px; color: var(--muted); line-height: 2.1; margin: 0 0 16px; }
    .secure-note { margin-top: 14px; font-size: 10.8px; color: #5b6780; text-align: center; line-height: 1.95; }

    /* ── responsive ───────────────────────────────────────────────────── */
    @media (max-width: 980px) {
        .shell { grid-template-columns: 1fr; max-width: 620px; gap: 16px; }
        .brand { flex-direction: column; align-items: center; text-align: center; gap: 9px; }
        .brand-mark { width: 46px; height: 46px; border-radius: 15px; flex-shrink: 0; }
        .brand-mark svg { width: 24px; height: 24px; }
        .brand-title { font-size: 22px; }
        .brand-sub { display: none; }
        .brand-feats, .brand-meta { display: none; }
    }
    @media (max-width: 560px) {
        body { padding: 12px; align-items: flex-start; }
        .card { padding: 18px 15px; border-radius: 20px; }
        .step-dot .lbl { display: none; }
        .stepper { margin-bottom: 22px; }
        .stepper::before { top: 15px; }
        .row2 { grid-template-columns: 1fr; gap: 0; }
        .in, select.in { height: 46px; font-size: 13.5px; }
        .btn { height: 48px; font-size: 14px; }
        .pane-desc { font-size: 12px; margin-bottom: 16px; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
    }
</style>
</head>
<body>
<div class="aurora" aria-hidden="true"><span class="a1"></span><span class="a2"></span><span class="a3"></span></div>

<main class="shell">
    <section class="brand">
        <div class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.1 9.1 0 0 1-3.6-.8L3 21l1.9-5.1A8.3 8.3 0 0 1 4 11.5a8.4 8.4 0 0 1 8.4-8.4h.6A8.4 8.4 0 0 1 21 11.5z"/>
                <path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01"/>
            </svg>
        </div>
        <div>
            <h1 class="brand-title">ویراکس</h1>
            <p class="brand-sub">راه‌اندازی گام‌به‌گام پیام‌رسان تیمی. چهار مرحله، بدون پیکربندی دستی فایل.</p>
        </div>
        <ul class="brand-feats">
            <li>
                <span class="fi">⚡</span>
                <div><b>تحویل بلادرنگ</b><small>WebSocket و long-poll پشتیبان، بدون تأخیر محسوس</small></div>
            </li>
            <li>
                <span class="fi">🛡</span>
                <div><b>امنیت پیش‌فرض</b><small>CSRF، محدودیت نرخ، رمزنگاری پیام و نشست امن</small></div>
            </li>
            <li>
                <span class="fi">🧩</span>
                <div><b>نصب خودکار</b><small>ساخت جداول، ایندکس‌ها و حساب مدیر در یک مرحله</small></div>
            </li>
        </ul>
        <div class="brand-meta">
            <span class="pill" id="brandPhp">PHP</span>
            <span class="pill">MySQL · utf8mb4</span>
            <span class="pill">RTL</span>
        </div>
    </section>

    <section class="card">
        <div class="stepper">
            <div class="step-dot active" data-step="1"><span class="dot">۱</span><span class="lbl">پیش‌نیازها</span></div>
            <div class="step-dot" data-step="2"><span class="dot">۲</span><span class="lbl">دیتابیس</span></div>
            <div class="step-dot" data-step="3"><span class="dot">۳</span><span class="lbl">سایت</span></div>
            <div class="step-dot" data-step="4"><span class="dot">۴</span><span class="lbl">مدیر</span></div>
        </div>

        <div class="step-pane active" id="pane1">
            <h2 class="pane-title">بررسی پیش‌نیازها</h2>
            <div class="pane-desc">میزبان، نسخه PHP و افزونه‌های لازم بررسی می‌شود.</div>
            <div id="chkList"><div class="chk"><span>در حال بررسی…</span><span class="st">⏳</span></div></div>
            <div class="alert err" id="alert1" role="alert" aria-live="polite"></div>
            <button class="btn" id="btn1" disabled>ادامه</button>
        </div>

        <div class="step-pane" id="pane2">
            <h2 class="pane-title">اطلاعات دیتابیس</h2>
            <div class="pane-desc">اتصال تست می‌شود، utf8mb4 و ساخت جدول بررسی و در صورت نبود دیتابیس ساخته می‌شود.</div>
            <div class="field">
                <label class="fl" for="db_host">میزبان دیتابیس</label>
                <input class="in" id="db_host" value="localhost" autocomplete="off" dir="ltr">
            </div>
            <div class="row2">
                <div class="field">
                    <label class="fl" for="db_port">پورت</label>
                    <input class="in" id="db_port" value="3306" dir="ltr">
                </div>
                <div class="field">
                    <label class="fl" for="db_prefix">پیشوند جدول‌ها (اختیاری)</label>
                    <input class="in" id="db_prefix" placeholder="veyrax_" autocomplete="off" dir="ltr">
                </div>
            </div>
            <div class="field">
                <label class="fl" for="db_name">نام دیتابیس</label>
                <input class="in" id="db_name" placeholder="veyrax" autocomplete="off" dir="ltr">
            </div>
            <div class="field">
                <label class="fl" for="db_user">نام کاربری دیتابیس</label>
                <input class="in" id="db_user" autocomplete="off" dir="ltr">
            </div>
            <div class="field">
                <label class="fl" for="db_pass">رمز دیتابیس</label>
                <div class="pw-wrap">
                    <input class="in" id="db_pass" type="password" autocomplete="new-password" dir="ltr">
                    <button type="button" class="pw-eye" data-eye="db_pass" aria-label="نمایش رمز">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <div class="hint">اگر هاست اشتراکی دارید و کاربر اجازه ساخت دیتابیس ندارد، ابتدا از پنل هاست دیتابیس را بسازید.</div>
            <div class="checks" id="dbChecks" style="display:none;"></div>
            <div class="alert" id="alert2" role="alert" aria-live="polite"></div>
            <button class="btn" id="btn2">تست اتصال و ادامه</button>
            <div class="hint" id="dbLockNote">تا وقتی تست اتصال با موفقیت انجام نشود، ادامه به مرحله بعد ممکن نیست.</div>
        </div>

        <div class="step-pane" id="pane3">
            <h2 class="pane-title">تنظیمات سایت</h2>
            <div class="pane-desc">نام نمایشی، آدرس و منطقه زمانی برنامه را مشخص کنید.</div>
            <div class="field">
                <label class="fl" for="site_name">نام سایت</label>
                <input class="in" id="site_name" value="ویراکس">
            </div>
            <div class="field">
                <label class="fl" for="app_url">آدرس برنامه (اختیاری)</label>
                <input class="in" id="app_url" placeholder="https://example.com/chat" dir="ltr">
            </div>
            <div class="field">
                <label class="fl" for="timezone">منطقه زمانی</label>
                <select class="in" id="timezone"></select>
            </div>
            <div class="alert err" id="alert3" role="alert" aria-live="polite"></div>
            <button class="btn" id="btn3">ادامه</button>
        </div>

        <div class="step-pane" id="pane4">
            <h2 class="pane-title">حساب مدیر</h2>
            <div class="pane-desc">این حساب برای ورود به چت و پنل مدیریت استفاده می‌شود.</div>
            <div class="field">
                <label class="fl" for="admin_user">نام کاربری مدیر</label>
                <input class="in" id="admin_user" dir="auto" autocomplete="off">
            </div>
            <div class="field">
                <label class="fl" for="admin_display">نام نمایشی مدیر (اختیاری)</label>
                <input class="in" id="admin_display" dir="auto" autocomplete="off" maxlength="50" placeholder="مدیر سیستم">
            </div>
            <div class="field">
                <label class="fl" for="admin_pass">رمز عبور (حداقل ۸ کاراکتر)</label>
                <div class="pw-wrap">
                    <input class="in" id="admin_pass" type="password" autocomplete="new-password" dir="ltr">
                    <button type="button" class="pw-eye" data-eye="admin_pass" aria-label="نمایش رمز">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="pw-meter" id="pwMeter"><i></i><i></i><i></i><i></i></div>
                <div class="pw-note" id="pwNote"></div>
            </div>
            <div class="field">
                <label class="fl" for="admin_pass2">تکرار رمز عبور</label>
                <input class="in" id="admin_pass2" type="password" autocomplete="new-password" dir="ltr">
            </div>
            <div class="alert" id="alert4" role="alert" aria-live="polite"></div>
            <button class="btn btn-ok" id="btn4">نصب و ایجاد جداول</button>
            <div class="secure-note">پس از نصب موفق، نصب‌کننده قفل می‌شود و پیکربندی در پوشه data ذخیره می‌گردد.</div>
        </div>

        <div class="step-pane" id="pane5">
            <div class="finish-box">
                <div class="big">✓</div>
                <h3>نصب کامل شد</h3>
                <p>جداول و ایندکس‌ها ساخته شدند، حساب مدیر ایجاد شد و نصب‌کننده قفل شد.<br>اکنون می‌توانید وارد چت شوید.</p>
                <button class="btn btn-ok" onclick="window.location.href='index.php'">ورود به چت</button>
                <button class="btn btn-ghost" onclick="window.open('admin-login.php','_blank')">ورود به پنل مدیریت</button>
            </div>
        </div>
    </section>
</main>

<script>
const CSRF = <?php echo json_encode($CSRF); ?>;
const panes = [1, 2, 3, 4, 5];
let dbOk = false;
let dbVerifiedKey = null;
let testedDb = null;

function $(id) { return document.getElementById(id); }
function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function showAlert(n, cls, msg) {
    const a = $('alert' + n);
    a.className = 'alert ' + cls;
    a.textContent = msg;
    a.style.display = msg ? 'block' : 'none';
}
function showPane(n) {
    panes.forEach(p => $('pane' + p).classList.toggle('active', p === n));
    document.querySelectorAll('.step-dot').forEach(d => {
        const s = parseInt(d.dataset.step, 10);
        d.classList.toggle('active', s === n);
        d.classList.toggle('done', s < n || n === 5);
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
async function api(payload) {
    let r;
    try {
        r = await fetch('install.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'VeyraxInstaller' },
            body: JSON.stringify(Object.assign({ csrf: CSRF }, payload))
        });
    } catch (e) {
        throw new Error('ارتباط با سرور برقرار نشد؛ اتصال سرور را بررسی کنید');
    }
    let d = null;
    try { d = await r.json(); } catch (e) { throw new Error('پاسخ نامعتبر از سرور (کد ' + r.status + ')'); }
    if (!d || typeof d !== 'object' || typeof d.status !== 'string') throw new Error('پاسخ نامعتبر از سرور');
    if (d.status === 'error') throw new Error(d.message || 'خطای نامشخص از سرور');
    return d;
}

(function initTimezones() {
    const sel = $('timezone');
    const common = ['Asia/Tehran', 'Asia/Dubai', 'Asia/Baghdad', 'Asia/Kabul', 'Europe/Istanbul', 'Europe/Berlin', 'Europe/London', 'UTC'];
    common.forEach(tz => {
        const o = document.createElement('option');
        o.value = tz; o.textContent = tz;
        sel.appendChild(o);
    });
    try {
        const tzlist = <?php
            $all = @timezone_identifiers_list();
            echo json_encode(is_array($all) ? $all : []);
        ?>;
        const extra = tzlist.filter(t => !common.includes(t));
        if (extra.length) {
            const g = document.createElement('optgroup');
            g.label = 'سایر مناطق';
            extra.forEach(t => {
                const o = document.createElement('option');
                o.value = t; o.textContent = t;
                g.appendChild(o);
            });
            sel.appendChild(g);
        }
        if (Intl.DateTimeFormat().resolvedOptions().timeZone) {
            const guess = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if ([...sel.options].some(o => o.value === guess)) sel.value = guess;
        }
    } catch (e) {}
})();

try { $('db_host').value = window.location.hostname || 'localhost'; } catch (e) {}

(async function step1() {
    try {
        const d = await api({ action: 'check' });
        const list = $('chkList');
        const items = Array.isArray(d.items) ? d.items : [];
        if (!items.length) throw new Error('فهرست بررسی‌ها از سرور دریافت نشد');
        const bp = $('brandPhp');
        if (bp && d.php) bp.textContent = 'PHP ' + d.php;
        list.innerHTML = items.map(it =>
            '<div class="chk' + (it.ok ? '' : ' bad') + '"><span>' + esc(it.label) +
            '<div class="hint">' + esc(it.detail || '') + '</div></span><span class="st">' + (it.ok ? '✔' : '✖') + '</span></div>'
        ).join('');
        const problems = Array.isArray(d.problems) ? d.problems : [];
        if (problems.length > 0) {
            showAlert(1, 'err', '⚠ ' + problems.join('\n⚠ '));
            $('btn1').disabled = true;
        } else {
            showAlert(1, 'ok', 'همه پیش‌نیازها روی سرور تأیید شد (PHP ' + esc(d.php || '') + ')');
            $('btn1').disabled = false;
        }
    } catch (e) {
        $('chkList').innerHTML = '<div class="chk bad"><span>بررسی ممکن نشد</span><span class="st">✖</span></div>';
        $('btn1').disabled = true;
        showAlert(1, 'err', 'بررسی پیش‌نیازها ناموفق بود: ' + e.message);
    }
})();

$('btn1').addEventListener('click', () => showPane(2));

function readDb() {
    return {
        db_host: $('db_host').value.trim(),
        db_port: $('db_port').value.trim() || '3306',
        db_name: $('db_name').value.trim(),
        db_user: $('db_user').value.trim(),
        db_pass: $('db_pass').value,
        db_prefix: $('db_prefix').value.trim()
    };
}
function dbKey(p) {
    return [p.db_host, p.db_port, p.db_name, p.db_user, p.db_pass, p.db_prefix].join('\u0000');
}
function renderDbChecks(d) {
    const box = $('dbChecks');
    const rows = (Array.isArray(d.checks) ? d.checks : []).map(c =>
        '<div class="row"><span>' + esc(c.label) + '</span><span>' + esc(c.detail) + '</span></div>'
    ).join('');
    box.innerHTML = rows + (rows ? '<div class="checks-note">این بررسی‌ها روی سرور و با همان اطلاعاتی که وارد کرده‌اید انجام شد.</div>' : '');
    box.style.display = rows ? 'flex' : 'none';
}
function resetDbCheck(msg) {
    dbOk = false;
    dbVerifiedKey = null;
    testedDb = null;
    $('dbChecks').innerHTML = '';
    $('dbChecks').style.display = 'none';
    $('btn2').textContent = 'تست اتصال و ادامه';
    showAlert(2, msg ? 'warn' : '', msg || '');
}
['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'db_prefix'].forEach(id => {
    $(id).addEventListener('input', () => {
        if (dbOk) resetDbCheck('اطلاعات دیتابیس تغییر کرد؛ دوباره «تست اتصال» را اجرا کنید.');
    });
});

$('btn2').addEventListener('click', async () => {
    const b = $('btn2');
    const payload = Object.assign({ action: 'test_db' }, readDb());
    if (dbOk && dbVerifiedKey === dbKey(payload)) { showPane(3); return; }
    resetDbCheck('');
    b.disabled = true;
    b.textContent = 'در حال تست اتصال...';
    try {
        const d = await api(payload);
        if (!d.server || !Array.isArray(d.checks)) throw new Error('پاسخ آزمون اتصال ناقص بود؛ دوباره تلاش کنید');
        dbOk = true;
        dbVerifiedKey = dbKey(payload);
        testedDb = payload;
        renderDbChecks(d);
        showAlert(2, 'ok', d.message + ' — ' + d.server + ' — ' + (d.endpoint || ''));
        b.textContent = 'ادامه به مرحله بعد ✅';
    } catch (e) {
        resetDbCheck('');
        showAlert(2, 'err', 'اتصال تأیید نشد: ' + e.message + '\nتا وقتی اتصال تأیید نشود، ادامه به مرحله بعد ممکن نیست.');
    } finally {
        b.disabled = false;
    }
});

$('btn3').addEventListener('click', () => {
    if (!$('site_name').value.trim()) { showAlert(3, 'err', 'نام سایت الزامی است'); return; }
    const u = $('app_url').value.trim();
    if (u && !/^https?:\/\/.+/i.test(u)) { showAlert(3, 'err', 'آدرس برنامه باید با http:// یا https:// شروع شود'); return; }
    showAlert(3, '', '');
    showPane(4);
});

$('btn4').addEventListener('click', async () => {
    const b = $('btn4');
    showAlert(4, '', '');
    if (!dbOk || !testedDb || !dbVerifiedKey) { showAlert(4, 'err', 'ابتدا در مرحله «دیتابیس» تست اتصال را با موفقیت انجام دهید'); return; }
    if ($('admin_pass').value.length < 8) { showAlert(4, 'err', 'رمز مدیر حداقل ۸ کاراکتر باشد'); return; }
    if ($('admin_pass').value !== $('admin_pass2').value) { showAlert(4, 'err', 'تکرار رمز مطابقت ندارد'); return; }
    if (!$('admin_user').value.trim()) { showAlert(4, 'err', 'نام کاربری مدیر الزامی است'); return; }
    b.disabled = true;
    b.textContent = 'در حال نصب...';
    try {
        const d = await api({
            action: 'install',
            db_host: testedDb.db_host, db_port: testedDb.db_port, db_name: testedDb.db_name,
            db_user: testedDb.db_user, db_pass: testedDb.db_pass, db_prefix: testedDb.db_prefix,
            admin_user: $('admin_user').value.trim(),
            admin_display: ($('admin_display') ? $('admin_display').value.trim() : ''),
            admin_pass: $('admin_pass').value,
            admin_pass2: $('admin_pass2').value,
            timezone: $('timezone').value,
            site_name: $('site_name').value.trim(),
            app_url: $('app_url').value.trim()
        });
        if (d.status !== 'success') throw new Error(d.message || 'نصب انجام نشد');
        showPane(5);
    } catch (e) {
        showAlert(4, 'err', 'نصب انجام نشد: ' + e.message);
        b.disabled = false;
        b.textContent = 'نصب و ایجاد جداول';
    }
});

/* ── interface helpers (no effect on the install flow itself) ─────────── */

// Show / hide a password field.
document.querySelectorAll('.pw-eye').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var inp = document.getElementById(btn.dataset.eye);
        if (!inp) return;
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.style.color = show ? '#a5b4fc' : '';
        btn.setAttribute('aria-label', show ? 'پنهان کردن رمز' : 'نمایش رمز');
    });
});

// Password strength: length, mixed case, digits, symbols.
(function () {
    var inp = document.getElementById('admin_pass');
    var meter = document.getElementById('pwMeter');
    var note = document.getElementById('pwNote');
    if (!inp || !meter) return;
    var labels = ['', 'ضعیف', 'متوسط', 'خوب', 'قوی'];
    inp.addEventListener('input', function () {
        var v = inp.value || '';
        var score = 0;
        if (v.length >= 8) score++;
        if (v.length >= 12) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        score = Math.min(4, Math.max(v ? 1 : 0, score));
        meter.className = 'pw-meter' + (v ? ' s' + score : '');
        note.textContent = v ? labels[score] : '';
        note.style.color = score <= 1 ? '#fca5a5' : (score === 2 ? '#fcd34d' : '#6ee7b7');
    });
})();

// A working button gets a slow sweep so a long install never looks frozen.
['btn1', 'btn2', 'btn3', 'btn4'].forEach(function (id) {
    var b = document.getElementById(id);
    if (!b) return;
    var sync = function () {
        b.classList.toggle('is-busy', b.disabled && /\.\.\.|…/.test(b.textContent || ''));
    };
    new MutationObserver(sync).observe(b, { attributes: true, attributeFilter: ['disabled'], childList: true, characterData: true, subtree: true });
    sync();
});

// Enter advances the current step.
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.ctrlKey || e.shiftKey) return;
    var t = e.target;
    if (!t || t.tagName !== 'INPUT') return;
    var pane = t.closest('.step-pane');
    if (!pane) return;
    var btn = pane.querySelector('.btn');
    if (btn && !btn.disabled) { e.preventDefault(); btn.click(); }
});
</script>
</body>
</html>

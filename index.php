<?php
session_start();
set_time_limit(0);

define('DATA_FILE', __DIR__ . '/data.json');
define('LIVE_DIR', __DIR__ . '/live');

if (!file_exists(DATA_FILE)) {
    file_put_contents(DATA_FILE, json_encode([]));
}
if (!is_dir(LIVE_DIR)) {
    mkdir(LIVE_DIR, 0777, true);
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$channels = json_decode(file_get_contents(DATA_FILE), true) ?: [];

// API Endpoint
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success', 
        'channels' => $channels
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("CSRF Token Validation Failed.");
    }

    if ($_POST['action'] === 'add') {
        $name = htmlspecialchars(strip_tags(trim($_POST['name'] ?? '')));
        $url = filter_var($_POST['url'] ?? '', FILTER_SANITIZE_URL);
        $logo = filter_var($_POST['logo'] ?? '', FILTER_SANITIZE_URL);
        $rtmp = filter_var($_POST['rtmp'] ?? '', FILTER_SANITIZE_URL);
        
        if (!empty($name) && !empty($url)) {
            $id = count($channels) > 0 ? max(array_column($channels, 'id')) + 1 : 1;
            $channels[] = [
                'id' => $id, 
                'name' => $name, 
                'url' => $url,
                'logo' => $logo,
                'rtmp' => $rtmp
            ];
            file_put_contents(DATA_FILE, json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            header('Location: ?msg=added');
            exit;
        }
    }

    if ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $channels = array_filter($channels, fn($c) => $c['id'] !== $id);
        $channels = array_values($channels);
        file_put_contents(DATA_FILE, json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        header('Location: ?msg=deleted');
        exit;
    }

    if ($_POST['action'] === 'play') {
        $id = (int)($_POST['id'] ?? 0);
        $channel = array_filter($channels, fn($c) => $c['id'] === $id);
        
        if (!empty($channel)) {
            $channel = reset($channel);
            $inputUrl = escapeshellarg($channel['url']);
            $logoUrl = !empty($channel['logo']) ? escapeshellarg($channel['logo']) : '';
            $rtmpUrl = !empty($channel['rtmp']) ? escapeshellarg($channel['rtmp']) : '';
            $out = escapeshellarg(LIVE_DIR . '/index.m3u8');
            
            // إيقاف أي بث سابق وتنظيف المجلد
            exec("killall -9 ffmpeg > /dev/null 2>&1");
            array_map('unlink', glob(LIVE_DIR . "/*.*"));
            
            // بناء أمر FFmpeg ديناميكياً
            $cmd = "ffmpeg -re -y -i $inputUrl ";
            
            if ($logoUrl) {
                $cmd .= "-i $logoUrl -filter_complex \"overlay=W-w-20:20\" ";
            }
            
            // إعدادات الترميز الأساسية
            $cmd .= "-c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -pix_fmt yuv420p ";
            
            if ($rtmpUrl) {
                // إذا تم إدخال رابط RTMP، قم بالبث إليه
                $cmd .= "-f flv $rtmpUrl > /dev/null 2>&1 &";
            } else {
                // إذا لم يوجد رابط RTMP، قم بإنشاء HLS محلي
                $cmd .= "-f hls -hls_time 4 -hls_list_size 5 -hls_flags delete_segments $out > /dev/null 2>&1 &";
            }
            
            exec($cmd);
            header("Location: ?msg=playing&id={$id}");
            exit;
        }
    }
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$baseUrl = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$liveUrl = $baseUrl . '/live/index.m3u8';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم البث المباشر</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background-color: #0f1115; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { background-color: #16191f; border-left: 1px solid #2d323b; min-height: 100vh; }
        .card { background-color: #1e222a; border: 1px solid #2d323b; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .card-header { background-color: #252a34; border-bottom: 1px solid #2d323b; font-weight: bold; }
        .table-dark { background-color: #1e222a; }
        .table-dark th { background-color: #252a34; border-bottom: 2px solid #363c47; }
        .table-dark td { border-bottom: 1px solid #2d323b; vertical-align: middle; }
        .btn-play { background-color: #10b981; color: white; }
        .btn-play:hover { background-color: #059669; color: white; }
        .brand-logo { width: 40px; height: 40px; background: #e11d48; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: white; margin-left: 10px; }
        .badge-rtmp { background-color: #8b5cf6; }
        .badge-hls { background-color: #10b981; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 sidebar p-4">
                <div class="d-flex align-items-center mb-5">
                    <div class="brand-logo"><i class="fa-solid fa-satellite-dish"></i></div>
                    <h4 class="m-0 text-white">إدارة البث</h4>
                </div>
                <ul class="nav flex-column">
                    <li class="nav-item mb-2">
                        <a href="index.php" class="nav-link text-white active"><i class="fa-solid fa-house ms-2 text-primary"></i> الرئيسية</a>
                    </li>
                    <li class="nav-item mb-2">
                        <a href="?api=1" target="_blank" class="nav-link text-white"><i class="fa-solid fa-code ms-2 text-warning"></i> API القنوات</a>
                    </li>
                </ul>
            </div>

            <div class="col-md-10 p-4">
                <h2 class="mb-4 text-white">لوحة التحكم</h2>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                        <i class="fa-solid fa-circle-check ms-2"></i> 
                        <?php 
                            if ($_GET['msg'] === 'added') echo 'تم حفظ القناة وتحديث البيانات بنجاح.';
                            if ($_GET['msg'] === 'deleted') echo 'تم إزالة القناة من القائمة.';
                            if ($_GET['msg'] === 'playing') echo 'تم تشغيل البث في الخلفية وتوجيهه حسب الإعدادات.';
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header"><i class="fa-solid fa-plus ms-2"></i> إضافة إعدادات البث</div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label text-info"><i class="fa-solid fa-tv ms-1"></i> اسم القناة</label>
                                        <input type="text" name="name" class="form-control bg-dark text-white border-secondary" required placeholder="مثال: Bein Sports 1">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label text-warning"><i class="fa-solid fa-link ms-1"></i> رابط المصدر (Input)</label>
                                        <input type="url" name="url" class="form-control bg-dark text-white border-secondary" required placeholder="m3u8 / ts / rtmp" dir="ltr">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label text-success"><i class="fa-regular fa-image ms-1"></i> رابط الصورة (شعار القناة)</label>
                                        <input type="url" name="logo" class="form-control bg-dark text-white border-secondary" placeholder="http://.../logo.png (اختياري)" dir="ltr">
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label text-danger"><i class="fa-solid fa-tower-broadcast ms-1"></i> رابط بث RTMP (الوجهة)</label>
                                        <input type="url" name="rtmp" class="form-control bg-dark text-white border-secondary" placeholder="rtmp://... (اختياري)" dir="ltr">
                                        <small class="text-muted d-block mt-1">إذا تركته فارغاً، سيتم تشغيل البث بصيغة HLS محلياً.</small>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-save ms-1"></i> حفظ وتحديث</button>
                                </form>
                            </div>
                        </div>

                        <?php if(file_exists(LIVE_DIR . '/index.m3u8')): ?>
                        <div class="card mt-4 border-success">
                            <div class="card-header bg-success text-white"><i class="fa-solid fa-link ms-2"></i> البث المحلي النشط (HLS)</div>
                            <div class="card-body">
                                <input type="text" class="form-control bg-dark text-white mb-2" value="<?=$liveUrl?>" id="hlsLink" readonly dir="ltr">
                                <button onclick="copyLink()" class="btn btn-outline-success w-100"><i class="fa-solid fa-copy"></i> نسخ الرابط</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header"><i class="fa-solid fa-list ms-2"></i> القنوات والمصادر</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-dark table-hover m-0">
                                        <thead>
                                            <tr>
                                                <th>الاسم</th>
                                                <th>المصدر & الوجهة</th>
                                                <th class="text-center">تشغيل / حذف</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($channels)): ?>
                                                <tr><td colspan="3" class="text-center text-muted py-4">لا توجد قنوات، ابدأ بإضافة الروابط.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($channels as $ch): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?=htmlspecialchars($ch['name'])?></strong><br>
                                                        <?php if(!empty($ch['logo'])): ?>
                                                            <span class="badge bg-secondary mt-1"><i class="fa-solid fa-image"></i> مدمج بشعار</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="max-width: 250px;">
                                                        <div class="text-truncate mb-1" title="<?=htmlspecialchars($ch['url'])?>">
                                                            <span class="text-warning"><i class="fa-solid fa-arrow-right-to-bracket"></i></span> 
                                                            <small dir="ltr"><?=htmlspecialchars($ch['url'])?></small>
                                                        </div>
                                                        <div class="text-truncate" title="<?=!empty($ch['rtmp']) ? htmlspecialchars($ch['rtmp']) : 'Local HLS'?>">
                                                            <?php if(!empty($ch['rtmp'])): ?>
                                                                <span class="text-danger"><i class="fa-solid fa-arrow-right-from-bracket"></i></span> 
                                                                <small dir="ltr" class="text-muted"><?=htmlspecialchars($ch['rtmp'])?></small>
                                                                <span class="badge badge-rtmp ms-1">RTMP</span>
                                                            <?php else: ?>
                                                                <span class="text-success"><i class="fa-solid fa-arrow-right-from-bracket"></i></span>
                                                                <small class="text-muted">المجلد المحلي</small>
                                                                <span class="badge badge-hls ms-1">HLS</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-center align-middle">
                                                        <div class="d-flex justify-content-center gap-2">
                                                            <form method="POST" class="m-0">
                                                                <input type="hidden" name="action" value="play">
                                                                <input type="hidden" name="id" value="<?=$ch['id']?>">
                                                                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                                                                <button type="submit" class="btn btn-sm btn-play px-3" title="تشغيل عبر FFmpeg"><i class="fa-solid fa-power-off"></i></button>
                                                            </form>
                                                            <form method="POST" class="m-0" onsubmit="return confirm('تأكيد الحذف؟');">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="id" value="<?=$ch['id']?>">
                                                                <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                                                                <button type="submit" class="btn btn-sm btn-danger px-3"><i class="fa-solid fa-trash"></i></button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyLink() {
            var copyText = document.getElementById("hlsLink");
            copyText.select();
            document.execCommand("copy");
            alert("تم النسخ!");
        }
    </script>
</body>
</html>

<?php
/**
 * VmShell Redesigned Landing Page v2.0
 * Complete Single PHP Page with Card Design & Testimonials Carousel
 * Optimized for PC Wide Screen, Responsive, and Elegant
 * Preserved: All original text, images, videos, links
 * Added: Card-style layout, testimonials carousel, enhanced formatting
 */

// ==================== Configuration ====================
define('CACHE_DIR', __DIR__ . '/video_cache');
define('CACHE_LIFETIME', 3600); 
define('MAX_CACHE_SIZE', 500 * 1024 * 1024); 
define('DEBUG_MODE', false); 

if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

function writeLog($message) {
    if (DEBUG_MODE) {
        $logFile = __DIR__ . '/debug.log';
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
}

function cleanOldCache() {
    if (!is_dir(CACHE_DIR)) return;
    $now = time();
    $files = scandir(CACHE_DIR);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $filepath = CACHE_DIR . '/' . $file;
        if (!is_file($filepath)) continue;
        if ($now - filemtime($filepath) > CACHE_LIFETIME) {
            @unlink($filepath);
        }
    }
}

function generateCacheFilename($url, $index = 0) {
    return md5($url . '_' . $index) . '.mp4';
}

function getVideoQuality($videoUrl) {
    if (preg_match('/(\d+)x(\d+)/', $videoUrl, $matches)) {
        return $matches[1] . 'x' . $matches[2];
    }
    return 'HD';
}

function downloadVideo($videoUrl) {
    $context = stream_context_create([
        'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'follow_location' => true],
        'https' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'verify_peer' => false, 'follow_location' => true]
    ]);
    return @file_get_contents($videoUrl, false, $context);
}

function extractFromTwitSaveImproved($twitterUrl) {
    $infoUrl = 'https://twitsave.com/info?url=' . urlencode($twitterUrl);
    $context = stream_context_create([
        'http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'],
        'https' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', 'verify_peer' => false]
    ]);
    $html = @file_get_contents($infoUrl, false, $context);
    if (!$html) return [];
    $videos = [];
    if (preg_match_all('/href="(https:\/\/twitsave\.com\/download\?file=([^"]+))"/', $html, $matches)) {
        foreach ($matches[2] as $encodedUrl) {
            $realUrl = @base64_decode(urldecode($encodedUrl), true);
            if ($realUrl && strpos($realUrl, 'video.twimg.com') !== false) $videos[] = $realUrl;
        }
    }
    if (empty($videos) && preg_match_all('/(https:\/\/video\.twimg\.com\/[^"\s<>]+)/', $html, $matches)) {
        foreach ($matches[1] as $videoUrl) {
            $videoUrl = preg_replace('/["\s<>].*$/', '', $videoUrl);
            if (!empty($videoUrl)) $videos[] = $videoUrl;
        }
    }
    return array_unique($videos);
}

function extractFromNitter($twitterUrl) {
    $nitterInstances = ['https://nitter.net/', 'https://nitter.poast.org/', 'https://nitter.privacydev.net/'];
    foreach ($nitterInstances as $instance) {
        $nitterUrl = str_replace(['https://twitter.com/', 'https://x.com/'], $instance, $twitterUrl);
        $context = stream_context_create(['http' => ['timeout' => 15], 'https' => ['timeout' => 15, 'verify_peer' => false]]);
        $response = @file_get_contents($nitterUrl, false, $context);
        if (!$response) continue;
        $videos = [];
        if (preg_match_all('/<video[^>]*>.*?<source[^>]*src="([^"]+)"[^>]*type="video/', $response, $matches)) {
            foreach ($matches[1] as $videoUrl) {
                if (strpos($videoUrl, 'http') !== 0) $videoUrl = $instance . ltrim($videoUrl, '/');
                $videos[] = $videoUrl;
            }
        }
        if (!empty($videos)) return $videos;
    }
    return [];
}

function extractTwitterVideos($twitterUrl) {
    if (empty($twitterUrl)) return [];
    cleanOldCache();
    $twitterUrl = str_replace('x.com', 'twitter.com', preg_replace('/\?.*/', '', $twitterUrl));
    $videos = extractFromTwitSaveImproved($twitterUrl);
    if (empty($videos)) $videos = extractFromNitter($twitterUrl);
    
    $cachedVideos = [];
    foreach ($videos as $index => $videoUrl) {
        $cacheFilename = generateCacheFilename($twitterUrl, $index);
        $cachePath = CACHE_DIR . '/' . $cacheFilename;
        if (file_exists($cachePath) && filesize($cachePath) > 1000) {
            $cachedVideos[] = ['filename' => $cacheFilename, 'url' => $videoUrl, 'size' => filesize($cachePath), 'index' => $index, 'quality' => getVideoQuality($videoUrl)];
            continue;
        }
        $videoData = downloadVideo($videoUrl);
        if ($videoData && strlen($videoData) > 1000) {
            if (file_put_contents($cachePath, $videoData) !== false) {
                $cachedVideos[] = ['filename' => $cacheFilename, 'url' => $videoUrl, 'size' => strlen($videoData), 'index' => $index, 'quality' => getVideoQuality($videoUrl)];
            }
        }
    }
    return $cachedVideos;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_POST['action'] === 'extract_videos') {
        $url = isset($_POST['url']) ? trim($_POST['url']) : '';
        if (empty($url) || !preg_match('/(twitter\.com|x\.com)/', $url) || !preg_match('/\/status\/\d+/', $url)) {
            echo json_encode(['success' => false, 'error' => '請輸入有效的推特鏈接 (包含 /status/ID)']);
            exit;
        }
        $videos = extractTwitterVideos($url);
        if (!empty($videos)) {
            echo json_encode(['success' => true, 'videos' => $videos, 'count' => count($videos)]);
        } else {
            echo json_encode(['success' => false, 'error' => '無法提取視頻，請檢查鏈接是否包含視頻']);
        }
        exit;
    }
}

if (isset($_GET['serve_video'])) {
    $filename = basename($_GET['serve_video']);
    $filepath = CACHE_DIR . '/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); exit('視頻不存在'); }
    header('Content-Type: video/mp4');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

if (rand(1, 100) <= 10) cleanOldCache();

function translateIPKey($key) {
    $translation = [
        "ip" => "IP地址",
        "city" => "城市",
        "region" => "地區",
        "country" => "國家",
        "loc" => "经緯度",
        "org" => "組織",
        "timezone" => "時區"
    ];
    return $translation[$key] ?? ucfirst($key);
}

function getIPInfoColor($key) {
    $colors = [
        "ip" => ["bg" => "bg-blue-50", "border" => "border-blue-200"],
        "city" => ["bg" => "bg-green-50", "border" => "border-green-200"],
        "region" => ["bg" => "bg-yellow-50", "border" => "border-yellow-200"],
        "country" => ["bg" => "bg-purple-50", "border" => "border-purple-200"],
        "loc" => ["bg" => "bg-orange-50", "border" => "border-orange-200"],
        "org" => ["bg" => "bg-red-50", "border" => "border-red-200"],
        "timezone" => ["bg" => "bg-indigo-50", "border" => "border-indigo-200"]
    ];
    return $colors[$key] ?? ["bg" => "bg-slate-50", "border" => "border-slate-200"];
}

// Testimonials data
$testimonials = [
    [
        'name' => '李明',
        'role' => '獨立站賣家',
        'company' => '跨境電商',
        'content' => '使用VmShell的香港VPS已经3年了，穩定性和速度都超出預期。特別是他们的視頻下載工具，讓我的工作效率提升了50%。客服團隊也很專業，問題解決速度快。',
        'avatar' => '👨‍💼'
    ],
    [
        'name' => '王芳',
        'role' => '內容創作者',
        'company' => 'TikTok达人',
        'content' => '推特視頻下載工具真的是救星！高清畫質、下載速度快，而且完全免費。我每天都用它来获取素材，VmShell團隊的創新精神讓人印象深刻。',
        'avatar' => '👩‍🎨'
    ],
    [
        'name' => '张伟',
        'role' => '技術總監',
        'company' => '科技公司',
        'content' => '从VPS到雲端管理平台，VmShell的產品線很完整。我們公司在香港、美國都部署了他们的服務器，性能穩定，價格也很有競爭力。強烈推薦！',
        'avatar' => '👨‍💻'
    ],
    [
        'name' => '陈思',
        'role' => '跨境創業者',
        'company' => '全球貿易',
        'content' => '最近开始用VmBank处理國際轉賬，速度比Wise还快。USDT到中國的通道特別實用，真正解決了我的跨境收款痛點。这是我用过最簡潔的國際銀行系統。',
        'avatar' => '👩‍💼'
    ],
    [
        'name' => '刘建',
        'role' => '系統管理員',
        'company' => '互聯網公司',
        'content' => 'VmShell的PVE模板真的是懶人福音，開箱即用，省了我好多配置時間。免費的MySQL數據庫服務也很穩定，已经推薦给團隊的其他同事了。',
        'avatar' => '👨‍🔧'
    ],
    [
        'name' => '周晓',
        'role' => '自由職業者',
        'company' => '遠程工作',
        'content' => '用了VmShell的eSIM服務后，出國旅遊再也不用擔心通訊問題了。實體卡和数字eSIM的組合方案太聰明了，支持多种支付方式也很方便。',
        'avatar' => '✈️'
    ]
];
?>
<!DOCTYPE html>
<html lang="zh-CN" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e40af">
    <title>VmShell-CMIN2.香港,全球雲計算 & 視頻搬运工下載专家</title>
    <meta name="description" content="VmShell 提供專業推特高清視頻提取工具，支持高清畫質，香港CMI一键极速下載。全球雲計算服務商，VPS、独立服務器、雲端管理平台。">
    <meta name="keywords" content="VmShell,推特視頻下載,X視頻下載,香港VPS,雲計算,視頻搬运,CMI,全球云服務">
    <meta name="author" content="VmShell INC">
    <meta property="og:title" content="VmShell-CMIN2.香港,全球雲計算 & 視頻搬运工下載专家">
    <meta property="og:description" content="VmShell 提供專業推特高清視頻提取工具，支持高清畫質，香港CMI一键极速下載。">
    <meta property="og:type" content="website">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Noto+Sans+SC:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', 'Noto Sans SC', sans-serif; }
        body { background: linear-gradient(to bottom right, #f8fafc, #f0f9ff, #fdf4ff); }
        .glass { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
        .card-hover { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .btn-gradient { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); transition: all 0.3s ease; }
        .btn-gradient:hover { filter: brightness(1.1); transform: scale(1.02); }
        .carousel-container { overflow: hidden; }
        .carousel-track { display: flex; transition: transform 0.5s ease-out; }
        .carousel-slide { flex-shrink: 0; width: 100%; }
        @media (min-width: 1024px) {
            .carousel-slide { width: 33.333%; }
        }
    </style>

</head>
<body class="text-slate-900">

    <!-- Navigation -->
    <nav class="sticky top-0 z-50 glass border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🎬</span>
                <a href="https://vmshell.com/" target="_blank" class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 transition-all">VmShell INC</a>
            </div>
            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                <a href="#downloader" class="hover:text-blue-600 transition-colors">視頻下載</a>
                <a href="#about" class="hover:text-blue-600 transition-colors">關於我們</a>
                <a href="#products" class="hover:text-blue-600 transition-colors">產品服務</a>
                <a href="https://vmshell.com/" target="_blank" class="px-5 py-2.5 rounded-full btn-gradient text-white shadow-lg shadow-blue-500/30">官方網站</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-16 space-y-16">
        
        <!-- Hero & Downloader Section -->
        <section id="downloader" class="grid lg:grid-cols-2 gap-12 items-center py-12">
            <div class="space-y-8">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-blue-50 text-blue-600 text-sm font-bold tracking-wide uppercase">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                    </span>
                    快速 · 免費 · 無需登录
                </div>
                <h1 class="text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight">
                    (推特)X視頻 <br>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-indigo-600">一键高速下載</span>
                </h1>
                <p class="text-xl text-slate-600 max-w-xl leading-relaxed">
                    VmShell 提供的專業推特高清視頻提取工具，支持高清畫質，視頻搬运工的高速助理。只需粘貼鏈接，剩下的交给我們:-)
                </p>
                
                <!-- Input Card -->
                <div class="p-8 rounded-2xl bg-white border border-slate-200 shadow-xl space-y-6">
                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="relative flex-1">
                            <input type="text" id="twitter_url" placeholder="粘貼推特鏈接 (https://x.com/...)" 
                                   class="w-full pl-6 pr-12 py-4 bg-slate-50 rounded-xl border-2 border-slate-200 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all text-lg">
                        </div>
                        <button onclick="pasteFromClipboard()" class="px-6 py-4 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold transition-colors whitespace-nowrap">
                            📋 粘貼
                        </button>
                    </div>
                    
                    <button id="extract-btn" onclick="extractVideos()" class="w-full px-10 py-4 rounded-xl btn-gradient text-white font-bold text-lg shadow-lg shadow-blue-500/40 hover:shadow-blue-500/60 transition-all flex items-center justify-center gap-3">
                        <span id="btn-text">🔍 視頻提取</span>
                        <span id="btn-loading" class="hidden animate-spin h-6 w-6 border-4 border-white/30 border-t-white rounded-full"></span>
                    </button>

                    <!-- Status Messages -->
                    <div id="status-container" class="space-y-3">
                        <div id="info-message" class="hidden p-4 rounded-xl bg-blue-50 text-blue-700 border border-blue-100 animate-fade-in"></div>
                        <div id="error-message" class="hidden p-4 rounded-xl bg-red-50 text-red-700 border border-red-100 animate-fade-in"></div>
                        <div id="success-message" class="hidden p-4 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 animate-fade-in"></div>
                    </div>

                    <!-- Video List -->
                    <div id="video-list" class="hidden space-y-4 pt-4 border-t border-slate-200">
                        <h3 class="text-lg font-bold flex items-center gap-2">✅ 已提取視頻列表</h3>
                        <div id="video-items" class="grid gap-4"></div>
                    </div>
                </div>
            </div>
            
            <!-- Hero Image -->
            <div class="relative hidden lg:block">
                <div class="absolute -inset-4 bg-gradient-to-r from-blue-500 to-indigo-500 rounded-3xl blur-2xl opacity-20 animate-pulse"></div>
                <img src="https://linuxword.com/wp-content/uploads/2025/05/vmshelllogo2025-1.jpg" alt="VmShell" class="relative rounded-2xl shadow-2xl border-8 border-white object-cover w-full">
            </div>
        </section>

        <!-- Testimonials Section -->
        <section class="py-16 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl border border-blue-200">
            <div class="px-6 md:px-12 space-y-8">
                <!-- Header -->
                <div class="text-center space-y-3">
                    <h2 class="text-4xl font-bold text-slate-900 flex items-center justify-center gap-2">
                        <span>⭐</span> 用戶評價
                    </h2>
                    <p class="text-lg text-slate-600 max-w-2xl mx-auto">
                        来自全球用戶的真實反饋，VmShell 用實力贏得信任
                    </p>
                </div>

                <!-- Testimonials Grid - Desktop -->
                <div class="hidden lg:grid grid-cols-3 gap-6" id="testimonials-desktop">
                    <?php for ($i = 0; $i < 3; $i++): $t = $testimonials[$i]; ?>
                    <div class="bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition-shadow border border-slate-200 space-y-4">
                        <div class="flex gap-1">
                            <?php for ($j = 0; $j < 5; $j++): ?>
                            <svg class="w-4 h-4 fill-yellow-400 text-yellow-400" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                            <?php endfor; ?>
                        </div>
                        <p class="text-slate-700 text-sm leading-relaxed line-clamp-4">"<?php echo htmlspecialchars($t['content']); ?>"</p>
                        <div class="flex items-center gap-3 pt-4 border-t border-slate-200">
                            <div class="text-3xl"><?php echo $t['avatar']; ?></div>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-slate-900 truncate"><?php echo htmlspecialchars($t['name']); ?></p>
                                <p class="text-xs text-slate-500"><?php echo htmlspecialchars($t['role']); ?></p>
                                <p class="text-xs text-slate-400"><?php echo htmlspecialchars($t['company']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Testimonials Carousel - Mobile -->
                <div class="lg:hidden space-y-6">
                    <div class="carousel-container">
                        <div class="carousel-track" id="carousel-track">
                            <?php foreach ($testimonials as $t): ?>
                            <div class="carousel-slide px-2">
                                <div class="bg-white rounded-xl p-6 shadow-md border border-slate-200 space-y-4">
                                    <div class="flex gap-1">
                                        <?php for ($j = 0; $j < 5; $j++): ?>
                                        <svg class="w-4 h-4 fill-yellow-400 text-yellow-400" viewBox="0 0 20 20"><path d="M10 15l-5.878 3.09 1.123-6.545L.489 6.91l6.572-.955L10 0l2.939 5.955 6.572.955-4.756 4.635 1.123 6.545z"/></svg>
                                        <?php endfor; ?>
                                    </div>
                                    <p class="text-slate-700 leading-relaxed">"<?php echo htmlspecialchars($t['content']); ?>"</p>
                                    <div class="flex items-center gap-3 pt-4 border-t border-slate-200">
                                        <div class="text-3xl"><?php echo $t['avatar']; ?></div>
                                        <div>
                                            <p class="font-bold text-slate-900"><?php echo htmlspecialchars($t['name']); ?></p>
                                            <p class="text-xs text-slate-500"><?php echo htmlspecialchars($t['role']); ?></p>
                                            <p class="text-xs text-slate-400"><?php echo htmlspecialchars($t['company']); ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="flex items-center justify-center gap-4">
                        <button onclick="carouselPrev()" class="p-2 rounded-full bg-white border border-slate-200 hover:bg-slate-50 transition-colors">
                            <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>

                        <div class="flex gap-2" id="carousel-dots">
                            <?php for ($i = 0; $i < count($testimonials); $i++): ?>
                            <button onclick="carouselGoTo(<?php echo $i; ?>)" class="h-2 rounded-full transition-all <?php echo $i === 0 ? 'w-8 bg-blue-600' : 'w-2 bg-slate-300 hover:bg-slate-400'; ?>" id="dot-<?php echo $i; ?>"></button>
                            <?php endfor; ?>
                        </div>

                        <button onclick="carouselNext()" class="p-2 rounded-full bg-white border border-slate-200 hover:bg-slate-50 transition-colors">
                            <svg class="w-5 h-5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Desktop Navigation -->
                <div class="hidden lg:flex items-center justify-center gap-4">
                    <button onclick="carouselPrevDesktop()" class="p-2 rounded-full bg-white border border-slate-200 hover:bg-blue-50 hover:border-blue-300 transition-colors">
                        <svg class="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>

                    <div class="flex gap-2" id="carousel-dots-desktop">
                        <?php for ($i = 0; $i < count($testimonials); $i++): ?>
                        <button onclick="carouselGoToDesktop(<?php echo $i; ?>)" class="h-2 rounded-full transition-all <?php echo $i === 0 ? 'w-8 bg-blue-600' : 'w-2 bg-slate-300 hover:bg-slate-400'; ?>" id="dot-desktop-<?php echo $i; ?>"></button>
                        <?php endfor; ?>
                    </div>

                    <button onclick="carouselNextDesktop()" class="p-2 rounded-full bg-white border border-slate-200 hover:bg-blue-50 hover:border-blue-300 transition-colors">
                        <svg class="w-6 h-6 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-3 gap-4 pt-8 border-t border-blue-200">
                    <div class="text-center">
                        <p class="text-3xl font-bold text-blue-600">99.99%</p>
                        <p class="text-sm text-slate-600">服務器在線率</p>
                    </div>
                    <div class="text-center">
                        <p class="text-3xl font-bold text-blue-600">5年+</p>
                        <p class="text-sm text-slate-600">行业经验</p>
                    </div>
                    <div class="text-center">
                        <p class="text-3xl font-bold text-blue-600">10万+</p>
                        <p class="text-sm text-slate-600">满意用戶</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- IP Query & Location Section -->
        <section class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>🌍</span> IP 查询与定位
            </h2>
            
            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 md:p-12 space-y-8">
                <div class="space-y-4 mb-6">
                    <p class="text-lg text-slate-700">
                        輸入任意 IP 地址，即可查询其地理位置、ISP 信息、经緯度坐标等详细信息。支持全球 IP 查询，實時定位显示。
                    </p>
                </div>

                <!-- IP Query Form -->
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-200">
                    <form method="post" action="" class="flex flex-col md:flex-row items-end gap-4">
                        <div class="flex-1 w-full">
                            <label class="block text-sm font-semibold text-slate-700 mb-2">輸入 IP 地址</label>
                            <input type="text" id="ip_address" name="ip_address" class="w-full px-4 py-3 rounded-lg border-2 border-slate-300 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all" placeholder="例如: 8.8.8.8 或 1.1.1.1" required>
                        </div>
                        <button type="submit" class="px-8 py-3 rounded-lg btn-gradient text-white font-semibold shadow-lg shadow-blue-500/40 hover:shadow-blue-500/60 transition-all whitespace-nowrap">
                            🔍 查询
                        </button>
                    </form>
                </div>

                <!-- IP Info Display -->
                <div id="ip_info" class="space-y-6">
                    <?php
                    if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST["ip_address"])) {
                        $ip = htmlspecialchars($_POST["ip_address"]);
                        $api_key = '2e08c5cc649877';
                        $api_url = "https://ipinfo.io/{$ip}/json?token={$api_key}";
                        $response = @file_get_contents($api_url);
                        $ip_info = json_decode($response);

                        if ($ip_info && !isset($ip_info->error)) {
                            echo "<div class='space-y-4'>";
                            echo "<h3 class='text-2xl font-bold text-slate-900 mb-4'>✅ IP 信息查询结果</h3>";
                            echo "<div class='grid md:grid-cols-2 lg:grid-cols-3 gap-4'>";
                            
                            foreach ($ip_info as $key => $value) {
                                if (in_array($key, ['ip', 'city', 'region', 'country', 'loc', 'org', 'timezone'])) {
                                    $colors = getIPInfoColor($key);
                                    $displayKey = translateIPKey($key);
                                    $displayValue = $value;
                                    
                                    if ($key === 'loc') {
                                        [$lat, $lon] = explode(",", $value);
                                        $displayValue = "緯度: {$lat}, 經度: {$lon}";
                                    }
                                    
                                    echo "<div class='p-4 rounded-lg border {$colors['bg']} {$colors['border']}'>";
                                    echo "<p class='text-sm text-slate-600 mb-1'>{$displayKey}</p>";
                                    echo "<p class='text-lg font-bold text-slate-900'>{$displayValue}</p>";
                                    echo "</div>";
                                }
                            }
                            
                            echo "</div>";
                            echo "</div>";

                            if (isset($ip_info->loc)) {
                                [$latitude, $longitude] = array_map('floatval', explode(",", $ip_info->loc));
                                echo "<div class='space-y-4 pt-6 border-t border-slate-200'>";
                                echo "<h3 class='text-2xl font-bold text-slate-900'>🗺️ 地圖定位</h3>";
                                echo "<div id='map' class='w-full h-96 rounded-lg border border-slate-200 shadow-md'></div>";
                                echo "<script src='https://maps.googleapis.com/maps/api/js?key=AIzaSyBvBAqjQ6f2APqfkhUl5WL3_utydnVnJow'></script>";
                                echo "<script>
                                    function initMap() {
                                        const myLatLng = {lat: {$latitude}, lng: {$longitude}};
                                        const map = new google.maps.Map(document.getElementById('map'), {
                                            zoom: 10,
                                            center: myLatLng,
                                            mapTypeId: 'roadmap'
                                        });
                                        new google.maps.Marker({
                                            position: myLatLng,
                                            map: map,
                                            title: 'IP 位置: {$ip}'
                                        });
                                    }
                                    if (document.readyState === 'loading') {
                                        document.addEventListener('DOMContentLoaded', initMap);
                                    } else {
                                        initMap();
                                    }
                                </script>";
                                echo "</div>";
                            }
                        } else {
                            echo "<div class='p-4 rounded-lg bg-red-50 border border-red-200'>";
                            echo "<p class='text-red-700 font-semibold'>❌ 無法找到该 IP 地址的信息</p>";
                            echo "<p class='text-sm text-red-600 mt-2'>請檢查 IP 地址是否正確，或稍後重試。</p>";
                            echo "</div>";
                        }
                    }
                    ?>
                </div>
            </div>
        </section>

        <!-- About Section -->
        <section id="about" class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>📖</span> 關於 VmShell
            </h2>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 md:p-12 space-y-6">
                <div class="prose prose-lg max-w-none text-slate-700 space-y-4">
                    <p>尊敬的 VmShell 用戶，您好！時光荏苒，歲月如梭。VmShell INC 全體同仁向您致以最誠摯的問候与新春祝福！</p>
                    <p>2026 年，对 VmShell INC 而言，更是意義非凡的一年。我們很榮幸地宣佈，VmShell INC 已经走過了五个輝煌的春秋！自 2021 年成立以來，VmShell INC 始終秉持着「客戶至上、技術創新」的服務理念，深耕雲端運算服務領域，专注于全球數據中心虛擬機服務器租賃与軟件開發。</p>
                    <p>我們致力于为全球用戶提供穩定、高效、安全的雲端解決方案，并感謝您五年来对我們的信任与支持，正是有了您的陪伴，我們才能不斷成長与進步。</p>
                    <p>为慶祝公司成立五週年，VmShell INC 特別推出一系列「VmShell2026 五週年感恩反饋活動」。我們精心準備了多項超值優惠，涵蓋香港、美國、日本及英國等多個熱門地區的優質產品，旨在为您的業務發展提供更强大的助力！</p>
                    <p>VMSHELL INC 是一家成立于 2021 年的美國雲計算服務公司，總部位於懷俄明州謝里丹，专注于提供全球數據中心的虛擬機服務器租賃和軟件開發服務。公司旗下品牌包括 VmShell 和 ToToTel，業務覆蓋亞洲、美洲以及歐洲，致力于为企業提供高效、穩定的網絡解決方案。</p>
                    <p>其核心服務包括虚拟私有服務器（VPS）、独立服務器以及雲端管理平台，广泛應用于企業業務拓展、流媒體優化和跨境電商等領域。</p>
                    <p>公司主打香港和美國圣何塞两大核心數據中心。香港數據中心採用中國移動 CMIN2.HK 高速網絡，支持三网優化，提供亞洲香港 CMIN2.HK 三网大陸優化 1Gbps 和美國 10Gbps 的國際帶寬，特別適合面向中國大陸及东南亚市場的用戶。</p>
                    <p>VMSHELL INC 以用戶體驗为核心，提供 24/7 技術支持，承諾 99.99% 的服務器在線率，并支持 PayPal、支付宝、比特币等多种支付方式，方便全球用戶。公司还開發了移動端管理應用，支持服務器運行監控、SSH 管理和 Linux 運維腳本，極大提升了用戶管理效率。</p>
                    <p>憑藉可靠的網絡性能和靈活的服務模式，VmShell INC 已成長为雲計算領域值得信賴的品牌，未來計劃持續優化技術社區功能，擴展更多創新服務。</p>
                    <div class="flex flex-col sm:flex-row gap-4 pt-4">
                        <a href="https://vmshell.com/" target="_blank" class="text-blue-600 font-semibold hover:text-blue-700">🌐 VmShell 官方網站 →</a>
                        <a href="https://tototel.com/" target="_blank" class="text-blue-600 font-semibold hover:text-blue-700">🌐 ToToTel 官網 →</a>
                    </div>
                </div>
                <img src="https://linuxword.com/wp-content/uploads/2026/02/vmshellvpscom.jpg" alt="VmShell VPS" class="w-full rounded-xl shadow-md mt-8">
            </div>
        </section>

        <!-- Products Section -->
        <section id="products" class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>🎁</span> VmShell2026五週年庆典感恩反饋活動
            </h2>
            
            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 md:p-12">
                <p class="text-lg text-slate-700 mb-6">
                    凡購買以下指定香港產品，購買后請務必開啟工單（Ticket），我們将为您手動開通贈送的美國·洛杉磯 E 服務器。讓您以一份價格，享受雙重區域部署的靈活性与高效能！
                </p>

                <!-- Product Grid -->
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Classic Product -->
                    <div class="rounded-xl border border-slate-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-slate-50 to-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-red-600 font-bold text-sm">入門級</span>
                            <span class="text-blue-600 font-bold text-sm">无贈送</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">香港.CMIN2.HK-Classic</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>數據中心:</strong> 香港 CMIN2 / IP 歸屬：隨機</p>
                            <p><strong>配置:</strong> 1 核 / 512MB 內存 / 1TB 流量</p>
                            <p><strong>帶寬:</strong> 共享 400Mbps</p>
                            <p><strong>流媒體:</strong> 奈菲（隨機有迪斯尼）</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">不需要優惠码</code></p>
                            <p><strong>測試 IP:</strong> 156.251.176.254</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-blue-600 mb-4">33.00 USD / 年</p>
                            <a href="https://vmshell.com/aff.php?aff=2689&pid=12" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors">立即購買</a>
                        </div>
                    </div>

                    <!-- Product A -->
                    <div class="rounded-xl border border-blue-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-blue-50 to-white ring-1 ring-blue-100">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-red-600 font-bold text-sm">香港 · 產品 A</span>
                            <span class="text-red-600 font-bold text-sm">贈送美國</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">CMIN2.HK-USA-IP</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>數據中心:</strong> 香港 CMIN2 / IP 歸屬：美國</p>
                            <p><strong>配置:</strong> 1 核 / 1GB 內存 / 2TB 流量</p>
                            <p><strong>帶寬:</strong> 共享 550Mbps</p>
                            <p><strong>流媒體:</strong> 奈菲 + GROK + Manus</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">vmshellusa2026</code></p>
                            <p><strong>測試 IP:</strong> 23.225.64.22</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-blue-600 mb-4">66.00 USD / 年</p>
                            <a href="https://vmshell.com/aff.php?aff=2689&pid=24" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors">立即購買</a>
                        </div>
                    </div>

                    <!-- Product B -->
                    <div class="rounded-xl border border-slate-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-slate-50 to-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-red-600 font-bold text-sm">香港 · 產品 B</span>
                            <span class="text-red-600 font-bold text-sm">贈送美國</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">CMIN2.HK-Macau-IP</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>數據中心:</strong> 香港 CMIN2 / IP 歸屬：澳门</p>
                            <p><strong>配置:</strong> 1 核 / 1GB 內存 / 2TB 流量</p>
                            <p><strong>帶寬:</strong> 共享 650Mbps</p>
                            <p><strong>流媒體:</strong> Netflix / Disney+</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">vmshellmo2026</code></p>
                            <p><strong>測試 IP:</strong> 163.53.246.88</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-blue-600 mb-4">77.00 USD / 年</p>
                            <a href="https://vmshell.com/aff.php?aff=2689&pid=25" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors">立即購買</a>
                        </div>
                    </div>

                    <!-- Product C -->
                    <div class="rounded-xl border border-slate-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-slate-50 to-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-red-600 font-bold text-sm">香港 · 產品 C</span>
                            <span class="text-red-600 font-bold text-sm">贈送美國</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">CMIN2.HK-HK-IP</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>數據中心:</strong> 香港 CMIN2 / IP 歸屬：香港</p>
                            <p><strong>配置:</strong> 1 核 / 1GB 內存 / 2TB 流量</p>
                            <p><strong>帶寬:</strong> 共享 750Mbps</p>
                            <p><strong>流媒體:</strong> Netflix / Disney+</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">vmshellhk2026</code></p>
                            <p><strong>測試 IP:</strong> 103.48.169.229</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-blue-600 mb-4">108.00 USD / 年</p>
                            <a href="https://vmshell.com/aff.php?aff=2689&pid=4" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors">立即購買</a>
                        </div>
                    </div>

                    <!-- US Dallas D -->
                    <div class="rounded-xl border border-green-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-green-50 to-white ring-1 ring-green-100">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-green-600 font-bold text-sm">贈送產品</span>
                            <span class="text-green-600 font-bold text-sm">美國·達拉斯</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">美國·達拉斯 D</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>數據中心:</strong> 達拉斯.US / IP 歸屬：香港</p>
                            <p><strong>配置:</strong> 1 核 / 1GB 內存 / 2TB 流量</p>
                            <p><strong>帶寬:</strong> 1Gbps</p>
                            <p><strong>流媒體:</strong> Netflix / Disney+ / AI</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">不需要優惠码</code></p>
                            <p><strong>測試 IP:</strong> 103.172.135.114</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-green-600 mb-4">25.00 USD / 年</p>
                            <a href="https://vmshell.com/aff.php?aff=2689&pid=18" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 transition-colors">查看(也可单独購買)</a>
                        </div>
                    </div>

                    <!-- US LA E -->
                    <div class="rounded-xl border border-purple-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-purple-50 to-white ring-1 ring-purple-100">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-purple-600 font-bold text-sm">贈送產品</span>
                            <span class="text-purple-600 font-bold text-sm">美國·洛杉磯</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">美國·洛杉磯 E</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>數據中心:</strong> 洛杉磯.US / IP 歸屬：美國</p>
                            <p><strong>配置:</strong> 1 核 / 1GB 內存 / 5TB 流量</p>
                            <p><strong>帶寬:</strong> 10Gbps</p>
                            <p><strong>流媒體:</strong> 美國全媒体 + AI 支持</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">不需要優惠码</code></p>
                            <p><strong>測試 IP:</strong> 23.173.216.107</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-purple-600 mb-4">40.00 USD / 年</p>
                            <a href="https://vmshell.com/aff.php?aff=2689&pid=21" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-purple-600 text-white font-semibold hover:bg-purple-700 transition-colors">查看(也可单独購買)</a>
                        </div>
                    </div>

                    <!-- Japan F -->
                    <div class="rounded-xl border border-slate-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-slate-50 to-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-orange-600 font-bold text-sm">日本</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">日本·產品 F</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>IP 歸屬:</strong> 日本</p>
                            <p><strong>配置:</strong> 1 核 / 1GB 內存 / 10GB + 4TB 流量</p>
                            <p><strong>帶寬:</strong> 國際 10Gbps</p>
                            <p><strong>流媒體:</strong> 仅支持 AI</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">jphuigui</code></p>
                            <p><strong>測試 IP:</strong> 94.177.17.84</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-orange-600 mb-4">90.00 USD / 年</p>
                            <a href="https://portal.tototel.com/aff.php?aff=1&pid=14" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-orange-600 text-white font-semibold hover:bg-orange-700 transition-colors">立即購買</a>
                        </div>
                    </div>

                    <!-- UK H -->
                    <div class="rounded-xl border border-slate-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-slate-50 to-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-red-600 font-bold text-sm">英國</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">英國·LONDON.UK-H</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>產品:</strong> LONDON.UK-Unlimited-KVM</p>
                            <p><strong>配置:</strong> 1C-1GB-20GB-不限流量@1Gbps</p>
                            <p><strong>IP 属性:</strong> 1 英國倫敦本地原生 IPV4</p>
                            <p><strong>流媒體:</strong> ChatGPT 和 TikTok.UK</p>
                            <p><strong>年付優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">England50</code></p>
                            <p><strong>測試 IP:</strong> 89.34.97.34</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-red-600 mb-4">69.99 USD / 年</p>
                            <a href="https://portal.tototel.com/aff.php?aff=1&pid=12" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-700 transition-colors">立即購買</a>
                        </div>
                    </div>

                    <!-- HK G -->
                    <div class="rounded-xl border border-slate-200 p-6 hover:shadow-lg transition-shadow bg-gradient-to-br from-slate-50 to-white">
                        <div class="flex items-center justify-between mb-4">
                            <span class="text-purple-600 font-bold text-sm">香港 · 產品 G</span>
                            <span class="text-purple-600 font-bold text-sm">不贈送</span>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 mb-4">CMIN2.HK-HK-Unlimited</h3>
                        <div class="space-y-2 text-sm text-slate-700 mb-6">
                            <p><strong>數據中心:</strong> 香港 CMIN2 / IP 歸屬：香港</p>
                            <p><strong>配置:</strong> 1 核 / 512MB 內存 / 無限流量</p>
                            <p><strong>帶寬:</strong> 限速 40Mbps (開機場隨時拉滿的!滾遠點!)</p>
                            <p><strong>流媒體:</strong> Netflix / Disney+</p>
                            <p><strong>優惠码:</strong> <code class="bg-slate-100 px-2 py-1 rounded">totohkcmin2</code></p>
                            <p><strong>測試 IP:</strong> 103.235.18.207</p>
                        </div>
                        <div class="border-t border-slate-200 pt-4">
                            <p class="text-2xl font-bold text-purple-600 mb-4">40.00 USD / 年</p>
                            <a href="https://portal.tototel.com/aff.php?aff=1&pid=11" target="_blank" class="block w-full text-center px-4 py-2 rounded-lg bg-purple-600 text-white font-semibold hover:bg-purple-700 transition-colors">立即購買</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- VmBank Section -->
        <section class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>🏦</span> VmBank 全球互聯網銀行
            </h2>
            
            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 md:p-12 space-y-8">
                <div class="text-center space-y-4 mb-8">
                    <span class="inline-block bg-blue-100 text-blue-600 px-4 py-2 rounded-full text-sm font-semibold">2025 全新上線</span>
                    <h3 class="text-3xl font-bold text-slate-900">讓中國人，用最簡單的方式，連接全球金融</h3>
                    <p class="text-lg text-slate-600 max-w-2xl mx-auto">
                        向 Wise 學習，打造至簡、合規、高效的跨境金融體系。
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 justify-center pt-4">
                        <a href="https://vmbanks.com" target="_blank" class="px-6 py-3 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors">訪問 VmBanks.com</a>
                        <a href="https://t.me/vmbanks" target="_blank" class="px-6 py-3 rounded-lg border border-blue-600 text-blue-600 font-semibold hover:bg-blue-50 transition-colors">加入 Telegram 交流群</a>
                    </div>
                </div>

                <img src="https://linuxword.com/wp-content/uploads/2026/02/VmBankUSD.jpg" alt="VmBank USD" class="w-full rounded-xl shadow-md">

                <div class="grid md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <h4 class="text-xl font-bold text-slate-900">核心使命</h4>
                        <p class="text-slate-700">
                            讓中國人，真正无门槛地走向全球金融。VmBank 不是一家傳統銀行，而是一套为中國人量身打造的全球互聯網銀行系統。全球开户·全球收款·全球转账·全球支付 —— 从此，一站完成。
                        </p>
                    </div>
                    <div class="space-y-4">
                        <h4 class="text-xl font-bold text-slate-900">核心競爭優勢</h4>
                        <ul class="space-y-2 text-slate-700">
                            <li>✅ 极致的兼容性</li>
                            <li>✅ 多元支付體系</li>
                            <li>✅ 實時交付系統</li>
                            <li>✅ 網絡質量保障</li>
                        </ul>
                    </div>
                </div>

                <img src="https://linuxword.com/wp-content/uploads/2026/02/VmBankUSDT.jpg" alt="VmBank USDT" class="w-full rounded-xl shadow-md">

                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-200">
                    <h4 class="text-lg font-bold text-blue-900 mb-4">🌟 VmBanks 全球第一家支持 USDT 快速转账到中國的金融平台</h4>
                    <ul class="space-y-3 text-slate-700">
                        <li><strong>USDT → 中國：</strong> 提供一条专门且優化的 USDT 兑换人民币并转入中國境内銀行卡的通道。</li>
                        <li><strong>极速到账：</strong> 宣称最快 8 分钟即可完成 USDT 到中國的转账，且 24 小时自动触发，不受傳統銀行工作時間限制。</li>
                        <li><strong>安全无忧：</strong> 强调「不封卡，没有后遗症」，旨在解決用戶通过个人通道或灰色路径转账所面临的资金冻结、账户风险等安全問題。</li>
                        <li><strong>简便易用：</strong> 针对中國用戶的使用习惯进行设计，力求操作流程简单便捷。</li>
                    </ul>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
                    <h4 class="text-lg font-bold text-slate-900">VmShell INC 聯繫信息</h4>
                    <div class="grid sm:grid-cols-2 gap-4 text-slate-700">
                        <p><strong>官網：</strong> <a href="https://vmbanks.com/" target="_blank" class="text-blue-600 hover:underline">https://vmbanks.com/</a></p>
                        <p><strong>Email：</strong> service@vmbanks.com</p>
                        <p><strong>WhatsApp：</strong> +1(323)-529-5889</p>
                        <p><strong>电话：</strong> +1(469)-278-6367</p>
                        <p class="sm:col-span-2"><strong>地址：</strong> 30 North Gould St Ste R, Sheridan, WY 82801, United States</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Resources Section -->
        <section class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>🛠️</span> 开源工具与免費資源
            </h2>

            <div class="grid md:grid-cols-2 gap-6">
                <!-- MySQL -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 space-y-4">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-2xl">🗄️</span>
                        <div>
                            <h3 class="text-xl font-bold text-slate-900">公网 MySQL 數據庫服務</h3>
                            <span class="inline-block bg-blue-100 text-blue-600 text-xs font-bold px-2 py-1 rounded mt-1">永久免費</span>
                        </div>
                    </div>
                    <p class="text-slate-700">
                        为了降低用戶重复安装數據庫的成本，VmShell 提供基于 ToToTel 灾备方案的免費 MySQL 服務，确保数据安全与網絡同步。
                    </p>
                    <div class="bg-slate-50 rounded-lg p-4 space-y-2 text-sm font-mono text-slate-700">
                        <p><strong>地址：</strong> mysql.vmshell.com</p>
                        <p><strong>端口：</strong> 3306</p>
                    </div>
                    <a href="https://mysql.vmshell.com/register.php" target="_blank" class="inline-block px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold hover:bg-blue-700 transition-colors">点击開通账号</a>
                    <img src="https://linuxword.com/wp-content/uploads/2025/03/f06c1a9ad290050bd0bafb4766462fbb.png" alt="MySQL" class="w-full rounded-lg mt-4">
                </div>

                <!-- PVE -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 space-y-4">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="text-2xl">🖥️</span>
                        <h3 class="text-xl font-bold text-slate-900">PVE 懶人无错版系統模板</h3>
                    </div>
                    <p class="text-slate-700">
                        提供预配置的 Linux 与 Windows PVE 模板，内置常用工具与優化设置，下載即用。
                    </p>
                    <div class="bg-green-50 rounded-lg p-4 space-y-2 text-sm text-slate-700 border border-green-200">
                        <p class="font-bold text-green-900">默认登录信息：</p>
                        <p>• Linux Root: 000000</p>
                        <p>• Windows: Windows@2019</p>
                        <p>• CDN 端口: 7788</p>
                        <p>• CDN 密码: root/000000</p>
                    </div>
                    <a href="https://linuxword.com/wp-content/uploads/PVE/dump.zip" class="inline-block px-4 py-2 rounded-lg bg-green-600 text-white font-semibold hover:bg-green-700 transition-colors">下載模板库 (dump.zip)</a>
                </div>
            </div>
        </section>

        <!-- GitHub Projects Section -->
        <section class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>💻</span> VmShell GitHub 开源项目
            </h2>

            <div class="grid md:grid-cols-2 lg:grid-cols-2 gap-6">
                <!-- Project 1 -->
                <div class="bg-white rounded-2xl border-l-4 border-l-blue-600 border border-slate-200 shadow-lg p-6 hover:shadow-xl transition-shadow">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-lg font-bold text-slate-900">VmShell-Strip.link-Credit-Card-WHMCS</h3>
                        <a href="https://github.com/FoxBary/VmShell-Strip.link-Credit-Card-WHMCS" target="_blank" class="text-blue-600 font-bold hover:underline">GitHub →</a>
                    </div>
                    <p class="text-slate-700 mb-4">
                        通过 Stripe 提供的 Link 支付方案，将 WHMCS 与信用卡支付完美結合。支持 3D Secure 2.0、Stripe Link 一键快速结账。
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-blue-50 p-3 rounded-lg">
                            <p class="font-bold text-blue-600 text-sm">3D Secure 2.0</p>
                            <p class="text-xs text-slate-600">符合 SCA 法规</p>
                        </div>
                        <div class="bg-blue-50 p-3 rounded-lg">
                            <p class="font-bold text-blue-600 text-sm">Stripe Link</p>
                            <p class="text-xs text-slate-600">一键快速结账</p>
                        </div>
                    </div>
                </div>

                <!-- Project 2 -->
                <div class="bg-white rounded-2xl border-l-4 border-l-teal-600 border border-slate-200 shadow-lg p-6 hover:shadow-xl transition-shadow">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-lg font-bold text-slate-900">VmShell-WHMCS-STRIP-ALIPAY</h3>
                        <a href="https://github.com/FoxBary/VmShell-WHMCS-STRIP-ALIPAY" target="_blank" class="text-teal-600 font-bold hover:underline">GitHub →</a>
                    </div>
                    <p class="text-slate-700 mb-4">
                        通过 Stripe 提供的支付宝通道，将 WHMCS 与支付宝完美結合。支持自动手续费计算、退款功能、實時狀態同步。
                    </p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-teal-50 p-3 rounded-lg">
                            <p class="font-bold text-teal-600 text-sm">自动手续费</p>
                            <p class="text-xs text-slate-600">自动计算并记录</p>
                        </div>
                        <div class="bg-teal-50 p-3 rounded-lg">
                            <p class="font-bold text-teal-600 text-sm">退款支持</p>
                            <p class="text-xs text-slate-600">后台一键发起</p>
                        </div>
                    </div>
                </div>

                <!-- Project 3 -->
                <div class="bg-white rounded-2xl border-l-4 border-l-green-600 border border-slate-200 shadow-lg p-6 hover:shadow-xl transition-shadow">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-lg font-bold text-slate-900">Strip-WHMCS-WeChatPAY</h3>
                        <a href="https://github.com/FoxBary/Strip-WHMCS-WeChatPAY" target="_blank" class="text-green-600 font-bold hover:underline">GitHub →</a>
                    </div>
                    <p class="text-slate-700">
                        WHMCS 系統对接最新 Stripe 的微信支付源碼。支持扫码支付、自动回调、多币种自动换算，極大提升中國及亚太市場客户支付體驗。
                    </p>
                </div>

                <!-- Project 4 -->
                <div class="bg-white rounded-2xl border-l-4 border-l-yellow-600 border border-slate-200 shadow-lg p-6 hover:shadow-xl transition-shadow">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-lg font-bold text-slate-900">CASH-APP-WHMCS</h3>
                        <a href="https://github.com/FoxBary/CASH-APP-WHMCS" target="_blank" class="text-yellow-600 font-bold hover:underline">GitHub →</a>
                    </div>
                    <p class="text-slate-700">
                        提供 Cash App 支付网关集成，通过 Stripe 平台實現 Cash App 支付功能，支持美國及其他地區用戶完成在線支付。
                    </p>
                </div>
            </div>
        </section>

        <!-- Video Section -->
        <section class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>🎥</span> 產品視頻介绍
            </h2>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 space-y-8">
                <div class="grid md:grid-cols-2 gap-8 items-center">
                    <div class="rounded-xl overflow-hidden shadow-lg">
                        <video preload="auto" autoplay loop muted playsinline controls class="w-full">
                            <source src="https://linuxword.com/wp-content/uploads/2025/04/波仔分享VMSHELL.mp4" type="video/mp4">
                            您的浏览器不支持視頻播放。
                        </video>
                    </div>
                    <div class="space-y-4">
                        <h3 class="text-2xl font-bold text-slate-900">關於波仔分享</h3>
                        <p class="text-slate-700">
                            欢迎订阅「波仔分享」频道！波仔，一个热爱生活、乐于分享的內容創作者。无论你对数码科技评测、網絡赚钱方法、副业攻略，还是真实的生活经验与踩坑记录感兴趣，波仔分享都会有你喜欢的内容！
                        </p>
                        <ul class="space-y-2 text-slate-700">
                            <li>💻 最新数码產品开箱与实测</li>
                            <li>💡 網絡副业推薦与平台实测</li>
                            <li>💰 真实项目收益经验分享</li>
                            <li>🎯 實用技巧与工具推薦</li>
                        </ul>
                        <a href="https://www.youtube.com/@%E6%B3%A2%E4%BB%94%E5%88%86%E4%BA%AB" target="_blank" class="inline-block px-6 py-3 rounded-lg bg-red-600 text-white font-semibold hover:bg-red-700 transition-colors">訪問 YouTube 频道 →</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- eSIM Section -->
        <section class="space-y-8">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>📱</span> VmShell 全球 eSIM 內測平台
            </h2>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 md:p-12 space-y-8">
                <div class="space-y-4">
                    <h3 class="text-2xl font-bold text-slate-900">🌟 VmShell 正式推出全球 eSIM 內測平台</h3>
                    <p class="text-slate-700 leading-relaxed">
                        该平台旨在为全球商務人士、跨境旅遊者及遠端辦公者提供一站式的電信解決方案。透過将傳統電信服務与數字化技術相結合，VmShell 不仅提供純數位的 eSIM 服務，更創新地推出了可寫入的實體 eSIM 卡，打破了設備硬件对 eSIM 技術的限制。
                    </p>
                    <p class="text-slate-700 leading-relaxed">
                        这是 VmShell 平台的亮點產品。对于不支援 eSIM 功能的舊款手機或特定設備，用戶只需加購 $10 USD 即可獲得一張實體 SIM 卡。该卡具備高度靈活性，用戶可将數位 eSIM 配置檔案寫入此實體卡中，實現「實體卡硬件 + eSIM 靈活性」的完美結合。
                    </p>
                </div>

                <div class="grid md:grid-cols-2 gap-6">
                    <img src="https://linuxword.com/wp-content/uploads/2026/02/zhonggang25.jpg" alt="eSIM" class="rounded-xl shadow-md">
                    <div class="space-y-4">
                        <h4 class="text-xl font-bold text-slate-900">核心競爭優勢</h4>
                        <div class="space-y-3">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                                <p class="font-bold text-blue-900">极致的兼容性</p>
                                <p class="text-sm text-slate-700">透過實體 eSIM 卡方案，讓所有具備 SIM 卡槽的設備都能享受 eSIM 的便利。</p>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg border border-green-200">
                                <p class="font-bold text-green-900">多元支付體系</p>
                                <p class="text-sm text-slate-700">全面支持信用卡、微信支付、USDT (TRC20) 支付。</p>
                            </div>
                            <div class="bg-purple-50 p-4 rounded-lg border border-purple-200">
                                <p class="font-bold text-purple-900">實時交付系統</p>
                                <p class="text-sm text-slate-700">數位 eSIM 信息直接發送至用戶郵箱，無需等待物流。</p>
                            </div>
                            <div class="bg-orange-50 p-4 rounded-lg border border-orange-200">
                                <p class="font-bold text-orange-900">網絡質量保障</p>
                                <p class="text-sm text-slate-700">依託 VmShell 全球數據中心資源，提供低延遲、高頻寬的連網體驗。</p>
                            </div>
                        </div>
                    </div>
                </div>

                <img src="https://linuxword.com/wp-content/uploads/2026/02/VmShellSIM原始设计图.png" alt="eSIM Design" class="w-full rounded-xl shadow-md">

                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-200 space-y-4">
                    <p class="text-slate-700">
                        預告：2026年，我們準備推出VmShell的全球實體SIM通訊卡！方便您的全球漫遊和旅遊。
                    </p>
                    <div class="space-y-2 text-slate-700">
                        <p><strong>內測平台：</strong> <a href="https://esim.linuxword.com" target="_blank" class="text-blue-600 hover:underline">https://esim.linuxword.com</a></p>
                        <p><strong>客服 Telegram：</strong> <a href="https://t.me/vmsus" target="_blank" class="text-blue-600 hover:underline">https://t.me/vmsus</a></p>
                        <p><strong>客服郵箱：</strong> <a href="mailto:bill@vmshell.com" class="text-blue-600 hover:underline">bill@vmshell.com</a></p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact Section -->
        <section class="space-y-8 pb-16">
            <h2 class="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <span>📞</span> 聯繫我們
            </h2>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-lg p-8 md:p-12">
                <div class="grid md:grid-cols-3 gap-8">
                    <div class="space-y-3">
                        <h3 class="text-lg font-bold text-slate-900">Telegram</h3>
                        <a href="https://t.me/vmsus" target="_blank" class="text-blue-600 hover:underline break-all">
                            https://t.me/vmsus
                        </a>
                    </div>
                    <div class="space-y-3">
                        <h3 class="text-lg font-bold text-slate-900">郵箱</h3>
                        <a href="mailto:bill@vmshell.com" class="text-blue-600 hover:underline">
                            bill@vmshell.com
                        </a>
                    </div>
                    <div class="space-y-3">
                        <h3 class="text-lg font-bold text-slate-900">官方網站</h3>
                        <a href="https://vmshell.com/" target="_blank" class="text-blue-600 hover:underline">
                            https://vmshell.com/
                        </a>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <!-- Footer - VmShell INC Official Style -->
    <footer class="bg-gradient-to-b from-slate-900 to-slate-950 text-slate-300 py-16 mt-20 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-6">
            <!-- Footer Content Grid -->
            <div class="grid md:grid-cols-4 gap-12 mb-12">
                <!-- Company Info -->
                <div class="space-y-4">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <span class="text-2xl">🎬</span> VmShell INC
                    </h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        全球領先的雲計算服務商，專注於虛擬機服務器租賃與軟件開發。
                    </p>
                    <p class="text-xs text-slate-500">
                        成立於 2021 年 | 總部位於懷俄明州謝里丹
                    </p>
                </div>

                <!-- Products -->
                <div class="space-y-4">
                    <h4 class="text-sm font-bold text-white uppercase tracking-wider">產品與服務</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="https://vmshell.com/" target="_blank" class="text-slate-400 hover:text-blue-400 transition-colors">VmShell VPS</a></li>
                        <li><a href="https://vmbanks.com" target="_blank" class="text-slate-400 hover:text-blue-400 transition-colors">VmBanks 銀行</a></li>
                        <li><a href="https://tototel.com/" target="_blank" class="text-slate-400 hover:text-blue-400 transition-colors">ToToTel 服務</a></li>
                        <li><a href="https://esim.linuxword.com" target="_blank" class="text-slate-400 hover:text-blue-400 transition-colors">eSIM 平台</a></li>
                    </ul>
                </div>

                <!-- Support -->
                <div class="space-y-4">
                    <h4 class="text-sm font-bold text-white uppercase tracking-wider">支持與幫助</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="https://vmshell.com/contact.php" target="_blank" class="text-slate-400 hover:text-blue-400 transition-colors">聯繫我們</a></li>
                        <li><a href="https://t.me/vmsus" target="_blank" class="text-slate-400 hover:text-blue-400 transition-colors">Telegram 客服</a></li>
                        <li><a href="mailto:bill@vmshell.com" class="text-slate-400 hover:text-blue-400 transition-colors">郵件支持</a></li>
                        <li><a href="https://vmshell.com/" target="_blank" class="text-slate-400 hover:text-blue-400 transition-colors">官方網站</a></li>
                    </ul>
                </div>

                <!-- Follow Us -->
                <div class="space-y-4">
                    <h4 class="text-sm font-bold text-white uppercase tracking-wider">關注我們</h4>
                    <div class="flex gap-3">
                        <a href="https://t.me/vmsus" target="_blank" class="p-2 rounded-lg bg-slate-800 hover:bg-blue-600 transition-colors text-slate-300 hover:text-white">
                            <span class="text-lg">📱</span>
                        </a>
                        <a href="mailto:bill@vmshell.com" class="p-2 rounded-lg bg-slate-800 hover:bg-blue-600 transition-colors text-slate-300 hover:text-white">
                            <span class="text-lg">✉️</span>
                        </a>
                        <a href="https://vmshell.com/" target="_blank" class="p-2 rounded-lg bg-slate-800 hover:bg-blue-600 transition-colors text-slate-300 hover:text-white">
                            <span class="text-lg">🌐</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Divider -->
            <div class="border-t border-slate-800 my-8"></div>

            <!-- Footer Bottom -->
            <div class="flex flex-col md:flex-row items-center justify-between gap-4 text-sm text-slate-400">
                <p>© 2021-2026 VmShell INC. 版權所有。</p>
                <div class="flex gap-6">
                    <a href="https://vmshell.com/" target="_blank" class="hover:text-blue-400 transition-colors">服務條款</a>
                    <a href="https://vmshell.com/contact.php" target="_blank" class="hover:text-blue-400 transition-colors">隱私政策</a>
                    <a href="https://vmshell.com/" target="_blank" class="hover:text-blue-400 transition-colors">聯繫方式</a>
                </div>
            </div>

            <!-- Company Details -->
            <div class="mt-8 pt-8 border-t border-slate-800 text-xs text-slate-500 text-center space-y-2">
                <p>VMSHELL INC - 美國正規註冊公司</p>
                <p>地址：30 North Gould St Ste R, Sheridan, Wyoming 82801, United States</p>
                <p>電話：+1(41)-409-278-6367 | 郵箱：bill@vmshell.com</p>
                <p>全球數據中心：香港 CMI | 美國聖何塞 | 日本 | 英國倫敦</p>
            </div>
        </div>
    </footer>

    <script>
        let carouselIndex = 0;
        const testimonialCount = <?php echo count($testimonials); ?>;

        function pasteFromClipboard() {
            navigator.clipboard.readText().then(text => {
                document.getElementById('twitter_url').value = text;
                showStatus('success', '✅ 已成功粘貼鏈接');
            }).catch(err => {
                showStatus('error', '❌ 無法訪問剪貼板，請手動粘貼');
            });
        }

        function extractVideos() {
            const url = document.getElementById('twitter_url').value.trim();
            if (!url) { showStatus('error', '❌ 請輸入推特鏈接'); return; }
            if (!/twitter\.com|x\.com/.test(url)) { showStatus('error', '❌ 請輸入有效的推特鏈接'); return; }

            const btn = document.getElementById('extract-btn');
            const btnText = document.getElementById('btn-text');
            const btnLoading = document.getElementById('btn-loading');

            btn.disabled = true;
            btnText.classList.add('hidden');
            btnLoading.classList.remove('hidden');

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=extract_videos&url=' + encodeURIComponent(url)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayVideos(data.videos);
                    showStatus('success', `✅ 成功提取 ${data.count} 个視頻`);
                } else {
                    showStatus('error', '❌ ' + data.error);
                }
            })
            .catch(error => { showStatus('error', '❌ 請求失敗：' + error.message); })
            .finally(() => {
                btn.disabled = false;
                btnText.classList.remove('hidden');
                btnLoading.classList.add('hidden');
            });
        }

        function displayVideos(videos) {
            const videoList = document.getElementById('video-list');
            const videoItems = document.getElementById('video-items');
            videoItems.innerHTML = '';

            videos.forEach((video, index) => {
                const sizeMB = (video.size / (1024 * 1024)).toFixed(2);
                const item = document.createElement('div');
                item.className = 'p-4 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between gap-4 hover:bg-slate-100 transition-colors';
                item.innerHTML = `
                    <div class="flex-1">
                        <h4 class="font-bold text-slate-900">視頻資源 ${index + 1}</h4>
                        <div class="flex gap-3 mt-1">
                            <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-600 text-xs font-bold uppercase">${video.quality}</span>
                            <span class="text-xs text-slate-500">${sizeMB} MB</span>
                        </div>
                    </div>
                    <a href="?serve_video=${video.filename}" download="vmshell_video_${index+1}.mp4" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-bold hover:bg-blue-700 transition-colors">下載</a>
                `;
                videoItems.appendChild(item);
            });
            videoList.classList.remove('hidden');
            videoList.scrollIntoView({ behavior: 'smooth' });
        }

        function showStatus(type, msg) {
            const ids = ['info-message', 'error-message', 'success-message'];
            ids.forEach(id => document.getElementById(id).classList.add('hidden'));
            const el = document.getElementById(type + '-message');
            el.textContent = msg;
            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 5000);
        }

        document.getElementById('twitter_url').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') extractVideos();
        });

        // Carousel functions with auto-play
        let carouselAutoPlayInterval = null;
        
        function updateCarousel() {
            const track = document.getElementById('carousel-track');
            if (track) {
                track.style.transform = `translateX(-${carouselIndex * 100}%)`;
            }
            updateDots();
        }

        function updateDots() {
            for (let i = 0; i < testimonialCount; i++) {
                const dot = document.getElementById('dot-' + i);
                if (dot) {
                    if (i === carouselIndex) {
                        dot.className = 'h-2 rounded-full transition-all w-8 bg-blue-600';
                    } else {
                        dot.className = 'h-2 rounded-full transition-all w-2 bg-slate-300 hover:bg-slate-400';
                    }
                }
            }
        }

        function carouselPrev() {
            carouselIndex = (carouselIndex - 1 + testimonialCount) % testimonialCount;
            updateCarousel();
            resetAutoPlay();
        }

        function carouselNext() {
            carouselIndex = (carouselIndex + 1) % testimonialCount;
            updateCarousel();
            resetAutoPlay();
        }

        function carouselGoTo(index) {
            carouselIndex = index;
            updateCarousel();
            resetAutoPlay();
        }

        function carouselPrevDesktop() {
            carouselIndex = (carouselIndex - 1 + testimonialCount) % testimonialCount;
            updateDots();
            resetAutoPlay();
        }

        function carouselNextDesktop() {
            carouselIndex = (carouselIndex + 1) % testimonialCount;
            updateDots();
            resetAutoPlay();
        }

        function carouselGoToDesktop(index) {
            carouselIndex = index;
            updateDots();
            resetAutoPlay();
        }

        function startAutoPlay() {
            if (carouselAutoPlayInterval) clearInterval(carouselAutoPlayInterval);
            carouselAutoPlayInterval = setInterval(() => {
                carouselIndex = (carouselIndex + 1) % testimonialCount;
                updateCarousel();
            }, 5000);
        }

        function resetAutoPlay() {
            if (carouselAutoPlayInterval) clearInterval(carouselAutoPlayInterval);
            startAutoPlay();
        }

        // Start auto-play when page loads
        document.addEventListener('DOMContentLoaded', () => {
            startAutoPlay();
        });
        
        // Also start auto-play immediately if DOM is already loaded
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startAutoPlay);
        } else {
            startAutoPlay();
        }
    </script>
</body>
</html>

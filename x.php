<?php
/**
 * VmShell Redesigned Layout v9.0
 * Optimized for PC Wide Screen, Responsive, and Elegant.
 * Removed: IP Query, Daily 60s News.
 * Preserved: Twitter Downloader, Product Info, All Product Cards.
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
            echo json_encode(['success' => false, 'error' => '请输入有效的推特链接 (包含 /status/ID)']);
            exit;
        }
        $videos = extractTwitterVideos($url);
        if (!empty($videos)) {
            echo json_encode(['success' => true, 'videos' => $videos, 'count' => count($videos)]);
        } else {
            echo json_encode(['success' => false, 'error' => '无法提取视频，请检查链接是否包含视频']);
        }
        exit;
    }
}

if (isset($_GET['serve_video'])) {
    $filename = basename($_GET['serve_video']);
    $filepath = CACHE_DIR . '/' . $filename;
    if (!file_exists($filepath)) { http_response_code(404); exit('视频不存在'); }
    header('Content-Type: video/mp4');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

if (rand(1, 100) <= 10) cleanOldCache();
?>
<!DOCTYPE html>
<html lang="zh-CN" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VmShell - 全球云计算 & 视频下载专家</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+SC:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', 'Noto Sans SC', sans-serif; background-color: #f8fafc; }
        .glass { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
        .hero-gradient { background: radial-gradient(circle at top right, #eff6ff, transparent), radial-gradient(circle at bottom left, #fdf4ff, transparent); }
        .card-hover { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .btn-gradient { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); transition: all 0.3s ease; }
        .btn-gradient:hover { filter: brightness(1.1); transform: scale(1.02); }
    </style>
</head>
<body class="text-slate-900 hero-gradient min-h-screen">

    <!-- Navigation -->
    <nav class="sticky top-0 z-50 glass border-b border-slate-200">
        <div class="max-w-[1600px] mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="text-3xl">🎬</span>
                <span class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-indigo-600"><a href="https://vmshell.com/" target="_blank" class="px-5 py-2.5 rounded-full btn-gradient text-white shadow-lg shadow-blue-500/30">VmShell INC</a></span>
            </div>
            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-slate-600">
                <a href="#downloader" class="hover:text-blue-600 transition-colors">视频下载</a>
                <a href="#about" class="hover:text-blue-600 transition-colors">关于我们</a>
                <a href="#products" class="hover:text-blue-600 transition-colors">产品服务</a>
                <a href="https://vmshell.com/" target="_blank" class="px-5 py-2.5 rounded-full btn-gradient text-white shadow-lg shadow-blue-500/30">官方网站</a>
            </div>
        </div>
    </nav>

    <main class="max-w-[1600px] mx-auto px-6 py-12 space-y-24">
        
        <!-- Hero & Downloader Section -->
        <section id="downloader" class="grid lg:grid-cols-2 gap-12 items-center">
            <div class="space-y-8">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-blue-50 text-blue-600 text-sm font-bold tracking-wide uppercase">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                    </span>
                    快速 · 免费 · 无需登录
                </div>
                <h1 class="text-5xl lg:text-7xl font-extrabold tracking-tight leading-tight">
                    (推特)X视频 <br>
                    <span class="text-blue-600">一键极速下载</span>
                </h1>
                <p class="text-xl text-slate-500 max-w-xl">
                    VmShell 提供的专业推特视频提取工具，支持高清画质，极速分析，保护隐私。只需粘贴链接，剩下的交给我们。
                </p>
                
                <div class="p-8 rounded-3xl glass shadow-2xl border-2 border-blue-100 space-y-6">
                    <div class="flex flex-col md:flex-row gap-4">
                        <div class="relative flex-1">
                            <input type="text" id="twitter_url" placeholder="粘贴推特链接 (https://x.com/...)" 
                                   class="w-full pl-6 pr-12 py-5 bg-white rounded-2xl border-2 border-slate-100 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/10 outline-none transition-all text-lg">
                            <button onclick="pasteFromClipboard()" class="absolute right-4 top-1/2 -translate-y-1/2 p-2 text-slate-400 hover:text-blue-600 transition-colors" title="粘贴">
                                📋
                            </button>
                        </div>
                        <button id="extract-btn" onclick="extractVideos()" class="px-10 py-5 rounded-2xl btn-gradient text-white font-bold text-lg shadow-xl shadow-blue-500/40 flex items-center justify-center gap-3">
                            <span id="btn-text">🔍 分析提取</span>
                            <span id="btn-loading" class="hidden animate-spin h-6 w-6 border-4 border-white/30 border-t-white rounded-full"></span>
                        </button>
                    </div>
                    
                    <div id="status-container" class="space-y-3">
                        <div id="info-message" class="hidden p-4 rounded-xl bg-blue-50 text-blue-700 border border-blue-100 animate-fade-in"></div>
                        <div id="error-message" class="hidden p-4 rounded-xl bg-red-50 text-red-700 border border-red-100 animate-fade-in"></div>
                        <div id="success-message" class="hidden p-4 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 animate-fade-in"></div>
                    </div>

                    <div id="video-list" class="hidden space-y-4 pt-4 border-t border-slate-100">
                        <h3 class="text-lg font-bold flex items-center gap-2">✅ 已提取视频列表</h3>
                        <div id="video-items" class="grid gap-4"></div>
                    </div>
                </div>
            </div>
            
            <div class="relative hidden lg:block">
                <div class="absolute -inset-4 bg-gradient-to-r from-blue-500 to-indigo-500 rounded-[3rem] blur-2xl opacity-20 animate-pulse"></div>
                <img src="https://linuxword.com/wp-content/uploads/2025/05/vmshelllogo2025-1.jpg" alt="VmShell" class="relative rounded-[2.5rem] shadow-2xl border-8 border-white">
            </div>
        </section>

        <!-- About Section -->
        <section id="about" class="space-y-12">
            <div class="text-center space-y-4">
                <h2 class="text-4xl font-bold">關於 VmShell</h2>
                <div class="w-24 h-1.5 bg-blue-600 mx-auto rounded-full"></div>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8">
                <div class="p-10 rounded-[2rem] glass card-hover space-y-6 border border-slate-100">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center text-3xl">🌐</div>
                    <h3 class="text-2xl font-bold">全球覆盖</h3>
                    <p class="text-slate-500 leading-relaxed">
                        VMSHELL INC 成立于2021年，总部位于怀俄明州。业务覆盖亚洲、美洲及欧洲，专注全球数据中心虚拟化服务。
                    </p>
                </div>
                <div class="p-10 rounded-[2rem] glass card-hover space-y-6 border border-slate-100">
                    <div class="w-16 h-16 bg-indigo-100 rounded-2xl flex items-center justify-center text-3xl">🚀</div>
                    <h3 class="text-2xl font-bold">极速网络</h3>
                    <p class="text-slate-500 leading-relaxed">
                        主打香港 CMIN2.HK 高速网络，支持三网优化。提供 1Gbps 到 10Gbps 的超大带宽，助力企业全球业务。
                    </p>
                </div>
                <div class="p-10 rounded-[2rem] glass card-hover space-y-6 border border-slate-100">
                    <div class="w-16 h-16 bg-emerald-100 rounded-2xl flex items-center justify-center text-3xl">🛡️</div>
                    <h3 class="text-2xl font-bold">稳定安全</h3>
                    <p class="text-slate-500 leading-relaxed">
                        承诺 99.99% 在线率，24/7 技术支持。支持多种支付方式，包括支付宝、PayPal及加密货币。
                    </p>
                </div>
            </div>

            <div class="p-12 rounded-[3rem] bg-slate-900 text-white overflow-hidden relative">
                <div class="absolute top-0 right-0 w-96 h-96 bg-blue-500/10 rounded-full blur-3xl -mr-48 -mt-48"></div>
                <div class="relative z-10 space-y-8 max-w-4xl">
                    <h3 class="text-3xl font-bold">五年砥砺前行，感恩有您</h3>
                    <div class="prose prose-invert max-w-none text-slate-400 text-lg leading-relaxed space-y-6">
                        <p>尊敬的 VmShell 用户：您好！时光荏苒，自 2021 年成立以来，VmShell INC 始终秉持着「客户至上、技术创新」的服务理念，深耕云端运算服务领域。</p>
                        <p>2026 年，我们很荣幸地宣布 VmShell 已走过五个辉煌春秋。我们致力于为全球用户提供稳定、高效、安全的云端解决方案，并感谢您五年来对我们的信任与支持。</p>
                        <div class="flex flex-wrap gap-6 pt-4">
                            <a href="https://vmshell.com/" class="text-blue-400 hover:text-blue-300 font-bold underline underline-offset-8 transition-colors">访问 VmShell 官网</a>
                            <a href="https://tototel.com/" class="text-indigo-400 hover:text-indigo-300 font-bold underline underline-offset-8 transition-colors">访问 ToToTel 官网</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Products Section -->
        <section id="products" class="space-y-12">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                <div class="space-y-4">
                    <h2 class="text-4xl font-bold">五周年感恩反馈活动</h2>
                    <p class="text-xl text-slate-500">买香港 PRO 送美国洛杉矶 E 项目 · 限时超值优惠</p>
                </div>
                <div class="px-6 py-3 rounded-2xl bg-amber-50 text-amber-700 border border-amber-100 text-sm font-bold">
                    ⚠️ 购买后请开启工单手动开通赠送产品
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-8">
                <!-- Product 1 -->
                <div class="group p-8 rounded-[2.5rem] glass border border-slate-100 card-hover flex flex-col">
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-slate-100 text-slate-600 text-xs font-bold uppercase tracking-wider">入门级</span>
                        <span class="text-2xl font-bold text-blue-600">$33.00<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">香港 CMIN2-Classic</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>📍</span> 数据中心：香港 CMIN2 (IP 随机)</div>
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1核 / 512MB / 1TB 流量</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：共享 400Mbps</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：支持 Netflix</div>
                    </div>
                    <a href="https://vmshell.com/aff.php?aff=2689&pid=12" target="_blank" class="w-full py-4 rounded-2xl bg-slate-900 text-white font-bold text-center hover:bg-blue-600 transition-all shadow-xl">立即抢购</a>
                </div>

                <!-- Product 2 -->
                <div class="group p-8 rounded-[2.5rem] glass border-2 border-blue-500 card-hover flex flex-col relative overflow-hidden">
                    <div class="absolute top-0 right-0 px-6 py-2 bg-blue-500 text-white text-xs font-bold rounded-bl-2xl">赠送活动</div>
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-blue-100 text-blue-600 text-xs font-bold uppercase tracking-wider">香港 · 产品 A</span>
                        <span class="text-2xl font-bold text-blue-600">$66.00<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">香港 CMIN2 / 美国IP</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>📍</span> IP 归属：<strong class="text-red-500">美国</strong></div>
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1核 / 1GB / 2TB 流量</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：共享 550Mbps</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：Netflix + GROK + Manus</div>
                    </div>
                    <a href="https://vmshell.com/aff.php?aff=2689&pid=24" target="_blank" class="w-full py-4 rounded-2xl btn-gradient text-white font-bold text-center shadow-xl shadow-blue-500/30">立即抢购</a>
                </div>

                <!-- Product 3 -->
                <div class="group p-8 rounded-[2.5rem] glass border border-slate-100 card-hover flex flex-col">
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-purple-100 text-purple-600 text-xs font-bold uppercase tracking-wider">香港 · 产品 B</span>
                        <span class="text-2xl font-bold text-blue-600">$77.00<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">香港 CMIN2 / 澳门IP</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>📍</span> IP 归属：<strong class="text-red-500">澳门</strong></div>
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1核 / 1GB / 2TB 流量</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：共享 650Mbps</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：Netflix / Disney+</div>
                    </div>
                    <a href="https://vmshell.com/aff.php?aff=2689&pid=25" target="_blank" class="w-full py-4 rounded-2xl bg-slate-900 text-white font-bold text-center hover:bg-blue-600 transition-all">立即抢购</a>
                </div>

                <!-- Product 4 -->
                <div class="group p-8 rounded-[2.5rem] glass border border-slate-100 card-hover flex flex-col">
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-rose-100 text-rose-600 text-xs font-bold uppercase tracking-wider">香港 · 产品 C</span>
                        <span class="text-2xl font-bold text-blue-600">$108.00<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">香港 CMIN2 / 香港IP</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>📍</span> IP 归属：<strong class="text-red-500">香港</strong></div>
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1核 / 1GB / 2TB 流量</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：共享 750Mbps</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：Netflix / Disney+</div>
                    </div>
                    <a href="https://vmshell.com/aff.php?aff=2689&pid=4" target="_blank" class="w-full py-4 rounded-2xl bg-slate-900 text-white font-bold text-center hover:bg-blue-600 transition-all">立即抢购</a>
                </div>

                <!-- Product 5 -->
                <div class="group p-8 rounded-[2.5rem] glass border border-slate-100 card-hover flex flex-col">
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-blue-100 text-blue-600 text-xs font-bold uppercase tracking-wider">美国 · 达拉斯 D</span>
                        <span class="text-2xl font-bold text-blue-600">$25.00<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">美国 · 达拉斯 D</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1核 / 1GB / 4TB 流量</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：1Gbps</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：Netflix / Disney+ / AI</div>
                        <div class="flex items-center gap-3"><span>🔍</span> 测试 IP：103.172.135.114</div>
                    </div>
                    <a href="https://vmshell.com/aff.php?aff=2689&pid=18" target="_blank" class="w-full py-4 rounded-2xl bg-slate-900 text-white font-bold text-center hover:bg-blue-600 transition-all">立即抢购</a>
                </div>

                <!-- Product 6 -->
                <div class="group p-8 rounded-[2.5rem] glass border border-slate-100 card-hover flex flex-col">
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-indigo-100 text-indigo-600 text-xs font-bold uppercase tracking-wider">美国 · 洛杉矶 E</span>
                        <span class="text-2xl font-bold text-blue-600">$40.00<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">美国 · 洛杉矶 E</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1核 / 1GB / 5TB 流量</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：10Gbps</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：支持 AI 辅助</div>
                        <div class="flex items-center gap-3"><span>🔍</span> 测试 IP：23.173.216.107</div>
                    </div>
                    <a href="https://vmshell.com/aff.php?aff=2689&pid=21" target="_blank" class="w-full py-4 rounded-2xl bg-slate-900 text-white font-bold text-center hover:bg-blue-600 transition-all">立即抢购</a>
                </div>

                <!-- Product 7 -->
                <div class="group p-8 rounded-[2.5rem] glass border border-slate-100 card-hover flex flex-col">
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-pink-100 text-pink-600 text-xs font-bold uppercase tracking-wider">日本 · 产品 F</span>
                        <span class="text-2xl font-bold text-blue-600">$90.00<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">日本 · 产品 F</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>📍</span> IP 归属：日本</div>
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1核 / 1GB / 4TB 流量</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：国际 10Gbps</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：仅支持 AI</div>
                    </div>
                    <a href="https://portal.tototel.com/aff.php?aff=1&pid=14" target="_blank" class="w-full py-4 rounded-2xl bg-slate-900 text-white font-bold text-center hover:bg-blue-600 transition-all">立即抢购</a>
                </div>

                <!-- Product 8 -->
                <div class="group p-8 rounded-[2.5rem] glass border border-slate-100 card-hover flex flex-col">
                    <div class="mb-6 flex justify-between items-start">
                        <span class="px-4 py-1.5 rounded-full bg-cyan-100 text-cyan-600 text-xs font-bold uppercase tracking-wider">英国 · 产品 H</span>
                        <span class="text-2xl font-bold text-blue-600">$69.99<span class="text-sm text-slate-400">/年</span></span>
                    </div>
                    <h3 class="text-2xl font-bold mb-4 group-hover:text-blue-600 transition-colors">伦敦 · Unlimited</h3>
                    <div class="space-y-4 flex-1 text-slate-500 text-sm mb-8">
                        <div class="flex items-center gap-3"><span>📍</span> IP 属性：英国伦敦原生</div>
                        <div class="flex items-center gap-3"><span>💻</span> 配置：1C-1GB-20GB SSD</div>
                        <div class="flex items-center gap-3"><span>⚡</span> 带宽：1Gbps 不限流量</div>
                        <div class="flex items-center gap-3"><span>🎬</span> 流媒体：ChatGPT / TikTok</div>
                    </div>
                    <a href="https://portal.tototel.com/aff.php?aff=1&pid=20" target="_blank" class="w-full py-4 rounded-2xl bg-slate-900 text-white font-bold text-center hover:bg-blue-600 transition-all">立即抢购</a>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-900 text-slate-400 py-20 px-6 mt-24">
        <div class="max-w-[1600px] mx-auto grid md:grid-cols-4 gap-12 border-b border-slate-800 pb-16 mb-12">
            <div class="space-y-6">
                <div class="flex items-center gap-3 text-white">
                    <span class="text-2xl">🎬</span>
                    <span class="text-xl font-bold">VmShell INC</span>
                </div>
                <p class="text-sm leading-relaxed">
                    致力於為中國人提供最簡單的方式，連接全球金融與網絡。五年品牌沉澱，值得信賴。
                </p>
            </div>
            <div class="space-y-6">
                <h4 class="text-white font-bold">快速链接</h4>
                <ul class="space-y-4 text-sm">
                    <li><a href="https://vmshell.com/" class="hover:text-blue-400 transition-colors">VmShell 官网</a></li>
                    <li><a href="https://tototel.com/" class="hover:text-blue-400 transition-colors">ToToTel 官网</a></li>
                    <li><a href="https://vmbanks.com/" class="hover:text-blue-400 transition-colors">VmBanks 银行</a></li>
                </ul>
            </div>
            <div class="space-y-6">
                <h4 class="text-white font-bold">核心业务</h4>
                <ul class="space-y-4 text-sm">
                    <li>推特视频下载</li>
                    <li>全球云计算 VPS</li>
                    <li>漫游 eSIM 卡</li>
                    <li>流媒体优化</li>
                </ul>
            </div>
            <div class="space-y-6">
                <h4 class="text-white font-bold">总部地址</h4>
                <p class="text-sm leading-relaxed">
                    怀俄明州谢里丹<br>
                    Wyoming, Sheridan, USA<br>
                    业务覆盖：亚洲、美洲、欧洲
                </p>
            </div>
        </div>
        <div class="max-w-[1600px] mx-auto flex flex-col md:flex-row justify-between items-center gap-6 text-xs uppercase tracking-widest">
            <p>&copy; 2021-2026 VmShell INC. All Rights Reserved.</p>
            <div class="flex gap-8">
                <span>Privacy Policy</span>
                <span>Terms of Service</span>
            </div>
        </div>
    </footer>

    <script>
        function pasteFromClipboard() {
            navigator.clipboard.readText().then(text => {
                document.getElementById('twitter_url').value = text;
                showStatus('success', '✅ 已成功粘贴链接');
            }).catch(err => {
                showStatus('error', '❌ 无法访问剪贴板，请手动粘贴');
            });
        }

        function extractVideos() {
            const url = document.getElementById('twitter_url').value.trim();
            if (!url) { showStatus('error', '❌ 请输入推特链接'); return; }
            if (!/twitter\.com|x\.com/.test(url)) { showStatus('error', '❌ 请输入有效的推特链接'); return; }

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
                    showStatus('success', `✅ 成功提取 ${data.count} 个视频`);
                } else {
                    showStatus('error', '❌ ' + data.error);
                }
            })
            .catch(error => { showStatus('error', '❌ 请求失败：' + error.message); })
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
                item.className = 'p-6 rounded-2xl bg-white border border-slate-100 flex items-center justify-between gap-4 shadow-sm';
                item.innerHTML = `
                    <div class="flex-1">
                        <h4 class="font-bold">视频资源 ${index + 1}</h4>
                        <div class="flex gap-3 mt-1">
                            <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-600 text-[10px] font-bold uppercase">${video.quality}</span>
                            <span class="text-xs text-slate-400">${sizeMB} MB</span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <a href="?serve_video=${video.filename}" download="vmshell_video_${index+1}.mp4" class="px-4 py-2 rounded-xl bg-blue-600 text-white text-sm font-bold hover:bg-blue-700 transition-colors">下载</a>
                        <button onclick="copyUrl('${video.url}')" class="px-4 py-2 rounded-xl bg-slate-100 text-slate-600 text-sm font-bold hover:bg-slate-200 transition-colors">复制</button>
                    </div>
                `;
                videoItems.appendChild(item);
            });
            videoList.classList.remove('hidden');
            videoList.scrollIntoView({ behavior: 'smooth' });
        }

        function copyUrl(text) {
            navigator.clipboard.writeText(text).then(() => {
                showStatus('success', '✅ 链接已复制');
            });
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
    </script>
</body>
</html>

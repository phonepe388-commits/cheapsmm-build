<?php
// GitHub Actions deploy script - self-deletes after running
if (!isset($_GET['t']) || $_GET['t'] !== '__DEPLOY_TOKEN__') {
    http_response_code(403);
    die('Forbidden');
}

// Safely call functions that may be in disable_functions on shared hosting
// (calling a disabled function causes Fatal Error even with @ prefix)
$_df = array_map('trim', explode(',', ini_get('disable_functions')));
if (!in_array('set_time_limit', $_df)) set_time_limit(300);
if (!in_array('ini_set', $_df)) {
    ini_set('memory_limit', '512M');
    ini_set('display_errors', '1');
}

$home       = '/home/__USERNAME__';
$zipFile    = "$home/project-with-vendor.zip";
$newApp     = "$home/laravel-app-new";
$app        = "$home/laravel-app";
$publicHtml = "$home/public_html";

$logFile = "$home/deploy.log";
file_put_contents($logFile, "");  // truncate on start

function logOut($msg) {
    global $logFile;
    file_put_contents($logFile, $msg, FILE_APPEND);
    echo $msg;
    flush();
}

header('Content-Type: text/plain');
logOut("Script started\n");

function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rmdir($dir);
}

function rcopy($src, $dst) {
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    foreach (array_diff(scandir($src), ['.', '..']) as $f) {
        $s = "$src/$f"; $d = "$dst/$f";
        is_dir($s) ? rcopy($s, $d) : copy($s, $d);
    }
}

function rchmod($dir, $fm, $dm) {
    @chmod($dir, $dm);
    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
        $p = "$dir/$f";
        is_dir($p) ? rchmod($p, $fm, $dm) : @chmod($p, $fm);
    }
}

try {
    // 1. Extract zip
    logOut("Extracting zip...\n");
    if (!file_exists($zipFile)) throw new Exception("Zip not found: $zipFile");
    rrmdir($newApp);
    mkdir($newApp, 0755, true);

    // Try fast exec unzip first, fall back to ZipArchive
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    $execOk = function_exists('exec') && !in_array('exec', $disabled);
    if ($execOk) {
        exec("unzip -o " . escapeshellarg($zipFile) . " -d " . escapeshellarg($newApp) . " 2>&1", $uzOut, $uzCode);
        if ($uzCode !== 0) throw new Exception("unzip failed: " . implode("\n", $uzOut));
        logOut("Extracted via unzip OK\n");
    } else {
        $zip = new ZipArchive;
        $res = $zip->open($zipFile);
        if ($res !== true) throw new Exception("ZipArchive::open failed with code: $res");
        if (!$zip->extractTo($newApp)) throw new Exception("ZipArchive::extractTo failed");
        $zip->close();
        logOut("Extracted via ZipArchive OK\n");
    }

    // 2. Move to final location
    logOut("Moving app...\n");
    rrmdir($app);
    rename($newApp, $app);
    logOut("Moved OK\n");

    // 3. Setup .env
    logOut("Setting up .env...\n");
    if (!file_exists("$app/.env.example")) throw new Exception(".env.example not found");
    $env = file_get_contents("$app/.env.example");
    $appKey = 'base64:' . base64_encode(random_bytes(32));
    $env = preg_replace('/^APP_URL=.*/m',        'APP_URL=https://__DOMAIN__',         $env);
    $env = preg_replace('/^APP_ENV=.*/m',        'APP_ENV=production',                  $env);
    $env = preg_replace('/^APP_DEBUG=.*/m',      'APP_DEBUG=true',                      $env);
    $env = preg_replace('/^APP_KEY=.*/m',        'APP_KEY=' . $appKey,                  $env);
    $env = preg_replace('/^DB_DATABASE=.*/m',    'DB_DATABASE=__DB_DATABASE__',         $env);
    $env = preg_replace('/^DB_USERNAME=.*/m',    'DB_USERNAME=__DB_USERNAME__',         $env);
    $env = preg_replace('/^DB_PASSWORD=.*/m',    'DB_PASSWORD=__DB_PASSWORD__',         $env);
    $env = preg_replace('/^DB_HOST=.*/m',        'DB_HOST=localhost',                   $env);
    $env = preg_replace('/^SESSION_DOMAIN=.*/m', 'SESSION_DOMAIN=__DOMAIN__',           $env);
    file_put_contents("$app/.env", $env);
    logOut(".env created\n");

    // 4. Permissions
    logOut("Setting permissions...\n");
    rchmod($app, 0644, 0755);
    rchmod("$app/storage", 0777, 0777);
    rchmod("$app/bootstrap/cache", 0777, 0777);

    // 5. Artisan commands (clear broken caches first, then migrate)
    if ($execOk) {
        logOut("Clearing caches...\n");
        exec("php $app/artisan optimize:clear 2>&1", $out); logOut(implode("\n", $out) . "\n"); $out = [];

        logOut("Running artisan migrate...\n");
        exec("php $app/artisan migrate --force 2>&1", $out); logOut(implode("\n", $out) . "\n"); $out = [];
    } else {
        logOut("exec() not available - clearing cache files manually...\n");
        @unlink("$app/bootstrap/cache/config.php");
        @unlink("$app/bootstrap/cache/routes-v7.php");
        @unlink("$app/bootstrap/cache/services.php");
        @unlink("$app/bootstrap/cache/packages.php");
        foreach (glob("$app/storage/framework/views/*.php") ?: [] as $f) @unlink($f);
        logOut("Cache files cleared\n");
    }

    // 6. Sync public_html (preserve directory, clear contents)
    logOut("Setting up public_html...\n");
    foreach (array_diff(scandir($publicHtml), ['.', '..']) as $f) {
        $p = "$publicHtml/$f";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rcopy("$app/public", $publicHtml);

    // 7. Fix index.php __DIR__ paths to absolute
    logOut("Fixing index.php...\n");
    $idx = file_get_contents("$publicHtml/index.php");
    $idx = str_replace("__DIR__.'/../storage",   "'{$app}/storage",   $idx);
    $idx = str_replace("__DIR__.'/../vendor",    "'{$app}/vendor",    $idx);
    $idx = str_replace("__DIR__.'/../bootstrap", "'{$app}/bootstrap", $idx);
    file_put_contents("$publicHtml/index.php", $idx);
    logOut("index.php fixed\n");

    // Show result for verification
    logOut("\n=== index.php content ===\n");
    logOut(file_get_contents("$publicHtml/index.php"));
    logOut("\n=== public_html files ===\n");
    logOut(implode("\n", scandir($publicHtml)) . "\n");

    // 8. Cleanup zip
    @unlink($zipFile);

    logOut("\n=== Deployment complete! ===\n");

} catch (Exception $e) {
    logOut("ERROR: " . $e->getMessage() . "\n");
    http_response_code(500);
}

// Self-delete
@unlink(__FILE__);

<?php
// GitHub Actions deploy script - self-deletes after running
if (!isset($_GET['t']) || $_GET['t'] !== '__DEPLOY_TOKEN__') {
    http_response_code(403);
    die('Forbidden');
}

// Lift PHP limits - extraction can be slow on shared hosting
set_time_limit(300);
@ini_set('memory_limit', '512M');

$home      = '/home/__USERNAME__';
$zipFile   = "$home/project-with-vendor.zip";
$newApp    = "$home/laravel-app-new";
$app       = "$home/laravel-app";
$publicHtml = "$home/public_html";

header('Content-Type: text/plain');

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
    echo "Extracting zip...\n"; flush();
    if (!file_exists($zipFile)) throw new Exception("Zip not found: $zipFile");
    rrmdir($newApp);
    mkdir($newApp, 0755, true);

    // Try fast exec unzip first, fall back to ZipArchive
    $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
    $execOk = function_exists('exec') && !in_array('exec', $disabled);
    if ($execOk) {
        exec("unzip -o " . escapeshellarg($zipFile) . " -d " . escapeshellarg($newApp) . " 2>&1", $uzOut, $uzCode);
        if ($uzCode !== 0) throw new Exception("unzip failed: " . implode("\n", $uzOut));
        echo "Extracted via unzip OK\n"; flush();
    } else {
        $zip = new ZipArchive;
        $res = $zip->open($zipFile);
        if ($res !== true) throw new Exception("ZipArchive::open failed with code: $res");
        if (!$zip->extractTo($newApp)) throw new Exception("ZipArchive::extractTo failed");
        $zip->close();
        echo "Extracted via ZipArchive OK\n"; flush();
    }

    // 2. Move to final location
    echo "Moving app...\n"; flush();
    rrmdir($app);
    rename($newApp, $app);
    echo "Moved OK\n"; flush();

    // 3. Setup .env
    echo "Setting up .env...\n"; flush();
    if (!file_exists("$app/.env.example")) throw new Exception(".env.example not found");
    $env = file_get_contents("$app/.env.example");
    $appKey = 'base64:' . base64_encode(random_bytes(32));
    $env = preg_replace('/^APP_URL=.*/m',        'APP_URL=https://__DOMAIN__',         $env);
    $env = preg_replace('/^APP_ENV=.*/m',        'APP_ENV=production',                  $env);
    $env = preg_replace('/^APP_DEBUG=.*/m',      'APP_DEBUG=false',                     $env);
    $env = preg_replace('/^APP_KEY=.*/m',        'APP_KEY=' . $appKey,                  $env);
    $env = preg_replace('/^DB_DATABASE=.*/m',    'DB_DATABASE=__DB_DATABASE__',         $env);
    $env = preg_replace('/^DB_USERNAME=.*/m',    'DB_USERNAME=__DB_USERNAME__',         $env);
    $env = preg_replace('/^DB_PASSWORD=.*/m',    'DB_PASSWORD=__DB_PASSWORD__',         $env);
    $env = preg_replace('/^DB_HOST=.*/m',        'DB_HOST=localhost',                   $env);
    $env = preg_replace('/^SESSION_DOMAIN=.*/m', 'SESSION_DOMAIN=__DOMAIN__',           $env);
    file_put_contents("$app/.env", $env);
    echo ".env created\n"; flush();

    // 4. Permissions
    echo "Setting permissions...\n"; flush();
    rchmod($app, 0644, 0755);
    rchmod("$app/storage", 0777, 0777);
    rchmod("$app/bootstrap/cache", 0777, 0777);

    // 5. Try artisan cache commands if exec() is available
    if ($execOk) {
        echo "Running artisan optimize...\n"; flush();
        exec("php $app/artisan config:cache 2>&1", $out); echo implode("\n", $out) . "\n"; $out = []; flush();
        exec("php $app/artisan route:cache 2>&1",  $out); echo implode("\n", $out) . "\n"; $out = []; flush();
        exec("php $app/artisan view:cache 2>&1",   $out); echo implode("\n", $out) . "\n"; $out = []; flush();
    } else {
        echo "exec() not available - skipping artisan cache (app will still work)\n"; flush();
    }

    // 6. Sync public_html (preserve directory, clear contents)
    echo "Setting up public_html...\n"; flush();
    foreach (array_diff(scandir($publicHtml), ['.', '..']) as $f) {
        $p = "$publicHtml/$f";
        is_dir($p) ? rrmdir($p) : unlink($p);
    }
    rcopy("$app/public", $publicHtml);

    // 7. Fix index.php __DIR__ paths to absolute
    echo "Fixing index.php...\n"; flush();
    $idx = file_get_contents("$publicHtml/index.php");
    $idx = str_replace("__DIR__.'/../storage",   "'{$app}/storage",   $idx);
    $idx = str_replace("__DIR__.'/../vendor",    "'{$app}/vendor",    $idx);
    $idx = str_replace("__DIR__.'/../bootstrap", "'{$app}/bootstrap", $idx);
    file_put_contents("$publicHtml/index.php", $idx);
    echo "index.php fixed\n"; flush();

    // Show result for verification
    echo "\n=== index.php content ===\n";
    echo file_get_contents("$publicHtml/index.php");
    echo "\n=== public_html files ===\n";
    echo implode("\n", scandir($publicHtml)) . "\n";

    // 8. Cleanup zip
    @unlink($zipFile);

    echo "\n=== Deployment complete! ===\n"; flush();

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    http_response_code(500);
}

// Self-delete
@unlink(__FILE__);

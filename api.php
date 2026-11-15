<?php
// Синхронизация приложения «Каперы». Хранит данные в data/data.json
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key = $_SERVER['HTTP_X_KEY'] ?? '';
if (!hash_equals(API_KEY, $key)) {
    http_response_code(401);
    echo '{"error":"bad key"}';
    exit;
}

$dir = __DIR__ . '/data';
if (!is_dir($dir)) mkdir($dir, 0755, true);
$file = $dir . '/data.json';

$fp = fopen($file, 'c+');
if (!$fp) { http_response_code(500); echo '{"error":"no write access"}'; exit; }
flock($fp, LOCK_EX);
$raw = stream_get_contents($fp);
$db = $raw ? json_decode($raw, true) : null;
if (!is_array($db)) $db = ['cappers' => [], 'bets' => [], 'pays' => [], 'settings' => null];
if (!isset($db['pays'])) $db['pays'] = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) { flock($fp, LOCK_UN); http_response_code(400); echo '{"error":"bad json"}'; exit; }
    $changed = false;
    foreach (['cappers', 'bets', 'pays'] as $k) {
        $map = [];
        foreach ($db[$k] as $it) $map[$it['id']] = $it;
        foreach (($in[$k] ?? []) as $it) {
            if (!is_array($it) || !isset($it['id'])) continue;
            $id = (string)$it['id'];
            if (!isset($map[$id]) || ($it['updatedAt'] ?? 0) > ($map[$id]['updatedAt'] ?? 0)) {
                $map[$id] = $it; $changed = true;
            }
        }
        $db[$k] = array_values($map);
    }
    if (!empty($in['settings']) && is_array($in['settings'])
        && (($in['settings']['updatedAt'] ?? 0) > ($db['settings']['updatedAt'] ?? 0))) {
        $db['settings'] = $in['settings']; $changed = true;
    }
    if ($changed) {
        // резервная копия раз в день (хранятся последние 30)
        $bk = $dir . '/backup-' . date('Y-m-d') . '.json';
        if ($raw && !file_exists($bk)) {
            file_put_contents($bk, $raw);
            $old = glob($dir . '/backup-*.json'); sort($old);
            while (count($old) > 30) unlink(array_shift($old));
        }
        $json = json_encode($db, JSON_UNESCAPED_UNICODE);
        ftruncate($fp, 0); rewind($fp); fwrite($fp, $json); fflush($fp);
    }
}
flock($fp, LOCK_UN); fclose($fp);
echo json_encode($db, JSON_UNESCAPED_UNICODE);

<?php
// ocr.php
require __DIR__ . '/config.php';
require __DIR__ . '/yandex_config.php'; // <--- Подключаем файл с ключами Яндекса

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Проверяем ключ доступа к нашему приложению
$key = $_SERVER['HTTP_X_KEY'] ?? '';
if (!hash_equals(API_KEY, $key)) {
    http_response_code(401);
    echo '{"error":"bad key"}';
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$imageBase64 = $input['image'] ?? '';

if (!$imageBase64) {
    http_response_code(400);
    echo '{"error":"no image"}';
    exit;
}

// Убираем префикс data:image/jpeg;base64,
if (strpos($imageBase64, 'base64,') !== false) {
    $imageBase64 = explode('base64,', $imageBase64)[1];
}

// Берем ключи из yandex_config.php
$folderId = YANDEX_FOLDER_ID;
$apiKey = YANDEX_API_KEY;

$url = 'https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze';

$body = [
    "folderId" => $folderId,
    "analyze_specs" => [[
        "content" => $imageBase64,
        "features" => [[
            "type" => "TEXT_DETECTION",
            "text_detection_config" => ["language_codes" => ["ru", "en"]]
        ]]
    ]]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Api-Key ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['error' => 'Curl error: ' . $err]);
    exit;
}

$resData = json_decode($response, true);

// Извлекаем весь распознанный текст
$text = '';
if (isset($resData['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'])) {
    foreach ($resData['results'][0]['results'][0]['textDetection']['pages'][0]['blocks'] as $block) {
        foreach ($block['lines'] as $line) {
            $text .= $line['text'] . "\n";
        }
    }
}

// Парсим текст под купон Winline
$data = ['stake' => null, 'coef' => null, 'note' => ''];

// Ищем сумму ставки (число перед стрелкой ->)
if (preg_match('/\b(\d+)\s*→\s*\d+/u', $text, $m)) {
    $data['stake'] = (float)$m[1];
}

// Ищем кэф (число с точкой в конце или после суммы)
if (preg_match('/\b\d+\.\d{2,3}\b/u', $text, $m)) {
    $data['coef'] = (float)$m[0];
} elseif (preg_match('/\b(\d+)\s*→\s*(\d+)\b/u', $text, $m)) {
    // Если кэф не распознался, считаем его как (выигрыш / ставку)
    $data['coef'] = round((float)$m[2] / (float)$m[1], 2);
}

// Ищем событие (обычно это строка перед "Поб", "Тотал", "Фора" и т.д.)
$lines = array_filter(explode("\n", $text));
$eventParts = [];
foreach ($lines as $i => $line) {
    if (preg_match('/(Поб|Тотал|Фора|Исход|Ничья)/iu', $line) && isset($lines[$i-1])) {
        $eventParts[] = trim($lines[$i-1]) . ' - ' . trim($line);
        break;
    }
}
if ($eventParts) {
    $data['note'] = implode(' ', $eventParts);
} elseif (count($lines) > 5) {
    // Фолбэк: берем самую длинную текстовую строку
    usort($lines, function($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    $data['note'] = mb_substr(trim($lines[0]), 0, 100);
}

echo json_encode(['ok' => true, 'data' => $data, 'raw_text' => $text]);
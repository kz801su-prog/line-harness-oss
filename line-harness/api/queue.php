<?php
// ============================================================
// LINE Harness  /  api/queue.php
// boss_auto_post.py の LineHarnessConnector から受信するエンドポイント
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Harness-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once dirname(__DIR__) . '/config.php';

// ── API認証 ──────────────────────────────────────────────────
$key = $_SERVER['HTTP_X_HARNESS_KEY'] ?? '';
if ($key !== HARNESS_API_KEY) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── JSON ボディを解析 ─────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['body_text'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'body_text は必須です'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── メディアタイプを判定 ─────────────────────────────────────
$media_url = $data['media_url'] ?? '';
$media_type = 'none';
if ($media_url) {
    $ext = strtolower(pathinfo(parse_url($media_url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4', 'mov', 'avi'], true)) {
        $media_type = 'video';
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $media_type = 'image';
    }
}

// ── MySQLに挿入 ──────────────────────────────────────────────
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                   DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $now = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $st  = $pdo->prepare(
        'INSERT INTO message_queue (body_text, media_url, media_type, generated_by, mode, queued_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $data['body_text'],
        $media_url ?: null,
        $media_type,
        $data['generated_by'] ?? null,
        $data['mode']         ?? null,
        $now,
    ]);

    echo json_encode([
        'status'    => 'success',
        'message'   => 'LINE Harnessの配信キューに正常に追加されました',
        'queued_at' => (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format(DateTime::ATOM),
        'queue_id'  => (int)$pdo->lastInsertId(),
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'DB エラー: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

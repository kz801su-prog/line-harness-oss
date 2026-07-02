<?php
// ============================================================
// LINE Harness  /  index.php  -  Admin Dashboard
// ============================================================
declare(strict_types=1);
mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';

// ── DB接続ヘルパー ───────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
                       DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ── 認証 ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pass'])) {
    if ($_POST['login_pass'] === ADMIN_PASSWORD) {
        $_SESSION['lh_auth'] = true;
    } else {
        $login_error = 'パスワードが違います';
    }
}
if (isset($_POST['logout'])) { session_destroy(); header('Location: ./'); exit; }
$auth = !empty($_SESSION['lh_auth']);

// ── AJAX API ─────────────────────────────────────────────────
if ($auth && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];

    // --- 統計情報 ---
    if ($api === 'stats') {
        try {
            $counts = db()->query(
                "SELECT status, COUNT(*) AS cnt FROM message_queue GROUP BY status"
            )->fetchAll();
            $stat = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0];
            foreach ($counts as $r) { $stat[$r['status']] = (int)$r['cnt']; }

            $today = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            $today_sent = (int)db()->query(
                "SELECT COUNT(*) FROM message_queue WHERE status='sent' AND DATE(sent_at)='$today'"
            )->fetchColumn();

            // LINE Bot情報（設定確認用）
            $line_ok = (LINE_TOKEN !== 'YOUR_LINE_CHANNEL_ACCESS_TOKEN');

            echo json_encode([
                'ok'         => true,
                'counts'     => $stat,
                'today_sent' => $today_sent,
                'line_ready' => $line_ok,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- キュー一覧 ---
    if ($api === 'queue') {
        $status = $_GET['status'] ?? 'all';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        $where  = ($status !== 'all') ? "WHERE status='" . $status . "'" : '';
        try {
            $rows = db()->query(
                "SELECT id, status, mode, body_text, media_type, media_url, generated_by,
                        queued_at, sent_at, retry_count, error_msg
                 FROM message_queue $where
                 ORDER BY id DESC LIMIT $limit OFFSET $offset"
            )->fetchAll();
            $total = (int)db()->query(
                "SELECT COUNT(*) FROM message_queue $where"
            )->fetchColumn();
            echo json_encode(['ok' => true, 'data' => $rows, 'total' => $total, 'page' => $page], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- キャンセル ---
    if ($api === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM message_queue WHERE id=? AND status='pending'")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => "ID:{$id} をキャンセルしました"], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- 失敗メッセージを再キュー ---
    if ($api === 'retry_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $n = db()->exec("UPDATE message_queue SET status='pending', retry_count=0, error_msg=NULL WHERE status='failed'");
            echo json_encode(['ok' => true, 'message' => "{$n}件を再キューしました"], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- ワーカー手動実行 ---
    if ($api === 'run_worker') {
        $url = rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
               . dirname($_SERVER['PHP_SELF']), '/') . '/worker/process.php?worker_key=' . HARNESS_API_KEY;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode($body, true);
        echo json_encode([
            'ok'      => ($code === 200),
            'code'    => $code,
            'result'  => $decoded ?? $body,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- 時間帯別 送信数（今日） ---
    if ($api === 'hourly') {
        try {
            $today = (new DateTime('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
            $rows  = db()->query(
                "SELECT HOUR(sent_at) AS h, COUNT(*) AS cnt
                 FROM message_queue
                 WHERE status='sent' AND DATE(sent_at)='$today'
                 GROUP BY h ORDER BY h"
            )->fetchAll();
            $hours = array_fill(0, 24, 0);
            foreach ($rows as $r) { $hours[(int)$r['h']] = (int)$r['cnt']; }
            echo json_encode(['ok' => true, 'data' => $hours], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'data' => array_fill(0, 24, 0)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'message' => '不明なAPI'], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>LINE Harness Dashboard | BOSS System</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<style>
/* ── Variables ── */
:root {
  --bg:       #080d16;
  --surface:  #0f1923;
  --surface2: #172032;
  --surface3: #1e2d40;
  --border:   #1e3048;
  --text:     #e2e8f0;
  --text2:    #7c93ad;
  --text3:    #4a6582;
  --accent:   #3b82f6;
  --line:     #06c755;
  --success:  #10b981;
  --warning:  #f59e0b;
  --danger:   #ef4444;
  --purple:   #8b5cf6;
  --gold:     #f59e0b;
}

/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Segoe UI', 'Hiragino Kaku Gothic Pro', 'Yu Gothic', sans-serif;
  font-size: 0.9rem;
  min-height: 100vh;
}

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

/* ── Navbar ── */
.nav-top {
  background: linear-gradient(90deg, #0a1628 0%, #0f1e35 100%);
  border-bottom: 1px solid var(--border);
  padding: 0 1.5rem;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
  backdrop-filter: blur(8px);
}
.nav-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  color: var(--text);
}
.brand-icon {
  width: 34px; height: 34px;
  background: var(--line);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.1rem; font-weight: 900; color: #fff;
}
.brand-name { font-size: 1.05rem; font-weight: 700; letter-spacing: .02em; }
.brand-sub  { font-size: .7rem; color: var(--text2); margin-top: -2px; }

.nav-links { display: flex; gap: 4px; }
.nav-link-btn {
  background: transparent;
  border: none;
  color: var(--text2);
  padding: 6px 14px;
  border-radius: 6px;
  cursor: pointer;
  font-size: .85rem;
  text-decoration: none;
  display: flex; align-items: center; gap: 5px;
  transition: .15s;
}
.nav-link-btn:hover, .nav-link-btn.active {
  background: var(--surface3);
  color: var(--text);
}
.nav-right { display: flex; align-items: center; gap: 12px; }
.status-pill {
  font-size: .75rem;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1px solid;
  display: flex; align-items: center; gap: 5px;
}
.status-pill .dot { width: 7px; height: 7px; border-radius: 50%; }
.pill-ok     { border-color: var(--line); color: var(--line); }
.pill-ok .dot { background: var(--line); box-shadow: 0 0 6px var(--line); animation: pulse 2s infinite; }
.pill-warn   { border-color: var(--warning); color: var(--warning); }
.pill-warn .dot { background: var(--warning); }

@keyframes pulse { 0%,100%{ opacity:1 } 50%{ opacity:.4 } }

/* ── Content ── */
.content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }

/* ── Stat Cards ── */
.stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media(max-width:900px){ .stat-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:500px){ .stat-grid { grid-template-columns: 1fr; } }

.stat-card {
  background: var(--surface);
  border-radius: 12px;
  padding: 1.2rem 1.4rem;
  border: 1px solid var(--border);
  position: relative;
  overflow: hidden;
  transition: transform .15s, box-shadow .15s;
  cursor: default;
}
.stat-card::before {
  content: '';
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 4px;
  border-radius: 12px 0 0 12px;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.4); }
.stat-pending::before  { background: var(--warning); }
.stat-running::before  { background: var(--accent); }
.stat-sent::before     { background: var(--line); }
.stat-failed::before   { background: var(--danger); }

.stat-label {
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text2);
  margin-bottom: .5rem;
  display: flex; align-items: center; gap: 6px;
}
.stat-num {
  font-size: 2.2rem;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.stat-pending .stat-num  { color: var(--warning); }
.stat-running .stat-num  { color: var(--accent); }
.stat-sent .stat-num     { color: var(--line); }
.stat-failed .stat-num   { color: var(--danger); }

.stat-sub {
  font-size: .72rem; color: var(--text3); margin-top: .4rem;
}

/* ── Main Grid ── */
.main-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 1rem;
}
@media(max-width:1100px){ .main-grid { grid-template-columns: 1fr; } }

/* ── Cards ── */
.card-box {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.card-head {
  background: var(--surface2);
  padding: .75rem 1.1rem;
  display: flex; align-items: center; justify-content: space-between;
  border-bottom: 1px solid var(--border);
  font-size: .85rem; font-weight: 600;
}
.card-head-title { display: flex; align-items: center; gap: 8px; color: var(--text); }
.card-body-pad { padding: 1rem 1.1rem; }

/* ── Tab Filter ── */
.tab-filters { display: flex; gap: 4px; }
.tab-btn {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text2);
  padding: 4px 12px;
  border-radius: 20px;
  cursor: pointer;
  font-size: .75rem;
  transition: .15s;
}
.tab-btn:hover { border-color: var(--accent); color: var(--accent); }
.tab-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }

/* ── Queue Table ── */
.queue-table { width: 100%; border-collapse: collapse; }
.queue-table th {
  background: var(--surface3);
  color: var(--text2);
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .06em;
  padding: .6rem 1rem;
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--border);
}
.queue-table td {
  padding: .7rem 1rem;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  font-size: .83rem;
}
.queue-table tr:last-child td { border-bottom: none; }
.queue-table tr:hover td { background: var(--surface2); }

/* ── Status Badge ── */
.badge-status {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 9px;
  border-radius: 20px;
  font-size: .7rem; font-weight: 600;
  white-space: nowrap;
}
.bs-pending    { background: rgba(245,158,11,.15); color: #f59e0b; border: 1px solid rgba(245,158,11,.3); }
.bs-processing { background: rgba(59,130,246,.15);  color: #3b82f6; border: 1px solid rgba(59,130,246,.3); }
.bs-sent       { background: rgba(6,199,85,.15);    color: #06c755; border: 1px solid rgba(6,199,85,.3); }
.bs-failed     { background: rgba(239,68,68,.15);   color: #ef4444; border: 1px solid rgba(239,68,68,.3); }

/* ── Preview Text ── */
.preview-text {
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--text2);
  font-size: .8rem;
}

/* ── Media Icon ── */
.media-icon { font-size: 1rem; }

/* ── Mode Badge ── */
.mode-badge {
  font-size: .68rem;
  padding: 2px 7px;
  border-radius: 4px;
  background: var(--surface3);
  color: var(--text2);
  font-weight: 500;
}
.mode-shopify { background: rgba(149,99,255,.15); color: #a78bfa; }
.mode-reward  { background: rgba(245,158,11,.15); color: #fbbf24; }

/* ── Rel Time ── */
.rel-time { font-size: .72rem; color: var(--text3); white-space: nowrap; }

/* ── Action Btn ── */
.btn-icon {
  background: transparent; border: 1px solid var(--border);
  color: var(--text2); padding: 3px 8px; border-radius: 6px;
  cursor: pointer; font-size: .75rem; transition: .15s;
}
.btn-icon:hover { border-color: var(--danger); color: var(--danger); }

/* ── Side Panel ── */
.side-stack { display: flex; flex-direction: column; gap: 1rem; }

/* ── Worker Panel ── */
.worker-btn {
  width: 100%;
  background: linear-gradient(135deg, #06c755 0%, #03a040 100%);
  border: none;
  color: #fff;
  padding: .75rem;
  border-radius: 8px;
  font-size: .9rem;
  font-weight: 700;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: opacity .15s, transform .1s;
  letter-spacing: .03em;
}
.worker-btn:hover  { opacity: .9; }
.worker-btn:active { transform: scale(.98); }
.worker-btn:disabled { opacity: .5; cursor: not-allowed; }

.retry-btn {
  width: 100%; background: transparent;
  border: 1px solid var(--danger); color: var(--danger);
  padding: .6rem; border-radius: 8px;
  font-size: .82rem; cursor: pointer; transition: .15s;
}
.retry-btn:hover { background: rgba(239,68,68,.1); }

/* ── Chart ── */
.chart-wrap { padding: .75rem 1rem 1rem; }
.chart-canvas { max-height: 140px; }

/* ── LINE Info ── */
.info-row { display: flex; align-items: center; justify-content: space-between; padding: .5rem 0; border-bottom: 1px solid var(--border); }
.info-row:last-child { border-bottom: none; }
.info-label { font-size: .75rem; color: var(--text2); }
.info-val   { font-size: .8rem; font-weight: 600; }

/* ── Pagination ── */
.pagination-wrap { display: flex; justify-content: center; gap: 4px; padding: .75rem 0; }
.page-btn {
  background: var(--surface3); border: 1px solid var(--border);
  color: var(--text2); padding: 4px 12px; border-radius: 6px;
  cursor: pointer; font-size: .78rem; transition: .15s;
}
.page-btn:hover, .page-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
.page-btn:disabled { opacity: .3; cursor: not-allowed; }

/* ── Toast ── */
.toast-wrap { position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.toast-item {
  background: var(--surface2); border: 1px solid var(--border);
  border-radius: 10px; padding: .8rem 1.2rem;
  font-size: .83rem; min-width: 260px; max-width: 380px;
  display: flex; align-items: center; gap: 10px;
  box-shadow: 0 8px 24px rgba(0,0,0,.5);
  animation: slideIn .25s ease;
}
.toast-ok   { border-left: 3px solid var(--success); }
.toast-err  { border-left: 3px solid var(--danger); }
.toast-info { border-left: 3px solid var(--accent); }
@keyframes slideIn { from{ opacity:0; transform:translateX(20px) } to{ opacity:1; transform:none } }

/* ── Login Screen ── */
.login-screen {
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  background: radial-gradient(ellipse at top, #0a1628 0%, #080d16 60%);
}
.login-box {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 2.5rem 2rem;
  width: 360px;
  box-shadow: 0 24px 64px rgba(0,0,0,.6);
}
.login-logo {
  width: 56px; height: 56px;
  background: var(--line);
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem; font-weight: 900; color: #fff;
  margin: 0 auto 1rem;
  box-shadow: 0 0 24px rgba(6,199,85,.4);
}
.login-title { text-align: center; font-size: 1.2rem; font-weight: 700; margin-bottom: .25rem; }
.login-sub   { text-align: center; font-size: .78rem; color: var(--text2); margin-bottom: 1.5rem; }
.field-label { font-size: .78rem; color: var(--text2); margin-bottom: 5px; display: block; }
.field-input {
  width: 100%; background: var(--bg); border: 1px solid var(--border);
  color: var(--text); border-radius: 8px; padding: .65rem .9rem;
  font-size: .9rem; outline: none; transition: border-color .15s;
}
.field-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
.login-btn {
  width: 100%; padding: .75rem;
  background: linear-gradient(135deg, #3b82f6, #6d28d9);
  border: none; border-radius: 8px; color: #fff;
  font-size: .9rem; font-weight: 700; cursor: pointer;
  margin-top: 1rem; transition: opacity .15s;
}
.login-btn:hover { opacity: .9; }
.alert-err { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: #f87171; border-radius: 8px; padding: .6rem .9rem; font-size: .82rem; margin-bottom: .75rem; }

/* ── Spinner ── */
.spinner { animation: spin 1s linear infinite; }
@keyframes spin { to{ transform: rotate(360deg) } }

/* ── Empty State ── */
.empty-state { text-align: center; padding: 3rem 1rem; color: var(--text3); }
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; }
</style>
</head>
<body>

<?php if (!$auth): ?>
<!-- ═══════════════════════ ログイン ════════════════════════ -->
<div class="login-screen">
  <div class="login-box">
    <div class="login-logo">L</div>
    <p class="login-title">LINE Harness</p>
    <p class="login-sub">BOSS System 配信管理ダッシュボード</p>
    <?php if (!empty($login_error)): ?>
      <div class="alert-err"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <label class="field-label" for="pw">管理パスワード</label>
      <input type="password" id="pw" name="login_pass" class="field-input" autofocus>
      <button type="submit" class="login-btn"><i class="bi bi-box-arrow-in-right me-1"></i>ログイン</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════ ダッシュボード ════════════════════ -->

<!-- Navbar -->
<nav class="nav-top">
  <a href="./" class="nav-brand" style="text-decoration:none">
    <div class="brand-icon">L</div>
    <div>
      <div class="brand-name">LINE Harness</div>
      <div class="brand-sub">BOSS System</div>
    </div>
  </a>

  <div class="nav-links">
    <span class="nav-link-btn active"><i class="bi bi-grid-1x2"></i> ダッシュボード</span>
    <a href="./keyword_settings.php" class="nav-link-btn"><i class="bi bi-tags"></i> キーワード設定</a>
  </div>

  <div class="nav-right">
    <div class="status-pill pill-ok" id="line-status" title="LINE API接続状態">
      <div class="dot"></div>
      <span>LINE API</span>
    </div>
    <small id="last-update" style="color:var(--text3);font-size:.72rem">更新中…</small>
    <form method="post" style="margin:0">
      <button name="logout" class="btn-icon" style="padding:5px 12px">
        <i class="bi bi-box-arrow-right"></i>
      </button>
    </form>
  </div>
</nav>

<!-- Main Content -->
<div class="content">

  <!-- Stat Cards -->
  <div class="stat-grid">
    <div class="stat-card stat-pending">
      <div class="stat-label"><i class="bi bi-hourglass-split"></i> 待機中</div>
      <div class="stat-num" id="cnt-pending">─</div>
      <div class="stat-sub">送信キュー</div>
    </div>
    <div class="stat-card stat-running">
      <div class="stat-label"><i class="bi bi-arrow-repeat"></i> 処理中</div>
      <div class="stat-num" id="cnt-processing">─</div>
      <div class="stat-sub">LINE API 送信中</div>
    </div>
    <div class="stat-card stat-sent">
      <div class="stat-label"><i class="bi bi-check2-circle"></i> 送信済み</div>
      <div class="stat-num" id="cnt-sent">─</div>
      <div class="stat-sub">今日: <span id="cnt-today">─</span> 件</div>
    </div>
    <div class="stat-card stat-failed">
      <div class="stat-label"><i class="bi bi-x-circle"></i> 失敗</div>
      <div class="stat-num" id="cnt-failed">─</div>
      <div class="stat-sub">リトライ待ち</div>
    </div>
  </div>

  <!-- Main Grid -->
  <div class="main-grid">

    <!-- Left: Queue Table -->
    <div class="card-box">
      <div class="card-head">
        <div class="card-head-title">
          <i class="bi bi-list-task" style="color:var(--accent)"></i>
          メッセージキュー
        </div>
        <div class="tab-filters">
          <button class="tab-btn active" onclick="setFilter('all',this)">すべて</button>
          <button class="tab-btn" onclick="setFilter('pending',this)">待機</button>
          <button class="tab-btn" onclick="setFilter('sent',this)">送信済</button>
          <button class="tab-btn" onclick="setFilter('failed',this)">失敗</button>
        </div>
      </div>
      <div id="queue-wrap" style="overflow-x:auto">
        <div class="empty-state"><i class="bi bi-hourglass spinner"></i>読み込み中…</div>
      </div>
      <div class="pagination-wrap" id="pagination"></div>
    </div>

    <!-- Right: Side Panel -->
    <div class="side-stack">

      <!-- Worker Control -->
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-title"><i class="bi bi-lightning-charge-fill" style="color:var(--line)"></i> ワーカー制御</div>
        </div>
        <div class="card-body-pad">
          <button class="worker-btn" id="worker-btn" onclick="runWorker()">
            <i class="bi bi-send-fill"></i> LINE へ今すぐ送信
          </button>
          <div id="worker-result" style="margin-top:.75rem;font-size:.78rem;color:var(--text2);text-align:center"></div>
          <hr style="border-color:var(--border);margin:.75rem 0">
          <button class="retry-btn" onclick="retryAll()">
            <i class="bi bi-arrow-clockwise me-1"></i> 失敗メッセージを再キュー
          </button>
          <div style="margin-top:.75rem;font-size:.72rem;color:var(--text3);line-height:1.6">
            <i class="bi bi-info-circle me-1"></i>
            XServer cron: <code style="color:var(--accent);font-size:.7rem">*/5 * * * * php …/worker/process.php</code>
          </div>
        </div>
      </div>

      <!-- Today's Chart -->
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-title"><i class="bi bi-bar-chart-fill" style="color:var(--purple)"></i> 本日の送信状況</div>
        </div>
        <div class="chart-wrap">
          <canvas id="hourly-chart" class="chart-canvas"></canvas>
        </div>
      </div>

      <!-- LINE API Info -->
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-title"><i class="bi bi-gear-fill" style="color:var(--gold)"></i> LINE API 設定状態</div>
        </div>
        <div class="card-body-pad" style="padding:.5rem 1.1rem">
          <div class="info-row">
            <span class="info-label">API Token</span>
            <span class="info-val" id="info-token">確認中…</span>
          </div>
          <div class="info-row">
            <span class="info-label">Broadcast URL</span>
            <span class="info-val" style="font-size:.7rem;color:var(--text3)">api.line.me/…/broadcast</span>
          </div>
          <div class="info-row">
            <span class="info-label">Worker Batch</span>
            <span class="info-val"><?= WORKER_BATCH ?> 件/回</span>
          </div>
          <div class="info-row">
            <span class="info-label">Max Retry</span>
            <span class="info-val"><?= MAX_RETRY ?> 回</span>
          </div>
        </div>
      </div>

    </div><!-- /side-stack -->
  </div><!-- /main-grid -->
</div><!-- /content -->

<!-- Toast Container -->
<div class="toast-wrap" id="toast-wrap"></div>

<script>
'use strict';

let currentFilter = 'all';
let currentPage   = 1;
let hourlyChart   = null;

// ── UTC → JST 変換して相対表示 ──────────────────────────────────
function relTime(dtStr) {
  if (!dtStr) return '─';
  const d = new Date(dtStr.replace(' ', 'T') + '+09:00');
  const s = (Date.now() - d.getTime()) / 1000;
  if (s < 0)    return 'たった今';
  if (s < 60)   return Math.floor(s) + '秒前';
  if (s < 3600) return Math.floor(s/60) + '分前';
  if (s < 86400)return Math.floor(s/3600) + '時間前';
  return Math.floor(s/86400) + '日前';
}

// ── 安全なHTML エスケープ ────────────────────────────────────────
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── 統計を取得・更新 ─────────────────────────────────────────────
async function refreshStats() {
  try {
    const r = await fetch('./?api=stats');
    const d = await r.json();
    if (!d.ok) return;
    animateNum('cnt-pending',    d.counts.pending    ?? 0);
    animateNum('cnt-processing', d.counts.processing ?? 0);
    animateNum('cnt-sent',       d.counts.sent       ?? 0);
    animateNum('cnt-failed',     d.counts.failed     ?? 0);
    document.getElementById('cnt-today').textContent = d.today_sent ?? 0;

    // LINE API ステータス表示
    const lineStatus = document.getElementById('line-status');
    const infoToken  = document.getElementById('info-token');
    if (d.line_ready) {
      lineStatus.className = 'status-pill pill-ok';
      lineStatus.innerHTML = '<div class="dot"></div><span>LINE API</span>';
      infoToken.innerHTML  = '<span style="color:var(--line)"><i class="bi bi-check-circle-fill me-1"></i>設定済</span>';
    } else {
      lineStatus.className = 'status-pill pill-warn';
      lineStatus.innerHTML = '<div class="dot"></div><span>未設定</span>';
      infoToken.innerHTML  = '<span style="color:var(--warning)"><i class="bi bi-exclamation-triangle me-1"></i>要設定</span>';
    }

    const now = new Date().toLocaleTimeString('ja-JP', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    document.getElementById('last-update').textContent = now + ' 更新';
  } catch(e) { /* silent */ }
}

// ── 数値アニメーション ────────────────────────────────────────────
function animateNum(id, target) {
  const el = document.getElementById(id);
  const start = parseInt(el.textContent) || 0;
  if (start === target) return;
  const step  = Math.ceil(Math.abs(target - start) / 12);
  let   cur   = start;
  const t = setInterval(() => {
    cur += (cur < target ? step : -step);
    if ((step > 0 && cur >= target) || (step < 0 && cur <= target)) { cur = target; clearInterval(t); }
    el.textContent = cur;
  }, 40);
}

// ── キューリスト取得・描画 ────────────────────────────────────────
async function refreshQueue(filter, page) {
  if (filter !== undefined) currentFilter = filter;
  if (page   !== undefined) currentPage   = page;
  const wrap = document.getElementById('queue-wrap');
  try {
    const r = await fetch(`./?api=queue&status=${currentFilter}&page=${currentPage}`);
    const d = await r.json();
    if (!d.ok || !d.data.length) {
      wrap.innerHTML = `<div class="empty-state"><i class="bi bi-inbox"></i>メッセージがありません</div>`;
      document.getElementById('pagination').innerHTML = '';
      return;
    }
    let html = `<table class="queue-table">
      <thead><tr>
        <th>#</th><th>ステータス</th><th>モード</th><th>プレビュー</th>
        <th>メディア</th><th>時刻</th><th></th>
      </tr></thead><tbody>`;

    for (const row of d.data) {
      const preview  = (row.body_text ?? '').replace(/\n/g,' ').substring(0,60);
      const mediaIco = row.media_type === 'video' ? '🎬' : row.media_type === 'image' ? '🖼️' : '─';
      const modeClass = row.mode === 'shopify' ? 'mode-shopify' : row.mode === 'reward' ? 'mode-reward' : '';
      const timeStr   = relTime(row.sent_at || row.queued_at);
      const errTip    = row.error_msg ? ` title="${esc(row.error_msg)}"` : '';

      html += `<tr>
        <td style="color:var(--text3)">${row.id}</td>
        <td><span class="badge-status bs-${esc(row.status)}"${errTip}>${statusIcon(row.status)} ${esc(row.status)}</span></td>
        <td><span class="mode-badge ${modeClass}">${esc(row.mode ?? '─')}</span></td>
        <td><div class="preview-text" title="${esc(preview)}">${esc(preview)}</div></td>
        <td class="media-icon">${mediaIco}</td>
        <td><span class="rel-time">${timeStr}</span></td>
        <td>${row.status === 'pending'
          ? `<button class="btn-icon" onclick="cancelMsg(${row.id})" title="キャンセル"><i class="bi bi-x"></i></button>`
          : ''}</td>
      </tr>`;
    }
    html += '</tbody></table>';
    wrap.innerHTML = html;
    renderPager(d.total, 20);
  } catch(e) {
    wrap.innerHTML = `<div class="empty-state"><i class="bi bi-wifi-off"></i>読み込みエラー</div>`;
  }
}

function statusIcon(s) {
  return {pending:'⏳',processing:'▶',sent:'✅',failed:'❌'}[s] ?? '';
}

// ── タブフィルター切替 ────────────────────────────────────────────
function setFilter(f, btn) {
  currentFilter = f; currentPage = 1;
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  refreshQueue();
}

// ── ページネーション ─────────────────────────────────────────────
function renderPager(total, limit) {
  const pages = Math.ceil(total / limit);
  if (pages <= 1) { document.getElementById('pagination').innerHTML = ''; return; }
  let html = '';
  for (let i = 1; i <= Math.min(pages, 10); i++) {
    html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="goPage(${i})">${i}</button>`;
  }
  document.getElementById('pagination').innerHTML = html;
}

function goPage(p) { currentPage = p; refreshQueue(); }

// ── メッセージキャンセル ─────────────────────────────────────────
async function cancelMsg(id) {
  if (!confirm(`ID:${id} を削除しますか？`)) return;
  const fd = new FormData(); fd.append('id', id);
  const r = await fetch('./?api=cancel', {method:'POST', body:fd});
  const d = await r.json();
  toast(d.message, d.ok ? 'ok' : 'err');
  if (d.ok) { refreshStats(); refreshQueue(); }
}

// ── 失敗メッセージ再キュー ────────────────────────────────────────
async function retryAll() {
  const r = await fetch('./?api=retry_all', {method:'POST'});
  const d = await r.json();
  toast(d.message, d.ok ? 'ok' : 'err');
  if (d.ok) { refreshStats(); refreshQueue(); }
}

// ── ワーカー手動実行 ─────────────────────────────────────────────
async function runWorker() {
  const btn = document.getElementById('worker-btn');
  const res = document.getElementById('worker-result');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-arrow-repeat spinner me-1"></i> 処理中…';
  res.innerHTML = '';
  try {
    const r = await fetch('./?api=run_worker');
    const d = await r.json();
    const result = d.result ?? {};
    if (d.ok) {
      const sent   = result.sent   ?? 0;
      const failed = result.failed ?? 0;
      res.innerHTML = `<span style="color:var(--line)"><i class="bi bi-check-circle me-1"></i>送信: ${sent}件</span>
        ${failed ? `<span style="color:var(--danger);margin-left:8px"><i class="bi bi-x-circle me-1"></i>失敗: ${failed}件</span>` : ''}`;
      toast(`${sent}件送信完了`, 'ok');
    } else {
      res.innerHTML = `<span style="color:var(--danger)">エラー (HTTP ${d.code})</span>`;
      toast('送信失敗', 'err');
    }
  } catch(e) {
    res.innerHTML = `<span style="color:var(--danger)">接続エラー</span>`;
    toast('ワーカー接続失敗', 'err');
  }
  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-send-fill"></i> LINE へ今すぐ送信';
  refreshStats();
  refreshQueue();
}

// ── 時間帯別チャート ─────────────────────────────────────────────
async function refreshChart() {
  const r = await fetch('./?api=hourly');
  const d = await r.json();
  const data = d.data ?? new Array(24).fill(0);
  const labels = Array.from({length:24}, (_,i) => i + '時');

  if (hourlyChart) { hourlyChart.destroy(); }
  const ctx = document.getElementById('hourly-chart').getContext('2d');
  hourlyChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data,
        backgroundColor: 'rgba(6,199,85,.35)',
        borderColor:     'rgba(6,199,85,.8)',
        borderWidth: 1,
        borderRadius: 3,
        hoverBackgroundColor: 'rgba(6,199,85,.6)',
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false }, tooltip: {
        callbacks: { label: ctx => ` ${ctx.parsed.y}件` }
      }},
      scales: {
        x: { grid: { color:'rgba(255,255,255,.04)' }, ticks: { color:'#4a6582', font:{size:9}, maxRotation:0 } },
        y: { grid: { color:'rgba(255,255,255,.04)' }, ticks: { color:'#4a6582', font:{size:9}, precision:0 }, beginAtZero:true },
      },
      animation: { duration: 600 },
    }
  });
}

// ── Toast 通知 ──────────────────────────────────────────────────
function toast(msg, type='info') {
  const wrap = document.getElementById('toast-wrap');
  const icons = { ok:'bi-check-circle-fill', err:'bi-x-circle-fill', info:'bi-info-circle-fill' };
  const colors = { ok:'var(--success)', err:'var(--danger)', info:'var(--accent)' };
  const el = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `<i class="bi ${icons[type]}" style="color:${colors[type]};font-size:1.1rem;flex-shrink:0"></i>
                  <span>${esc(msg)}</span>`;
  wrap.appendChild(el);
  setTimeout(() => el.style.cssText += 'opacity:0;transition:.3s;transform:translateX(20px)', 3500);
  setTimeout(() => el.remove(), 3900);
}

// ── 初期化と自動更新 ────────────────────────────────────────────
async function init() {
  await Promise.all([refreshStats(), refreshQueue(), refreshChart()]);
}

init();
setInterval(() => { refreshStats(); refreshQueue(); }, 15000);  // 15秒ごとに自動更新
setInterval(refreshChart, 60000);                                // 1分ごとにチャート更新
</script>

<?php endif; ?>
</body>
</html>

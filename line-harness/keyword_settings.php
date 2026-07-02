<?php
// ============================================================
// LINE Harness  /  keyword_settings.php
// キーワード → アセットパターン管理画面
// ============================================================
declare(strict_types=1);
mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';

// ── DB接続 ────────────────────────────────────────────────────
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

// ── 認証 ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pass'])) {
    if ($_POST['login_pass'] === ADMIN_PASSWORD) {
        $_SESSION['lh_auth'] = true;
    } else {
        $login_error = 'パスワードが違います';
    }
}
if (isset($_POST['logout'])) { session_destroy(); header('Location: ./keyword_settings.php'); exit; }
$auth = !empty($_SESSION['lh_auth']);

// ── AJAX API ──────────────────────────────────────────────────
if ($auth && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];

    // --- 一覧取得 ---
    if ($api === 'list') {
        try {
            $rows = db()->query(
                "SELECT id, keyword, asset_url, asset_type, ng_words, allowed_domains, is_active, created_at
                 FROM keyword_patterns ORDER BY keyword ASC"
            )->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- 保存（新規 or 更新） ---
    if ($api === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        $id       = isset($raw['id']) && $raw['id'] ? (int)$raw['id'] : null;
        $keyword  = trim($raw['keyword']        ?? '');
        $asset    = trim($raw['asset_url']      ?? '');
        $type     = trim($raw['asset_type']     ?? 'image');
        $ng       = trim($raw['ng_words']       ?? '');
        $domains  = trim($raw['allowed_domains']?? '');
        $active   = isset($raw['is_active']) ? (int)(bool)$raw['is_active'] : 1;

        if (!$keyword) {
            echo json_encode(['ok' => false, 'message' => 'キーワードは必須です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            if ($id) {
                db()->prepare(
                    "UPDATE keyword_patterns SET keyword=?, asset_url=?, asset_type=?, ng_words=?, allowed_domains=?, is_active=? WHERE id=?"
                )->execute([$keyword, $asset ?: null, $type, $ng ?: null, $domains ?: null, $active, $id]);
                echo json_encode(['ok' => true, 'message' => "「{$keyword}」を更新しました", 'id' => $id], JSON_UNESCAPED_UNICODE);
            } else {
                db()->prepare(
                    "INSERT INTO keyword_patterns (keyword, asset_url, asset_type, ng_words, allowed_domains, is_active) VALUES (?,?,?,?,?,?)"
                )->execute([$keyword, $asset ?: null, $type, $ng ?: null, $domains ?: null, $active]);
                $newId = (int)db()->lastInsertId();
                echo json_encode(['ok' => true, 'message' => "「{$keyword}」を登録しました", 'id' => $newId], JSON_UNESCAPED_UNICODE);
            }
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- 削除 ---
    if ($api === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)(json_decode(file_get_contents('php://input'), true)['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM keyword_patterns WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => "ID:{$id} を削除しました"], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- テキストからキーワード自動抽出 ---
    if ($api === 'extract' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw  = json_decode(file_get_contents('php://input'), true) ?? [];
        $text = $raw['text'] ?? '';
        // 日本語CJK2〜6文字のフレーズを抽出
        preg_match_all(
            '/(?:[一-龯ぁ-んァ-ヶーａ-ｚＡ-Ｚ！-～\w]{2,6})/u',
            $text, $m
        );
        // ストップワード除去・重複除去
        $stop = ['です','ます','した','して','する','ある','いる','ない','れる','られ','から','ため','もの','こと','それ','この','その','あの','さん','くん','ちゃん','として','について','という','ような','よう','など'];
        $candidates = array_values(array_unique(array_filter($m[0], fn($w) =>
            !in_array($w, $stop, true) && mb_strlen($w) >= 2
        )));
        // 既存パターンと照合
        try {
            $existing = db()->query("SELECT keyword FROM keyword_patterns")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $existing = [];
        }
        $result = array_map(fn($w) => [
            'word'    => $w,
            'exists'  => in_array($w, $existing, true),
        ], array_slice($candidates, 0, 30));
        echo json_encode(['ok' => true, 'candidates' => $result], JSON_UNESCAPED_UNICODE);
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
<title>キーワード設定 | LINE Harness</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
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
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Segoe UI','Hiragino Kaku Gothic Pro','Yu Gothic',sans-serif;
  font-size: .9rem; min-height: 100vh;
}
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

/* Navbar */
.nav-top {
  background: linear-gradient(90deg,#0a1628,#0f1e35);
  border-bottom: 1px solid var(--border);
  padding: 0 1.5rem; height: 56px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 100;
}
.nav-brand { display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text); }
.brand-icon { width:34px;height:34px;background:var(--line);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:900;color:#fff; }
.brand-name { font-size:1.05rem;font-weight:700; }
.brand-sub  { font-size:.7rem;color:var(--text2);margin-top:-2px; }
.nav-links { display:flex;gap:4px; }
.nav-link-btn { background:transparent;border:none;color:var(--text2);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:.85rem;text-decoration:none;display:flex;align-items:center;gap:5px;transition:.15s; }
.nav-link-btn:hover,.nav-link-btn.active { background:var(--surface3);color:var(--text); }
.nav-right { display:flex;align-items:center;gap:10px; }

/* Content */
.content { padding: 1.5rem; max-width: 1400px; margin: 0 auto; }
.page-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 1.25rem; display:flex;align-items:center;gap:10px; }
.page-title i { color: var(--purple); }

/* Layout Grid */
.layout-grid { display: grid; grid-template-columns: 1fr 420px; gap: 1.25rem; align-items: start; }
@media(max-width:1100px){ .layout-grid { grid-template-columns: 1fr; } }

/* Card */
.card-box { background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden; }
.card-head { background:var(--surface2);padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);font-size:.85rem;font-weight:600; }
.card-head-title { display:flex;align-items:center;gap:8px;color:var(--text); }
.card-body-pad { padding: 1.1rem; }

/* Table */
.kw-table { width:100%;border-collapse:collapse; }
.kw-table th { background:var(--surface3);color:var(--text2);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;padding:.6rem 1rem;text-align:left;white-space:nowrap;border-bottom:1px solid var(--border); }
.kw-table td { padding:.7rem 1rem;border-bottom:1px solid var(--border);vertical-align:middle;font-size:.83rem; }
.kw-table tr:last-child td { border-bottom:none; }
.kw-table tr:hover td { background:var(--surface2); }

/* Chips */
.chip-wrap { display:flex;flex-wrap:wrap;gap:6px;margin-top:.5rem; }
.chip {
  display:inline-flex;align-items:center;gap:5px;
  padding:4px 10px;border-radius:20px;font-size:.75rem;font-weight:600;
  cursor:pointer;transition:.15s;user-select:none;
}
.chip-new     { background:rgba(59,130,246,.15);border:1px solid rgba(59,130,246,.35);color:#60a5fa; }
.chip-new:hover { background:rgba(59,130,246,.3); }
.chip-exists  { background:rgba(6,199,85,.12);border:1px solid rgba(6,199,85,.3);color:#06c755; }
.chip-selected { background:var(--accent);border-color:var(--accent);color:#fff; }

/* Form */
.form-field { margin-bottom:.9rem; }
.field-label { font-size:.78rem;color:var(--text2);margin-bottom:5px;display:block; }
.field-input,.field-select,.field-textarea {
  width:100%;background:var(--bg);border:1px solid var(--border);
  color:var(--text);border-radius:8px;padding:.6rem .85rem;
  font-size:.85rem;outline:none;transition:border-color .15s;
  font-family:inherit;
}
.field-input:focus,.field-select:focus,.field-textarea:focus {
  border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15);
}
.field-textarea { resize:vertical;min-height:80px; }
.field-select option { background:var(--surface); }
.field-hint { font-size:.7rem;color:var(--text3);margin-top:4px; }

/* Buttons */
.btn-primary {
  background:linear-gradient(135deg,#3b82f6,#6d28d9);border:none;
  color:#fff;padding:.65rem 1.2rem;border-radius:8px;
  font-size:.85rem;font-weight:700;cursor:pointer;transition:opacity .15s;
  display:inline-flex;align-items:center;gap:6px;
}
.btn-primary:hover { opacity:.9; }
.btn-secondary {
  background:transparent;border:1px solid var(--border);
  color:var(--text2);padding:.6rem 1rem;border-radius:8px;
  font-size:.82rem;cursor:pointer;transition:.15s;
  display:inline-flex;align-items:center;gap:6px;
}
.btn-secondary:hover { border-color:var(--accent);color:var(--accent); }
.btn-danger { background:transparent;border:1px solid var(--danger);color:var(--danger);padding:3px 9px;border-radius:6px;cursor:pointer;font-size:.75rem;transition:.15s; }
.btn-danger:hover { background:rgba(239,68,68,.15); }
.btn-edit { background:transparent;border:1px solid var(--border);color:var(--text2);padding:3px 9px;border-radius:6px;cursor:pointer;font-size:.75rem;transition:.15s; }
.btn-edit:hover { border-color:var(--accent);color:var(--accent); }

/* Status toggle */
.toggle-wrap { display:flex;align-items:center;gap:8px; }
.toggle { position:relative;width:38px;height:22px; }
.toggle input { display:none; }
.toggle-slider { position:absolute;inset:0;background:var(--surface3);border-radius:20px;cursor:pointer;transition:.2s; }
.toggle-slider::before { content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s; }
.toggle input:checked + .toggle-slider { background:var(--line); }
.toggle input:checked + .toggle-slider::before { transform:translateX(16px); }

/* Active badge */
.badge-on  { background:rgba(6,199,85,.15);color:#06c755;border:1px solid rgba(6,199,85,.3);padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600; }
.badge-off { background:rgba(239,68,68,.1);color:#f87171;border:1px solid rgba(239,68,68,.2);padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600; }

/* Extract section */
.extract-box {
  background:var(--surface2);border:1px dashed var(--border);border-radius:8px;
  padding:.85rem;margin-bottom:.75rem;
}
.extract-title { font-size:.78rem;font-weight:600;color:var(--text2);margin-bottom:.5rem;display:flex;align-items:center;gap:6px; }
.no-results { text-align:center;padding:2rem 1rem;color:var(--text3); }
.no-results i { font-size:2rem;display:block;margin-bottom:.5rem; }

/* Divider */
.section-div { border:none;border-top:1px solid var(--border);margin:.75rem 0; }

/* Toast */
.toast-wrap { position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:8px; }
.toast-item { background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:.8rem 1.2rem;font-size:.83rem;min-width:260px;max-width:380px;display:flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,.5);animation:slideIn .25s ease; }
.toast-ok  { border-left:3px solid var(--success); }
.toast-err { border-left:3px solid var(--danger); }
@keyframes slideIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:none} }

/* Login */
.login-screen { min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse at top,#0a1628,#080d16 60%); }
.login-box { background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:2.5rem 2rem;width:360px;box-shadow:0 24px 64px rgba(0,0,0,.6); }
.login-logo { width:56px;height:56px;background:var(--line);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:900;color:#fff;margin:0 auto 1rem;box-shadow:0 0 24px rgba(6,199,85,.4); }
.login-title { text-align:center;font-size:1.2rem;font-weight:700;margin-bottom:.25rem; }
.login-sub   { text-align:center;font-size:.78rem;color:var(--text2);margin-bottom:1.5rem; }
.alert-err { background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;border-radius:8px;padding:.6rem .9rem;font-size:.82rem;margin-bottom:.75rem; }
.login-field-label { font-size:.78rem;color:var(--text2);margin-bottom:5px;display:block; }
.login-field-input { width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.65rem .9rem;font-size:.9rem;outline:none;transition:.15s; }
.login-field-input:focus { border-color:var(--accent); }
.login-btn { width:100%;padding:.75rem;background:linear-gradient(135deg,#3b82f6,#6d28d9);border:none;border-radius:8px;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;margin-top:1rem; }

/* Spinner */
.spinner { animation: spin 1s linear infinite; display:inline-block; }
@keyframes spin { to{ transform:rotate(360deg) } }
</style>
</head>
<body>

<?php if (!$auth): ?>
<!-- ════════════════ ログイン ════════════════ -->
<div class="login-screen">
  <div class="login-box">
    <div class="login-logo">L</div>
    <p class="login-title">LINE Harness</p>
    <p class="login-sub">キーワード設定 — ログイン</p>
    <?php if (!empty($login_error)): ?>
      <div class="alert-err"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <label class="login-field-label" for="pw">管理パスワード</label>
      <input type="password" id="pw" name="login_pass" class="login-field-input" autofocus>
      <button type="submit" class="login-btn"><i class="bi bi-box-arrow-in-right me-1"></i>ログイン</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ════════════════ メイン画面 ════════════════ -->

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
    <a href="./" class="nav-link-btn"><i class="bi bi-grid-1x2"></i> ダッシュボード</a>
    <span class="nav-link-btn active"><i class="bi bi-tags"></i> キーワード設定</span>
  </div>
  <div class="nav-right">
    <form method="post" style="margin:0">
      <button name="logout" class="btn-secondary" style="padding:5px 12px">
        <i class="bi bi-box-arrow-right"></i>
      </button>
    </form>
  </div>
</nav>

<div class="content">
  <div class="page-title">
    <i class="bi bi-tags-fill"></i>
    キーワード → アセットパターン設定
  </div>

  <div class="layout-grid">

    <!-- Left: Pattern List -->
    <div>
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-title">
            <i class="bi bi-list-ul" style="color:var(--accent)"></i>
            登録パターン一覧
          </div>
          <div style="font-size:.75rem;color:var(--text3)">
            <span id="pattern-count">─</span> 件
          </div>
        </div>
        <div id="pattern-table-wrap" style="overflow-x:auto">
          <div class="no-results"><i class="bi bi-hourglass spinner"></i>読み込み中…</div>
        </div>
      </div>
    </div>

    <!-- Right: Form + Extract -->
    <div style="display:flex;flex-direction:column;gap:1rem">

      <!-- Keyword Extractor -->
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-title">
            <i class="bi bi-magic" style="color:var(--warning)"></i>
            テキストからキーワード自動抽出
          </div>
        </div>
        <div class="card-body-pad">
          <div class="form-field">
            <label class="field-label">投稿テキストをペーストしてください</label>
            <textarea id="extract-input" class="field-textarea" placeholder="新商品のセールを開催中！お得なクーポンもあります。今すぐチェックしてください。"></textarea>
          </div>
          <button class="btn-secondary" onclick="extractKeywords()" style="width:100%">
            <i class="bi bi-cpu me-1"></i>キーワードを抽出
          </button>
          <div id="extract-result" class="chip-wrap" style="margin-top:.75rem;min-height:32px"></div>
          <div id="extract-hint" style="font-size:.72rem;color:var(--text3);margin-top:.5rem"></div>
        </div>
      </div>

      <!-- Edit / New Form -->
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-title">
            <i class="bi bi-pencil-square" style="color:var(--line)"></i>
            <span id="form-title">新規パターン追加</span>
          </div>
          <button class="btn-secondary" id="reset-btn" onclick="resetForm()" style="padding:3px 10px;display:none;font-size:.75rem">
            <i class="bi bi-x me-1"></i>クリア
          </button>
        </div>
        <div class="card-body-pad">
          <input type="hidden" id="edit-id">

          <div class="form-field">
            <label class="field-label" for="f-keyword">
              キーワード <span style="color:var(--danger)">*</span>
            </label>
            <input type="text" id="f-keyword" class="field-input" placeholder="例: 新商品、セール、クーポン">
          </div>

          <div class="form-field">
            <label class="field-label" for="f-asset-url">アセットURL</label>
            <input type="url" id="f-asset-url" class="field-input" placeholder="https://example.com/image.jpg">
            <div class="field-hint">このキーワードに紐づく画像・動画のURL（省略可）</div>
          </div>

          <div class="form-field">
            <label class="field-label" for="f-asset-type">アセットタイプ</label>
            <select id="f-asset-type" class="field-select">
              <option value="image">🖼 画像 (image)</option>
              <option value="video">🎬 動画 (video)</option>
              <option value="none">─ なし (none)</option>
            </select>
          </div>

          <div class="form-field">
            <label class="field-label" for="f-ng-words">NGワード（カンマ区切り）</label>
            <input type="text" id="f-ng-words" class="field-input" placeholder="例: 最安値,No.1,保証">
            <div class="field-hint">このキーワードと同時に含まれると警告するワード</div>
          </div>

          <div class="form-field">
            <label class="field-label" for="f-domains">許可ドメイン（カンマ区切り）</label>
            <input type="text" id="f-domains" class="field-input" placeholder="例: sya-cho.blog,example.com">
            <div class="field-hint">アセットURLとして許可するドメイン（省略=制限なし）</div>
          </div>

          <div class="form-field">
            <label class="field-label">有効 / 無効</label>
            <div class="toggle-wrap">
              <label class="toggle">
                <input type="checkbox" id="f-active" checked>
                <span class="toggle-slider"></span>
              </label>
              <span id="active-label" style="font-size:.82rem;color:var(--text2)">有効</span>
            </div>
          </div>

          <hr class="section-div">

          <div style="display:flex;gap:.75rem">
            <button class="btn-primary" onclick="savePattern()" style="flex:1">
              <i class="bi bi-floppy me-1"></i>保存
            </button>
          </div>
        </div>
      </div>

    </div><!-- /right -->
  </div><!-- /layout-grid -->
</div><!-- /content -->

<!-- Toast -->
<div class="toast-wrap" id="toast-wrap"></div>

<script>
'use strict';

// ── HTML エスケープ ──────────────────────────────────────────
function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── パターン一覧を取得・描画 ─────────────────────────────────
async function loadPatterns() {
  const wrap = document.getElementById('pattern-table-wrap');
  try {
    const r = await fetch('./keyword_settings.php?api=list');
    const d = await r.json();
    if (!d.ok || !d.data.length) {
      wrap.innerHTML = '<div class="no-results"><i class="bi bi-inbox"></i>パターンが登録されていません</div>';
      document.getElementById('pattern-count').textContent = '0';
      return;
    }
    document.getElementById('pattern-count').textContent = d.data.length;
    let html = `<table class="kw-table">
      <thead><tr>
        <th>#</th><th>キーワード</th><th>アセット</th><th>NGワード</th><th>状態</th><th></th>
      </tr></thead><tbody>`;
    for (const row of d.data) {
      const asset = row.asset_url
        ? `<a href="${esc(row.asset_url)}" target="_blank" style="color:var(--accent);font-size:.75rem;text-decoration:none" title="${esc(row.asset_url)}">
             ${row.asset_type === 'video' ? '🎬' : '🖼'} リンク
           </a>`
        : '<span style="color:var(--text3)">─</span>';
      const ng = row.ng_words
        ? `<span style="color:var(--danger);font-size:.72rem">${esc(row.ng_words.substring(0,30))}${row.ng_words.length>30?'…':''}</span>`
        : '<span style="color:var(--text3)">─</span>';
      const badge = row.is_active
        ? '<span class="badge-on">有効</span>'
        : '<span class="badge-off">無効</span>';
      html += `<tr>
        <td style="color:var(--text3)">${row.id}</td>
        <td><strong style="color:var(--text)">${esc(row.keyword)}</strong></td>
        <td>${asset}</td>
        <td>${ng}</td>
        <td>${badge}</td>
        <td style="white-space:nowrap">
          <button class="btn-edit me-1" onclick='editPattern(${JSON.stringify(row)})'><i class="bi bi-pencil"></i></button>
          <button class="btn-danger"    onclick="deletePattern(${row.id},'${esc(row.keyword)}')"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`;
    }
    html += '</tbody></table>';
    wrap.innerHTML = html;
  } catch(e) {
    wrap.innerHTML = '<div class="no-results"><i class="bi bi-wifi-off"></i>読み込みエラー</div>';
  }
}

// ── 編集フォームに値をセット ─────────────────────────────────
function editPattern(row) {
  document.getElementById('edit-id').value    = row.id;
  document.getElementById('f-keyword').value  = row.keyword;
  document.getElementById('f-asset-url').value= row.asset_url ?? '';
  document.getElementById('f-asset-type').value = row.asset_type ?? 'none';
  document.getElementById('f-ng-words').value = row.ng_words ?? '';
  document.getElementById('f-domains').value  = row.allowed_domains ?? '';
  document.getElementById('f-active').checked = !!row.is_active;
  updateActiveLabel();
  document.getElementById('form-title').textContent = `編集: 「${row.keyword}」`;
  document.getElementById('reset-btn').style.display = '';
  document.getElementById('f-keyword').focus();
  document.getElementById('f-keyword').scrollIntoView({behavior:'smooth',block:'center'});
}

// ── フォームリセット ─────────────────────────────────────────
function resetForm() {
  document.getElementById('edit-id').value    = '';
  document.getElementById('f-keyword').value  = '';
  document.getElementById('f-asset-url').value= '';
  document.getElementById('f-asset-type').value = 'image';
  document.getElementById('f-ng-words').value = '';
  document.getElementById('f-domains').value  = '';
  document.getElementById('f-active').checked = true;
  updateActiveLabel();
  document.getElementById('form-title').textContent = '新規パターン追加';
  document.getElementById('reset-btn').style.display = 'none';
}

// ── 保存 ─────────────────────────────────────────────────────
async function savePattern() {
  const payload = {
    id:              document.getElementById('edit-id').value || null,
    keyword:         document.getElementById('f-keyword').value.trim(),
    asset_url:       document.getElementById('f-asset-url').value.trim(),
    asset_type:      document.getElementById('f-asset-type').value,
    ng_words:        document.getElementById('f-ng-words').value.trim(),
    allowed_domains: document.getElementById('f-domains').value.trim(),
    is_active:       document.getElementById('f-active').checked,
  };
  if (!payload.keyword) { toast('キーワードを入力してください', 'err'); return; }
  try {
    const r = await fetch('./keyword_settings.php?api=save', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify(payload),
    });
    const d = await r.json();
    toast(d.message, d.ok ? 'ok' : 'err');
    if (d.ok) { resetForm(); loadPatterns(); }
  } catch(e) {
    toast('保存に失敗しました', 'err');
  }
}

// ── 削除 ─────────────────────────────────────────────────────
async function deletePattern(id, kw) {
  if (!confirm(`「${kw}」を削除しますか？`)) return;
  const r = await fetch('./keyword_settings.php?api=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
    body: JSON.stringify({ id }),
  });
  const d = await r.json();
  toast(d.message, d.ok ? 'ok' : 'err');
  if (d.ok) loadPatterns();
}

// ── キーワード自動抽出 ────────────────────────────────────────
async function extractKeywords() {
  const text = document.getElementById('extract-input').value.trim();
  if (!text) { toast('テキストを入力してください', 'err'); return; }
  const result  = document.getElementById('extract-result');
  const hint    = document.getElementById('extract-hint');
  result.innerHTML = '<span class="spinner" style="color:var(--text3);font-size:.85rem">⏳ 抽出中…</span>';
  try {
    const r = await fetch('./keyword_settings.php?api=extract', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
      body: JSON.stringify({ text }),
    });
    const d = await r.json();
    if (!d.ok || !d.candidates.length) {
      result.innerHTML = '<span style="color:var(--text3);font-size:.78rem">キーワードが見つかりませんでした</span>';
      hint.textContent = '';
      return;
    }
    result.innerHTML = d.candidates.map(c => {
      const cls = c.exists ? 'chip chip-exists' : 'chip chip-new';
      const ico = c.exists ? '✓' : '+';
      return `<span class="${cls}" onclick="selectChip(this,'${esc(c.word)}')" title="${c.exists ? '既に登録済み' : 'クリックでフォームに反映'}">${ico} ${esc(c.word)}</span>`;
    }).join('');
    const total    = d.candidates.length;
    const existing = d.candidates.filter(c => c.exists).length;
    hint.textContent = `${total}件抽出 / 登録済み ${existing}件 — チップをクリックでフォームに反映`;
  } catch(e) {
    result.innerHTML = '<span style="color:var(--danger)">抽出エラー</span>';
  }
}

// ── チップクリック → フォームへ ──────────────────────────────
function selectChip(el, word) {
  document.querySelectorAll('.chip-selected').forEach(c => c.classList.remove('chip-selected'));
  el.classList.add('chip-selected');
  document.getElementById('f-keyword').value = word;
  document.getElementById('f-keyword').focus();
}

// ── 有効/無効ラベル更新 ──────────────────────────────────────
function updateActiveLabel() {
  const on = document.getElementById('f-active').checked;
  document.getElementById('active-label').textContent = on ? '有効' : '無効';
}
document.getElementById('f-active').addEventListener('change', updateActiveLabel);

// ── Toast ─────────────────────────────────────────────────────
function toast(msg, type='info') {
  const wrap   = document.getElementById('toast-wrap');
  const icons  = { ok:'bi-check-circle-fill', err:'bi-x-circle-fill', info:'bi-info-circle-fill' };
  const colors = { ok:'var(--success)', err:'var(--danger)', info:'var(--accent)' };
  const el = document.createElement('div');
  el.className = `toast-item toast-${type}`;
  el.innerHTML = `<i class="bi ${icons[type]}" style="color:${colors[type]};font-size:1.1rem;flex-shrink:0"></i>
                  <span>${esc(msg)}</span>`;
  wrap.appendChild(el);
  setTimeout(() => el.style.cssText += 'opacity:0;transition:.3s;transform:translateX(20px)', 3500);
  setTimeout(() => el.remove(), 3900);
}

// ── Init ──────────────────────────────────────────────────────
loadPatterns();
</script>

<?php endif; ?>
</body>
</html>

<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pass'])) {
    if ($_POST['login_pass'] === ADMIN_PASSWORD) $_SESSION['lh_auth'] = true;
    else $login_error = 'パスワードが違います';
}
if (isset($_POST['logout'])) { session_destroy(); header('Location: ./ai_settings.php'); exit; }
$auth = !empty($_SESSION['lh_auth']);

// ── AJAX ─────────────────────────────────────────────────────
if ($auth && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');

    // 設定一覧取得
    if ($_GET['api'] === 'get') {
        try {
            $rows = db()->query("SELECT `key`, `value` FROM system_settings")->fetchAll();
            $s = [];
            foreach ($rows as $r) $s[$r['key']] = $r['value'];
            // APIキーはマスク
            if (!empty($s['ai_api_key'])) $s['ai_api_key_masked'] = substr($s['ai_api_key'],0,6).'*****';
            echo json_encode(['ok'=>true,'data'=>$s], JSON_UNESCAPED_UNICODE);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
        exit;
    }

    // 設定保存
    if ($_GET['api'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        $allowed = ['ai_provider','ai_model','ai_api_key','ai_system_prompt','ai_temperature',
                    'ai_max_tokens','post_topic','post_style','post_hashtags',
                    'schedule_enabled','schedule_hours','schedule_days'];
        try {
            $st = db()->prepare("INSERT INTO system_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            foreach ($allowed as $k) {
                if (!array_key_exists($k, $raw)) continue;
                // APIキーが空なら既存を保持
                if ($k === 'ai_api_key' && $raw[$k] === '') continue;
                $st->execute([$k, $raw[$k]]);
            }
            echo json_encode(['ok'=>true,'message'=>'設定を保存しました'],JSON_UNESCAPED_UNICODE);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
        exit;
    }

    // AI接続テスト
    if ($_GET['api'] === 'test_ai' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        $provider = $raw['provider'] ?? 'openai';
        $model    = $raw['model']    ?? 'gpt-4o';
        // APIキーが送られていない場合はDBから取得
        $apiKey = (!empty($raw['api_key']) && $raw['api_key'] !== '') ? $raw['api_key']
                : (db()->query("SELECT `value` FROM system_settings WHERE `key`='ai_api_key'")->fetchColumn() ?: '');
        if (!$apiKey) { echo json_encode(['ok'=>false,'message'=>'APIキーが未設定です'],JSON_UNESCAPED_UNICODE); exit; }

        $testPrompt = 'テスト接続です。「接続成功」と日本語で1文だけ返答してください。';
        $result = call_ai($provider, $model, $apiKey, 'あなたはアシスタントです。', $testPrompt, 0.1, 50);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok'=>false,'message'=>'不明なAPI'],JSON_UNESCAPED_UNICODE);
    exit;
}

// ── AI呼び出し共通関数 ───────────────────────────────────────
function call_ai(string $provider, string $model, string $apiKey, string $sysprompt, string $userprompt, float $temp, int $maxTokens): array {
    $ch = curl_init();
    $headers = ['Content-Type: application/json'];
    switch ($provider) {
        case 'openai':
            curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
            $headers[] = "Authorization: Bearer $apiKey";
            $body = json_encode(['model'=>$model,'temperature'=>$temp,'max_tokens'=>$maxTokens,
                'messages'=>[['role'=>'system','content'=>$sysprompt],['role'=>'user','content'=>$userprompt]]]);
            break;
        case 'anthropic':
            curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
            $headers[] = "x-api-key: $apiKey";
            $headers[] = 'anthropic-version: 2023-06-01';
            $body = json_encode(['model'=>$model,'max_tokens'=>$maxTokens,'temperature'=>$temp,
                'system'=>$sysprompt,'messages'=>[['role'=>'user','content'=>$userprompt]]]);
            break;
        case 'gemini':
            curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}");
            $body = json_encode(['contents'=>[['parts'=>[['text'=>$sysprompt."\n\n".$userprompt]]]],'generationConfig'=>['temperature'=>$temp,'maxOutputTokens'=>$maxTokens]]);
            break;
        default:
            return ['ok'=>false,'message'=>'不明なプロバイダー'];
    }
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>true]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok'=>false,'message'=>'cURLエラー: '.$err];
    $d = json_decode($resp, true);
    if ($code !== 200) return ['ok'=>false,'message'=>"APIエラー({$code}): ".($d['error']['message'] ?? $resp)];
    $text = match($provider) {
        'openai'    => $d['choices'][0]['message']['content'] ?? '',
        'anthropic' => $d['content'][0]['text'] ?? '',
        'gemini'    => $d['candidates'][0]['content']['parts'][0]['text'] ?? '',
        default     => ''
    };
    return ['ok'=>true,'text'=>trim($text)];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI設定 | LINE Harness</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{--bg:#080d16;--surface:#0f1923;--surface2:#172032;--surface3:#1e2d40;--border:#1e3048;--text:#e2e8f0;--text2:#7c93ad;--text3:#4a6582;--accent:#3b82f6;--line:#06c755;--success:#10b981;--warning:#f59e0b;--danger:#ef4444;--purple:#8b5cf6;--gold:#f59e0b;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Segoe UI','Hiragino Kaku Gothic Pro','Yu Gothic',sans-serif;font-size:.9rem;min-height:100vh;}
::-webkit-scrollbar{width:6px;}::-webkit-scrollbar-track{background:var(--bg);}::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
.nav-top{background:linear-gradient(90deg,#0a1628,#0f1e35);border-bottom:1px solid var(--border);padding:0 1.5rem;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);}
.brand-icon{width:34px;height:34px;background:var(--line);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:900;color:#fff;}
.brand-name{font-size:1.05rem;font-weight:700;}.brand-sub{font-size:.7rem;color:var(--text2);margin-top:-2px;}
.nav-links{display:flex;gap:4px;}
.nav-link-btn{background:transparent;border:none;color:var(--text2);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:.85rem;text-decoration:none;display:flex;align-items:center;gap:5px;transition:.15s;}
.nav-link-btn:hover,.nav-link-btn.active{background:var(--surface3);color:var(--text);}
.nav-right{display:flex;align-items:center;gap:10px;}
.content{padding:1.5rem;max-width:900px;margin:0 auto;}
.page-title{font-size:1.3rem;font-weight:700;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;}
.card-box{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1rem;}
.card-head{background:var(--surface2);padding:.75rem 1.1rem;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border);font-size:.88rem;font-weight:600;}
.card-body{padding:1.2rem 1.3rem;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:.9rem;}
@media(max-width:640px){.form-row{grid-template-columns:1fr;}}
.form-field{margin-bottom:.9rem;}
.field-label{font-size:.78rem;color:var(--text2);margin-bottom:5px;display:flex;align-items:center;gap:6px;}
.field-input,.field-select,.field-textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.6rem .85rem;font-size:.85rem;outline:none;transition:border-color .15s;font-family:inherit;}
.field-input:focus,.field-select:focus,.field-textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15);}
.field-select option{background:var(--surface);}
.field-textarea{resize:vertical;min-height:100px;}
.field-hint{font-size:.7rem;color:var(--text3);margin-top:4px;}
.field-hint a{color:var(--accent);}
.btn-primary{background:linear-gradient(135deg,#3b82f6,#6d28d9);border:none;color:#fff;padding:.65rem 1.4rem;border-radius:8px;font-size:.87rem;font-weight:700;cursor:pointer;transition:opacity .15s;display:inline-flex;align-items:center;gap:7px;}
.btn-primary:hover{opacity:.9;}
.btn-secondary{background:transparent;border:1px solid var(--border);color:var(--text2);padding:.6rem 1.1rem;border-radius:8px;font-size:.84rem;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:6px;}
.btn-secondary:hover{border-color:var(--accent);color:var(--accent);}
.btn-test{background:transparent;border:1px solid var(--success);color:var(--success);padding:.55rem 1rem;border-radius:8px;font-size:.8rem;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:5px;}
.btn-test:hover{background:rgba(16,185,129,.1);}
.section-div{border:none;border-top:1px solid var(--border);margin:1rem 0;}
.provider-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem;}
@media(max-width:540px){.provider-grid{grid-template-columns:1fr;}}
.provider-card{background:var(--surface2);border:2px solid var(--border);border-radius:10px;padding:.9rem;cursor:pointer;transition:.15s;text-align:center;}
.provider-card:hover{border-color:var(--accent);}
.provider-card.selected{border-color:var(--accent);background:rgba(59,130,246,.1);}
.provider-icon{font-size:1.6rem;margin-bottom:.4rem;}
.provider-name{font-size:.8rem;font-weight:700;}
.provider-sub{font-size:.68rem;color:var(--text3);margin-top:2px;}
.model-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.7rem;font-weight:600;background:var(--surface3);color:var(--text2);margin-top:4px;}
.toggle-wrap{display:flex;align-items:center;gap:10px;}
.toggle{position:relative;width:38px;height:22px;}.toggle input{display:none;}
.toggle-slider{position:absolute;inset:0;background:var(--surface3);border-radius:20px;cursor:pointer;transition:.2s;}
.toggle-slider::before{content:'';position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;}
.toggle input:checked+.toggle-slider{background:var(--line);}
.toggle input:checked+.toggle-slider::before{transform:translateX(16px);}
.test-result{margin-top:.75rem;padding:.75rem 1rem;border-radius:8px;font-size:.82rem;display:none;}
.test-ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);color:#6ee7b7;}
.test-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5;}
.toast-wrap{position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.toast-item{background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:.8rem 1.2rem;font-size:.83rem;min-width:240px;max-width:360px;display:flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(0,0,0,.5);animation:slideIn .25s ease;}
.toast-ok{border-left:3px solid var(--success);}.toast-err{border-left:3px solid var(--danger);}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
.spinner{animation:spin 1s linear infinite;display:inline-block;}@keyframes spin{to{transform:rotate(360deg)}}
.login-screen{min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(ellipse at top,#0a1628,#080d16 60%);}
.login-box{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:2.5rem 2rem;width:360px;box-shadow:0 24px 64px rgba(0,0,0,.6);}
.login-logo{width:56px;height:56px;background:var(--line);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:900;color:#fff;margin:0 auto 1rem;box-shadow:0 0 24px rgba(6,199,85,.4);}
.login-title{text-align:center;font-size:1.2rem;font-weight:700;margin-bottom:.25rem;}.login-sub{text-align:center;font-size:.78rem;color:var(--text2);margin-bottom:1.5rem;}
.alert-err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#f87171;border-radius:8px;padding:.6rem .9rem;font-size:.82rem;margin-bottom:.75rem;}
.lf-label{font-size:.78rem;color:var(--text2);margin-bottom:5px;display:block;}.lf-input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.65rem .9rem;font-size:.9rem;outline:none;transition:.15s;}.lf-input:focus{border-color:var(--accent);}
.login-btn{width:100%;padding:.75rem;background:linear-gradient(135deg,#3b82f6,#6d28d9);border:none;border-radius:8px;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;margin-top:1rem;}
</style>
</head>
<body>
<?php if (!$auth): ?>
<div class="login-screen"><div class="login-box">
  <div class="login-logo">L</div>
  <p class="login-title">LINE Harness</p><p class="login-sub">AI設定 — ログイン</p>
  <?php if (!empty($login_error)): ?><div class="alert-err"><?= htmlspecialchars($login_error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <form method="post"><label class="lf-label">管理パスワード</label><input type="password" name="login_pass" class="lf-input" autofocus><button type="submit" class="login-btn">ログイン</button></form>
</div></div>
<?php else: ?>

<nav class="nav-top">
  <a href="./" class="nav-brand"><div class="brand-icon">L</div><div><div class="brand-name">LINE Harness</div><div class="brand-sub">BOSS System</div></div></a>
  <div class="nav-links">
    <a href="./" class="nav-link-btn"><i class="bi bi-grid-1x2"></i> ダッシュボード</a>
    <a href="./post_editor.php" class="nav-link-btn"><i class="bi bi-pencil-square"></i> 投稿作成</a>
    <span class="nav-link-btn active"><i class="bi bi-cpu"></i> AI設定</span>
    <a href="./keyword_settings.php" class="nav-link-btn"><i class="bi bi-tags"></i> キーワード</a>
  </div>
  <div class="nav-right">
    <form method="post"><button name="logout" class="btn-secondary" style="padding:5px 12px"><i class="bi bi-box-arrow-right"></i></button></form>
  </div>
</nav>

<div class="content">
  <div class="page-title"><i class="bi bi-cpu-fill" style="color:var(--purple)"></i> AI・自動投稿 設定</div>

  <!-- AIプロバイダー選択 -->
  <div class="card-box">
    <div class="card-head"><i class="bi bi-robot" style="color:var(--purple)"></i> AIプロバイダー</div>
    <div class="card-body">
      <div class="provider-grid">
        <div class="provider-card" id="prov-openai" onclick="selectProvider('openai')">
          <div class="provider-icon">🤖</div>
          <div class="provider-name">OpenAI</div>
          <div class="provider-sub">GPT-4o / GPT-4</div>
          <div class="model-badge" id="badge-openai">gpt-4o</div>
        </div>
        <div class="provider-card" id="prov-anthropic" onclick="selectProvider('anthropic')">
          <div class="provider-icon">🧠</div>
          <div class="provider-name">Anthropic Claude</div>
          <div class="provider-sub">Claude Sonnet / Haiku</div>
          <div class="model-badge" id="badge-anthropic">claude-sonnet-4-6</div>
        </div>
        <div class="provider-card" id="prov-gemini" onclick="selectProvider('gemini')">
          <div class="provider-icon">✨</div>
          <div class="provider-name">Google Gemini</div>
          <div class="provider-sub">Gemini 1.5 Pro / Flash</div>
          <div class="model-badge" id="badge-gemini">gemini-1.5-pro</div>
        </div>
      </div>
      <input type="hidden" id="sel-provider" value="openai">

      <div class="form-row">
        <div class="form-field">
          <label class="field-label"><i class="bi bi-grid"></i> モデル</label>
          <select id="sel-model" class="field-select" onchange="updateModelBadge()">
            <optgroup label="OpenAI" id="og-openai">
              <option value="gpt-4o">GPT-4o（最新・推奨）</option>
              <option value="gpt-4o-mini">GPT-4o mini（高速・安価）</option>
              <option value="gpt-4-turbo">GPT-4 Turbo</option>
            </optgroup>
            <optgroup label="Anthropic" id="og-anthropic">
              <option value="claude-sonnet-4-6">Claude Sonnet 4.6（推奨）</option>
              <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5（高速）</option>
              <option value="claude-opus-4-8">Claude Opus 4.8（最高品質）</option>
            </optgroup>
            <optgroup label="Google" id="og-gemini">
              <option value="gemini-1.5-pro">Gemini 1.5 Pro</option>
              <option value="gemini-1.5-flash">Gemini 1.5 Flash（高速）</option>
            </optgroup>
          </select>
        </div>
        <div class="form-field">
          <label class="field-label"><i class="bi bi-thermometer"></i> Temperature <span style="color:var(--text3)">（創造性: 0.0〜1.0）</span></label>
          <input type="number" id="sel-temp" class="field-input" value="0.7" min="0" max="1" step="0.1">
        </div>
      </div>

      <div class="form-field">
        <label class="field-label"><i class="bi bi-key"></i> APIキー <span style="color:var(--danger)">*</span></label>
        <input type="password" id="sel-apikey" class="field-input" placeholder="sk-... / sk-ant-... / AIza...">
        <div class="field-hint" id="apikey-hint">
          OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a>
        </div>
        <div style="margin-top:.5rem;font-size:.75rem;color:var(--text3)" id="apikey-stored"></div>
      </div>

      <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
        <button class="btn-test" onclick="testConnection()"><i class="bi bi-lightning"></i> 接続テスト</button>
        <span id="test-spinner" style="display:none;color:var(--text3);font-size:.8rem"><span class="spinner">⟳</span> テスト中…</span>
      </div>
      <div class="test-result" id="test-result"></div>
    </div>
  </div>

  <!-- 投稿スタイル設定 -->
  <div class="card-box">
    <div class="card-head"><i class="bi bi-chat-quote" style="color:var(--line)"></i> 投稿スタイル・テーマ</div>
    <div class="card-body">
      <div class="form-field">
        <label class="field-label"><i class="bi bi-bookmark"></i> 投稿テーマ・商品カテゴリ</label>
        <input type="text" id="f-topic" class="field-input" placeholder="例: レザーバッグ、ゴルフウェア、メンズファッション">
        <div class="field-hint">AIが文章を生成する際に参考にするテーマ</div>
      </div>
      <div class="form-field">
        <label class="field-label"><i class="bi bi-palette"></i> 文体・トーン</label>
        <input type="text" id="f-style" class="field-input" placeholder="例: 親しみやすく、商品の魅力を伝える">
      </div>
      <div class="form-field">
        <label class="field-label"><i class="bi bi-hash"></i> 定型ハッシュタグ</label>
        <input type="text" id="f-hashtags" class="field-input" placeholder="例: #ボス #レザーバッグ #メンズファッション">
        <div class="field-hint">投稿末尾に自動追記（空白で区切り）</div>
      </div>
      <div class="form-field">
        <label class="field-label"><i class="bi bi-gear"></i> AIシステムプロンプト（上級）</label>
        <textarea id="f-sysprompt" class="field-textarea" placeholder="例: あなたはラグジュアリーブランドのSNS担当です。商品の品質と職人技を強調した、洗練された日本語の投稿文を作成してください。絵文字を適度に使い、親しみやすさも大切にしてください。"></textarea>
        <div class="field-hint">空白の場合はデフォルトのプロンプトを使用</div>
      </div>
      <div class="form-field">
        <label class="field-label"><i class="bi bi-123"></i> 最大文字数（トークン）</label>
        <input type="number" id="f-maxtokens" class="field-input" value="300" min="50" max="2000" step="50" style="max-width:160px">
      </div>
    </div>
  </div>

  <!-- スケジュール設定 -->
  <div class="card-box">
    <div class="card-head"><i class="bi bi-clock" style="color:var(--gold)"></i> 自動投稿スケジュール</div>
    <div class="card-body">
      <div class="form-field">
        <div class="toggle-wrap">
          <label class="toggle"><input type="checkbox" id="f-sched-enabled"><span class="toggle-slider"></span></label>
          <span id="sched-label" style="font-size:.85rem;color:var(--text2)">スケジュール無効</span>
        </div>
        <div class="field-hint" style="margin-top:.5rem">有効にすると boss_auto_post.py の cron 実行時に自動投稿</div>
      </div>
      <div id="sched-detail" style="display:none">
        <div class="form-field">
          <label class="field-label"><i class="bi bi-clock-history"></i> 投稿時刻（カンマ区切り・24時間）</label>
          <input type="text" id="f-sched-hours" class="field-input" placeholder="例: 8,12,18,20" style="max-width:280px">
          <div class="field-hint">上記の時間帯にAIが自動で投稿文を作成してキューに追加</div>
        </div>
        <div class="form-field">
          <label class="field-label"><i class="bi bi-calendar3"></i> 投稿曜日（カンマ区切り・1=月〜7=日）</label>
          <input type="text" id="f-sched-days" class="field-input" placeholder="例: 1,2,3,4,5（平日のみ）" style="max-width:280px">
        </div>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1.25rem">
    <button class="btn-primary" onclick="saveSettings()"><i class="bi bi-floppy"></i> 設定を保存</button>
  </div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<script>
'use strict';

const PROVIDER_MODELS = {
  openai:    ['gpt-4o','gpt-4o-mini','gpt-4-turbo'],
  anthropic: ['claude-sonnet-4-6','claude-haiku-4-5-20251001','claude-opus-4-8'],
  gemini:    ['gemini-1.5-pro','gemini-1.5-flash'],
};
const APIKEY_HINTS = {
  openai:    'OpenAI: <a href="https://platform.openai.com/api-keys" target="_blank" style="color:var(--accent)">platform.openai.com/api-keys</a>',
  anthropic: 'Anthropic: <a href="https://console.anthropic.com/settings/keys" target="_blank" style="color:var(--accent)">console.anthropic.com/settings/keys</a>',
  gemini:    'Google: <a href="https://aistudio.google.com/app/apikey" target="_blank" style="color:var(--accent)">aistudio.google.com/app/apikey</a>',
};

function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function selectProvider(p) {
  document.querySelectorAll('.provider-card').forEach(c=>c.classList.remove('selected'));
  document.getElementById('prov-'+p).classList.add('selected');
  document.getElementById('sel-provider').value = p;
  // モデルリストを該当グループのみ有効化
  ['openai','anthropic','gemini'].forEach(pp => {
    const og = document.getElementById('og-'+pp);
    if (og) og.style.display = (pp===p) ? '' : 'none';
  });
  document.getElementById('sel-model').value = PROVIDER_MODELS[p][0];
  document.getElementById('apikey-hint').innerHTML = APIKEY_HINTS[p];
  updateModelBadge();
}

function updateModelBadge() {
  const p = document.getElementById('sel-provider').value;
  const m = document.getElementById('sel-model').value;
  document.getElementById('badge-'+p).textContent = m;
}

document.getElementById('f-sched-enabled').addEventListener('change', function(){
  const on = this.checked;
  document.getElementById('sched-label').textContent = on ? 'スケジュール有効' : 'スケジュール無効';
  document.getElementById('sched-detail').style.display = on ? '' : 'none';
});

async function loadSettings() {
  try {
    const r = await fetch('./ai_settings.php?api=get');
    const d = await r.json();
    if (!d.ok) return;
    const s = d.data;
    if (s.ai_provider)      selectProvider(s.ai_provider);
    if (s.ai_model)         document.getElementById('sel-model').value = s.ai_model;
    if (s.ai_temperature)   document.getElementById('sel-temp').value  = s.ai_temperature;
    if (s.post_topic)       document.getElementById('f-topic').value   = s.post_topic;
    if (s.post_style)       document.getElementById('f-style').value   = s.post_style;
    if (s.post_hashtags)    document.getElementById('f-hashtags').value= s.post_hashtags;
    if (s.ai_system_prompt) document.getElementById('f-sysprompt').value= s.ai_system_prompt;
    if (s.ai_max_tokens)    document.getElementById('f-maxtokens').value= s.ai_max_tokens;
    if (s.ai_api_key_masked) document.getElementById('apikey-stored').textContent = '保存済みキー: '+s.ai_api_key_masked;

    const schedOn = s.schedule_enabled === '1';
    document.getElementById('f-sched-enabled').checked = schedOn;
    document.getElementById('sched-label').textContent  = schedOn ? 'スケジュール有効' : 'スケジュール無効';
    document.getElementById('sched-detail').style.display = schedOn ? '' : 'none';
    if (s.schedule_hours) document.getElementById('f-sched-hours').value = s.schedule_hours;
    if (s.schedule_days)  document.getElementById('f-sched-days').value  = s.schedule_days;
    updateModelBadge();
  } catch(e){}
}

async function saveSettings() {
  const payload = {
    ai_provider:      document.getElementById('sel-provider').value,
    ai_model:         document.getElementById('sel-model').value,
    ai_api_key:       document.getElementById('sel-apikey').value,
    ai_temperature:   document.getElementById('sel-temp').value,
    ai_system_prompt: document.getElementById('f-sysprompt').value,
    ai_max_tokens:    document.getElementById('f-maxtokens').value,
    post_topic:       document.getElementById('f-topic').value,
    post_style:       document.getElementById('f-style').value,
    post_hashtags:    document.getElementById('f-hashtags').value,
    schedule_enabled: document.getElementById('f-sched-enabled').checked ? '1' : '0',
    schedule_hours:   document.getElementById('f-sched-hours').value,
    schedule_days:    document.getElementById('f-sched-days').value,
  };
  try {
    const r = await fetch('./ai_settings.php?api=save', {method:'POST', headers:{'Content-Type':'application/json;charset=utf-8'}, body:JSON.stringify(payload)});
    const d = await r.json();
    toast(d.message, d.ok ? 'ok' : 'err');
    if (d.ok && payload.ai_api_key) {
      document.getElementById('sel-apikey').value = '';
      document.getElementById('apikey-stored').textContent = '保存済みキー: '+payload.ai_api_key.substring(0,6)+'*****';
    }
  } catch(e){ toast('保存失敗','err'); }
}

async function testConnection() {
  const provider = document.getElementById('sel-provider').value;
  const model    = document.getElementById('sel-model').value;
  const apikey   = document.getElementById('sel-apikey').value;
  const spinner  = document.getElementById('test-spinner');
  const result   = document.getElementById('test-result');
  spinner.style.display = 'inline-flex';
  result.style.display  = 'none';
  try {
    const r = await fetch('./ai_settings.php?api=test_ai', {
      method:'POST', headers:{'Content-Type':'application/json;charset=utf-8'},
      body: JSON.stringify({provider, model, api_key: apikey}),
    });
    const d = await r.json();
    result.style.display = 'block';
    if (d.ok) {
      result.className = 'test-result test-ok';
      result.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i><strong>接続成功</strong> — ' + esc(d.text);
    } else {
      result.className = 'test-result test-err';
      result.innerHTML = '<i class="bi bi-x-circle-fill me-2"></i><strong>エラー</strong>: ' + esc(d.message);
    }
  } catch(e) {
    result.style.display='block'; result.className='test-result test-err';
    result.innerHTML='<i class="bi bi-x-circle-fill me-2"></i>通信エラー';
  }
  spinner.style.display = 'none';
}

function toast(msg, type='info') {
  const wrap=document.getElementById('toast-wrap');
  const icons={ok:'bi-check-circle-fill',err:'bi-x-circle-fill',info:'bi-info-circle-fill'};
  const colors={ok:'var(--success)',err:'var(--danger)',info:'var(--accent)'};
  const el=document.createElement('div'); el.className='toast-item toast-'+type;
  el.innerHTML=`<i class="bi ${icons[type]}" style="color:${colors[type]};font-size:1.1rem;flex-shrink:0"></i><span>${esc(msg)}</span>`;
  wrap.appendChild(el);
  setTimeout(()=>el.style.cssText+='opacity:0;transition:.3s;transform:translateX(20px)',3500);
  setTimeout(()=>el.remove(),3900);
}

loadSettings();
</script>
<?php endif; ?>
</body>
</html>

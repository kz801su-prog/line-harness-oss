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
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

function getSetting(string $key, string $default = ''): string {
    try {
        $v = db()->prepare("SELECT `value` FROM system_settings WHERE `key`=?");
        $v->execute([$key]);
        return (string)($v->fetchColumn() ?: $default);
    } catch(Exception $e){ return $default; }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['login_pass'])) {
    if ($_POST['login_pass']===ADMIN_PASSWORD) $_SESSION['lh_auth']=true;
    else $login_error='パスワードが違います';
}
if (isset($_POST['logout'])){ session_destroy(); header('Location: ./post_editor.php'); exit; }
$auth = !empty($_SESSION['lh_auth']);

// ── AJAX ─────────────────────────────────────────────────────
if ($auth && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');

    // AIで文章生成
    if ($_GET['api']==='generate' && $_SERVER['REQUEST_METHOD']==='POST') {
        $raw      = json_decode(file_get_contents('php://input'), true) ?? [];
        $userHint = trim($raw['hint'] ?? '');

        $provider  = getSetting('ai_provider',  'openai');
        $model     = getSetting('ai_model',     'gpt-4o');
        $apiKey    = getSetting('ai_api_key',   '');
        $sysprompt = getSetting('ai_system_prompt', '');
        $topic     = getSetting('post_topic',   '');
        $style     = getSetting('post_style',   '親しみやすく、商品の魅力を伝える');
        $hashtags  = getSetting('post_hashtags','');
        $maxTokens = (int)getSetting('ai_max_tokens', '300');
        $temp      = (float)getSetting('ai_temperature', '0.7');

        if (!$apiKey) { echo json_encode(['ok'=>false,'message'=>'AIのAPIキーが未設定です。AI設定画面で設定してください。'],JSON_UNESCAPED_UNICODE); exit; }

        if (!$sysprompt) {
            $sysprompt = "あなたはSNS投稿の専門家です。LINEへの配信メッセージを作成してください。\n"
                       . "文体: {$style}\n"
                       . ($topic ? "テーマ: {$topic}\n" : '')
                       . "・絵文字を2〜3個使う\n・200文字以内で簡潔に\n・行動を促すCTA（クリック、購入、チェックなど）を含める";
        }

        $userPrompt = $userHint ?: ($topic ? "{$topic}についてのLINE投稿文を1つ作成してください。" : "魅力的なLINE投稿文を1つ作成してください。");
        if ($hashtags) $userPrompt .= "\n末尾にこのハッシュタグを追記してください: {$hashtags}";

        $result = call_ai($provider, $model, $apiKey, $sysprompt, $userPrompt, $temp, $maxTokens);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // キューに追加
    if ($_GET['api']==='queue' && $_SERVER['REQUEST_METHOD']==='POST') {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        $text  = trim($raw['body_text'] ?? '');
        $media = trim($raw['media_url'] ?? '');
        $mode  = trim($raw['mode']      ?? 'manual');
        if (!$text){ echo json_encode(['ok'=>false,'message'=>'本文を入力してください'],JSON_UNESCAPED_UNICODE); exit; }

        $mediaType='none';
        if ($media) {
            $ext=strtolower(pathinfo(parse_url($media,PHP_URL_PATH),PATHINFO_EXTENSION));
            $mediaType=in_array($ext,['mp4','mov'],true)?'video':(in_array($ext,['jpg','jpeg','png','gif','webp'],true)?'image':'none');
        }
        try {
            $now=(new DateTime('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
            db()->prepare("INSERT INTO message_queue (body_text,media_url,media_type,generated_by,mode,queued_at) VALUES (?,?,?,?,?,?)")
               ->execute([$text,$media?:null,$mediaType,'post_editor',$mode,$now]);
            $id=(int)db()->lastInsertId();
            echo json_encode(['ok'=>true,'message'=>"キューに追加しました（ID:{$id}）",'queue_id'=>$id],JSON_UNESCAPED_UNICODE);
        } catch(Exception $e){ echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
        exit;
    }

    // 投稿履歴（直近20件）
    if ($_GET['api']==='history') {
        try {
            $rows=db()->query("SELECT id,body_text,mode,status,queued_at,sent_at FROM message_queue ORDER BY id DESC LIMIT 20")->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$rows],JSON_UNESCAPED_UNICODE);
        } catch(Exception $e){ echo json_encode(['ok'=>false,'data'=>[]],JSON_UNESCAPED_UNICODE); }
        exit;
    }

    // キーワードパターン一覧（メディア選択用）
    if ($_GET['api']==='patterns') {
        try {
            $rows=db()->query("SELECT keyword,asset_url,asset_type FROM keyword_patterns WHERE is_active=1 ORDER BY keyword")->fetchAll();
            echo json_encode(['ok'=>true,'data'=>$rows],JSON_UNESCAPED_UNICODE);
        } catch(Exception $e){ echo json_encode(['ok'=>true,'data'=>[]],JSON_UNESCAPED_UNICODE); }
        exit;
    }

    echo json_encode(['ok'=>false,'message'=>'不明なAPI'],JSON_UNESCAPED_UNICODE);
    exit;
}

function call_ai(string $provider, string $model, string $apiKey, string $sysprompt, string $userprompt, float $temp, int $maxTokens): array {
    $ch=curl_init();
    $headers=['Content-Type: application/json'];
    switch($provider){
        case 'openai':
            curl_setopt($ch,CURLOPT_URL,'https://api.openai.com/v1/chat/completions');
            $headers[]="Authorization: Bearer $apiKey";
            $body=json_encode(['model'=>$model,'temperature'=>$temp,'max_tokens'=>$maxTokens,'messages'=>[['role'=>'system','content'=>$sysprompt],['role'=>'user','content'=>$userprompt]]]);
            break;
        case 'anthropic':
            curl_setopt($ch,CURLOPT_URL,'https://api.anthropic.com/v1/messages');
            $headers[]="x-api-key: $apiKey"; $headers[]='anthropic-version: 2023-06-01';
            $body=json_encode(['model'=>$model,'max_tokens'=>$maxTokens,'temperature'=>$temp,'system'=>$sysprompt,'messages'=>[['role'=>'user','content'=>$userprompt]]]);
            break;
        case 'gemini':
            curl_setopt($ch,CURLOPT_URL,"https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}");
            $body=json_encode(['contents'=>[['parts'=>[['text'=>$sysprompt."\n\n".$userprompt]]]],'generationConfig'=>['temperature'=>$temp,'maxOutputTokens'=>$maxTokens]]);
            break;
        default: return ['ok'=>false,'message'=>'不明なプロバイダー'];
    }
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true]);
    $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($err) return ['ok'=>false,'message'=>'cURLエラー: '.$err];
    $d=json_decode($resp,true);
    if($code!==200) return ['ok'=>false,'message'=>"APIエラー({$code}): ".($d['error']['message']??$resp)];
    $text=match($provider){'openai'=>$d['choices'][0]['message']['content']??'','anthropic'=>$d['content'][0]['text']??'','gemini'=>$d['candidates'][0]['content']['parts'][0]['text']??'',default=>''};
    return ['ok'=>true,'text'=>trim($text)];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>投稿作成 | LINE Harness</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{--bg:#080d16;--surface:#0f1923;--surface2:#172032;--surface3:#1e2d40;--border:#1e3048;--text:#e2e8f0;--text2:#7c93ad;--text3:#4a6582;--accent:#3b82f6;--line:#06c755;--success:#10b981;--warning:#f59e0b;--danger:#ef4444;--purple:#8b5cf6;}
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
.content{padding:1.5rem;max-width:1200px;margin:0 auto;}
.page-title{font-size:1.3rem;font-weight:700;margin-bottom:1.25rem;display:flex;align-items:center;gap:10px;}
.layout{display:grid;grid-template-columns:1fr 360px;gap:1.25rem;align-items:start;}
@media(max-width:960px){.layout{grid-template-columns:1fr;}}
.card-box{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1rem;}
.card-head{background:var(--surface2);padding:.75rem 1.1rem;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);font-size:.88rem;font-weight:600;}
.card-head-l{display:flex;align-items:center;gap:8px;}
.card-body{padding:1.2rem 1.3rem;}
.field-label{font-size:.78rem;color:var(--text2);margin-bottom:5px;display:flex;align-items:center;gap:6px;}
.field-input,.field-select{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:.6rem .85rem;font-size:.85rem;outline:none;transition:border-color .15s;font-family:inherit;}
.field-input:focus,.field-select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.15);}
.field-select option{background:var(--surface);}
.post-textarea{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:1rem;font-size:.93rem;outline:none;transition:border-color .15s;font-family:inherit;resize:vertical;min-height:220px;line-height:1.7;}
.post-textarea:focus{border-color:var(--line);box-shadow:0 0 0 3px rgba(6,199,85,.15);}
.char-count{font-size:.72rem;color:var(--text3);text-align:right;margin-top:4px;}
.char-over{color:var(--danger);}
.hint-input{width:100%;background:var(--surface3);border:1px solid var(--border);color:var(--text2);border-radius:8px;padding:.55rem .85rem;font-size:.82rem;outline:none;transition:.15s;font-family:inherit;}
.hint-input:focus{border-color:var(--accent);color:var(--text);}
.btn-ai{background:linear-gradient(135deg,#7c3aed,#4f46e5);border:none;color:#fff;padding:.7rem 1.2rem;border-radius:8px;font-size:.87rem;font-weight:700;cursor:pointer;transition:opacity .15s;display:inline-flex;align-items:center;gap:7px;width:100%;}
.btn-ai:hover{opacity:.9;}.btn-ai:disabled{opacity:.5;cursor:not-allowed;}
.btn-queue{background:linear-gradient(135deg,#06c755,#059669);border:none;color:#fff;padding:.7rem 1.2rem;border-radius:8px;font-size:.87rem;font-weight:700;cursor:pointer;transition:opacity .15s;display:inline-flex;align-items:center;justify-content:center;gap:7px;width:100%;}
.btn-queue:hover{opacity:.9;}.btn-queue:disabled{opacity:.5;cursor:not-allowed;}
.btn-secondary{background:transparent;border:1px solid var(--border);color:var(--text2);padding:.55rem .9rem;border-radius:8px;font-size:.82rem;cursor:pointer;transition:.15s;display:inline-flex;align-items:center;gap:5px;}
.btn-secondary:hover{border-color:var(--accent);color:var(--accent);}
.divider{border:none;border-top:1px solid var(--border);margin:.9rem 0;}
/* Preview */
.line-preview{background:#1a2741;border:1px solid var(--border);border-radius:12px;padding:1rem;min-height:120px;}
.preview-bubble{background:#06c755;color:#fff;border-radius:18px 18px 18px 4px;padding:.75rem 1rem;font-size:.88rem;line-height:1.7;white-space:pre-wrap;word-break:break-all;display:inline-block;max-width:100%;box-shadow:0 2px 8px rgba(0,0,0,.3);}
.preview-media{margin-top:.5rem;}
.preview-media img{border-radius:10px;max-width:100%;max-height:160px;object-fit:cover;}
.preview-empty{color:var(--text3);font-size:.8rem;text-align:center;padding:2rem 0;}
/* History */
.hist-item{border-bottom:1px solid var(--border);padding:.65rem 0;cursor:pointer;transition:.1s;}
.hist-item:last-child{border-bottom:none;}
.hist-item:hover{padding-left:4px;}
.hist-text{font-size:.8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2);margin-bottom:2px;}
.hist-meta{font-size:.68rem;color:var(--text3);display:flex;gap:8px;}
.bs-sent{color:#06c755;}.bs-pending{color:#f59e0b;}.bs-failed{color:#ef4444;}
/* Pattern chip */
.pattern-chip{display:inline-flex;align-items:center;gap:4px;background:var(--surface3);border:1px solid var(--border);border-radius:6px;padding:3px 9px;font-size:.72rem;cursor:pointer;margin:2px;transition:.12s;color:var(--text2);}
.pattern-chip:hover{border-color:var(--accent);color:var(--accent);}
/* Toast */
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
  <p class="login-title">LINE Harness</p><p class="login-sub">投稿作成 — ログイン</p>
  <?php if (!empty($login_error)): ?><div class="alert-err"><?= htmlspecialchars($login_error,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <form method="post"><label class="lf-label">管理パスワード</label><input type="password" name="login_pass" class="lf-input" autofocus><button type="submit" class="login-btn">ログイン</button></form>
</div></div>
<?php else: ?>

<nav class="nav-top">
  <a href="./" class="nav-brand"><div class="brand-icon">L</div><div><div class="brand-name">LINE Harness</div><div class="brand-sub">BOSS System</div></div></a>
  <div class="nav-links">
    <a href="./" class="nav-link-btn"><i class="bi bi-grid-1x2"></i> ダッシュボード</a>
    <span class="nav-link-btn active"><i class="bi bi-pencil-square"></i> 投稿作成</span>
    <a href="./ai_settings.php" class="nav-link-btn"><i class="bi bi-cpu"></i> AI設定</a>
    <a href="./keyword_settings.php" class="nav-link-btn"><i class="bi bi-tags"></i> キーワード</a>
  </div>
  <div class="nav-right">
    <form method="post"><button name="logout" class="btn-secondary" style="padding:5px 12px"><i class="bi bi-box-arrow-right"></i></button></form>
  </div>
</nav>

<div class="content">
  <div class="page-title"><i class="bi bi-pencil-square" style="color:var(--line)"></i> 投稿作成 &amp; AI生成</div>

  <div class="layout">
    <!-- Left: Editor -->
    <div>
      <!-- AI生成 -->
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-l"><i class="bi bi-robot" style="color:var(--purple)"></i> AIで投稿文を生成</div>
          <span id="ai-model-label" style="font-size:.72rem;color:var(--text3)">読込中…</span>
        </div>
        <div class="card-body">
          <div style="margin-bottom:.75rem">
            <label class="field-label"><i class="bi bi-lightbulb"></i> 生成ヒント（省略可）</label>
            <input type="text" id="ai-hint" class="hint-input" placeholder="例: 今週の新入荷のレザーバッグを紹介、夏のセール告知、会員限定クーポン案内">
          </div>
          <button class="btn-ai" id="btn-generate" onclick="generatePost()">
            <i class="bi bi-stars"></i> AIで投稿文を生成
          </button>
          <div id="gen-error" style="margin-top:.6rem;font-size:.78rem;color:var(--danger);display:none"></div>
        </div>
      </div>

      <!-- 本文エディター -->
      <div class="card-box">
        <div class="card-head">
          <div class="card-head-l"><i class="bi bi-chat-text" style="color:var(--line)"></i> 投稿本文</div>
          <button class="btn-secondary" style="padding:3px 9px;font-size:.75rem" onclick="clearEditor()"><i class="bi bi-trash"></i> クリア</button>
        </div>
        <div class="card-body">
          <textarea id="post-body" class="post-textarea" placeholder="ここに投稿テキストを入力、またはAIで生成してください。" oninput="updatePreview();updateCharCount()"></textarea>
          <div class="char-count"><span id="char-num">0</span> 文字</div>

          <div class="divider"></div>

          <div style="margin-bottom:.75rem">
            <label class="field-label"><i class="bi bi-image"></i> メディアURL（省略可）</label>
            <input type="url" id="post-media" class="field-input" placeholder="https://example.com/image.jpg" oninput="updatePreview()">
          </div>
          <div style="margin-bottom:.9rem">
            <label class="field-label"><i class="bi bi-tags"></i> キーワードパターンから選択</label>
            <div id="pattern-chips" style="margin-top:4px"><span style="font-size:.75rem;color:var(--text3)">読込中…</span></div>
          </div>

          <div style="margin-bottom:.75rem">
            <label class="field-label"><i class="bi bi-grid"></i> 投稿モード</label>
            <select id="post-mode" class="field-select" style="max-width:200px">
              <option value="manual">手動 (manual)</option>
              <option value="shopify">Shopify</option>
              <option value="reward">リワード</option>
              <option value="ai_auto">AI自動</option>
            </select>
          </div>

          <button class="btn-queue" id="btn-queue" onclick="queuePost()">
            <i class="bi bi-send-fill"></i> LINE キューに追加
          </button>
        </div>
      </div>
    </div>

    <!-- Right: Preview + History -->
    <div>
      <!-- LINEプレビュー -->
      <div class="card-box">
        <div class="card-head"><div class="card-head-l"><i class="bi bi-phone" style="color:var(--line)"></i> LINEプレビュー</div></div>
        <div class="card-body" style="background:#0d1f30">
          <div class="line-preview">
            <div id="preview-content"><div class="preview-empty">プレビューなし</div></div>
          </div>
        </div>
      </div>

      <!-- 最近の投稿 -->
      <div class="card-box">
        <div class="card-head"><div class="card-head-l"><i class="bi bi-clock-history" style="color:var(--warning)"></i> 直近の投稿</div>
          <button class="btn-secondary" style="padding:3px 9px;font-size:.72rem" onclick="loadHistory()"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <div class="card-body" style="padding:.5rem .9rem">
          <div id="history-list"><div style="text-align:center;padding:1.5rem;color:var(--text3);font-size:.8rem">読込中…</div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<script>
'use strict';
function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function updatePreview(){
  const text  = document.getElementById('post-body').value;
  const media = document.getElementById('post-media').value.trim();
  const wrap  = document.getElementById('preview-content');
  if (!text && !media){ wrap.innerHTML='<div class="preview-empty">プレビューなし</div>'; return; }
  let html = '';
  if (text) html += `<div class="preview-bubble">${esc(text)}</div>`;
  if (media) {
    const ext = media.split('.').pop().toLowerCase();
    if (['jpg','jpeg','png','gif','webp'].includes(ext))
      html += `<div class="preview-media"><img src="${esc(media)}" onerror="this.style.display='none'"></div>`;
    else if (['mp4','mov'].includes(ext))
      html += `<div class="preview-media" style="padding:.5rem;background:var(--surface3);border-radius:8px;font-size:.78rem;color:var(--text2)"><i class="bi bi-film me-1"></i>動画: ${esc(media)}</div>`;
  }
  wrap.innerHTML = html;
}

function updateCharCount(){
  const n = document.getElementById('post-body').value.length;
  const el = document.getElementById('char-num');
  el.textContent = n;
  el.parentElement.className = n > 500 ? 'char-count char-over' : 'char-count';
}

function clearEditor(){ document.getElementById('post-body').value=''; document.getElementById('post-media').value=''; updatePreview(); updateCharCount(); }

async function generatePost(){
  const btn = document.getElementById('btn-generate');
  const errEl = document.getElementById('gen-error');
  const hint = document.getElementById('ai-hint').value.trim();
  btn.disabled=true; btn.innerHTML='<span class="spinner">⟳</span> 生成中…';
  errEl.style.display='none';
  try {
    const r=await fetch('./post_editor.php?api=generate',{method:'POST',headers:{'Content-Type':'application/json;charset=utf-8'},body:JSON.stringify({hint})});
    const d=await r.json();
    if(d.ok){
      document.getElementById('post-body').value=d.text;
      updatePreview(); updateCharCount();
      toast('AIが投稿文を生成しました','ok');
    } else {
      errEl.textContent=d.message; errEl.style.display='block';
    }
  } catch(e){ errEl.textContent='通信エラー'; errEl.style.display='block'; }
  btn.disabled=false; btn.innerHTML='<i class="bi bi-stars"></i> AIで投稿文を生成';
}

async function queuePost(){
  const text  = document.getElementById('post-body').value.trim();
  const media = document.getElementById('post-media').value.trim();
  const mode  = document.getElementById('post-mode').value;
  if(!text){ toast('本文を入力してください','err'); return; }
  const btn=document.getElementById('btn-queue');
  btn.disabled=true; btn.innerHTML='<span class="spinner">⟳</span> 追加中…';
  try {
    const r=await fetch('./post_editor.php?api=queue',{method:'POST',headers:{'Content-Type':'application/json;charset=utf-8'},body:JSON.stringify({body_text:text,media_url:media,mode})});
    const d=await r.json();
    toast(d.message, d.ok?'ok':'err');
    if(d.ok){ clearEditor(); loadHistory(); }
  } catch(e){ toast('追加失敗','err'); }
  btn.disabled=false; btn.innerHTML='<i class="bi bi-send-fill"></i> LINE キューに追加';
}

async function loadHistory(){
  const el=document.getElementById('history-list');
  try {
    const r=await fetch('./post_editor.php?api=history'); const d=await r.json();
    if(!d.ok||!d.data.length){ el.innerHTML='<div style="text-align:center;padding:1.5rem;color:var(--text3);font-size:.8rem">投稿履歴なし</div>'; return; }
    el.innerHTML=d.data.map(row=>{
      const preview=(row.body_text??'').replace(/\n/g,' ').substring(0,50);
      const t=row.sent_at||row.queued_at||'';
      const statusClass={sent:'bs-sent',pending:'bs-pending',processing:'bs-pending',failed:'bs-failed'}[row.status]||'';
      return `<div class="hist-item" onclick="loadFromHistory(${JSON.stringify(esc(row.body_text))})">
        <div class="hist-text">${esc(preview)}${(row.body_text?.length??0)>50?'…':''}</div>
        <div class="hist-meta"><span class="${statusClass}">${esc(row.status)}</span><span>${esc(t.substring(0,16))}</span></div>
      </div>`;
    }).join('');
  } catch(e){ el.innerHTML='<div style="text-align:center;padding:1rem;color:var(--text3);font-size:.8rem">読込エラー</div>'; }
}

function loadFromHistory(text){
  document.getElementById('post-body').value=text;
  updatePreview(); updateCharCount();
  document.getElementById('post-body').scrollIntoView({behavior:'smooth',block:'center'});
}

async function loadPatterns(){
  try {
    const r=await fetch('./post_editor.php?api=patterns'); const d=await r.json();
    const wrap=document.getElementById('pattern-chips');
    if(!d.ok||!d.data.length){ wrap.innerHTML='<span style="font-size:.75rem;color:var(--text3)">キーワードパターン未登録</span>'; return; }
    wrap.innerHTML=d.data.map(p=>`<span class="pattern-chip" onclick="applyPattern(${JSON.stringify(esc(p.asset_url))})">
      ${p.asset_type==='video'?'🎬':'🖼'} ${esc(p.keyword)}</span>`).join('');
  } catch(e){}
}

function applyPattern(url){
  document.getElementById('post-media').value=url;
  updatePreview();
}

async function loadAiLabel(){
  try {
    const r=await fetch('./ai_settings.php?api=get'); const d=await r.json();
    if(d.ok){
      const s=d.data;
      const pname={openai:'OpenAI',anthropic:'Claude',gemini:'Gemini'}[s.ai_provider]||s.ai_provider;
      document.getElementById('ai-model-label').textContent=`${pname} / ${s.ai_model||'─'}`;
    }
  } catch(e){}
}

function toast(msg,type='info'){
  const wrap=document.getElementById('toast-wrap');
  const icons={ok:'bi-check-circle-fill',err:'bi-x-circle-fill',info:'bi-info-circle-fill'};
  const colors={ok:'var(--success)',err:'var(--danger)',info:'var(--accent)'};
  const el=document.createElement('div'); el.className='toast-item toast-'+type;
  el.innerHTML=`<i class="bi ${icons[type]}" style="color:${colors[type]};font-size:1.1rem;flex-shrink:0"></i><span>${esc(msg)}</span>`;
  wrap.appendChild(el);
  setTimeout(()=>el.style.cssText+='opacity:0;transition:.3s;transform:translateX(20px)',3500);
  setTimeout(()=>el.remove(),3900);
}

loadHistory(); loadPatterns(); loadAiLabel();
</script>
<?php endif; ?>
</body>
</html>

<?php
// =====================================================================
// keyword_settings.php  -  キーワードパターン設定UI
// 機能3: テキスト自動解析→パターン管理（追加/編集/削除/ドロップダウン選択）
// =====================================================================
session_start();
require_once __DIR__ . '/config.php';

// ── 認証 ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pass'])) {
    if ($_POST['login_pass'] === SETTINGS_PASSWORD) {
        $_SESSION['boss_auth'] = true;
    } else {
        $login_error = 'パスワードが違います';
    }
}
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
$auth = !empty($_SESSION['boss_auth']);

// ── DB接続 ───────────────────────────────────────────────────────────
function pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
             . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ── AJAX API ─────────────────────────────────────────────────────────
if ($auth && isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = $_GET['api'];

    // --- 一覧取得 ---
    if ($api === 'list') {
        $rows = pdo()->query('SELECT * FROM keyword_patterns ORDER BY id')->fetchAll();
        echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- 追加/更新 ---
    if ($api === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $kw  = trim($_POST['keyword']   ?? '');
        $url = trim($_POST['asset_url'] ?? '');
        $cat = trim($_POST['category']  ?? '');
        if (!$kw || !$url) {
            echo json_encode(['ok' => false, 'message' => 'キーワードとURLは必須です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $st = pdo()->prepare(
                'INSERT INTO keyword_patterns (keyword, asset_url, category)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE asset_url=VALUES(asset_url), category=VALUES(category), updated_at=NOW()'
            );
            $st->execute([$kw, $url, $cat]);
            echo json_encode(['ok' => true, 'message' => "「{$kw}」を保存しました"], JSON_UNESCAPED_UNICODE);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // --- 削除 ---
    if ($api === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) { echo json_encode(['ok' => false, 'message' => '不正なID']); exit; }
        pdo()->prepare('DELETE FROM keyword_patterns WHERE id=?')->execute([$id]);
        echo json_encode(['ok' => true, 'message' => '削除しました'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- テキスト解析 ---
    if ($api === 'extract' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $text = $_POST['text'] ?? '';

        // 既存パターンとの照合
        $existing = pdo()->query('SELECT keyword FROM keyword_patterns')->fetchAll(PDO::FETCH_COLUMN);
        $found_existing = array_values(array_filter($existing, fn($kw) => mb_strpos($text, $kw) !== false));

        // 日本語キーワード候補の抽出（CJK統合漢字・ひらがな・カタカナ 2〜6文字）
        preg_match_all(
            '/(?:[一-龯ぁ-んァ-ン々〆〤ヴヵヶ]{2,6})/u',
            $text,
            $m
        );
        $candidates = array_unique($m[0] ?? []);

        // 一般的なストップワードを除外
        $stopwords = [
            'する','です','ます','した','ある','ない','なる','こと','もの',
            'ため','から','まで','など','また','でも','けど','ので','のに',
            'これ','その','この','それ','あの','どの','私ども','皆様','ご紹介',
            'ください','いただき','ございます','ぜひ',
        ];
        $candidates = array_values(array_diff($candidates, $existing, $stopwords));
        $candidates = array_slice($candidates, 0, 24);

        echo json_encode([
            'ok'              => true,
            'found_existing'  => $found_existing,
            'new_candidates'  => $candidates,
        ], JSON_UNESCAPED_UNICODE);
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
<title>キーワードパターン設定 | BOSS System</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{--bg:#0d1117;--card:#161b22;--border:#30363d;--accent:#58a6ff;--danger:#f85149;--gold:#e3b341;}
  body{background:var(--bg);color:#c9d1d9;font-size:.92rem;}
  .navbar-brand{color:var(--gold)!important;font-weight:700;letter-spacing:.04em;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:8px;}
  .card-header{background:#1c2128;border-bottom:1px solid var(--border);color:var(--accent);}
  .form-control,.form-select{background:#0d1117!important;color:#c9d1d9!important;border-color:var(--border)!important;}
  .form-control:focus,.form-select:focus{border-color:var(--accent)!important;box-shadow:0 0 0 3px rgba(88,166,255,.15)!important;}
  .table{color:#c9d1d9;}
  .table thead th{background:#1c2128;color:var(--accent);border-color:var(--border);font-weight:500;}
  .table tbody tr{border-color:var(--border);}
  .table tbody tr:hover{background:#1c2128;}
  .btn-primary{background:var(--accent);border-color:var(--accent);color:#0d1117!important;font-weight:600;}
  .btn-primary:hover{background:#79b8ff;border-color:#79b8ff;}
  .btn-danger{background:var(--danger);border-color:var(--danger);}
  .badge-cat{background:#21262d;border:1px solid var(--border);color:#8b949e;font-size:.75em;padding:2px 8px;border-radius:20px;}
  .chip{display:inline-block;padding:4px 14px;margin:3px;border-radius:20px;cursor:pointer;
        border:1px solid var(--border);background:#21262d;font-size:.82em;transition:.15s;}
  .chip:hover{border-color:var(--accent);color:var(--accent);}
  .chip.matched{border-color:var(--gold);color:var(--gold);background:#2a2209;}
  .chip.candidate{border-color:var(--accent);color:#c9d1d9;}
  .chip.candidate:hover{background:#1c2128;}
  .login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;}
  .url-cell{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .flash{display:none;animation:fadein .3s ease;}
  @keyframes fadein{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
</style>
</head>
<body>

<?php if (!$auth): ?>
<!-- ═══ ログイン画面 ═══ -->
<div class="login-wrap">
  <div style="width:340px">
    <div class="text-center mb-4">
      <i class="bi bi-shield-lock-fill" style="font-size:2.5rem;color:var(--gold)"></i>
      <h5 class="mt-2 text-white">BOSS System</h5>
      <small class="text-secondary">キーワード設定管理</small>
    </div>
    <div class="card p-4">
      <?php if (!empty($login_error)): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($login_error) ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label class="form-label text-secondary small">管理パスワード</label>
          <input type="password" name="login_pass" class="form-control" autofocus>
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> ログイン</button>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══ メイン設定画面 ═══ -->
<nav class="navbar px-4 py-2" style="background:#161b22;border-bottom:1px solid #30363d">
  <span class="navbar-brand"><i class="bi bi-tags-fill me-1"></i> BOSS System – キーワード設定</span>
  <form method="post" class="d-inline">
    <button name="logout" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-arrow-right"></i> ログアウト
    </button>
  </form>
</nav>

<div class="container-fluid px-4 py-4">
  <div id="flash" class="flash alert py-2 small mb-3"></div>

  <div class="row g-4">

    <!-- ─── 左: パターン一覧 ─── -->
    <div class="col-xl-7">
      <div class="card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-list-ul me-1"></i> 登録済みキーワードパターン</span>
          <span class="badge bg-secondary" id="cnt">─</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th style="width:130px">キーワード</th>
                  <th>アセットURL</th>
                  <th style="width:100px">カテゴリ</th>
                  <th style="width:70px"></th>
                </tr>
              </thead>
              <tbody id="kw-tbody">
                <tr><td colspan="4" class="text-center text-secondary py-4">読み込み中…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ─── 右: 操作パネル ─── -->
    <div class="col-xl-5">

      <!-- テキスト解析 -->
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-search me-1"></i> テキストからキーワードを自動抽出</div>
        <div class="card-body">
          <textarea id="ana-text" class="form-control mb-2" rows="4"
            placeholder="投稿テキストをここに貼り付け…&#10;（AI生成文、商品説明文など）"></textarea>
          <button class="btn btn-outline-info btn-sm w-100 mb-3" onclick="analyzeText()">
            <i class="bi bi-cpu me-1"></i> 解析する
          </button>
          <div id="ana-result" style="display:none">
            <p class="text-secondary small mb-1">🟡 既存パターンと一致したキーワード：</p>
            <div id="ana-existing" class="mb-3"></div>
            <p class="text-secondary small mb-1">🔵 新規候補（クリックで追加フォームへ）：</p>
            <div id="ana-candidates"></div>
          </div>
        </div>
      </div>

      <!-- 追加/編集フォーム -->
      <div class="card">
        <div class="card-header"><i class="bi bi-plus-circle me-1"></i> パターンを追加・更新</div>
        <div class="card-body">

          <!-- ドロップダウン: 既存パターン選択 -->
          <div class="mb-3">
            <label class="form-label text-secondary small">
              <i class="bi bi-chevron-down"></i> 既存パターンを選んで編集
            </label>
            <select id="sel-existing" class="form-select" onchange="fillFromDropdown()">
              <option value="">── 新規追加 ──</option>
            </select>
          </div>

          <div class="mb-2">
            <label class="form-label text-secondary small">
              キーワード <span class="text-danger">*</span>
            </label>
            <input id="f-kw"  type="text" class="form-control" placeholder="例: レザー">
          </div>
          <div class="mb-2">
            <label class="form-label text-secondary small">
              アセットURL <span class="text-danger">*</span>
            </label>
            <input id="f-url" type="text" class="form-control"
              placeholder="https://boss-store.com/assets/media/…">
          </div>
          <div class="mb-3">
            <label class="form-label text-secondary small">カテゴリ</label>
            <input id="f-cat" type="text" class="form-control"
              placeholder="例: leather / golf / apparel">
          </div>
          <button class="btn btn-primary w-100" onclick="savePattern()">
            <i class="bi bi-save me-1"></i> 保存する
          </button>
          <button class="btn btn-outline-secondary w-100 mt-2 btn-sm" onclick="clearForm()">
            クリア
          </button>
        </div>
      </div>
    </div>

  </div><!-- /row -->
</div><!-- /container -->

<script>
const Q = s => document.querySelector(s);
const API = a => `${location.pathname}?api=${a}`;

// ── パターン一覧を読み込む ────────────────────────────────────────
async function loadPatterns() {
  const res  = await fetch(API('list'));
  const data = await res.json();
  if (!data.ok) return;
  const rows = data.data;
  document.getElementById('cnt').textContent = rows.length + ' 件';

  const tbody = Q('#kw-tbody');
  const sel   = Q('#sel-existing');
  tbody.innerHTML = '';
  sel.innerHTML   = '<option value="">── 新規追加 ──</option>';

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary py-4">登録なし</td></tr>';
    return;
  }
  rows.forEach(r => {
    const short = r.asset_url.length > 52 ? r.asset_url.slice(0,52)+'…' : r.asset_url;
    tbody.insertAdjacentHTML('beforeend', `
      <tr>
        <td><strong>${e(r.keyword)}</strong></td>
        <td class="url-cell" title="${e(r.asset_url)}"><small>${e(short)}</small></td>
        <td><span class="badge-cat">${e(r.category||'─')}</span></td>
        <td>
          <button class="btn btn-danger btn-sm py-0 px-1"
            onclick="deletePattern(${r.id},'${e(r.keyword)}')" title="削除">
            <i class="bi bi-trash3"></i>
          </button>
        </td>
      </tr>`);
    sel.insertAdjacentHTML('beforeend',
      `<option value="${r.id}" data-kw="${e(r.keyword)}" data-url="${e(r.asset_url)}" data-cat="${e(r.category||'')}">${e(r.keyword)}</option>`);
  });
}

// ── ドロップダウンで既存パターンを選択 → フォームに反映 ──────────
function fillFromDropdown() {
  const opt = Q('#sel-existing option:checked');
  Q('#f-kw').value  = opt.dataset.kw  || '';
  Q('#f-url').value = opt.dataset.url || '';
  Q('#f-cat').value = opt.dataset.cat || '';
}

// ── パターンを保存 ───────────────────────────────────────────────
async function savePattern() {
  const kw  = Q('#f-kw').value.trim();
  const url = Q('#f-url').value.trim();
  const cat = Q('#f-cat').value.trim();
  if (!kw || !url) { flash('キーワードとURLを入力してください', 'danger'); return; }
  const fd = new FormData();
  fd.append('keyword', kw); fd.append('asset_url', url); fd.append('category', cat);
  const res  = await fetch(API('save'), { method:'POST', body:fd });
  const data = await res.json();
  flash(data.message, data.ok ? 'success' : 'danger');
  if (data.ok) { loadPatterns(); clearForm(); }
}

// ── パターンを削除 ───────────────────────────────────────────────
async function deletePattern(id, kw) {
  if (!confirm(`「${kw}」を削除しますか？`)) return;
  const fd = new FormData(); fd.append('id', id);
  const res  = await fetch(API('delete'), { method:'POST', body:fd });
  const data = await res.json();
  if (data.ok) { loadPatterns(); flash(data.message, 'success'); }
}

// ── テキスト解析 ─────────────────────────────────────────────────
async function analyzeText() {
  const text = Q('#ana-text').value;
  if (!text.trim()) { flash('テキストを入力してください', 'warning'); return; }
  const fd = new FormData(); fd.append('text', text);
  const res  = await fetch(API('extract'), { method:'POST', body:fd });
  const data = await res.json();

  Q('#ana-result').style.display = 'block';

  const fe = Q('#ana-existing');
  fe.innerHTML = data.found_existing.length
    ? data.found_existing.map(k => `<span class="chip matched">${e(k)}</span>`).join('')
    : '<span class="text-secondary small">一致なし</span>';

  const nc = Q('#ana-candidates');
  nc.innerHTML = data.new_candidates.length
    ? data.new_candidates.map(k =>
        `<span class="chip candidate" onclick="setKeyword('${e(k)}')">${e(k)}</span>`).join('')
    : '<span class="text-secondary small">候補なし</span>';
}

// ── チップクリック → キーワードフィールドへ ──────────────────────
function setKeyword(kw) {
  Q('#f-kw').value = kw;
  Q('#sel-existing').value = '';
  Q('#f-kw').focus();
}

// ── ヘルパー ─────────────────────────────────────────────────────
function clearForm() {
  ['#f-kw','#f-url','#f-cat'].forEach(id => Q(id).value='');
  Q('#sel-existing').value='';
}
function flash(msg, type) {
  const el = Q('#flash');
  el.className = `flash alert alert-${type} py-2 small mb-3`;
  el.textContent = msg;
  el.style.display = 'block';
  clearTimeout(el._t);
  el._t = setTimeout(() => { el.style.display='none'; }, 4000);
}
function e(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                  .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// 初期ロード
loadPatterns();
</script>
<?php endif; ?>
</body>
</html>

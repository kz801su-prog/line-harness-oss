<?php
// ============================================================
// LINE Harness + BOSS System  /  config.php
// XServerの管理画面で取得した値に書き換えてください
// ============================================================

// ── MySQL ────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    3306);
define('DB_USER',    'YOUR_MYSQL_USER');      // 例: kz801xs_boss
define('DB_PASS',    'YOUR_MYSQL_PASSWORD');
define('DB_NAME',    'YOUR_DATABASE_NAME');   // 例: kz801xs_boss_db
define('DB_CHARSET', 'utf8mb4');

// ── LINE Messaging API ────────────────────────────────────────
// LINE Developers > チャネル基本設定 > チャネルアクセストークン(長期)
define('LINE_TOKEN',         'YOUR_LINE_CHANNEL_ACCESS_TOKEN');
define('LINE_API_BROADCAST', 'https://api.line.me/v2/bot/message/broadcast');
define('LINE_API_INFO',      'https://api.line.me/v2/bot/info');

// ── 認証 ──────────────────────────────────────────────────────
// boss_auto_post.py の HARNESS_API_KEY と同じ値にする
define('HARNESS_API_KEY',  'YOUR_HARNESS_API_KEY_CHANGE_THIS');
// ダッシュボード / キーワード設定 のログインパスワード
define('ADMIN_PASSWORD',   'BOSS_LINE_2026');

// ── ワーカー設定 ──────────────────────────────────────────────
define('WORKER_BATCH',   5);  // 1回に処理する最大件数
define('MAX_RETRY',      3);  // 送信失敗時の最大リトライ回数

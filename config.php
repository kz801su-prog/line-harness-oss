<?php
// =====================================================================
// config.php  -  BOSS System データベース・認証設定
// XServerの「MySQL管理」で確認した値に変更してください
// このファイルはWebから直接アクセスできないよう .htaccess で保護済み
// =====================================================================

// XServer MySQL 接続情報
define('DB_HOST',    'localhost');
define('DB_PORT',    3306);
define('DB_USER',    'kz801xs_692');      // 例: kz801xs_boss
define('DB_PASS',    'NA58wngrLfVHtK');
define('DB_NAME',    'kz801xs_line');   // 例: kz801xs_boss_db
define('DB_CHARSET', 'utf8mb4');

// 設定画面のアクセスパスワード（強力なパスワードに変更してください）
define('SETTINGS_PASSWORD', 'BOSS_SETTINGS_2026');

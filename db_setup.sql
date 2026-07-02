-- =====================================================================
-- db_setup.sql  -  BOSS自動投稿システム MySQL セットアップ
-- 実行方法: XServerのphpMyAdminから貼り付けて実行
-- =====================================================================

-- ① 投稿履歴テーブル
CREATE TABLE IF NOT EXISTS `post_history` (
    `post_id`         VARCHAR(50)   NOT NULL,
    `mode`            VARCHAR(20)   DEFAULT NULL,
    `body_text`       TEXT          DEFAULT NULL,
    `media_url`       TEXT          DEFAULT NULL,
    `created_at`      DATETIME      DEFAULT NULL,
    `queued_at`       DATETIME      DEFAULT NULL,
    `likes`           INT           DEFAULT 0,
    `impressions`     INT           DEFAULT 0,
    `clicks`          INT           DEFAULT 0,
    `shares`          INT           DEFAULT 0,
    `engagement_rate` FLOAT         DEFAULT 0.0,
    `updated_at`      DATETIME      DEFAULT NULL,
    PRIMARY KEY (`post_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ② プロンプト変更履歴テーブル（AI自己最適化ログ）
CREATE TABLE IF NOT EXISTS `prompt_history` (
    `version`     INT          NOT NULL AUTO_INCREMENT,
    `prompt_text` TEXT         DEFAULT NULL,
    `reason`      TEXT         DEFAULT NULL,
    `applied_at`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ③ キーワードパターンテーブル（PHP設定画面で管理）
CREATE TABLE IF NOT EXISTS `keyword_patterns` (
    `id`          INT           NOT NULL AUTO_INCREMENT,
    `keyword`     VARCHAR(100)  NOT NULL,
    `asset_url`   TEXT          NOT NULL,
    `category`    VARCHAR(50)   DEFAULT NULL,
    `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- デフォルトキーワードパターン（初期データ）
INSERT IGNORE INTO `keyword_patterns` (keyword, asset_url, category) VALUES
('レザー',    'https://boss-store.com/assets/media/antique_leather_dark.mp4', 'leather'),
('バッグ',    'https://boss-store.com/assets/media/leather_bag_hero.jpg',     'bag'),
('ジャケット', 'https://boss-store.com/assets/media/fabric_closeup.jpg',      'apparel'),
('ゴルフ',    'https://boss-store.com/assets/media/golf_course_sunset.jpg',   'golf'),
('職人',      'https://boss-store.com/assets/media/craftsman_workshop.jpg',   'brand');

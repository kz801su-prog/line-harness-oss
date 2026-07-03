-- ============================================================
-- LINE Harness + BOSS System  /  db_setup.sql
-- XServerのphpMyAdminで実行してください（全テーブルを一括作成）
-- ============================================================

-- ① メッセージキューテーブル（LINE Harness コア）
CREATE TABLE IF NOT EXISTS `message_queue` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `body_text`    TEXT          NOT NULL,
    `media_url`    TEXT          DEFAULT NULL,
    `media_type`   ENUM('image','video','none') DEFAULT 'none',
    `generated_by` VARCHAR(100)  DEFAULT NULL,
    `mode`         VARCHAR(20)   DEFAULT NULL,
    `status`       ENUM('pending','processing','sent','failed') DEFAULT 'pending',
    `retry_count`  INT           DEFAULT 0,
    `queued_at`    DATETIME      DEFAULT NULL,
    `sent_at`      DATETIME      DEFAULT NULL,
    `error_msg`    TEXT          DEFAULT NULL,
    `line_msg_id`  VARCHAR(200)  DEFAULT NULL,
    `recipients`   INT           DEFAULT 0,
    `created_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status`     (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ② 投稿履歴テーブル（BOSS System 機能2 - PDCAループ用）
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

-- ③ プロンプト変更履歴テーブル（BOSS System 機能2 - AI自己最適化ログ）
CREATE TABLE IF NOT EXISTS `prompt_history` (
    `version`     INT          NOT NULL AUTO_INCREMENT,
    `prompt_text` TEXT         DEFAULT NULL,
    `reason`      TEXT         DEFAULT NULL,
    `applied_at`  DATETIME     DEFAULT NULL,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ④ キーワードパターンテーブル（BOSS System 機能3 - keyword_settings.php で管理）
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

-- キーワードパターン初期データ
INSERT IGNORE INTO `keyword_patterns` (keyword, asset_url, category) VALUES
('レザー',     'https://boss-store.com/assets/media/antique_leather_dark.mp4', 'leather'),
('バッグ',     'https://boss-store.com/assets/media/leather_bag_hero.jpg',     'bag'),
('ジャケット', 'https://boss-store.com/assets/media/fabric_closeup.jpg',       'apparel'),
('ゴルフ',     'https://boss-store.com/assets/media/golf_course_sunset.jpg',   'golf'),
('職人',       'https://boss-store.com/assets/media/craftsman_workshop.jpg',   'brand');

-- ⑤ システム設定テーブル（AI設定・スケジュール設定を保存）
CREATE TABLE IF NOT EXISTS `system_settings` (
    `key`        VARCHAR(100) NOT NULL,
    `value`      TEXT         DEFAULT NULL,
    `updated_at` DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- デフォルト設定
INSERT IGNORE INTO `system_settings` (`key`, `value`) VALUES
('ai_provider',       'openai'),
('ai_model',          'gpt-4o'),
('ai_api_key',        ''),
('ai_system_prompt',  ''),
('ai_temperature',    '0.7'),
('ai_max_tokens',     '300'),
('post_topic',        ''),
('post_style',        '親しみやすく、商品の魅力を伝える'),
('post_hashtags',     ''),
('schedule_enabled',  '0'),
('schedule_hours',    '10,18'),
('schedule_days',     '1,2,3,4,5');

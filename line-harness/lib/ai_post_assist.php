<?php
declare(strict_types=1);

/**
 * Purpose: AI設定画面のサジェスト/承認モードが AI へ渡す指示文を組み立てる。
 * Connected to: line-harness/ai_settings.php の suggest_style / approve_style API。
 */
function build_style_suggestion_prompt(array $context): string
{
    $topic = trim((string)($context['topic'] ?? ''));
    $currentStyle = trim((string)($context['current_style'] ?? ''));
    $hashtags = trim((string)($context['hashtags'] ?? ''));
    $systemPrompt = trim((string)($context['system_prompt'] ?? ''));
    $mode = trim((string)($context['mode'] ?? 'preview'));

    return implode("\n", [
        '次の情報を参考に、LINE投稿向けの投稿スタイル設定をJSONで提案してください。',
        'mode=' . $mode,
        '必ず {"post_topic":"","post_style":"","post_hashtags":"","ai_system_prompt":"","approval_comment":""} のJSONだけを返してください。',
        'post_topic は商品カテゴリやテーマを簡潔に整理してください。',
        'post_style は文体・トーンを1文でまとめてください。',
        'post_hashtags は空白区切りで返してください。',
        'ai_system_prompt はそのまま保存できる実用的な文章にしてください。',
        'approval_comment は人が承認判断しやすい短い説明にしてください。',
        '現在のテーマ: ' . ($topic !== '' ? $topic : '未設定'),
        '現在の文体: ' . ($currentStyle !== '' ? $currentStyle : '未設定'),
        '現在のハッシュタグ: ' . ($hashtags !== '' ? $hashtags : '未設定'),
        '現在のシステムプロンプト: ' . ($systemPrompt !== '' ? $systemPrompt : '未設定'),
    ]);
}

/**
 * Purpose: 投稿作成画面のハンズフリー生成で、本文案と素材案を同時に返す指示文を組み立てる。
 * Connected to: line-harness/post_editor.php の generate_handsfree API。
 */
function build_handsfree_post_prompt(array $context): string
{
    $hint = trim((string)($context['hint'] ?? ''));
    $imagePrompt = trim((string)($context['image_prompt'] ?? ''));
    $videoPrompt = trim((string)($context['video_prompt'] ?? ''));
    $imageReferences = array_values(array_filter(array_map('trim', (array)($context['image_reference_urls'] ?? []))));
    $videoReferences = array_values(array_filter(array_map('trim', (array)($context['video_reference_urls'] ?? []))));
    $theme = trim((string)($context['theme'] ?? ''));
    $style = trim((string)($context['style'] ?? ''));

    return implode("\n", [
        'LINE投稿の本文案と素材案をまとめて作成してください。',
        '必ず {"post_text":"","media_url":"","image_prompt":"","video_prompt":"","image_reference_urls":[],"video_reference_urls":[],"confirmation_points":[]} のJSONだけを返してください。',
        'media_url は既存URL案が無ければ空文字で返してください。',
        'confirmation_points には人が最終確認する短いチェック項目を配列で返してください。',
        '投稿テーマ: ' . ($theme !== '' ? $theme : '未設定'),
        '投稿スタイル: ' . ($style !== '' ? $style : '未設定'),
        '生成ヒント: ' . ($hint !== '' ? $hint : '指定なし'),
        '画像生成指示: ' . ($imagePrompt !== '' ? $imagePrompt : '指定なし'),
        '動画生成指示: ' . ($videoPrompt !== '' ? $videoPrompt : '指定なし'),
        '画像参考URL: ' . (!empty($imageReferences) ? implode(', ', $imageReferences) : 'なし'),
        '動画参考URL: ' . (!empty($videoReferences) ? implode(', ', $videoReferences) : 'なし'),
    ]);
}

/**
 * Purpose: AI の JSON 応答を投稿作成画面で扱える配列へ正規化する。
 * Connected to: line-harness/post_editor.php の generate_handsfree API とプレビュー反映処理。
 */
function normalize_handsfree_payload(string $raw): array
{
    $base = [
        'post_text' => '',
        'media_url' => '',
        'image_prompt' => '',
        'video_prompt' => '',
        'image_reference_urls' => [],
        'video_reference_urls' => [],
        'confirmation_points' => [],
    ];

    $trimmed = trim($raw);
    $jsonCandidate = preg_replace('/^```json\s*|\s*```$/u', '', $trimmed);
    $decoded = json_decode($jsonCandidate ?? '', true);

    if (!is_array($decoded)) {
        $base['post_text'] = $trimmed;
        return $base;
    }

    $base['post_text'] = trim((string)($decoded['post_text'] ?? ''));
    $base['media_url'] = trim((string)($decoded['media_url'] ?? ''));
    $base['image_prompt'] = trim((string)($decoded['image_prompt'] ?? ''));
    $base['video_prompt'] = trim((string)($decoded['video_prompt'] ?? ''));
    $base['image_reference_urls'] = normalize_string_list($decoded['image_reference_urls'] ?? []);
    $base['video_reference_urls'] = normalize_string_list($decoded['video_reference_urls'] ?? []);
    $base['confirmation_points'] = normalize_string_list($decoded['confirmation_points'] ?? []);

    return $base;
}

/**
 * Purpose: AI 応答の配列値を UI に安全に流せる文字列配列へ寄せる。
 * Connected to: ai_post_assist の normalize_handsfree_payload / style suggestion parsing。
 *
 * @return list<string>
 */
function normalize_string_list(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text !== '') {
            $normalized[] = $text;
        }
    }
    return $normalized;
}

/**
 * Purpose: AI設定向けの JSON 応答を設定保存しやすい配列へ正規化する。
 * Connected to: line-harness/ai_settings.php の suggest_style / approve_style API。
 */
function normalize_style_suggestion_payload(string $raw): array
{
    $base = [
        'post_topic' => '',
        'post_style' => '',
        'post_hashtags' => '',
        'ai_system_prompt' => '',
        'approval_comment' => '',
    ];

    $trimmed = trim($raw);
    $jsonCandidate = preg_replace('/^```json\s*|\s*```$/u', '', $trimmed);
    $decoded = json_decode($jsonCandidate ?? '', true);
    if (!is_array($decoded)) {
        $base['approval_comment'] = $trimmed;
        return $base;
    }

    foreach (array_keys($base) as $key) {
        $base[$key] = trim((string)($decoded[$key] ?? ''));
    }

    return $base;
}

/**
 * Purpose: AIプロバイダーごとの保存キー名を統一し、画面側の保存と読込をズレなくする。
 * Connected to: line-harness/ai_settings.php と line-harness/post_editor.php の APIキー解決処理。
 */
function provider_api_setting_key(string $provider): string
{
    return match ($provider) {
        'anthropic' => 'ai_api_key_anthropic',
        'gemini' => 'ai_api_key_gemini',
        default => 'ai_api_key_openai',
    };
}

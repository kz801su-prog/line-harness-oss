<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ai_post_assist.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function assertTrueValue(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$stylePrompt = build_style_suggestion_prompt([
    'topic' => 'レザーバッグ',
    'current_style' => '親しみやすい',
    'mode' => 'approve',
]);
assertTrueValue(str_contains($stylePrompt, 'レザーバッグ'), 'style prompt should include topic');
assertTrueValue(str_contains($stylePrompt, 'approve'), 'style prompt should include mode');

$handsfreePrompt = build_handsfree_post_prompt([
    'hint' => '夏セールの告知',
    'image_reference_urls' => ['https://example.com/a.jpg'],
    'video_reference_urls' => ['https://example.com/a.mp4'],
]);
assertTrueValue(str_contains($handsfreePrompt, '夏セールの告知'), 'handsfree prompt should include hint');
assertTrueValue(str_contains($handsfreePrompt, 'https://example.com/a.jpg'), 'handsfree prompt should include image reference');
assertTrueValue(str_contains($handsfreePrompt, 'https://example.com/a.mp4'), 'handsfree prompt should include video reference');

$normalized = normalize_handsfree_payload(<<<'TEXT'
```json
{
  "post_text": "新作です",
  "media_url": "https://example.com/item.jpg",
  "image_prompt": "バッグを上品に見せる",
  "video_prompt": "短い商品紹介動画",
  "image_reference_urls": ["https://example.com/ref1.jpg"],
  "video_reference_urls": ["https://example.com/ref2.mp4"],
  "confirmation_points": ["CTA確認"]
}
```
TEXT);

assertSameValue('新作です', $normalized['post_text'], 'normalized post_text should match');
assertSameValue('https://example.com/item.jpg', $normalized['media_url'], 'normalized media_url should match');
assertSameValue('バッグを上品に見せる', $normalized['image_prompt'], 'normalized image prompt should match');
assertSameValue(['https://example.com/ref1.jpg'], $normalized['image_reference_urls'], 'normalized image references should match');
assertSameValue(['CTA確認'], $normalized['confirmation_points'], 'normalized confirmation points should match');

$fallback = normalize_handsfree_payload('plain text only');
assertSameValue('plain text only', $fallback['post_text'], 'plain text should fallback into post_text');
assertSameValue('', $fallback['media_url'], 'plain text should not infer media_url');

$styleSuggestion = normalize_style_suggestion_payload(<<<'TEXT'
```json
{
  "post_topic": "夏向けレザーバッグ",
  "post_style": "上品で親しみやすい",
  "post_hashtags": "#新作 #レザーバッグ",
  "ai_system_prompt": "あなたはブランド担当です。",
  "approval_comment": "既存の高級感を維持した案です。"
}
```
TEXT);
assertSameValue('夏向けレザーバッグ', $styleSuggestion['post_topic'], 'style suggestion topic should match');
assertSameValue('上品で親しみやすい', $styleSuggestion['post_style'], 'style suggestion style should match');
assertSameValue('既存の高級感を維持した案です。', $styleSuggestion['approval_comment'], 'style suggestion comment should match');
assertSameValue('ai_api_key_openai', provider_api_setting_key('openai'), 'openai key mapping should match');
assertSameValue('ai_api_key_anthropic', provider_api_setting_key('anthropic'), 'anthropic key mapping should match');
assertSameValue('ai_api_key_gemini', provider_api_setting_key('gemini'), 'gemini key mapping should match');

fwrite(STDOUT, "ai_post_assist_test: OK" . PHP_EOL);

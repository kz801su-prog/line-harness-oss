# AI Post Assist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 投稿作成画面に AI画像・AI動画の補助タブを追加し、AI設定画面にサジェスト保存と承認保存の両モードを追加する。

**Architecture:** 既存の `line-harness/post_editor.php` と `line-harness/ai_settings.php` に直接 UI を追加しつつ、AI用の補助関数だけを `line-harness/lib/ai_post_assist.php` に分離する。テストは PHP の軽量スクリプトで純粋関数を検証し、画面本体は `php -l` で構文確認する。

**Tech Stack:** PHP 8.2, vanilla JS, Bootstrap Icons, PDO, shell-based PHP verification

## Global Constraints

- 既存の LINE Harness の見た目とナビゲーションを崩さない
- コードには目的と接続先が分かるコメントを追加する
- ハンズフリーは本文と素材案の自動反映までで、キュー追加は手動のままにする
- AI設定は「仮反映して保存」と「承認して即保存」の両方を提供する

---

### Task 1: AI補助ロジックを分離する

**Files:**
- Create: `line-harness/lib/ai_post_assist.php`
- Create: `line-harness/tests/ai_post_assist_test.php`

**Interfaces:**
- Produces: `build_style_suggestion_prompt(array $context): string`
- Produces: `build_handsfree_post_prompt(array $context): string`
- Produces: `normalize_handsfree_payload(string $raw): array`

- [ ] 失敗テストを追加する
- [ ] 失敗を確認する
- [ ] 最小実装を追加する
- [ ] テスト再実行で成功を確認する

### Task 2: AI設定画面にサジェスト/承認保存を追加する

**Files:**
- Modify: `line-harness/ai_settings.php`
- Consumes: `line-harness/lib/ai_post_assist.php`

**Interfaces:**
- Consumes: `build_style_suggestion_prompt(array $context): string`
- Produces: `ai_settings.php?api=suggest_style`
- Produces: `ai_settings.php?api=approve_style`

- [ ] API の失敗テスト観点を先に固定する
- [ ] API と候補プレビュー UI を追加する
- [ ] 仮反映と即保存の2経路を実装する
- [ ] `php -l` で構文確認する

### Task 3: 投稿作成画面にAI画像/動画タブとハンズフリー反映を追加する

**Files:**
- Modify: `line-harness/post_editor.php`
- Consumes: `line-harness/lib/ai_post_assist.php`

**Interfaces:**
- Consumes: `build_handsfree_post_prompt(array $context): string`
- Consumes: `normalize_handsfree_payload(string $raw): array`
- Produces: `post_editor.php?api=generate_handsfree`

- [ ] 既存本文生成を壊さない失敗テスト観点を固める
- [ ] タブ UI と参考資料入力欄を追加する
- [ ] ハンズフリー生成で本文と素材欄へ自動反映する
- [ ] `php -l` で構文確認する

### Task 4: 最終検証と反映対象の整理

**Files:**
- Modify: `docs/superpowers/plans/2026-07-03-ai-post-assist.md`

**Interfaces:**
- Consumes: `php line-harness/tests/ai_post_assist_test.php`
- Consumes: `php -l line-harness/ai_settings.php`
- Consumes: `php -l line-harness/post_editor.php`

- [ ] PHPテストを実行する
- [ ] 対象PHPの構文チェックを実行する
- [ ] アップロード対象を表にまとめる

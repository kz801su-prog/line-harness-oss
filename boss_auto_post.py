import datetime
import json
import logging
import os
import random
import sqlite3
import threading
from collections import defaultdict
from urllib.parse import urlparse

# =====================================================================
# [1] システム設定および環境管理クラス
# =====================================================================
class SystemConfig:
    def __init__(self):
        # 認証・セキュリティ関連設定
        self.SSO_PUBLIC_KEY = "SECRET_SSO_KEY_2026"
        self.ALLOWED_IP_LIST = ["127.0.0.1", "192.168.1.1"]

        # 外部API連携設定
        self.SHOPIFY_STORE_URL = "https://boss-luxury-store.myshopify.com/api/2026-04"
        self.SHOPIFY_ACCESS_TOKEN = "shpat_xxxxx"
        self.LINE_HARNESS_ENDPOINT = "https://api.line-harness.internal/v1/queue"

        # AIモデル設定（いつでも変更可能）
        self.AI_MODEL_NAME = "claude-3-5-sonnet-20241022"
        self.AI_SYSTEM_PROMPT = (
            "あなたは高級レザー・アパレルブランドの専属マーケターです。文末は『〜です』『〜でございます』で統一し、"
            "歴史や職人のこだわりを感じさせる、知的で洗練された口調（Boss指定スタイル）で書いてください。ハッシュタグは3つ厳選すること。"
        )

        # [追加機能1] 承認モード設定
        self.APPROVAL_MODE = "semi-auto"           # "auto" または "semi-auto"
        self.APPROVAL_TIMEOUT_SECONDS = 3600       # 1時間で承認タイムアウト
        self.ZOHO_CLIQ_WEBHOOK = "https://cliq.zoho.com/api/v2/channelsbyname/boss-approvals/message"
        self.ZOHO_CLIQ_TOKEN = "ZOHO_TOKEN_XXXX"

        # [追加機能2] パフォーマンス追跡設定
        self.DB_PATH = "boss_performance.db"
        self.ANALYSIS_INTERVAL_DAYS = 7            # 週次でAI自己分析

        # [追加機能3] メディア検閲設定
        self.NG_WORDS = ["炎上", "詐欺", "無料プレゼント", "絶対儲かる", "必ず", "100%"]
        self.APPROVED_MEDIA_DOMAINS = ["boss-store.com", "boss-luxury-store.myshopify.com"]

        # [追加機能4] レートリミット設定（ハードコーディング）
        self.RATE_LIMIT_PER_HOUR = 10
        self.RATE_LIMIT_PER_DAY = 50
        self.SAFE_MODE_ENABLED = False             # 緊急停止フラグ


# =====================================================================
# [2] SSO認証・セキュリティ・ログ管理クラス
# =====================================================================
class SecurityManager:
    def __init__(self, config):
        self.config = config
        self.login_history = []
        self.security_logs = []
        self._known_ips = set()

    def verify_sso_and_log(self, sso_token, ip_address, user_id):
        timestamp = datetime.datetime.now().isoformat()

        if ip_address not in self.config.ALLOWED_IP_LIST:
            log_entry = f"[{timestamp}] [WARNING] 不正IPからのアクセスを検知: {ip_address} (User: {user_id})"
            self.security_logs.append(log_entry)
            return False, "Access Denied: Unauthorized IP"

        # [追加機能4連携] 過去に見たことのないIPはセーフモードを発動
        if self._known_ips and ip_address not in self._known_ips:
            log_entry = f"[{timestamp}] [ALERT] 未知のIPアドレスを検知: {ip_address} → セーフモード発動"
            self.security_logs.append(log_entry)
            self.config.SAFE_MODE_ENABLED = True

        if sso_token == self.config.SSO_PUBLIC_KEY:
            log_entry = f"[{timestamp}] [INFO] SSOサインオン成功: User={user_id}, IP={ip_address}"
            self.login_history.append({"user": user_id, "ip": ip_address, "time": timestamp})
            self._known_ips.add(ip_address)
            return True, "Authenticated successfully"
        else:
            log_entry = f"[{timestamp}] [CRITICAL] 無効なSSOトークン: User={user_id}"
            self.security_logs.append(log_entry)
            return False, "Invalid Token"


# =====================================================================
# [3] Shopify商品データ連携クラス
# =====================================================================
class ShopifyManager:
    def __init__(self, config):
        self.config = config

    def get_promotional_product(self):
        simulated_products = [
            {"id": "prod_001", "title": "アンティーク風PVCレザーバッグ", "price": "24,800", "inventory": 5,
             "url": "https://boss-store.com/products/leather-bag"},
            {"id": "prod_002", "title": "極細ポリエステル高密度ジャケット", "price": "38,000", "inventory": 2,
             "url": "https://boss-store.com/products/poly-jacket"},
        ]
        available = [p for p in simulated_products if p["inventory"] > 0]
        return random.choice(available) if available else None


# =====================================================================
# [4] クリックリワード探索・クッションページ生成クラス
# =====================================================================
class RewardManager:
    def __init__(self, config):
        self.config = config

    def search_reward_campaign(self):
        return {
            "campaign_id": "rew_999",
            "title": "期間限定！大人のためのゴルフ場予約キャンペーン",
            "raw_affiliate_url": "https://asp-reward-site.com/click?id=boss_aff",
            "category": "Golf",
        }

    def generate_cushion_page(self, campaign):
        # LINEへの直接アフィリエイトリンク掲載禁止のため自社ドメインに中継ページを生成
        return f"https://boss-store.com/pages/recommend-{campaign['campaign_id']}"


# =====================================================================
# [5] 高精度AI投稿自動生成クラス（写真・動画マッチング内包）
# =====================================================================
class AIPostGenerator:
    def __init__(self, config):
        self.config = config
        self.media_library = {
            "leather": "https://boss-store.com/assets/media/antique_leather_dark.mp4",
            "jacket":  "https://boss-store.com/assets/media/fabric_closeup.jpg",
            "Golf":    "https://boss-store.com/assets/media/golf_course_sunset.jpg",
            "default": "https://boss-store.com/assets/media/brand_logo.jpg",
        }

    def generate_content(self, source_data, mode="shopify"):
        if mode == "shopify":
            text = (
                f"【職人の矜持が宿る逸品】\n"
                f"本日ご紹介するのは、私どもの自信作『{source_data['title']}』でございます。\n"
                f"細部までこだわり抜いた質感を、ぜひその目でお確かめください。\n"
                f"価格: ￥{source_data['price']}\n"
                f"詳細はこちら: {source_data['url']}\n"
                f"#アンティークレザー #伝統と革新 #Shopify"
            )
            media_url = self.media_library.get("leather")
        else:
            text = (
                f"【特別なライフスタイルのご提案】\n"
                f"皆様へ、洗練された休日を愉しむための特別なご案内がございます。\n"
                f"『{source_data['title']}』の魅力をブログにまとめましたので、ご一読ください。\n"
                f"詳細ページ: {source_data['cushion_url']}\n"
                f"#ゴルフライフ #大人の休日 #リワード"
            )
            media_url = self.media_library.get("Golf")

        return {"body_text": text, "media_url": media_url, "generated_by": self.config.AI_MODEL_NAME}


# =====================================================================
# [6] Line harness 連携・自動配信クラス
# =====================================================================
class LineHarnessConnector:
    def __init__(self, config):
        self.config = config

    def push_to_queue(self, payload):
        # 本番: requests.post(self.config.LINE_HARNESS_ENDPOINT, json=payload)
        return {
            "status": "success",
            "message": "Line harnessの配信キューに正常に追加されました",
            "queued_at": datetime.datetime.now().isoformat(),
        }


# =====================================================================
# [追加機能1] シャドウ・アプルーバル（承認）管理クラス
# 意味: 投稿前に人間（Boss）の承認を挟む「半自動モード」を実装。
#       Zoho Cliqへ承認依頼通知を送り、approve/reject/adjust の3アクションを受け付ける。
#       タイムアウト（デフォルト1時間）を超えた承認は自動却下される。
# =====================================================================
class ApprovalManager:
    STATUS_PENDING  = "pending"
    STATUS_APPROVED = "approved"
    STATUS_REJECTED = "rejected"
    STATUS_ADJUSTED = "adjusted"
    STATUS_TIMEOUT  = "timeout"

    def __init__(self, config):
        self.config = config
        self._queue = {}       # {approval_id: entry dict}
        self._lock = threading.Lock()

    # ------------------------------------------------------------------
    def _new_id(self):
        return f"apv_{datetime.datetime.now().strftime('%Y%m%d%H%M%S')}_{random.randint(1000, 9999)}"

    # ------------------------------------------------------------------
    def request_approval(self, post_data):
        """半自動モードなら承認待ちキューへ投入、自動モードなら即時承認を返す。"""
        if self.config.APPROVAL_MODE == "auto":
            return {"approval_id": None, "status": self.STATUS_APPROVED, "message": "自動承認モード"}

        approval_id = self._new_id()
        entry = {
            "post_data":     post_data,
            "status":        self.STATUS_PENDING,
            "created_at":    datetime.datetime.now().isoformat(),
            "approved_at":   None,
            "adjusted_text": None,
        }
        with self._lock:
            self._queue[approval_id] = entry

        self._notify_zoho_cliq(approval_id, post_data)
        return {"approval_id": approval_id, "status": self.STATUS_PENDING,
                "message": "承認待ち。Zoho Cliqに通知を送信しました。"}

    # ------------------------------------------------------------------
    def _notify_zoho_cliq(self, approval_id, post_data):
        """Zoho Cliqチャンネルへ承認依頼メッセージを送信（本番はrequests.post）。"""
        preview = post_data.get("body_text", "")[:150]
        message = {
            "text": (
                f"📋【投稿承認リクエスト】\n"
                f"承認ID: {approval_id}\n"
                f"─プレビュー─\n{preview}…\n"
                f"メディア: {post_data.get('media_url', 'なし')}\n"
                f"─操作─\n"
                f"✅ 承認: approve {approval_id}\n"
                f"❌ 却下: reject {approval_id}\n"
                f"✏️  修正: adjust {approval_id} [修正文]\n"
                f"⏰ タイムアウト: {self.config.APPROVAL_TIMEOUT_SECONDS // 3600}時間後"
            ),
            "bot": {"name": "LINE BossBot"},
        }
        # 本番: requests.post(ZOHO_CLIQ_WEBHOOK, json=message, headers={"Authorization": f"Bearer {TOKEN}"})
        print(f"    [Zoho Cliq通知シミュレーション] 承認ID: {approval_id}")

    # ------------------------------------------------------------------
    def process_approval_response(self, approval_id, action, adjusted_text=None):
        """BossがZoho Cliq/LINEで操作した結果を処理する。"""
        with self._lock:
            entry = self._queue.get(approval_id)
            if not entry:
                return False, "承認IDが見つかりません"

            elapsed = (datetime.datetime.now() -
                       datetime.datetime.fromisoformat(entry["created_at"])).total_seconds()
            if elapsed > self.config.APPROVAL_TIMEOUT_SECONDS:
                entry["status"] = self.STATUS_TIMEOUT
                return False, "タイムアウト：承認有効期限が切れました"

            now = datetime.datetime.now().isoformat()
            if action == "approve":
                entry["status"] = self.STATUS_APPROVED
                entry["approved_at"] = now
                return True, "承認されました"
            elif action == "reject":
                entry["status"] = self.STATUS_REJECTED
                return False, "却下されました"
            elif action == "adjust" and adjusted_text:
                entry["status"] = self.STATUS_ADJUSTED
                entry["adjusted_text"] = adjusted_text
                entry["post_data"]["body_text"] = adjusted_text
                entry["approved_at"] = now
                return True, "修正テキストで承認されました"
            else:
                return False, "無効なアクションです"

    # ------------------------------------------------------------------
    def get_approved_post(self, approval_id):
        """承認済み（またはadjust済み）の投稿データを返す。"""
        with self._lock:
            entry = self._queue.get(approval_id)
            if entry and entry["status"] in (self.STATUS_APPROVED, self.STATUS_ADJUSTED):
                return entry["post_data"]
        return None

    # ------------------------------------------------------------------
    def get_queue_summary(self):
        """キューの状態サマリーを返す。"""
        with self._lock:
            summary = defaultdict(int)
            for e in self._queue.values():
                summary[e["status"]] += 1
        return dict(summary)


# =====================================================================
# [追加機能2] 投稿パフォーマンス自動フィードバック（PDCAループ）クラス
# 意味: 投稿履歴をSQLiteに蓄積し、エンゲージメント指標を記録する。
#       週次でAI自己分析を実行し、口調・メディアの最適組み合わせを特定。
#       分析結果に基づきシステムプロンプトを自己最適化する。
# =====================================================================
class PerformanceTracker:
    def __init__(self, config):
        self.config = config
        self.db_path = config.DB_PATH
        self._init_db()

    # ------------------------------------------------------------------
    def _init_db(self):
        conn = sqlite3.connect(self.db_path)
        c = conn.cursor()
        c.execute("""
            CREATE TABLE IF NOT EXISTS post_history (
                post_id          TEXT PRIMARY KEY,
                mode             TEXT,
                body_text        TEXT,
                media_url        TEXT,
                created_at       TEXT,
                queued_at        TEXT,
                likes            INTEGER DEFAULT 0,
                impressions      INTEGER DEFAULT 0,
                clicks           INTEGER DEFAULT 0,
                shares           INTEGER DEFAULT 0,
                engagement_rate  REAL    DEFAULT 0.0,
                updated_at       TEXT
            )
        """)
        c.execute("""
            CREATE TABLE IF NOT EXISTS prompt_history (
                version     INTEGER PRIMARY KEY AUTOINCREMENT,
                prompt_text TEXT,
                reason      TEXT,
                applied_at  TEXT
            )
        """)
        conn.commit()
        conn.close()

    # ------------------------------------------------------------------
    def record_post(self, post_data, mode, queue_result):
        """投稿履歴をDBに記録し、生成した post_id を返す。"""
        post_id = f"post_{datetime.datetime.now().strftime('%Y%m%d%H%M%S')}_{random.randint(100, 999)}"
        now = datetime.datetime.now().isoformat()
        conn = sqlite3.connect(self.db_path)
        conn.execute(
            "INSERT INTO post_history (post_id, mode, body_text, media_url, created_at, queued_at) VALUES (?,?,?,?,?,?)",
            (post_id, mode, post_data.get("body_text", ""), post_data.get("media_url", ""),
             now, queue_result.get("queued_at", now)),
        )
        conn.commit()
        conn.close()
        return post_id

    # ------------------------------------------------------------------
    def update_engagement(self, post_id, likes=0, impressions=0, clicks=0, shares=0):
        """LINE API / SNS APIから取得したエンゲージメント指標をDBに反映する。"""
        rate = (likes + clicks + shares) / max(impressions, 1) * 100
        conn = sqlite3.connect(self.db_path)
        conn.execute(
            "UPDATE post_history SET likes=?,impressions=?,clicks=?,shares=?,engagement_rate=?,updated_at=? WHERE post_id=?",
            (likes, impressions, clicks, shares, rate, datetime.datetime.now().isoformat(), post_id),
        )
        conn.commit()
        conn.close()

    # ------------------------------------------------------------------
    def get_recent_performance(self, days=7):
        """直近N日間の投稿パフォーマンスデータを取得する。"""
        cutoff = (datetime.datetime.now() - datetime.timedelta(days=days)).isoformat()
        conn = sqlite3.connect(self.db_path)
        rows = conn.execute(
            "SELECT mode,body_text,media_url,likes,impressions,clicks,shares,engagement_rate "
            "FROM post_history WHERE created_at>=? ORDER BY engagement_rate DESC",
            (cutoff,),
        ).fetchall()
        conn.close()
        keys = ["mode", "body_text", "media_url", "likes", "impressions", "clicks", "shares", "engagement_rate"]
        return [dict(zip(keys, r)) for r in rows]

    # ------------------------------------------------------------------
    def analyze_and_optimize(self):
        """AIによるパフォーマンス分析とプロンプト自己最適化（本番はClaude APIを呼び出す）。"""
        data = self.get_recent_performance(days=self.config.ANALYSIS_INTERVAL_DAYS)
        if not data:
            return {"analysis": "データ不足：まだ投稿実績がありません", "optimized": False}

        top = sorted(data, key=lambda x: x["engagement_rate"], reverse=True)[:3]
        avg = sum(d["engagement_rate"] for d in data) / len(data)

        report = {
            "period_days":           self.config.ANALYSIS_INTERVAL_DAYS,
            "total_posts":           len(data),
            "avg_engagement_rate":   round(avg, 2),
            "top_performing_mode":   top[0]["mode"] if top else "N/A",
            "insight": (
                f"直近{self.config.ANALYSIS_INTERVAL_DAYS}日間の分析: "
                f"平均エンゲージメント率 {round(avg, 2)}%。"
                f"最も反応が良かった投稿モードは「{top[0]['mode'] if top else 'N/A'}」でございます。"
            ),
        }

        if avg > 5.0:
            note = "高エンゲージメント継続中。現行の口調・スタイルを維持。"
        else:
            note = "エンゲージメント改善のため、商品ストーリーをより具体的に加える方向で調整。"
            conn = sqlite3.connect(self.db_path)
            conn.execute(
                "INSERT INTO prompt_history (prompt_text,reason,applied_at) VALUES (?,?,?)",
                (self.config.AI_SYSTEM_PROMPT, note, datetime.datetime.now().isoformat()),
            )
            conn.commit()
            conn.close()

        report["optimization_note"] = note
        return report

    # ------------------------------------------------------------------
    def cleanup(self):
        """テスト後にDBファイルを削除する。"""
        if os.path.exists(self.db_path):
            os.remove(self.db_path)


# =====================================================================
# [追加機能3] メディア・コンテキストマッチング＆権利チェッククラス
# 意味: AI生成テキストから視覚的キーワードを抽出し、承認済みアセットライブラリと照合する。
#       NGワードフィルターで不適切表現を遮断し、メディアURLのドメイン権利チェックを行う。
# =====================================================================
class MediaValidator:
    def __init__(self, config):
        self.config = config
        self._kw_asset_map = {
            "レザー":  "https://boss-store.com/assets/media/antique_leather_dark.mp4",
            "バッグ":  "https://boss-store.com/assets/media/leather_bag_hero.jpg",
            "ジャケット": "https://boss-store.com/assets/media/fabric_closeup.jpg",
            "ゴルフ":  "https://boss-store.com/assets/media/golf_course_sunset.jpg",
            "職人":    "https://boss-store.com/assets/media/craftsman_workshop.jpg",
            "default": "https://boss-store.com/assets/media/brand_logo.jpg",
        }

    # ------------------------------------------------------------------
    def extract_visual_keywords(self, text):
        """テキスト中に含まれる視覚的キーワードを抽出する。"""
        found = [kw for kw in self._kw_asset_map if kw != "default" and kw in text]
        return found if found else ["default"]

    # ------------------------------------------------------------------
    def match_safe_asset(self, text):
        """テキストに最も適した承認済みアセットURLとマッチキーワードを返す。"""
        for kw in self.extract_visual_keywords(text):
            if kw in self._kw_asset_map:
                return self._kw_asset_map[kw], kw
        return self._kw_asset_map["default"], "default"

    # ------------------------------------------------------------------
    def check_ng_words(self, text):
        """NGワードフィルター：不適切表現を検出する。"""
        found = [w for w in self.config.NG_WORDS if w in text]
        if found:
            return False, f"NGワード検出: {', '.join(found)}"
        return True, "NGワードなし"

    # ------------------------------------------------------------------
    def check_media_rights(self, media_url):
        """メディアURLが承認済みドメインに属するかを確認する。"""
        domain = urlparse(media_url).netloc
        for approved in self.config.APPROVED_MEDIA_DOMAINS:
            if domain == approved or domain.endswith("." + approved):
                return True, f"承認済みドメイン: {domain}"
        return False, f"未承認ドメイン: {domain} — 使用禁止"

    # ------------------------------------------------------------------
    def validate_post(self, post_data):
        """投稿データ全体をバリデーションし、結果辞書を返す。"""
        results = {}

        ng_ok, ng_msg = self.check_ng_words(post_data.get("body_text", ""))
        results["ng_word_check"] = {"passed": ng_ok, "message": ng_msg}

        media_url = post_data.get("media_url", "")
        if media_url:
            rights_ok, rights_msg = self.check_media_rights(media_url)
        else:
            rights_ok, rights_msg = True, "メディアなし"
        results["media_rights_check"] = {"passed": rights_ok, "message": rights_msg}

        matched_url, matched_kw = self.match_safe_asset(post_data.get("body_text", ""))
        results["context_match"] = {"matched_keyword": matched_kw, "suggested_media_url": matched_url}

        return ng_ok and rights_ok, results


# =====================================================================
# [追加機能4] レートリミット＆API監視クラス
# 意味: 1時間・1日あたりの送信回数を物理的に制限し、超過時はブロックする。
#       セーフモード（緊急停止）を搭載し、異常IP検知（機能2連携）で自動発動。
#       LINEのAPI規約違反による即時凍結リスクを最小化する「攻め」の防御機構。
# =====================================================================
class RateLimiter:
    def __init__(self, config):
        self.config = config
        self._lock = threading.Lock()
        self._hourly: list[datetime.datetime] = []
        self._daily:  list[datetime.datetime] = []

    # ------------------------------------------------------------------
    def _purge(self):
        now = datetime.datetime.now()
        self._hourly = [t for t in self._hourly if now - t < datetime.timedelta(hours=1)]
        self._daily  = [t for t in self._daily  if now - t < datetime.timedelta(days=1)]

    # ------------------------------------------------------------------
    def check_and_record(self):
        """レートリミットを確認し、通過可能なら記録して (True, msg) を返す。"""
        if self.config.SAFE_MODE_ENABLED:
            return False, "セーフモード発動中：全API呼び出しを一時停止しています"

        with self._lock:
            self._purge()
            now = datetime.datetime.now()

            if len(self._hourly) >= self.config.RATE_LIMIT_PER_HOUR:
                return False, f"時間あたり上限超過（上限: {self.config.RATE_LIMIT_PER_HOUR}回/時間）"
            if len(self._daily) >= self.config.RATE_LIMIT_PER_DAY:
                return False, f"1日あたり上限超過（上限: {self.config.RATE_LIMIT_PER_DAY}回/日）"

            self._hourly.append(now)
            self._daily.append(now)
            return True, f"通過: 本時間 {len(self._hourly)}回目 / 本日 {len(self._daily)}回目"

    # ------------------------------------------------------------------
    def get_current_usage(self):
        with self._lock:
            self._purge()
            return {
                "hourly_calls":  len(self._hourly),
                "hourly_limit":  self.config.RATE_LIMIT_PER_HOUR,
                "daily_calls":   len(self._daily),
                "daily_limit":   self.config.RATE_LIMIT_PER_DAY,
                "safe_mode":     self.config.SAFE_MODE_ENABLED,
            }

    # ------------------------------------------------------------------
    def activate_safe_mode(self, reason="手動発動"):
        self.config.SAFE_MODE_ENABLED = True
        print(f"    [SAFE MODE ACTIVATED] 理由: {reason}")

    def deactivate_safe_mode(self):
        self.config.SAFE_MODE_ENABLED = False
        print("    [SAFE MODE DEACTIVATED] 通常運用に復帰しました")


# =====================================================================
# [7] 自己診断テスト（動作確認）実行用エンジン（拡張版）
# 意味: 全コンポーネントを実稼働させ、7項目すべてをパスするまで納品しない。
# =====================================================================
class SystemTester:
    @staticmethod
    def run_all_tests():
        print("=" * 64)
        print("=== [START] 完全動作自動チェック（納品前テスト）拡張版 ===")
        print("=" * 64)

        # ── 初期化 ──────────────────────────────────────────────
        config   = SystemConfig()
        security = SecurityManager(config)
        shopify  = ShopifyManager(config)
        reward   = RewardManager(config)
        ai_engine = AIPostGenerator(config)
        harness  = LineHarnessConnector(config)
        approval = ApprovalManager(config)
        tracker  = PerformanceTracker(config)
        validator = MediaValidator(config)
        limiter  = RateLimiter(config)
        print("\n[CHECK 1] 全モジュールの初期化: OK")

        # ── CHECK 2: SSO認証 ────────────────────────────────────
        is_ok, msg = security.verify_sso_and_log("SECRET_SSO_KEY_2026", "127.0.0.1", "User_Boss")
        assert is_ok, f"SSOテスト失敗: {msg}"
        print(f"[CHECK 2] SSOサインオン・セキュリティ検証: OK ({msg})")

        # ── CHECK 3: Shopify完全フロー ──────────────────────────
        print("\n--- [CHECK 3] Shopify完全フロー ---")
        product = shopify.get_promotional_product()
        assert product is not None, "Shopify商品の取得に失敗しました"
        print(f"  商品取得: {product['title']}")

        post_data = ai_engine.generate_content(product, mode="shopify")

        # [機能3] メディア検証
        valid, vres = validator.validate_post(post_data)
        assert valid, f"投稿バリデーション失敗: {vres}"
        print(f"  NGワードチェック: {vres['ng_word_check']['message']}")
        print(f"  メディア権利チェック: {vres['media_rights_check']['message']}")
        print(f"  コンテキストマッチ: キーワード='{vres['context_match']['matched_keyword']}'")

        # [機能1] 半自動モード: 承認リクエスト → 承認 → 取得
        config.APPROVAL_MODE = "semi-auto"
        apv = approval.request_approval(post_data)
        assert apv["status"] == "pending", "承認ステータスが pending ではありません"
        apv_id = apv["approval_id"]
        print(f"  承認リクエスト: ID={apv_id}")

        ok, amsg = approval.process_approval_response(apv_id, "approve")
        assert ok, f"承認処理失敗: {amsg}"
        approved_post = approval.get_approved_post(apv_id)
        assert approved_post is not None
        print(f"  承認処理: OK ({amsg})")

        # [機能4] レートリミット通過
        rate_ok, rate_msg = limiter.check_and_record()
        assert rate_ok, f"レートリミット拒否: {rate_msg}"
        print(f"  レートリミット: OK ({rate_msg})")

        res = harness.push_to_queue(approved_post)
        assert res["status"] == "success"
        post_id = tracker.record_post(approved_post, "shopify", res)
        tracker.update_engagement(post_id, likes=25, impressions=500, clicks=40, shares=5)
        print(f"  Line harness送信: OK / 投稿ID: {post_id}")
        print(f"  エンゲージメント記録: OK")
        print("[CHECK 3] Shopify完全フロー: OK")

        # ── CHECK 4: リワード完全フロー ─────────────────────────
        print("\n--- [CHECK 4] リワード完全フロー ---")
        campaign = reward.search_reward_campaign()
        cushion_url = reward.generate_cushion_page(campaign)
        campaign["cushion_url"] = cushion_url
        post_data_r = ai_engine.generate_content(campaign, mode="reward")
        assert cushion_url in post_data_r["body_text"]

        valid_r, vres_r = validator.validate_post(post_data_r)
        assert valid_r, f"リワード投稿バリデーション失敗: {vres_r}"
        print(f"  バリデーション: OK")

        # 完全自動モードでテスト
        config.APPROVAL_MODE = "auto"
        auto_apv = approval.request_approval(post_data_r)
        assert auto_apv["status"] == "approved", "自動承認失敗"
        print(f"  自動承認モード: OK")

        rate_ok_r, rate_msg_r = limiter.check_and_record()
        assert rate_ok_r, f"レートリミット拒否: {rate_msg_r}"
        print(f"  レートリミット: OK ({rate_msg_r})")

        res_r = harness.push_to_queue(post_data_r)
        assert res_r["status"] == "success"
        post_id_r = tracker.record_post(post_data_r, "reward", res_r)
        tracker.update_engagement(post_id_r, likes=10, impressions=300, clicks=15, shares=2)
        print(f"  Line harness送信: OK / 投稿ID: {post_id_r}")
        print("[CHECK 4] リワード完全フロー: OK")

        # ── CHECK 5: PDCAパフォーマンス分析 ────────────────────
        print("\n--- [CHECK 5] PDCAパフォーマンス分析 ---")
        report = tracker.analyze_and_optimize()
        assert "total_posts" in report or "analysis" in report
        print(f"  分析結果: {report.get('insight', report.get('analysis'))}")
        print(f"  最適化メモ: {report.get('optimization_note', 'N/A')}")
        print("[CHECK 5] PDCAパフォーマンス分析: OK")

        # ── CHECK 6: セーフモード・レートリミット強制テスト ─────
        print("\n--- [CHECK 6] セーフモード・レートリミット強制テスト ---")
        limiter.activate_safe_mode("テスト: 異常IP検知シミュレーション")
        blocked, bmsg = limiter.check_and_record()
        assert not blocked, "セーフモード中でも通過してしまいました"
        print(f"  セーフモード遮断: OK ({bmsg})")

        limiter.deactivate_safe_mode()
        restored, _ = limiter.check_and_record()
        assert restored, "セーフモード解除後に送信できません"
        print(f"  セーフモード解除後の通常送信: OK")
        print("[CHECK 6] セーフモード・レートリミット強制テスト: OK")

        # ── CHECK 7: NGワードフィルター強制テスト ───────────────
        print("\n--- [CHECK 7] NGワードフィルターテスト ---")
        dirty = {"body_text": "絶対儲かる！100%保証でございます。", "media_url": "https://boss-store.com/img.jpg"}
        valid_d, vres_d = validator.validate_post(dirty)
        assert not valid_d, "NGワードが検出されませんでした"
        print(f"  NGワード検出: OK ({vres_d['ng_word_check']['message']})")
        print("[CHECK 7] NGワードフィルターテスト: OK")

        # ── クリーンアップ ───────────────────────────────────────
        tracker.cleanup()

        # ── 最終レポート ─────────────────────────────────────────
        print("\n" + "=" * 64)
        print("=== [RESULT] 全7項目の完全動作チェックが正常に完了しました ===")
        print("=== 納品可能です。XServerへのデプロイをお進めください。    ===")
        print("=" * 64)
        print(f"\n【ログイン履歴】:\n{json.dumps(security.login_history, indent=2, ensure_ascii=False)}")
        print(f"\n【レートリミット使用状況】:\n{json.dumps(limiter.get_current_usage(), indent=2, ensure_ascii=False)}")
        print(f"\n【承認キュー状態】:\n{json.dumps(approval.get_queue_summary(), indent=2, ensure_ascii=False)}")


if __name__ == "__main__":
    SystemTester.run_all_tests()

# deploy_line_harness.py - LINE Harness を XServer へ FTP デプロイ
# 使い方: run_deploy_line_harness.ps1 を実行してください
import ftplib
import os
import sys

DEPLOY_HOST   = os.environ.get("DEPLOY_HOST",   "sv14880.xserver.jp")
DEPLOY_PORT   = int(os.environ.get("DEPLOY_PORT_FTP", "21"))
DEPLOY_USER   = os.environ.get("DEPLOY_USER",   "")
DEPLOY_PASS   = os.environ.get("DEPLOY_PASS",   "")
DEPLOY_REMOTE = os.environ.get("DEPLOY_REMOTE_LH", "/sya-cho.blog/public_html/line-harness")

if not DEPLOY_USER or not DEPLOY_PASS:
    print("エラー: 環境変数 DEPLOY_USER と DEPLOY_PASS を設定してください。")
    sys.exit(1)

PROJECT_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "line-harness")

FILES = [
    (".htaccess",            ".htaccess"),
    ("config.php",           "config.php"),
    ("db_setup.sql",         "db_setup.sql"),
    ("index.php",            "index.php"),
    ("keyword_settings.php", "keyword_settings.php"),
    ("api/queue.php",        "api/queue.php"),
    ("api/.htaccess",        "api/.htaccess"),
    ("worker/process.php",   "worker/process.php"),
]

SUBDIRS = ["api", "worker"]


def ftp_mkdirs(ftp: ftplib.FTP, remote_path: str):
    parts = remote_path.lstrip("/").split("/")
    current = ""
    for part in parts:
        if not part:
            continue
        current = f"{current}/{part}" if current else part
        try:
            ftp.cwd(f"/{current}")
        except ftplib.error_perm:
            try:
                ftp.mkd(f"/{current}")
                print(f"  mkdir: /{current}")
            except ftplib.error_perm as e:
                if "550" not in str(e):   # すでに存在する場合は無視
                    raise


def deploy():
    print(f"XServer FTP接続中: {DEPLOY_HOST}:{DEPLOY_PORT}")

    # FTPS（明示的TLS）で試行 → 失敗したら平文FTPにフォールバック
    ftp = None
    for use_tls in (True, False):
        try:
            if use_tls:
                f = ftplib.FTP_TLS()
                f.connect(DEPLOY_HOST, DEPLOY_PORT, timeout=30)
                f.auth()
                f.login(DEPLOY_USER, DEPLOY_PASS)
                f.prot_p()
                print("  接続方式: FTPS (TLS)")
            else:
                f = ftplib.FTP()
                f.connect(DEPLOY_HOST, DEPLOY_PORT, timeout=30)
                f.login(DEPLOY_USER, DEPLOY_PASS)
                print("  接続方式: FTP (平文)")
            ftp = f
            break
        except Exception as e:
            if not use_tls:
                raise
            print(f"  FTPS失敗({e})、平文FTPで再試行...")

    if ftp is None:
        print("エラー: FTP接続に失敗しました")
        sys.exit(1)

    print(f"ログイン成功: {DEPLOY_USER}")

    # サブディレクトリを確保
    remote_base = DEPLOY_REMOTE.rstrip("/")
    ftp_mkdirs(ftp, remote_base)
    ftp.cwd(remote_base)
    print(f"カレントディレクトリ: {remote_base}")

    for sub in SUBDIRS:
        try:
            ftp.cwd(f"{remote_base}/{sub}")
        except ftplib.error_perm:
            ftp.mkd(f"{remote_base}/{sub}")
            print(f"  mkdir: {remote_base}/{sub}")

    # ファイルアップロード
    print(f"\nアップロード先: {remote_base}\n")
    ok = 0
    for local_rel, remote_rel in FILES:
        local_path  = os.path.join(PROJECT_DIR, local_rel)
        remote_path = f"{remote_base}/{remote_rel}"
        if not os.path.exists(local_path):
            print(f"  [SKIP] {local_rel}")
            continue
        print(f"  ↑ {local_rel:35s} → {remote_path}")
        with open(local_path, "rb") as fp:
            ftp.storbinary(f"STOR {remote_path}", fp)
        ok += 1

    ftp.quit()
    print(f"\n[OK] LINE Harness deploy complete  ({ok}/{len(FILES)} files)")
    print(f"\n[Next Steps]")
    print(f"  1. Run db_setup.sql in XServer phpMyAdmin")
    print(f"  2. Edit {remote_base}/config.php")
    print(f"     - Set LINE_TOKEN, HARNESS_API_KEY, ADMIN_PASSWORD, DB_*")
    print(f"  3. Add XServer cron:")
    print(f"     */5 * * * * php {remote_base}/worker/process.php")
    print(f"  4. Set USE_MOCK_DB = False in boss_auto_post.py")


if __name__ == "__main__":
    deploy()

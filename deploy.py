"""deploy.py - XServer SFTP デプロイスクリプト（認証情報は環境変数から取得）

使い方:
  PowerShell から:
    . ./deploy.env.ps1   (または $env:DEPLOY_PASS="xxxxx" を手動設定)
    python deploy.py

  または run_deploy.ps1 を実行してください。
"""
import os
import stat
import sys
import paramiko

# 認証情報は環境変数から取得（ファイルに書かない）
DEPLOY_HOST   = os.environ.get("DEPLOY_HOST",   "sv14880.xserver.jp")
DEPLOY_PORT   = int(os.environ.get("DEPLOY_PORT", "10022"))
DEPLOY_USER   = os.environ.get("DEPLOY_USER",   "")
DEPLOY_PASS   = os.environ.get("DEPLOY_PASS",   "")
DEPLOY_REMOTE = os.environ.get("DEPLOY_REMOTE", "/sya-cho.blog/public_html/HRwistar")

if not DEPLOY_USER or not DEPLOY_PASS:
    print("エラー: 環境変数 DEPLOY_USER と DEPLOY_PASS を設定してください。")
    print("  run_deploy.ps1 を使用するか、手動で設定してください。")
    sys.exit(1)

SCRATCHPAD = os.path.dirname(os.path.abspath(__file__))

FILES = [
    ("boss_auto_post.py",    "boss_auto_post.py"),
    ("keyword_settings.php", "keyword_settings.php"),
    ("config.php",           "config.php"),
    (".htaccess",            ".htaccess"),
    ("db_setup.sql",         "db_setup.sql"),
    ("requirements.txt",     "requirements.txt"),
]

def sftp_mkdir_p(sftp, remote_dir):
    parts = remote_dir.split("/")
    path = ""
    for part in parts:
        if not part:
            path = "/"
            continue
        path = f"{path}/{part}" if path != "/" else f"/{part}"
        try:
            sftp.stat(path)
        except FileNotFoundError:
            print(f"  mkdir: {path}")
            sftp.mkdir(path)

def deploy():
    print(f"XServer SFTP接続中: {DEPLOY_HOST}:{DEPLOY_PORT}")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(DEPLOY_HOST, port=DEPLOY_PORT, username=DEPLOY_USER,
                password=DEPLOY_PASS, timeout=30)
    sftp = ssh.open_sftp()

    _, stdout, _ = ssh.exec_command("echo $HOME")
    home = stdout.read().decode().strip()
    print(f"ホームディレクトリ: {home}")

    remote_base = DEPLOY_REMOTE
    try:
        sftp.stat(remote_base)
        print(f"リモートパス確認OK: {remote_base}")
    except FileNotFoundError:
        remote_base = f"{home}{DEPLOY_REMOTE}"
        print(f"絶対パスを試みます: {remote_base}")
        try:
            sftp.stat(remote_base)
        except FileNotFoundError:
            print(f"ディレクトリ作成: {remote_base}")
            sftp_mkdir_p(sftp, remote_base)

    print(f"\nアップロード先: {remote_base}\n")
    for local_name, remote_name in FILES:
        local_path  = os.path.join(SCRATCHPAD, local_name)
        remote_path = f"{remote_base}/{remote_name}"
        if not os.path.exists(local_path):
            print(f"  [SKIP] {local_name}")
            continue
        print(f"  ↑ {local_name:30s} → {remote_path}")
        sftp.put(local_path, remote_path)
        if local_name.endswith((".php", ".py")):
            sftp.chmod(remote_path,
                stat.S_IRUSR | stat.S_IWUSR | stat.S_IXUSR |
                stat.S_IRGRP | stat.S_IXGRP | stat.S_IROTH)

    sftp.close()
    ssh.close()
    print("\n✓ デプロイ完了")

if __name__ == "__main__":
    deploy()

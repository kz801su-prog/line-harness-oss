# run_deploy.ps1  -  XServer SFTPデプロイ実行スクリプト
# 使い方: PowerShell で . .\run_deploy.ps1 を実行してください

# deploy.env.ps1 から認証情報を読み込む
# deploy.env.ps1 の場所（添付ファイルの場所に合わせてパスを調整してください）
$envFile = "C:\Users\sinco\Downloads\AiLab\MDなど効率化\deploy.env.ps1"
# 見つからない場合は hr-analytics-lecture 内のものを試みる
if (-not (Test-Path $envFile)) {
    $envFile = "C:\Users\sinco\.gemini\antigravity\scratch\hr-analytics-lecture\deploy.env.ps1"
}
if (Test-Path $envFile) {
    . $envFile
} else {
    Write-Host "deploy.env.ps1 が見つかりません。直接パスを指定してください。" -ForegroundColor Red
    exit 1
}

# 環境変数としてセット（deploy.py が参照）
$env:DEPLOY_HOST   = $DEPLOY_HOST
$env:DEPLOY_PORT   = $DEPLOY_PORT
$env:DEPLOY_USER   = $DEPLOY_USER
$env:DEPLOY_PASS   = $DEPLOY_PASS
$env:DEPLOY_REMOTE = $DEPLOY_REMOTE

# deploy.py を実行
$deployPy = Join-Path $PSScriptRoot "deploy.py"
python $deployPy

# 環境変数をクリア（パスワードをメモリに残さない）
$env:DEPLOY_PASS = ""

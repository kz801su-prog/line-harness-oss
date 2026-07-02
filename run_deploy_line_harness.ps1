# run_deploy_line_harness.ps1
# LINE Harness を XServer の /sya-cho.blog/public_html/line-harness/ にデプロイ

$envFile = "C:\Users\sinco\Downloads\AiLab\MDなど効率化\deploy.env.ps1"
if (-not (Test-Path $envFile)) {
    Write-Host "エラー: $envFile が見つかりません" -ForegroundColor Red
    exit 1
}
. $envFile

# line-harness 用のリモートパスを上書き（HRwistar → line-harness）
$env:DEPLOY_REMOTE_LH = "/sya-cho.blog/public_html/line-harness"

Write-Host "LINE Harness デプロイを開始します..." -ForegroundColor Cyan
Write-Host "接続先: $env:DEPLOY_HOST:$env:DEPLOY_PORT" -ForegroundColor Gray
Write-Host "リモート: $env:DEPLOY_REMOTE_LH" -ForegroundColor Gray

python "$PSScriptRoot\deploy_line_harness.py"

# パスワードをメモリから消去
$env:DEPLOY_PASS = ""

Write-Host ""
Write-Host "スクリプト終了" -ForegroundColor Green

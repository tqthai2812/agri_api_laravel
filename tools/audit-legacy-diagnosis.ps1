param(
    [string]$ProjectRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath $ProjectRoot).Path
$folders = @('app', 'database\seeders', 'database\factories', 'routes', 'bootstrap', 'config', 'tests')
$pattern = '(?i)diagnos|disease|yolo'

# Chi doc source; khong quet .env, vendor, storage hoac migration lich su.
$rows = @(
    foreach ($folder in $folders) {
        $path = Join-Path $root $folder
        if (-not (Test-Path -LiteralPath $path)) { continue }

        foreach ($file in Get-ChildItem -LiteralPath $path -Recurse -File -Filter '*.php') {
            foreach ($hit in Select-String -LiteralPath $file.FullName -Pattern $pattern -AllMatches) {
                [pscustomobject]@{
                    File = $file.FullName.Substring($root.Length).TrimStart([char[]]'\/')
                    Line = $hit.LineNumber
                    Tokens = (($hit.Matches | ForEach-Object { $_.Value } | Sort-Object -Unique) -join ', ')
                }
            }
        }
    }
)

if ($rows.Count -gt 0) {
    $rows | Format-Table -AutoSize -Wrap
    Write-Output 'Can doi chieu cac file tren truoc khi bo nhom chan doan. Script khong sua file hoac database.'
    exit 1
}

Write-Output 'Khong tim thay token chan doan cu trong cac thu muc PHP da quet.'
Write-Output 'Day la quet token, khong thay the kiem tra route/provider duoc dang ky dong.'
exit 0
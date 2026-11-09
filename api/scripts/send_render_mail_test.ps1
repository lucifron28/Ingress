$ErrorActionPreference = 'Stop'

function Set-SecretEnvironmentVariable {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][string]$Prompt
    )

    $secureValue = Read-Host $Prompt -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureValue)
    try {
        [Environment]::SetEnvironmentVariable(
            $Name,
            [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer),
            'Process'
        )
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
        $secureValue.Dispose()
    }
}

$exitCode = 1
$origin = Read-Host 'Render service HTTPS origin, for example https://ingress-pp2u.onrender.com'
$caBundle = Read-Host 'Path to a trusted PEM CA bundle on this computer'
$sender = Read-Host 'Verified sender address, or onboarding@resend.dev for account-owner testing'
$recipient = Read-Host 'Recipient: enter the email address registered to your Resend account'
$configCache = Join-Path $env:TEMP ('ingress-mail-test-' + [guid]::NewGuid().ToString('N') + '.php')

try {
    if (-not (Test-Path -LiteralPath $caBundle -PathType Leaf)) {
        throw 'The CA bundle path does not exist.'
    }
    if ([string]::IsNullOrWhiteSpace($sender) -or [string]::IsNullOrWhiteSpace($recipient)) {
        throw 'A sender address and Resend account email are required.'
    }

    Set-SecretEnvironmentVariable -Name 'DB_URL' -Prompt 'Paste the Neon direct connection URL with sslmode=verify-full'
    Set-SecretEnvironmentVariable -Name 'APP_KEY' -Prompt 'Paste the APP_KEY shown by Render'
    Set-SecretEnvironmentVariable -Name 'RESEND_API_KEY' -Prompt 'Paste the Resend API key'

    $env:APP_CONFIG_CACHE = $configCache
    $env:APP_ENV = 'production'
    $env:APP_DEBUG = 'false'
    $env:APP_URL = $origin
    $env:INGRESS_RUNTIME = 'cloud'
    $env:INGRESS_DEMO_MODE = 'false'
    $env:TRUSTED_PROXIES = 'REMOTE_ADDR'
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_SSLMODE = 'verify-full'
    $env:DB_SSLROOTCERT = $caBundle
    $env:MAIL_MAILER = 'resend'
    $env:MAIL_FROM_ADDRESS = $sender
    $env:MAIL_FROM_NAME = 'Ingress'
    $env:INGRESS_MAIL_TEST_RECIPIENT = $recipient

    Push-Location (Join-Path $PSScriptRoot '..')
    try {
        php artisan ingress:send-mail-test
        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }
} finally {
    foreach ($name in @('APP_CONFIG_CACHE', 'DB_URL', 'APP_KEY', 'RESEND_API_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'INGRESS_RUNTIME', 'INGRESS_DEMO_MODE', 'TRUSTED_PROXIES', 'DB_CONNECTION', 'DB_SSLMODE', 'DB_SSLROOTCERT', 'MAIL_MAILER', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'INGRESS_MAIL_TEST_RECIPIENT')) {
        Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue
    }
}

exit $exitCode

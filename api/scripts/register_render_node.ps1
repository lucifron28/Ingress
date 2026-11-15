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
$nodeId = (Read-Host 'Unique Event Node ID (letters, numbers, underscore, or hyphen)').Trim()
if ($nodeId -notmatch '\A[a-zA-Z0-9_-]{1,64}\z') {
    throw 'Use 1 to 64 letters, numbers, underscores, or hyphens for the node ID.'
}

if ((Read-Host "Type $nodeId to confirm node registration") -cne $nodeId) {
    throw 'Event Node registration was not confirmed.'
}

$origin = Read-Host 'Render service HTTPS origin, for example https://ingress-pp2u.onrender.com'
$caBundle = Read-Host 'Path to a trusted PEM CA bundle on this computer'
$configCache = Join-Path $env:TEMP ('ingress-node-' + [guid]::NewGuid().ToString('N') + '.php')

try {
    if (-not (Test-Path -LiteralPath $caBundle -PathType Leaf)) {
        throw 'The CA bundle path does not exist.'
    }

    Set-SecretEnvironmentVariable -Name 'DB_URL' -Prompt 'Paste the Neon direct connection URL with sslmode=verify-full'
    Set-SecretEnvironmentVariable -Name 'APP_KEY' -Prompt 'Paste the APP_KEY shown by Render'

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

    Push-Location (Join-Path $PSScriptRoot '..')
    try {
        php artisan ingress:register-node $nodeId
        $exitCode = $LASTEXITCODE
    } finally {
        Pop-Location
    }
} finally {
    foreach ($name in @('APP_CONFIG_CACHE', 'DB_URL', 'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'INGRESS_RUNTIME', 'INGRESS_DEMO_MODE', 'TRUSTED_PROXIES', 'DB_CONNECTION', 'DB_SSLMODE', 'DB_SSLROOTCERT')) {
        Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue
    }
}

exit $exitCode

$ErrorActionPreference = 'Stop'

$managerRoot = (Resolve-Path $PSScriptRoot).Path
$repositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$artifactRoot = Join-Path $managerRoot 'artifacts'
$publishPath = Join-Path $artifactRoot 'win-x64'
$resolvedPublishPath = [System.IO.Path]::GetFullPath($publishPath)
$requiredPrefix = [System.IO.Path]::GetFullPath($artifactRoot) + [System.IO.Path]::DirectorySeparatorChar

if (-not $resolvedPublishPath.StartsWith($requiredPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Publish output must stay inside tools\Ingress.NodeManager\artifacts.'
}

New-Item -ItemType Directory -Path $artifactRoot -Force | Out-Null
$project = Join-Path $managerRoot 'Ingress.NodeManager\Ingress.NodeManager.csproj'
$publishArguments = @(
    'publish',
    $project,
    '--configuration', 'Release',
    '--runtime', 'win-x64',
    '--self-contained', 'true',
    '-p:PublishSingleFile=true',
    '-p:IncludeNativeLibrariesForSelfExtract=true',
    '-p:EnableCompressionInSingleFile=true',
    '-p:DebugType=None',
    '-p:DebugSymbols=false',
    '--output', $publishPath
)

Push-Location $repositoryRoot
try {
    & dotnet @publishArguments
    if ($LASTEXITCODE -ne 0) {
        throw 'dotnet publish failed.'
    }
}
finally {
    Pop-Location
}

$executable = Join-Path $publishPath 'Ingress.NodeManager.exe'
$requiredScripts = @('IngressNetworkManager.ps1', 'IngressNetworkManager.psm1', 'IngressLanFirewall.ps1')
$missingScripts = @($requiredScripts | Where-Object { -not (Test-Path -LiteralPath (Join-Path $publishPath "scripts\$_") -PathType Leaf) })
if (-not (Test-Path -LiteralPath $executable -PathType Leaf) -or $missingScripts.Count -gt 0) {
    throw "The published package is missing required files: $($missingScripts -join ', ')."
}

Write-Output "Published: $executable"
Write-Output "Bundled scripts: $($requiredScripts -join ', ')"

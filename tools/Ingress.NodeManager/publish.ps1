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
$module = Join-Path $publishPath 'scripts\IngressNetworkManager.psm1'
if (-not (Test-Path -LiteralPath $executable) -or -not (Test-Path -LiteralPath $module)) {
    throw 'The executable or bundled Network Manager module is missing from the package.'
}

Write-Output "Published: $executable"
Write-Output "Network Manager: $module"

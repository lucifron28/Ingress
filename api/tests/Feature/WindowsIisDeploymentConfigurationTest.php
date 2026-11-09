<?php

namespace Tests\Feature;

use Tests\TestCase;

class WindowsIisDeploymentConfigurationTest extends TestCase
{
    public function test_iis_disables_directory_listing_and_routes_laravel_requests_safely(): void
    {
        $configuration = file_get_contents(base_path('public/web.config'));

        $this->assertIsString($configuration);
        $this->assertStringContainsString('<directoryBrowse enabled="false" />', $configuration);
        $this->assertStringContainsString('<add segment=".env" />', $configuration);
        $this->assertStringContainsString('<add segment=".git" />', $configuration);
        $this->assertStringContainsString('<add segment="storage" />', $configuration);
        $this->assertStringContainsString('<add segment="vendor" />', $configuration);
        $this->assertStringContainsString('<requestLimits maxAllowedContentLength="33554432" />', $configuration);
        $this->assertStringContainsString('<action type="Rewrite" url="index.php" />', $configuration);
    }
}

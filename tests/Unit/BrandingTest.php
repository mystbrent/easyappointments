<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class BrandingTest extends TestCase
{
    private function src(string $path): string
    {
        return file_get_contents(__DIR__ . '/../../' . $path);
    }

    public function testGeneralScssHasNoBareGreen(): void
    {
        foreach (['assets/css/general.scss', 'assets/css/layouts/booking_layout.scss'] as $file) {
            $css = $this->src($file);
            $total = substr_count($css, '#429a82');
            $wrapped = preg_match_all('/var\(--ea-accent,\s*#429a82\)/', $css);
            $this->assertGreaterThan(0, $total, $file);
            $this->assertSame($total, $wrapped, "$file has a bare #429a82");
        }
    }

    public function testDispatchThemeDefinesAccentVars(): void
    {
        $css = $this->src('assets/css/themes/dispatch.scss');
        $this->assertStringContainsString('--ea-accent: #b54016', $css);
        $this->assertStringContainsString('--ea-accent-ink', $css);
        $this->assertStringContainsString('--ea-bg', $css);
        $this->assertStringContainsString('--ea-ink', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
        $this->assertStringContainsString('--ea-accent: #eb8656', $css);
    }

    public function testThemeColorMetaReadsCompanyColor(): void
    {
        foreach (['booking', 'message', 'account'] as $layout) {
            $php = $this->src("application/views/layouts/{$layout}_layout.php");
            $this->assertMatchesRegularExpression('/theme-color" content="<\?= e\(setting\(\'company_color\'\) \?: \'#35A768\'\)/', $php, $layout);
        }
    }
}

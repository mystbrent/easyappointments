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
        $greens = ['#429a82', '#439a82', '#225d4d', '#0bb98d', '#1a865f'];
        foreach (['assets/css/general.scss', 'assets/css/layouts/booking_layout.scss'] as $file) {
            $css = $this->src($file);
            foreach ($greens as $hex) {
                $total = substr_count($css, $hex);
                $wrapped = preg_match_all('/var\(--ea-accent(-strong)?,\s*' . preg_quote($hex, '/') . '\)/', $css);
                $this->assertSame($total, $wrapped, "$file has a bare $hex");
            }
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

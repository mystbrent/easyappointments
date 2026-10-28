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

    private const V = 'application/views/';

    private function prospectFiles(): array
    {
        $v = self::V;
        return [
            $v . 'components/booking_footer.php', $v . 'components/booking_header.php',
            $v . 'layouts/booking_layout.php', $v . 'layouts/message_layout.php', $v . 'layouts/account_layout.php',
            $v . 'errors/html/error_404.php', $v . 'errors/html/error_general.php', $v . 'errors/html/error_db.php',
            $v . 'errors/html/error_exception.php', $v . 'errors/html/error_php.php',
            $v . 'emails/appointment_saved_email.php', $v . 'emails/appointment_deleted_email.php',
            $v . 'emails/account_recovery_email.php', $v . 'pages/booking_confirmation.php',
        ];
    }

    private function stripComments(string $php): string
    {
        $php = preg_replace('#/\*.*?\*/#s', '', $php);
        $php = preg_replace('#<!--.*?-->#s', '', $php);
        return preg_replace('#^\s*//.*$#m', '', $php);
    }

    public function testNoEaCreditInProspectViews(): void
    {
        foreach ($this->prospectFiles() as $file) {
            $code = $this->stripComments($this->src($file));
            $this->assertDoesNotMatchRegularExpression('/Easy!?Appointments/i', $code, $file);
            $this->assertStringNotContainsString('easyappointments.org', $code, $file);
        }
    }

    public function testFooterSaysPoweredByDispatch(): void
    {
        $v = self::V;
        $files = [
            $v . 'components/booking_footer.php', $v . 'layouts/message_layout.php', $v . 'layouts/account_layout.php',
            $v . 'errors/html/error_404.php', $v . 'errors/html/error_general.php', $v . 'errors/html/error_db.php',
            $v . 'errors/html/error_exception.php', $v . 'errors/html/error_php.php',
            $v . 'emails/appointment_saved_email.php', $v . 'emails/appointment_deleted_email.php',
            $v . 'emails/account_recovery_email.php',
        ];
        foreach ($files as $file) {
            $this->assertStringContainsString('Powered by Dispatch', $this->src($file), $file);
        }
    }

    public function testHeaderNeverFallsBackToEaLogo(): void
    {
        $this->assertStringNotContainsString('assets/img/logo.png', $this->src(self::V . 'components/booking_header.php'));
    }

    public function testEmailFallbackColourIsNeutral(): void
    {
        foreach (['appointment_saved', 'appointment_deleted', 'account_recovery'] as $e) {
            $php = $this->src(self::V . "emails/{$e}_email.php");
            $this->assertStringContainsString("?? '#18181b'", $php, $e);
            $this->assertStringNotContainsString('#429a82', $php, $e);
        }
    }

    public function testConfirmationEmailLineIsConditional(): void
    {
        $php = $this->src(self::V . 'pages/booking_confirmation.php');
        $this->assertMatchesRegularExpression(
            "/setting\('customer_notifications'\).*?appointment_details_was_sent_to_you.*?endif/s",
            $php,
        );
    }

    public function testCopyrightHeadersKept(): void
    {
        $php = $this->src('application/controllers/Booking.php');
        $this->assertStringContainsString('@copyright', $php);
        $this->assertStringContainsString('GPL-3.0', $php);
    }

    public function testErrorDbNeverCallsSetting(): void
    {
        $this->assertStringNotContainsString('setting(', $this->src(self::V . 'errors/html/error_db.php'));
    }
}

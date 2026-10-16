<?php

namespace Modules\Sacraments\Services\Certificates;

use Modules\Sacraments\Certificates\CertificateLayoutTokens;
use Modules\Sacraments\Certificates\CertificateTextFit;
use Modules\Sacraments\Certificates\CertificateThemeCatalog;
use Modules\Sacraments\Certificates\CertificateViewAssembler;

/**
 * Server HTML is the archival visual source of truth (same semantic structure as Angular).
 */
class CertificateHtmlRenderer
{
    public function __construct(
        protected CertificateViewAssembler $views
    ) {}

    /**
     * @param  array<string, mixed>  $projection
     */
    public function render(array $projection): string
    {
        $view = is_array($projection['certificate_view'] ?? null)
            ? $projection['certificate_view']
            : $this->views->assemble($projection);
        $themeId = (string) ($view['themeId'] ?? 'generic');
        $tokens = CertificateThemeCatalog::tokens($themeId);
        $paper = strtoupper((string) ($view['paper'] ?? 'A4')) === 'LETTER' ? 'LETTER' : 'A4';
        $width = $paper === 'LETTER' ? '279.4mm' : '297mm';
        $height = $paper === 'LETTER' ? '215.9mm' : '210mm';
        $fontCss = $this->fontFaceCss();
        $layoutCss = $this->layoutCssVariables();
        $nameFits = $this->recipientNameFits($view, $paper);

        return view('sacraments::certificates.print', [
            'view' => $view,
            'tokens' => $tokens,
            'paper' => $paper,
            'width' => $width,
            'height' => $height,
            'fontCss' => $fontCss,
            'layoutCss' => $layoutCss,
            'nameFits' => $nameFits,
            'h' => static fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ])->render();
    }

    private function fontFaceCss(): string
    {
        $dir = module_path('Sacraments', 'resources/fonts');
        $faces = [
            'Cormorant Garamond' => $dir.'/cormorant-garamond-latin.woff2',
            'EB Garamond' => $dir.'/eb-garamond-latin.woff2',
            'Cinzel' => $dir.'/cinzel-latin.woff2',
            'Playfair Display' => $dir.'/playfair-display-latin.woff2',
        ];
        $css = '';
        foreach ($faces as $family => $path) {
            if (! is_file($path)) {
                continue;
            }
            $b64 = base64_encode((string) file_get_contents($path));
            $css .= "@font-face{font-family:'{$family}';src:url('data:font/woff2;base64,{$b64}') format('woff2');font-display:swap;}";
        }

        return $css;
    }

    private function layoutCssVariables(): string
    {
        $vars = CertificateLayoutTokens::cssVariables();
        $lines = array_map(
            static fn (string $key, string $value) => "{$key}:{$value};",
            array_keys($vars),
            array_values($vars)
        );

        return ':root{'.implode('', $lines).'}';
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, array{fontSizeMm: float, status: string, overflow: bool}>
     */
    private function recipientNameFits(array $view, string $paper): array
    {
        $contentWidth = CertificateLayoutTokens::contentWidthMm($paper);
        $type = (string) ($view['sacramentType'] ?? 'GENERIC_REGISTRY');
        $fits = [];

        if ($type === 'HOLY_MATRIMONY') {
            $columnWidth = max(60.0, ($contentWidth - 22.0) / 2);
            $groom = (string) ($view['groom']['fullName'] ?? $view['groomName'] ?? '');
            $bride = (string) ($view['bride']['fullName'] ?? $view['brideName'] ?? '');
            $fits['groom'] = CertificateTextFit::fitRecipientName($groom, $columnWidth);
            $fits['bride'] = CertificateTextFit::fitRecipientName($bride, $columnWidth);

            return $fits;
        }

        $recipient = (string) ($view['recipientName'] ?? '');
        $fits['recipient'] = CertificateTextFit::fitRecipientName($recipient, $contentWidth);

        return $fits;
    }
}

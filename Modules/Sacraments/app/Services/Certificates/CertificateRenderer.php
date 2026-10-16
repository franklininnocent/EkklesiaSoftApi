<?php

namespace Modules\Sacraments\Services\Certificates;

/**
 * Chooses Chromium PDF when available; otherwise the v1 text PDF fallback.
 */
class CertificateRenderer
{
    public function __construct(
        protected CertificateHtmlRenderer $html,
        protected ChromiumCertificatePdfRenderer $chromium,
        protected CertificatePdfRenderer $textPdf
    ) {}

    /**
     * @param  array<string, mixed>  $projection
     * @return array{html:string, pdf:?string, pdf_engine:string}
     */
    public function renderIssueArtifacts(array $projection): array
    {
        $html = $this->html->render($projection);
        if ($this->chromium->isAvailable()) {
            try {
                return [
                    'html' => $html,
                    'pdf' => $this->chromium->render($html, $projection),
                    'pdf_engine' => 'chromium',
                ];
            } catch (\Throwable) {
                // Fall through to text PDF so issue still produces a downloadable file.
            }
        }

        return [
            'html' => $html,
            'pdf' => $this->textPdf->render($projection),
            'pdf_engine' => 'text_fallback',
        ];
    }
}

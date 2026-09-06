<?php

namespace Modules\Sacraments\Services\Certificates;

use Symfony\Component\Process\Process;

class ChromiumCertificatePdfRenderer
{
    public function isAvailable(): bool
    {
        $path = $this->binary();

        return is_string($path) && $path !== '' && is_executable($path);
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    public function render(string $html, array $projection = []): string
    {
        if (! $this->isAvailable()) {
            throw new \RuntimeException('Chromium is not available.');
        }

        $dir = sys_get_temp_dir();
        $htmlPath = $dir.'/cert-'.uniqid('', true).'.html';
        $pdfPath = $dir.'/cert-'.uniqid('', true).'.pdf';
        file_put_contents($htmlPath, $html);

        $process = new Process([
            $this->binary(),
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--no-pdf-header-footer',
            '--print-to-pdf-no-header',
            '--print-to-pdf='.$pdfPath,
            $htmlPath,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
            if (! $process->isSuccessful() || ! is_file($pdfPath)) {
                throw new \RuntimeException(trim($process->getErrorOutput().' '.$process->getOutput()) ?: 'Chromium PDF failed.');
            }

            return (string) file_get_contents($pdfPath);
        } finally {
            @unlink($htmlPath);
            @unlink($pdfPath);
        }
    }

    public function binary(): ?string
    {
        $configured = config('sacraments.certificates.chromium_path') ?: env('CHROMIUM_PATH');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        foreach (['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome-stable'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}

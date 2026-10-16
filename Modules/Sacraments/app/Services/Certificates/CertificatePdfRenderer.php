<?php

namespace Modules\Sacraments\Services\Certificates;

use Modules\Sacraments\Certificates\CertificateTemplateRegistry;

/**
 * Dependency-free PDF renderer for Phase 8 certificates.
 * Content is taken only from frozen projection_json (never live member data).
 */
class CertificatePdfRenderer
{
    public function __construct(
        protected CertificateTemplateRegistry $templates
    ) {}

    /**
     * @param  array<string, mixed>  $projection
     */
    public function render(array $projection): string
    {
        $typeCode = (string) ($projection['sacrament']['type_code'] ?? '');
        $meta = $this->templates->forTypeCode($typeCode);
        $lines = $this->linesFor($meta['title'], $projection, $typeCode);

        return $this->buildPdf($lines);
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return list<string>
     */
    private function linesFor(string $title, array $projection, string $typeCode): array
    {
        $s = $projection['sacrament'] ?? [];
        $participants = $projection['participants'] ?? [];
        $byRole = [];
        foreach ($participants as $row) {
            $role = (string) ($row['role'] ?? '');
            $name = (string) ($row['display_name'] ?? '');
            if ($role === '') {
                continue;
            }
            if (! isset($byRole[$role])) {
                $byRole[$role] = [];
            }
            $byRole[$role][] = $name !== '' ? $name : '—';
        }

        $name = static function (array $byRole, string $role, ?string $fallback = null) {
            if (! empty($byRole[$role][0])) {
                return $byRole[$role][0];
            }

            return $fallback ?: '—';
        };

        $lines = [
            $title,
            'Sacramental Register',
            '',
            'Date: '.($s['date_administered'] ?? '—'),
            'Place: '.($s['place_administered'] ?? '—'),
            '',
        ];

        if ($typeCode === 'BAPTISM') {
            $lines[] = 'Recipient: '.$name($byRole, 'recipient', $s['recipient_name'] ?? null);
            $lines[] = 'Father: '.$name($byRole, 'father', $s['father_name'] ?? null);
            $lines[] = 'Mother: '.$name($byRole, 'mother', $s['mother_name'] ?? null);
            $lines[] = 'Godfather: '.$name($byRole, 'godfather', $s['godparent1_name'] ?? null);
            $lines[] = 'Godmother: '.$name($byRole, 'godmother', $s['godparent2_name'] ?? null);
            $lines[] = 'Minister: '.$name($byRole, 'minister', $s['minister_name'] ?? null);
        } elseif ($typeCode === 'MATRIMONY') {
            $lines[] = 'Bride: '.$name($byRole, 'bride', $s['marriage_bride_full_name'] ?? null);
            $lines[] = 'Groom: '.$name($byRole, 'groom', $s['marriage_groom_full_name'] ?? null);
            if (! empty($byRole['witness'])) {
                $lines[] = 'Witnesses: '.implode(', ', $byRole['witness']);
            }
            $lines[] = 'Minister: '.$name($byRole, 'minister', $s['minister_name'] ?? null);
            if (! empty($s['marriage_bride_diocese_name'])) {
                $lines[] = 'Bride diocese: '.$s['marriage_bride_diocese_name'];
            }
            if (! empty($s['marriage_groom_diocese_name'])) {
                $lines[] = 'Groom diocese: '.$s['marriage_groom_diocese_name'];
            }
        } elseif ($typeCode === 'CONFIRMATION') {
            $lines[] = 'Confirmand: '.$name($byRole, 'recipient', $s['recipient_name'] ?? null);
            if (! empty($byRole['sponsor'])) {
                $lines[] = 'Sponsors: '.implode(', ', $byRole['sponsor']);
            }
            $lines[] = 'Minister: '.$name($byRole, 'minister', $s['minister_name'] ?? null);
        } elseif ($typeCode === 'EUCHARIST') {
            $lines[] = 'Communicant: '.$name($byRole, 'recipient', $s['recipient_name'] ?? null);
            if (! empty($s['event_subtype'])) {
                $lines[] = 'Event: '.$s['event_subtype'];
            }
            $lines[] = 'Minister: '.$name($byRole, 'minister', $s['minister_name'] ?? null);
        } elseif ($typeCode === 'ANOINTING') {
            $lines[] = 'Recipient: '.$name($byRole, 'recipient', $s['recipient_name'] ?? null);
            if (! empty($s['place_classification'])) {
                $lines[] = 'Location type: '.$s['place_classification'];
            }
            $lines[] = 'Minister: '.$name($byRole, 'minister', $s['minister_name'] ?? null);
        } elseif ($typeCode === 'HOLY_ORDERS') {
            $lines[] = 'Candidate: '.$name($byRole, 'candidate', $s['recipient_name'] ?? null);
            $ordination = $s['typed_attributes']['ordination_type'] ?? null;
            if ($ordination) {
                $lines[] = 'Ordination: '.$ordination;
            }
            if (! empty($s['typed_attributes']['diocese_name'])) {
                $lines[] = 'Diocese: '.$s['typed_attributes']['diocese_name'];
            }
            $lines[] = 'Ordaining Bishop: '.$name($byRole, 'minister', $s['minister_name'] ?? null);
            if (! empty($byRole['co_consecrator'])) {
                $lines[] = 'Co-consecrators: '.implode(', ', $byRole['co_consecrator']);
            }
        } else {
            $lines[] = 'Recipient: '.$name($byRole, 'recipient', $s['recipient_name'] ?? null);
            $lines[] = 'Minister: '.$name($byRole, 'minister', $s['minister_name'] ?? null);
        }

        $lines[] = '';
        if (! empty($s['certificate_number'])) {
            $lines[] = 'Certificate No: '.$s['certificate_number'];
        }
        if (! empty($s['book_number']) || ! empty($s['page_number'])) {
            $lines[] = 'Book/Page: '.trim(($s['book_number'] ?? '').' / '.($s['page_number'] ?? ''), ' /');
        }

        $lines[] = '';
        $lines[] = 'Issued from sacramental register snapshots.';
        $lines[] = 'Language: '.($projection['language'] ?? 'en');

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function buildPdf(array $lines): string
    {
        $content = "BT\n/F1 12 Tf\n14 TL\n50 780 Td\n";
        foreach ($lines as $i => $line) {
            $escaped = $this->escapePdfText($line);
            if ($i === 0) {
                $content .= "/F1 16 Tf\n({$escaped}) Tj\n/F1 12 Tf\nT*\n";
            } else {
                $content .= "({$escaped}) Tj\nT*\n";
            }
        }
        $content .= 'ET';

        $objects = [];
        $objects[] = '1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj';
        $objects[] = '2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj';
        $objects[] = '3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>endobj';
        $objects[] = '4 0 obj<< /Length '.strlen($content).' >>stream'."\n".$content."\nendstream endobj";
        $objects[] = '5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj."\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefPos}\n%%EOF";

        return $pdf;
    }

    private function escapePdfText(string $text): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}

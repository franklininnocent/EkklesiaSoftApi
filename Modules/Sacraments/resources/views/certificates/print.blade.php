@php
  $h = $h ?? static fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $terms = $view['terminology'] ?? [];
  $church = $view['church'] ?? [];
  $registry = $view['registry'] ?? [];
  $type = $view['sacramentType'] ?? 'GENERIC_REGISTRY';
  $isMatrimony = $type === 'HOLY_MATRIMONY';
  $date = $view['dateOfEvent'] ?? '';
  $formatDate = static function ($value): string {
      if (! $value) {
          return '';
      }
      try {
          return (new DateTimeImmutable((string) $value))->format('j F Y');
      } catch (Throwable) {
          return (string) $value;
      }
  };
  $dateDisplay = $formatDate($date);
  $birthDisplay = $formatDate($view['dateOfBirth'] ?? null);
  $recipientFont = fn (string $key): string => ($nameFits[$key]['fontSizeMm'] ?? 10).'mm';
  $hasRegistry = !empty($registry['bookNumber']) || !empty($registry['pageNumber']) || !empty($registry['registryEntry']) || !empty($registry['certificateNumber']) || !empty($view['issuedAt']);
  $issuedDisplay = $formatDate($view['issuedAt'] ?? null);
  $detailCount = ($dateDisplay ? 1 : 0)
      + (!empty($view['placeOfEvent']) ? 1 : 0)
      + (!$isMatrimony && !empty($view['dateOfBirth']) ? 1 : 0)
      + (!$isMatrimony && !empty($view['placeOfBirth']) ? 1 : 0)
      + (!$isMatrimony && !empty($view['fatherName']) ? 1 : 0)
      + (!$isMatrimony && !empty($view['motherName']) ? 1 : 0)
      + (!$isMatrimony && !empty($view['sponsors']) ? 1 : 0)
      + ($isMatrimony && !empty($view['witnesses']) && is_array($view['witnesses']) ? count(array_filter($view['witnesses'])) : 0)
      + (!empty($view['witnesses']) && !$isMatrimony ? 1 : 0)
      + (!empty($view['ministerName']) && !$isMatrimony ? 1 : 0);
  $compactLower = $isMatrimony || $detailCount >= 5;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{{ $h($view['certificateTitle'] ?? 'Certificate') }}</title>
  <style>
    {!! $fontCss !!}
    {!! $layoutCss !!}
    @page { size: {{ $paper === 'LETTER' ? 'letter' : 'A4' }} landscape; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; }
    .cert-page {
      width: {{ $width }};
      height: {{ $height }};
      background: radial-gradient(ellipse at 50% 0%, {{ $tokens['accent'] }}14, transparent 42%), {{ $tokens['pageBackground'] }};
      color: {{ $tokens['ink'] }};
      font-family: "Cormorant Garamond", "EB Garamond", Georgia, serif;
      font-size: var(--cert-size-detail);
      line-height: var(--cert-line-height-body);
      position: relative;
      overflow: hidden;
    }
    .outer { position: absolute; inset: 4.5mm; border: 0.9mm solid {{ $tokens['borderOuter'] }}; }
    .inner { position: absolute; inset: 7.2mm; border: 0.35mm solid {{ $tokens['borderInner'] }}; }
    .corner { position: absolute; width: 8mm; height: 8mm; border: 0.45mm solid {{ $tokens['borderInner'] }}; }
    .tl { top: 8.5mm; left: 8.5mm; border-right: 0; border-bottom: 0; }
    .tr { top: 8.5mm; right: 8.5mm; border-left: 0; border-bottom: 0; }
    .bl { bottom: 8.5mm; left: 8.5mm; border-right: 0; border-top: 0; }
    .br { bottom: 8.5mm; right: 8.5mm; border-left: 0; border-top: 0; }
    .safe {
      position: relative;
      z-index: 1;
      padding: var(--cert-safe-pt) var(--cert-safe-px) var(--cert-safe-pb);
      height: 100%;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }
    .header { display: grid; grid-template-columns: 22mm 1fr 22mm; gap: 6mm; align-items: center; flex-shrink: 0; }
    .emblem { width: 22mm; height: 22mm; color: {{ $tokens['accent'] }}; }
    .emblem svg { width: 100%; height: 100%; }
    .kicker {
      text-align: center;
      letter-spacing: 1.4mm;
      text-transform: uppercase;
      font-size: var(--cert-size-kicker);
      color: {{ $tokens['mutedInk'] }};
      margin: 0;
      font-family: Cinzel, serif;
    }
    h1 {
      font-family: "Playfair Display", Georgia, serif;
      color: {{ $tokens['accent'] }};
      font-size: var(--cert-size-title);
      font-weight: 400;
      text-align: center;
      margin: 1.2mm 0 1.6mm;
      line-height: 1.08;
    }
    .church { text-align: center; font-family: Cinzel, serif; font-size: var(--cert-size-church); margin: 0; line-height: 1.2; }
    .muted { text-align: center; color: {{ $tokens['mutedInk'] }}; font-size: var(--cert-size-meta); margin: 0.6mm 0 0; line-height: 1.25; }
    .jurisdiction {
      text-align: center;
      color: {{ $tokens['mutedInk'] }};
      font-size: var(--cert-size-meta);
      margin: 0.6mm 0 0;
      text-transform: uppercase;
      letter-spacing: 0.6mm;
      line-height: 1.25;
    }
    .who { text-align: center; margin: var(--cert-gap-section) 0 var(--cert-gap-after-recipient); flex-shrink: 0; }
    .who.matrimony { display: grid; grid-template-columns: 1fr 1fr; align-items: start; gap: 10mm; }
    .spouse-card { min-width: 0; padding: 0 2mm; text-align: center; }
    .spouse-card .heading {
      display: block;
      font-family: Cinzel, serif;
      font-size: var(--cert-size-recipient-label);
      letter-spacing: 0.9mm;
      text-transform: uppercase;
      color: {{ $tokens['mutedInk'] }};
      margin-bottom: 1.5mm;
    }
    .spouse-card .name {
      font-family: "Playfair Display", serif;
      font-size: var(--cert-size-recipient);
      margin: 0;
      font-weight: 400;
      line-height: 1.12;
      overflow-wrap: anywhere;
    }
    .spouse-card .status { font-size: var(--cert-size-meta); margin-top: 1.2mm; color: {{ $tokens['ink'] }}; line-height: 1.3; }
    .sp-field { margin-top: 2mm; text-align: center; }
    .sp-field .flbl {
      display: block;
      font-size: var(--cert-size-label);
      letter-spacing: 0.35mm;
      text-transform: uppercase;
      color: {{ $tokens['mutedInk'] }};
      line-height: 1.2;
      margin-bottom: 0.5mm;
    }
    .sp-field .fval {
      display: block;
      font-size: var(--cert-size-field-value);
      color: {{ $tokens['ink'] }};
      line-height: 1.3;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 3mm 8mm;
      flex-shrink: 0;
      align-content: start;
    }
    .grid > div { min-width: 0; }
    .lbl {
      display: block;
      font-size: var(--cert-size-label);
      text-transform: uppercase;
      letter-spacing: 0.35mm;
      color: {{ $tokens['mutedInk'] }};
      line-height: 1.2;
      margin-bottom: 0.5mm;
    }
    .val {
      font-size: var(--cert-size-detail);
      margin: 0;
      padding-bottom: 0.8mm;
      border-bottom: 0.2mm solid {{ $tokens['ink'] }}33;
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.3;
    }
    .registry.footer {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 1.2mm;
      margin-top: var(--cert-gap-section);
      padding: 2.5mm 5mm;
      border: 0.2mm solid {{ $tokens['accent'] }}55;
      font-size: var(--cert-size-registry);
      color: {{ $tokens['mutedInk'] }};
      flex-shrink: 0;
    }
    .registry-line {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      align-items: center;
    }
    .registry.footer .registry-line span + span::before {
      content: '|';
      margin: 0 3.5mm;
      color: {{ $tokens['mutedInk'] }};
      opacity: 0.45;
      font-weight: 300;
    }
    .registry-issued {
      font-size: var(--cert-size-registry);
      color: {{ $tokens['mutedInk'] }};
      text-align: center;
    }
    .who .lbl {
      display: block;
      font-size: var(--cert-size-recipient-label);
      letter-spacing: 0.8mm;
      text-transform: uppercase;
      color: {{ $tokens['mutedInk'] }};
    }
    .who .name {
      font-family: "Playfair Display", serif;
      font-size: var(--cert-size-recipient);
      margin: 1.2mm 0 0;
      font-weight: 400;
      line-height: 1.12;
      overflow-wrap: anywhere;
    }
    .lower {
      flex: 1 1 auto;
      display: flex;
      flex-direction: column;
      min-height: 0;
      margin-top: var(--cert-gap-section);
    }
    .lower__spacer {
      flex: 1 1 auto;
      min-height: var(--cert-spacer-min);
      max-height: var(--cert-spacer-max);
    }
    .lower.matrimony .lower__spacer,
    .lower.compact .lower__spacer { max-height: 8mm; }
    .signs {
      display: grid;
      grid-template-columns: 1fr 1fr 26mm;
      gap: 8mm;
      align-items: end;
      flex-shrink: 0;
    }
    .line {
      border-bottom: 0.25mm solid {{ $tokens['ink'] }};
      min-height: 8mm;
      font-size: var(--cert-size-signature-line);
      line-height: 1.2;
    }
    .seal {
      width: 26mm;
      height: 20mm;
      border: 0.35mm dashed {{ $tokens['accent'] }};
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: var(--cert-size-signature-label);
      color: {{ $tokens['accent'] }};
      text-align: center;
    }
    .foot {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      margin-top: var(--cert-gap-section);
      font-size: var(--cert-size-footer);
      color: {{ $tokens['mutedInk'] }};
      flex-shrink: 0;
    }
    .foot span { line-height: 1.3; max-width: 75%; }
    .qr img { width: 16mm; height: 16mm; }
  </style>
</head>
<body>
  <div class="cert-page">
    <div class="outer"></div>
    <div class="inner"></div>
    <span class="corner tl"></span>
    <span class="corner tr"></span>
    <span class="corner bl"></span>
    <span class="corner br"></span>
    <div class="safe">
      <div class="header">
        <div class="emblem">
          <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="30" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M32 10v44M18 22h28" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><path d="M22 14c8 6 8 30 0 36M42 14c-8 6-8 30 0 36" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        </div>
        <div>
          <p class="kicker">{{ $h($view['subtitle'] ?? $terms['subtitle'] ?? '') }}</p>
          <h1>{{ $h($view['certificateTitle'] ?? '') }}</h1>
          <p class="church">{{ $h($church['name'] ?? '') }}</p>
          @if (!empty($church['diocese']))<p class="jurisdiction">{{ $h($church['diocese']) }}</p>@endif
          @if (!empty($church['address']))<p class="muted">{{ $h($church['address']) }}</p>@endif
        </div>
        <div></div>
      </div>
      @if ($isMatrimony)
        <div class="who matrimony">
          <div class="spouse-card">
            <span class="heading">{{ $h($terms['brideLabel'] ?? 'Bride') }}</span>
            <p class="name" style="font-size: {{ $recipientFont('bride') }}">{{ $h($view['bride']['fullName'] ?? $view['brideName'] ?? '') }}</p>
            @if (!empty($view['bride']['baptismalStatusLabel']))
              <div class="status">{{ $h($view['bride']['baptismalStatusLabel']) }}</div>
            @endif
            @if (!empty($view['bride']['fatherName']))
              <div class="sp-field">
                <span class="flbl">{{ $h($terms['fatherLabel'] ?? "Father's Name") }}</span>
                <span class="fval">{{ $h($view['bride']['fatherName']) }}</span>
              </div>
            @endif
            @if (!empty($view['bride']['motherName']))
              <div class="sp-field">
                <span class="flbl">{{ $h($terms['motherLabel'] ?? "Mother's Name") }}</span>
                <span class="fval">{{ $h($view['bride']['motherName']) }}</span>
              </div>
            @endif
            @if (!empty($view['bride']['parishResidence']))
              <div class="sp-field">
                <span class="flbl">{{ $h($terms['parishResidenceLabel'] ?? 'Parish / residence') }}</span>
                <span class="fval">{{ $h($view['bride']['parishResidence']) }}</span>
              </div>
            @endif
          </div>
          <div class="spouse-card">
            <span class="heading">{{ $h($terms['groomLabel'] ?? 'Groom') }}</span>
            <p class="name" style="font-size: {{ $recipientFont('groom') }}">{{ $h($view['groom']['fullName'] ?? $view['groomName'] ?? '') }}</p>
            @if (!empty($view['groom']['baptismalStatusLabel']))
              <div class="status">{{ $h($view['groom']['baptismalStatusLabel']) }}</div>
            @endif
            @if (!empty($view['groom']['fatherName']))
              <div class="sp-field">
                <span class="flbl">{{ $h($terms['fatherLabel'] ?? "Father's Name") }}</span>
                <span class="fval">{{ $h($view['groom']['fatherName']) }}</span>
              </div>
            @endif
            @if (!empty($view['groom']['motherName']))
              <div class="sp-field">
                <span class="flbl">{{ $h($terms['motherLabel'] ?? "Mother's Name") }}</span>
                <span class="fval">{{ $h($view['groom']['motherName']) }}</span>
              </div>
            @endif
            @if (!empty($view['groom']['parishResidence']))
              <div class="sp-field">
                <span class="flbl">{{ $h($terms['parishResidenceLabel'] ?? 'Parish / residence') }}</span>
                <span class="fval">{{ $h($view['groom']['parishResidence']) }}</span>
              </div>
            @endif
          </div>
        </div>
      @else
        <div class="who">
          <span class="lbl">{{ $h($terms['recipientLabel'] ?? '') }}</span>
          <p class="name" style="font-size: {{ $recipientFont('recipient') }}">{{ $h($view['recipientName'] ?? '') }}</p>
        </div>
      @endif
      <div class="grid">
        @if ($dateDisplay)
          <div><div class="lbl">{{ $h($terms['dateLabel'] ?? 'Date') }}</div><div class="val">{{ $h($dateDisplay) }}</div></div>
        @endif
        @if (!empty($view['placeOfEvent']))
          <div><div class="lbl">{{ $h($terms['placeLabel'] ?? 'Place') }}</div><div class="val">{{ $h($view['placeOfEvent']) }}</div></div>
        @endif
        @if (!$isMatrimony && !empty($view['dateOfBirth']))
          <div><div class="lbl">{{ $h($terms['dateOfBirthLabel'] ?? 'Date of birth') }}</div><div class="val">{{ $h($birthDisplay) }}</div></div>
        @endif
        @if (!$isMatrimony && !empty($view['placeOfBirth']))
          <div><div class="lbl">{{ $h($terms['placeOfBirthLabel'] ?? 'Place of birth') }}</div><div class="val">{{ $h($view['placeOfBirth']) }}</div></div>
        @endif
        @if (!$isMatrimony && !empty($view['fatherName']))
          <div><div class="lbl">{{ $h($terms['fatherLabel'] ?? 'Father') }}</div><div class="val">{{ $h($view['fatherName']) }}</div></div>
        @endif
        @if (!$isMatrimony && !empty($view['motherName']))
          <div><div class="lbl">{{ $h($terms['motherLabel'] ?? 'Mother') }}</div><div class="val">{{ $h($view['motherName']) }}</div></div>
        @endif
        @if (!$isMatrimony && !empty($view['sponsors']))
          <div><div class="lbl">{{ $h($terms['sponsorsLabel'] ?? 'Sponsors') }}</div><div class="val">{{ $h(is_array($view['sponsors']) ? implode(', ', $view['sponsors']) : $view['sponsors']) }}</div></div>
        @endif
        @if ($isMatrimony && !empty($view['witnesses']) && is_array($view['witnesses']))
          @foreach ($view['witnesses'] as $i => $witnessName)
            <div><div class="lbl">{{ $h(($i === 0 ? ($terms['witness1Label'] ?? 'Witness 1') : ($terms['witness2Label'] ?? 'Witness '.($i + 1)))) }}</div><div class="val">{{ $h($witnessName) }}</div></div>
          @endforeach
        @elseif (!empty($view['witnesses']) && !$isMatrimony)
          <div><div class="lbl">{{ $h($terms['witnessesLabel'] ?? 'Witnesses') }}</div><div class="val">{{ $h(is_array($view['witnesses']) ? implode(', ', $view['witnesses']) : $view['witnesses']) }}</div></div>
        @endif
        @if (!empty($view['ministerName']) && !$isMatrimony)
          <div><div class="lbl">{{ $h($terms['ministerLabel'] ?? 'Minister') }}</div><div class="val">{{ $h($view['ministerName']) }}</div></div>
        @endif
      </div>
      <div class="lower{{ $isMatrimony ? ' matrimony' : '' }}{{ $compactLower && !$isMatrimony ? ' compact' : '' }}">
        <div class="lower__spacer" aria-hidden="true"></div>
        <div class="signs">
          <div>
            <div class="line">{{ $h($view['ministerName'] ?? '') }}</div>
            <div class="lbl">{{ $h($terms['ministerLabel'] ?? 'Minister') }}</div>
          </div>
          <div>
            <div class="line"></div>
            <div class="lbl">{{ $h($terms['registrarLabel'] ?? 'Registrar') }}</div>
          </div>
          <div class="seal">{{ $h($terms['sealLabel'] ?? 'Seal') }}</div>
        </div>
        @if ($hasRegistry)
          <div class="registry footer">
            <div class="registry-line">
              @if (!empty($registry['bookNumber']))<span>{{ $h($terms['registryBookLabel'] ?? 'Book') }} {{ $h($registry['bookNumber']) }}</span>@endif
              @if (!empty($registry['pageNumber']))<span>{{ $h($terms['registryPageLabel'] ?? 'Page') }} {{ $h($registry['pageNumber']) }}</span>@endif
              @if (!empty($registry['registryEntry']))<span>{{ $h($terms['registryEntryLabel'] ?? 'Entry No.') }} {{ $h($registry['registryEntry']) }}</span>@endif
              @if (!empty($registry['certificateNumber']))<span>{{ $h($terms['certificateNumberLabel'] ?? 'Certificate No.') }} {{ $h($registry['certificateNumber']) }}</span>@endif
            </div>
            @if ($issuedDisplay)
              <div class="registry-issued">{{ $h($terms['issuedAtLabel'] ?? 'Date of Issuance') }}: {{ $h($issuedDisplay) }}</div>
            @endif
          </div>
        @endif
        <div class="foot">
          <span>{{ $h($terms['issuedNotice'] ?? '') }}</span>
          @if (!empty($view['verificationQrDataUri']))
            <div class="qr"><img src="{{ $view['verificationQrDataUri'] }}" alt=""></div>
          @endif
        </div>
      </div>
    </div>
  </div>
</body>
</html>

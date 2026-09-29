@php
    use App\Support\Email\EmailTheme;
    $t = EmailTheme::inlineBaseStyles();
    $productName = $productName ?? EmailTheme::productName();
    $preheader = $preheader ?? '';
    $tenantName = $tenantName ?? null;
    $dir = $dir ?? 'ltr';
@endphp
<!DOCTYPE html>
<html lang="en" dir="{{ $dir }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $productName }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 640px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .email-pad { padding-left: 16px !important; padding-right: 16px !important; }
            .email-stack { display: block !important; width: 100% !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:{{ $t['background'] }};font-family:{{ $t['fontFamily'] }};color:{{ $t['text'] }};">
    <x-email.preheader :text="$preheader" />

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:{{ $t['background'] }};">
        <tr>
            <td align="center" style="padding:{{ $t['pagePad'] }} 12px;">
                <!--[if mso]>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="{{ $t['containerWidth'] }}"><tr><td>
                <![endif]-->
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="email-container" style="max-width:{{ $t['containerWidth'] }}px;width:100%;background-color:{{ $t['surface'] }};border:1px solid {{ $t['border'] }};border-radius:{{ $t['cardRadius'] }};overflow:hidden;">
                    <tr>
                        <td>
                            <x-email.header :product-name="$productName" :tenant-name="$tenantName" />
                        </td>
                    </tr>
                    <tr>
                        <td class="email-pad" style="padding:{{ $t['sectionGap'] }} {{ $t['pagePad'] }};font-family:{{ $t['fontFamily'] }};font-size:{{ $t['bodySize'] }};line-height:{{ $t['bodyLineHeight'] }};color:{{ $t['text'] }};word-break:break-word;overflow-wrap:anywhere;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <x-email.footer :product-name="$productName" />
                        </td>
                    </tr>
                </table>
                <!--[if mso]>
                </td></tr></table>
                <![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>

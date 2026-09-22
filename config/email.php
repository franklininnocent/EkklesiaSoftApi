<?php

/**
 * EkklesiaSoft Email Design System — single source of truth for transactional mail.
 * Colors align with Church Financial OS tokens (not the legacy purple login gradient).
 */
return [

    'product_name' => env('EMAIL_PRODUCT_NAME', 'EkklesiaSoft'),

    'logo_url' => env('EMAIL_LOGO_URL'),

    'support_address' => env('EMAIL_SUPPORT_ADDRESS'),

    'container_width' => 640,

    'colors' => [
        'primary' => '#2563eb',
        'primary_text' => '#ffffff',
        'background' => '#f8fafc',
        'surface' => '#ffffff',
        'text' => '#0f172a',
        'text_secondary' => '#334155',
        'muted' => '#64748b',
        'border' => '#e2e8f0',
        'divider' => '#e2e8f0',
        'success' => '#1b4332',
        'success_soft' => '#d8f3dc',
        'warning' => '#b45309',
        'warning_soft' => '#fef3c7',
        'danger' => '#b91c1c',
        'danger_soft' => '#fee2e2',
        'info' => '#075985',
        'info_soft' => '#e0f2fe',
        'link' => '#2563eb',
        'header_bar' => '#2563eb',
        'preheader' => '#f8fafc',
    ],

    'typography' => [
        'font_family' => "Segoe UI, Roboto, Helvetica, Arial, sans-serif",
        'body_size' => '16px',
        'body_line_height' => '1.5',
        'heading_size' => '22px',
        'heading_line_height' => '1.3',
        'section_size' => '18px',
        'secondary_size' => '14px',
        'label_size' => '13px',
        'footer_size' => '13px',
        'preheader_size' => '1px',
        'button_size' => '16px',
    ],

    'spacing' => [
        'page_pad' => '24px',
        'section_gap' => '20px',
        'card_pad' => '16px',
        'button_pad_y' => '12px',
        'button_pad_x' => '24px',
    ],

    'radius' => [
        'card' => '10px',
        'button' => '8px',
    ],

    'button' => [
        'min_height' => '44px',
    ],

];

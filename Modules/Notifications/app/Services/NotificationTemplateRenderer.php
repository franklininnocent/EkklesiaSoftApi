<?php

namespace Modules\Notifications\Services;

class NotificationTemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function render(string $template, array $data): string
    {
        return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function (array $matches) use ($data): string {
            $key = $matches[1];

            return isset($data[$key]) ? (string) $data[$key] : '';
        }, $template) ?? $template;
    }
}

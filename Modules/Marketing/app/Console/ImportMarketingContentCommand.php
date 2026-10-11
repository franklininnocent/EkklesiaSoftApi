<?php

namespace Modules\Marketing\Console;

use Illuminate\Console\Command;
use Modules\Marketing\Services\MarketingContentService;

class ImportMarketingContentCommand extends Command
{
    protected $signature = 'marketing:import-content {path : Absolute or relative path to JSON file} {--notes=}';

    protected $description = 'Publish marketing site content from a JSON file (ops / Super Admin CLI).';

    public function handle(MarketingContentService $marketingContent): int
    {
        $path = $this->argument('path');

        if (! is_string($path) || ! is_readable($path)) {
            $this->error('File not readable: '.$path);

            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error('Could not read file.');

            return self::FAILURE;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error('Invalid JSON.');

            return self::FAILURE;
        }

        try {
            $revision = $marketingContent->publish($decoded, null, $this->option('notes'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Published revision #'.$revision->id);

        return self::SUCCESS;
    }
}

<?php

namespace Modules\MassIntentions\Console\Commands;

use Illuminate\Console\Command;
use Modules\MassIntentions\Services\MassGenerationHealthService;

class CheckMassGenerationHealthCommand extends Command
{
    protected $signature = 'mass-intentions:check-generation-health';

    protected $description = 'List parishes whose Mass occurrence generation cursor is behind the attention threshold';

    public function handle(MassGenerationHealthService $health): int
    {
        $lagging = $health->tenantsRequiringAttention();

        if ($lagging === []) {
            $this->info('All active regular schedules meet the generation cursor threshold.');

            return self::SUCCESS;
        }

        $this->error(count($lagging).' tenant(s) need Mass generation attention:');

        foreach ($lagging as $row) {
            $this->line(sprintf(
                '  tenant_id=%d reason=%s through=%s error=%s',
                $row['tenant_id'],
                $row['reason'],
                $row['last_generated_through'] ?? 'null',
                $row['last_error'] ?? 'null'
            ));
        }

        return self::FAILURE;
    }
}

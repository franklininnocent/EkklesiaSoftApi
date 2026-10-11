<?php

namespace Modules\Marketing\Tests\Support;

use Modules\Authentication\Models\User;
use Modules\Marketing\Services\MarketingPlanCatalogService;

trait PublishesMarketingPlanCatalog
{
    protected function publishMarketingPlanCatalog(?User $actor = null): void
    {
        $catalog = app(MarketingPlanCatalogService::class);
        if ($actor === null && method_exists($this, 'asSuperAdmin')) {
            $actor = $this->asSuperAdmin()['user'];
        }
        if ($actor === null) {
            throw new \RuntimeException('Super Admin actor required to publish marketing plan catalog.');
        }

        $revision = $catalog->generatePreview($actor);
        $published = $catalog->publishRevision($actor, (int) $revision->id, $revision->payload_hash);
        $catalog->afterPublishCommit($published);
    }
}

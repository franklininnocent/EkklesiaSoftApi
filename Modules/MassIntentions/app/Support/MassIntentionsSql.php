<?php

namespace Modules\MassIntentions\Support;

use Illuminate\Support\Facades\DB;

final class MassIntentionsSql
{
    public static function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}

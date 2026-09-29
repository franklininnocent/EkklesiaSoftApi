<?php

namespace Modules\EcclesiasticalData\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\EcclesiasticalData\Dto\LeadershipAssignmentDto;

class EcclesiasticalLeadershipAssignmentResource extends JsonResource
{
    /**
     * @param  LeadershipAssignmentDto  $resource
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

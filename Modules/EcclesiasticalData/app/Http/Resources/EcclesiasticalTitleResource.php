<?php

namespace Modules\EcclesiasticalData\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\EcclesiasticalData\Models\EcclesiasticalTitle */
class EcclesiasticalTitleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'name' => $this->title,
            'abbreviation' => $this->abbreviation,
            'hierarchy_level' => $this->hierarchy_level,
            'display_order' => $this->display_order,
        ];
    }
}

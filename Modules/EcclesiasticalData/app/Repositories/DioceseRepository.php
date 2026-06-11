<?php

namespace Modules\EcclesiasticalData\Repositories;

use Modules\EcclesiasticalData\Models\DioceseManagement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DioceseRepository extends BaseRepository
{
    protected function makeModel(): Model
    {
        return new DioceseManagement();
    }

    /**
     * Get dioceses with cursor pagination, search, and filters
     * Uses cursor-based pagination for better performance with large datasets
     * 
     * @param array $params
     * @return array ['data' => Collection, 'next_cursor' => string|null, 'has_more' => bool, 'per_page' => int]
     */
    public function getDiocesesPaginated(array $params): array
    {
        // Optimize eager loading: only load id and name fields for relationships
        // Note: When using selective field loading, Laravel automatically includes the foreign key
        $query = $this->model->with([
            'country:id,name,iso2',
            'state:id,name,state_code',
            'denomination:id,name'
        ])->select('archdioceses.*'); // Ensure we select all columns from the main table

        // Apply search (server-side filtering)
        if (!empty($params['search'])) {
            $query->search($params['search']);
        }

        // Apply filters (server-side filtering)
        if (!empty($params['country_id'])) {
            // When filtering by country_id, also check legacy country string field
            // This handles cases where country_id is NULL but country string has a value
            $query->where(function($q) use ($params) {
                $q->byCountryId($params['country_id']);
                
                // Also check if we need to match by country name for legacy records
                // Get country name from countries table if country_id exists
                $country = \Modules\Tenants\Models\Country::find($params['country_id']);
                if ($country) {
                    $q->orWhere('country', $country->name);
                }
            });
        }

        if (!empty($params['denomination_id'])) {
            $query->byDenomination($params['denomination_id']);
        }

        if (isset($params['is_active'])) {
            if ($params['is_active']) {
                $query->active();
            } else {
                $query->where('active', false);
            }
        }

        // Apply sorting - cursor pagination requires consistent ordering on indexed columns
        $sortBy = $params['sort_by'] ?? 'name';
        $sortDir = $params['sort_dir'] ?? 'asc';
        
        // Only allow sorting on indexed columns for optimal performance
        $allowedSortColumns = ['name', 'created_at', 'id'];
        if (!in_array($sortBy, $allowedSortColumns)) {
            // Fallback to default indexed column
            $sortBy = 'name';
        }
        
        // Default sorting: Prioritize India, Brazil, United States, then others
        // Apply custom country priority sorting when sorting by name in ascending order (default)
        if ($sortBy === 'name' && $sortDir === 'asc') {
            // Custom ordering: Country priority first, then name
            // Priority: India (101 or country='India') = 1, Brazil (31 or country='Brazil') = 2, United States (233 or country='United States') = 3, Others = 4
            $query->orderByRaw("
                CASE 
                    WHEN country_id = 101 OR country = 'India' THEN 1
                    WHEN country_id = 31 OR country = 'Brazil' THEN 2
                    WHEN country_id = 233 OR country = 'United States' OR country = 'USA' THEN 3
                    ELSE 4
                END ASC
            ");
            // Then sort by name within each country group
            $query->orderBy('name', 'asc');
        } else {
            // For other sort columns or descending order, use standard sorting
            $query->orderBy($sortBy, $sortDir);
        }
        
        // Always include 'id' in ordering for cursor stability
        $query->orderBy('id', $sortDir); // Secondary sort for cursor stability

        $perPage = (int)($params['per_page'] ?? 20);
        
        // Fetch one extra record to determine if there are more pages
        $query->limit($perPage + 1);
        
        // Apply cursor if provided
        if (!empty($params['cursor'])) {
            $cursor = $this->decodeCursor($params['cursor']);
            if ($cursor && isset($cursor['value']) && isset($cursor['id'])) {
            // For default name sorting with country priority, cursor needs special handling
            if ($sortBy === 'name' && $sortDir === 'asc') {
                // Cursor contains country_priority, name, and id
                if (isset($cursor['country_priority']) && isset($cursor['name_value'])) {
                    $query->where(function($q) use ($cursor) {
                        $q->whereRaw("
                            CASE 
                                WHEN country_id = 101 OR country = 'India' THEN 1
                                WHEN country_id = 31 OR country = 'Brazil' THEN 2
                                WHEN country_id = 233 OR country = 'United States' OR country = 'USA' THEN 3
                                ELSE 4
                            END > ?
                        ", [$cursor['country_priority']])
                        ->orWhere(function($q2) use ($cursor) {
                            $q2->whereRaw("
                                CASE 
                                    WHEN country_id = 101 OR country = 'India' THEN 1
                                    WHEN country_id = 31 OR country = 'Brazil' THEN 2
                                    WHEN country_id = 233 OR country = 'United States' OR country = 'USA' THEN 3
                                    ELSE 4
                                END = ?
                            ", [$cursor['country_priority']])
                            ->where(function($q3) use ($cursor) {
                                $q3->where('name', '>', $cursor['name_value'])
                                   ->orWhere(function($q4) use ($cursor) {
                                       $q4->where('name', '=', $cursor['name_value'])
                                          ->where('id', '>', $cursor['id']);
                                   });
                            });
                        });
                    });
                } else {
                    // Fallback to standard cursor if country_priority not in cursor
                    $query->where(function($q) use ($sortBy, $cursor) {
                        $q->where($sortBy, '>', $cursor['value'])
                          ->orWhere(function($q2) use ($sortBy, $cursor) {
                              $q2->where($sortBy, '=', $cursor['value'])
                                 ->where('id', '>', $cursor['id']);
                          });
                    });
                }
            } else {
                    // Standard cursor handling for other sort columns
                    if ($sortDir === 'asc') {
                        $query->where(function($q) use ($sortBy, $cursor) {
                            $q->where($sortBy, '>', $cursor['value'])
                              ->orWhere(function($q2) use ($sortBy, $cursor) {
                                  $q2->where($sortBy, '=', $cursor['value'])
                                     ->where('id', '>', $cursor['id']);
                              });
                        });
                    } else {
                        $query->where(function($q) use ($sortBy, $cursor) {
                            $q->where($sortBy, '<', $cursor['value'])
                              ->orWhere(function($q2) use ($sortBy, $cursor) {
                                  $q2->where($sortBy, '=', $cursor['value'])
                                     ->where('id', '<', $cursor['id']);
                              });
                        });
                    }
                }
            }
        }
        
        $results = $query->get();
        $hasMore = $results->count() > $perPage;
        
        // Remove the extra record if it exists
        if ($hasMore) {
            $results = $results->take($perPage);
        }
        
        // Generate next cursor from the last item
        $nextCursor = null;
        if ($hasMore && $results->isNotEmpty()) {
            $lastItem = $results->last();
            
            // For default name sorting with country priority, include country priority in cursor
            if ($sortBy === 'name' && $sortDir === 'asc') {
                $countryPriority = 4; // Default for others
                // Check both country_id and legacy country string field
                if ($lastItem->country_id == 101 || $lastItem->country == 'India') {
                    $countryPriority = 1; // India
                } elseif ($lastItem->country_id == 31 || $lastItem->country == 'Brazil') {
                    $countryPriority = 2; // Brazil
                } elseif ($lastItem->country_id == 233 || $lastItem->country == 'United States' || $lastItem->country == 'USA') {
                    $countryPriority = 3; // United States
                }
                
                $nextCursor = $this->encodeCursor([
                    'country_priority' => $countryPriority,
                    'name_value' => $lastItem->name,
                    'value' => $lastItem->name, // Keep for backward compatibility
                    'id' => $lastItem->id
                ]);
            } else {
                $nextCursor = $this->encodeCursor([
                    'value' => $lastItem->{$sortBy},
                    'id' => $lastItem->id
                ]);
            }
        }
        
        // Convert Collection to array for JSON serialization
        // Ensure relationships are loaded and properly serialized
        // FALLBACK: If country_id/denomination_id are NULL, use legacy string fields
        $dataArray = $results->map(function ($diocese) {
            // Load relationships if foreign keys exist
            if ($diocese->country_id && !$diocese->relationLoaded('country')) {
                $diocese->load('country:id,name,iso2');
            }
            if ($diocese->state_id && !$diocese->relationLoaded('state')) {
                $diocese->load('state:id,name,state_code');
            }
            if ($diocese->denomination_id && !$diocese->relationLoaded('denomination')) {
                $diocese->load('denomination:id,name');
            }
            
            // Get the array representation
            $array = $diocese->toArray();
            
            // Handle country: Use relationship if available, otherwise fallback to legacy string field
            $countryRelation = null;
            if ($diocese->country_id) {
                if (!$diocese->relationLoaded('country')) {
                    // Try to load if not already loaded
                    $diocese->load('country:id,name,iso2');
                }
                $countryRelation = $diocese->getRelation('country');
            }
            
            if ($countryRelation && is_object($countryRelation) && isset($countryRelation->id)) {
                $array['country'] = [
                    'id' => $countryRelation->id,
                    'name' => $countryRelation->name,
                    'iso2' => $countryRelation->iso2 ?? null,
                ];
            } else {
                // Use legacy string field when country_id is NULL or relationship doesn't exist
                // Access country directly from model attribute, not from array (toArray might not include it)
                $legacyCountry = $diocese->getAttribute('country') ?? $array['country'] ?? null;
                if ($legacyCountry && is_string($legacyCountry) && !empty(trim($legacyCountry))) {
                    $array['country'] = ['name' => trim($legacyCountry)];
                } else {
                    $array['country'] = null;
                }
            }
            
            // Handle state: Use relationship if available, otherwise fallback to legacy region field
            $stateRelation = null;
            if ($diocese->state_id) {
                if (!$diocese->relationLoaded('state')) {
                    // Try to load if not already loaded
                    $diocese->load('state:id,name,state_code');
                }
                $stateRelation = $diocese->getRelation('state');
            }
            
            if ($stateRelation && is_object($stateRelation) && isset($stateRelation->id)) {
                $array['state'] = [
                    'id' => $stateRelation->id,
                    'name' => $stateRelation->name,
                    'state_code' => $stateRelation->state_code ?? null,
                ];
            } else {
                // Use legacy region field when state_id is NULL or relationship doesn't exist
                // Access region directly from model attribute, not from array (toArray might not include it)
                $legacyRegion = $diocese->getAttribute('region') ?? $array['region'] ?? null;
                if ($legacyRegion && is_string($legacyRegion) && !empty(trim($legacyRegion))) {
                    $array['state'] = ['name' => trim($legacyRegion)];
                } else {
                    $array['state'] = null;
                }
            }
            
            // Handle denomination: Use relationship if available
            $denominationRelation = null;
            if ($diocese->denomination_id) {
                if (!$diocese->relationLoaded('denomination')) {
                    // Try to load if not already loaded
                    $diocese->load('denomination:id,name');
                }
                $denominationRelation = $diocese->getRelation('denomination');
            }
            
            if ($denominationRelation && is_object($denominationRelation) && isset($denominationRelation->id)) {
                $array['denomination'] = [
                    'id' => $denominationRelation->id,
                    'name' => $denominationRelation->name,
                ];
            } else {
                // denomination_id is NULL - set to null (no legacy field for denomination)
                $array['denomination'] = null;
            }
            
            return $array;
        })->toArray();
        
        return [
            'data' => $dataArray,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'per_page' => $perPage
        ];
    }

    /**
     * Encode cursor for pagination
     */
    private function encodeCursor(array $cursor): string
    {
        return base64_encode(json_encode($cursor));
    }

    /**
     * Decode cursor for pagination
     */
    private function decodeCursor(string $cursor): ?array
    {
        try {
            $decoded = json_decode(base64_decode($cursor), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get dioceses with traditional pagination (for backward compatibility)
     */
    /**
     * Get dioceses with traditional Laravel pagination
     * This is the main pagination method - simpler and more maintainable than cursor pagination
     */
    public function getDiocesesPaginatedLegacy(array $params): LengthAwarePaginator
    {
        $query = $this->model->with([
            'country:id,name,iso2',
            'state:id,name,state_code',
            'denomination:id,name'
        ])->select('archdioceses.*');

        // Apply search (server-side filtering)
        if (!empty($params['search'])) {
            $query->search($params['search']);
        }

        // Apply filters (server-side filtering)
        if (!empty($params['country_id'])) {
            // When filtering by country_id, also check legacy country string field
            $query->where(function($q) use ($params) {
                $q->byCountryId($params['country_id']);
                
                // Also check if we need to match by country name for legacy records
                $country = \Modules\Tenants\Models\Country::find($params['country_id']);
                if ($country) {
                    $q->orWhere('country', $country->name);
                }
            });
        }

        if (!empty($params['denomination_id'])) {
            $query->byDenomination($params['denomination_id']);
        }

        if (isset($params['is_active'])) {
            if ($params['is_active']) {
                $query->active();
            } else {
                $query->where('active', false);
            }
        }

        // Apply sorting with country priority for default name sorting
        $sortBy = $params['sort_by'] ?? 'name';
        $sortDir = $params['sort_dir'] ?? 'asc';
        
        // Default sorting: Prioritize India, Brazil, United States, then others
        if ($sortBy === 'name' && $sortDir === 'asc') {
            // Custom ordering: Country priority first, then name
            $query->orderByRaw("
                CASE 
                    WHEN country_id = 101 OR country = 'India' THEN 1
                    WHEN country_id = 31 OR country = 'Brazil' THEN 2
                    WHEN country_id = 233 OR country = 'United States' OR country = 'USA' THEN 3
                    ELSE 4
                END ASC
            ");
            // Then sort by name within each country group
            $query->orderBy('name', 'asc');
        } else {
            // For other sort columns or descending order, use standard sorting
            $query->orderBy($sortBy, $sortDir);
        }

        $perPage = (int)($params['per_page'] ?? 20);
        $page = (int)($params['page'] ?? 1);
        
        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Get diocese with all relationships
     */
    public function findWithRelations(string $id)
    {
        return $this->model
            ->with([
                'country',
                'state',
                'denomination',
                'childArchdioceses',
                'parentArchdiocese'
            ])
            ->findOrFail($id);
    }

    /**
     * Get dioceses by country
     */
    public function getByCountry(int $countryId)
    {
        return $this->model
            ->byCountryId($countryId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get archdioceses (metropolitans) only
     * Identifies archdioceses by name pattern (contains "Archdiocese")
     */
    public function getArchdioceses()
    {
        return $this->model
            ->where('name', 'LIKE', '%Archdiocese%')
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_dioceses' => $this->model->count(),
            'active_dioceses' => $this->model->active()->count(),
            'inactive_dioceses' => $this->model->where('active', false)->count(),
            // Archdioceses have null parent_archdiocese_id, regular dioceses have a parent
            'total_archdioceses' => $this->model->whereNull('parent_archdiocese_id')->count(),
            'total_regular_dioceses' => $this->model->whereNotNull('parent_archdiocese_id')->count(),
            'by_country' => $this->model
                ->with('country:id,name')
                ->selectRaw('country_id, count(*) as total')
                ->groupBy('country_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'country' => $item->country->name ?? 'Unknown',
                        'total' => $item->total
                    ];
                })
                ->values()
                ->toArray(),
            'by_denomination' => $this->model
                ->with('denomination:id,name')
                ->selectRaw('denomination_id, count(*) as total')
                ->groupBy('denomination_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'denomination' => $item->denomination->name ?? 'Unknown',
                        'total' => $item->total
                    ];
                })
                ->values()
                ->toArray(),
            'recent_additions' => $this->model
                ->with(['country:id,name', 'denomination:id,name'])
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'country_id', 'denomination_id', 'parent_archdiocese_id', 'created_at'])
                ->map(function ($item) {
                    $data = $item->toArray();
                    // Add is_archdiocese based on parent_archdiocese_id
                    $data['is_archdiocese'] = is_null($item->parent_archdiocese_id);
                    return $data;
                })
                ->toArray(),
        ];
    }
}


<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Tenants\Models\Archdiocese;

/**
 * Archdioceses Controller
 * 
 * Provides lookup data for archdioceses and dioceses.
 * Read-only API for dropdown lists and selection.
 */
class ArchdiocesesController extends Controller
{
    /**
     * Get all active archdioceses.
     * Supports filtering by country and denomination.
     * 
     * @route GET /api/archdioceses
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Archdiocese::with('denomination')->active();

            // Filter by country
            if ($request->has('country')) {
                $query->byCountry($request->country);
            }

            // Filter by denomination
            if ($request->has('denomination_id')) {
                $query->where('denomination_id', $request->denomination_id);
            }

            // Optional search filter
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('headquarters_city', 'like', "%{$search}%");
                });
            }

            $archdioceses = $query->orderBy('name')->get();

            return response()->json([
                'success' => true,
                'data' => $archdioceses,
                'total' => $archdioceses->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching archdioceses: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching archdioceses',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific archdiocese by ID.
     * 
     * @route GET /api/archdioceses/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $archdiocese = Archdiocese::with(['denomination', 'bishops'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $archdiocese,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching archdiocese: ' . $e->getMessage(), [
                'archdiocese_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Archdiocese not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found',
            ], 404);
        }
    }

    /**
     * Get list of countries with archdioceses.
     * 
     * @route GET /api/archdioceses/countries
     */
    public function countries(): JsonResponse
    {
        try {
            $countries = Archdiocese::active()
                ->select('country')
                ->distinct()
                ->orderBy('country')
                ->pluck('country');

            return response()->json([
                'success' => true,
                'data' => $countries,
                'total' => $countries->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching archdiocese countries: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching countries',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}

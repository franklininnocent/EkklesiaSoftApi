<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecureFileController
{
    /**
     * Serve a protected file with signature verification
     * 
     * This endpoint requires a valid signed URL to access tenant files.
     * The signature ensures the URL hasn't been tampered with and hasn't expired.
     * 
     * @param Request $request
     * @return Response|StreamedResponse|JsonResponse
     */
    public function serveFile(Request $request): Response|StreamedResponse|JsonResponse
    {
        try {
            // Signature is automatically verified by Laravel's ValidateSignature middleware
            // If we reach here, the signature is valid
            
            $filePath = $request->query('path');
            
            if (!$filePath) {
                Log::warning('Secure file access attempted without path');
                return response()->json([
                    'success' => false,
                    'message' => 'File path is required',
                ], 400);
            }
            
            // Check if file exists
            if (!Storage::disk('public')->exists($filePath)) {
                Log::warning('Secure file access attempted for non-existent file', [
                    'path' => $filePath,
                    'user_id' => Auth::id(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'File not found',
                ], 404);
            }
            
            // Additional tenant-specific access control for authenticated users
            if (Auth::check()) {
                $user = Auth::user();
                
                // Only enforce tenant isolation for non-SuperAdmin/EkklesiaAdmin
                if (!$user->isSuperAdmin() && !$user->isEkklesiaAdmin()) {
                    $tenantId = $user->tenant_id;
                    
                    // Verify tenant ownership for tenant-specific files
                    if (preg_match('/tenants\/(\d+)\//', $filePath, $matches)) {
                        $fileTenantId = (int) $matches[1];
                        
                        if ($fileTenantId !== $tenantId) {
                            Log::warning('Secure file access attempted for different tenant', [
                                'user_id' => $user->id,
                                'user_tenant_id' => $tenantId,
                                'file_tenant_id' => $fileTenantId,
                                'path' => $filePath,
                            ]);
                            return response()->json([
                                'success' => false,
                                'message' => 'Unauthorized file access',
                            ], 403);
                        }
                    }
                }
            }
            
            // Get file metadata
            $mimeType = Storage::disk('public')->mimeType($filePath);
            $size = Storage::disk('public')->size($filePath);
            $lastModified = Storage::disk('public')->lastModified($filePath);
            
            // Log successful access
            Log::info('Secure file accessed', [
                'path' => $filePath,
                'user_id' => Auth::id(),
                'mime_type' => $mimeType,
                'size' => $size,
            ]);
            
            // Stream the file with appropriate headers
            return Storage::disk('public')->response($filePath, null, [
                'Content-Type' => $mimeType,
                'Content-Length' => $size,
                'Cache-Control' => 'private, max-age=3600', // Cache for 1 hour
                'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'DENY',
                'X-XSS-Protection' => '1; mode=block',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error serving secure file', [
                'path' => $request->query('path'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error serving file',
            ], 500);
        }
    }
    
    /**
     * Generate a signed URL for a file
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function generateSignedUrl(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'path' => 'required|string',
                'expiration_minutes' => 'nullable|integer|min:1|max:1440', // Max 24 hours
            ]);
            
            $filePath = $request->input('path');
            $expirationMinutes = $request->input('expiration_minutes', 60); // Default 1 hour
            
            // Check if file exists
            if (!Storage::disk('public')->exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found',
                ], 404);
            }
            
            // Verify user has access to this file
            if (Auth::check()) {
                $user = Auth::user();
                
                // Only enforce tenant isolation for non-SuperAdmin/EkklesiaAdmin
                if (!$user->isSuperAdmin() && !$user->isEkklesiaAdmin()) {
                    $tenantId = $user->tenant_id;
                    
                    // Verify tenant ownership
                    if (preg_match('/tenants\/(\d+)\//', $filePath, $matches)) {
                        $fileTenantId = (int) $matches[1];
                        
                        if ($fileTenantId !== $tenantId) {
                            return response()->json([
                                'success' => false,
                                'message' => 'Unauthorized file access',
                            ], 403);
                        }
                    }
                }
            }
            
            // Generate signed URL
            $signedUrl = \URL::temporarySignedRoute(
                'tenants.files.serve',
                now()->addMinutes($expirationMinutes),
                ['path' => $filePath]
            );
            
            Log::info('Signed URL generated', [
                'path' => $filePath,
                'user_id' => Auth::id(),
                'expiration_minutes' => $expirationMinutes,
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'signed_url' => $signedUrl,
                    'expires_at' => now()->addMinutes($expirationMinutes)->toIso8601String(),
                    'expiration_minutes' => $expirationMinutes,
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error generating signed URL', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating signed URL',
            ], 500);
        }
    }
}


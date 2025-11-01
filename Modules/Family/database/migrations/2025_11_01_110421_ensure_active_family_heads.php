<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Ensures all family heads are active members.
     * For any inactive family head, activates them or finds an alternative active head.
     */
    public function up(): void
    {
        // Get all families that have members
        $families = Family::with('members')->get();
        
        foreach ($families as $family) {
            if ($family->members->isEmpty()) {
                continue; // Skip families without members
            }
            
            // Find the designated head member (relationship = 'self' or 'head')
            $headMember = $family->members
                ->whereIn('relationship_to_head', ['self', 'head'])
                ->first();
            
            if ($headMember) {
                // If head member is inactive, activate them
                if ($headMember->status !== 'active') {
                    echo "Activating inactive family head: {$headMember->first_name} {$headMember->last_name} (Family: {$family->family_code})\n";
                    $headMember->update(['status' => 'active']);
                }
                
                // Sync the head_of_family field
                $family->update([
                    'head_of_family' => trim("{$headMember->first_name} {$headMember->last_name}")
                ]);
            } else {
                // No designated head found, find first active member and set as head
                $firstActive = $family->members->where('status', 'active')->first();
                
                if ($firstActive) {
                    echo "Designating first active member as head: {$firstActive->first_name} {$firstActive->last_name} (Family: {$family->family_code})\n";
                    $firstActive->update(['relationship_to_head' => 'self']);
                    $family->update([
                        'head_of_family' => trim("{$firstActive->first_name} {$firstActive->last_name}")
                    ]);
                } else {
                    // No active members at all, activate first member
                    $firstMember = $family->members->first();
                    echo "All members inactive, activating first member as head: {$firstMember->first_name} {$firstMember->last_name} (Family: {$family->family_code})\n";
                    $firstMember->update([
                        'status' => 'active',
                        'relationship_to_head' => 'self'
                    ]);
                    $family->update([
                        'head_of_family' => trim("{$firstMember->first_name} {$firstMember->last_name}")
                    ]);
                }
            }
        }
        
        echo "\n✅ All families now have active family heads.\n";
    }

    /**
     * Reverse the migrations.
     * 
     * Note: We cannot reliably reverse this migration as we don't know
     * which members were originally inactive. So we do nothing.
     */
    public function down(): void
    {
        // Cannot reliably reverse - we don't have original state
    }
};

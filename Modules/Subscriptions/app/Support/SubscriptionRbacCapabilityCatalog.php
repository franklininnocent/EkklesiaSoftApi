<?php

namespace Modules\Subscriptions\Support;

/**
 * Customer-facing RBAC capability matrix (display only — enforcement is route middleware + RBAC permissions).
 */
final class SubscriptionRbacCapabilityCatalog
{
    /**
     * @return list<array{label: string, entitlement: string, customer_note: string}>
     */
    public static function rows(): array
    {
        return [
            [
                'label' => 'Open Roles & Permissions and view parish roles',
                'entitlement' => 'RBAC_BASIC',
                'customer_note' => 'Included with Roles & Permissions on your plan.',
            ],
            [
                'label' => 'View the permission catalog and what each role includes',
                'entitlement' => 'RBAC_BASIC',
                'customer_note' => 'Read-only; you cannot change permissions on a role without Advanced.',
            ],
            [
                'label' => 'Assign staff to existing parish roles',
                'entitlement' => 'RBAC_BASIC',
                'customer_note' => 'Still requires your user account to have role-assignment permission.',
            ],
            [
                'label' => 'Create, edit, or delete custom parish roles',
                'entitlement' => 'RBAC_ADVANCED',
                'customer_note' => 'Requires Advanced Roles & Permissions on your plan.',
            ],
            [
                'label' => 'Change which permissions are attached to a role',
                'entitlement' => 'RBAC_ADVANCED',
                'customer_note' => 'Fine-grained role design; not included on Starter-only RBAC.',
            ],
            [
                'label' => 'Activate or deactivate parish roles',
                'entitlement' => 'RBAC_ADVANCED',
                'customer_note' => 'Part of custom role management.',
            ],
        ];
    }

    /**
     * @return array{basic: string, advanced: string}
     */
    public static function featureDescriptions(): array
    {
        return [
            'RBAC_BASIC' => 'Use parish Roles & Permissions: view roles and the permission catalog, and assign staff to existing roles. Does not include creating custom roles or editing which permissions a role has.',
            'RBAC_ADVANCED' => 'Everything in Roles & Permissions, plus create and edit custom parish roles, activate or deactivate roles, and choose which permissions each role includes.',
        ];
    }
}

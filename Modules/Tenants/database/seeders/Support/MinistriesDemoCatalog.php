<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Modules\MinistriesAssociations\Models\Organization;

/**
 * Stable demo organization fixtures for tenant ministries demonstrations.
 *
 * Matched by {@see Organization} code per tenant (idempotent create, no field overwrites on reuse).
 */
final class MinistriesDemoCatalog
{
    public const ANCHOR_CODE = 'SSVP';

    /**
     * @return list<string>
     */
    public static function organizationCodes(): array
    {
        return array_column(self::organizations(), 'code');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function organizations(): array
    {
        return [
            [
                'code' => 'SSVP',
                'name' => 'Society of St. Vincent de Paul',
                'short_name' => 'SVP',
                'category_code' => 'charitable',
                'type_code' => 'society',
                'status' => Organization::STATUS_ACTIVE,
                'patron_saint' => 'St. Vincent de Paul',
                'established_date' => '1985-09-27',
                'theme_color' => '#1E6B4F',
                'email' => 'svdp.demo@parish.example',
                'phone' => '+91-98765-43210',
                'website' => 'https://parish.example/ministries/svdp',
                'social_links' => [
                    'facebook' => 'https://facebook.example/svdp-parish',
                    'whatsapp' => '+919876543210',
                ],
                'description' => 'Home visits, food support, and emergency aid for families in the parish.',
                'vision' => 'A parish where no neighbour goes without dignity or basic care.',
                'mission' => 'Serve Christ in the poor through person-to-person help.',
                'objectives' => 'Weekly home visits; quarterly food drives; school kit distribution.',
                'allow_multi_role_holding' => false,
                'guests_can_hold_office' => false,
                'member_count' => 12,
                'leadership' => [
                    ['position' => 'president', 'member_index' => 0, 'term' => 'active', 'effective_to' => null],
                    ['position' => 'secretary', 'member_index' => 1, 'term' => 'active', 'effective_to' => null],
                    ['position' => 'treasurer', 'member_index' => 2, 'term' => 'active', 'effective_to' => '+20 days'],
                ],
                'membership_variants' => [
                    ['member_index' => 3, 'status' => 'suspended', 'member_type' => 'regular'],
                    ['member_index' => 4, 'status' => 'exited', 'member_type' => 'regular', 'historical' => true],
                    ['member_index' => 10, 'status' => 'active', 'member_type' => 'life'],
                ],
                'completed_leadership' => [
                    ['position' => 'president', 'member_index' => 5, 'effective_from' => '-2 years', 'effective_to' => '-6 months'],
                ],
            ],
            [
                'code' => 'YMCA',
                'name' => 'Young Men\'s Christian Association (Parish Chapter)',
                'short_name' => 'YMCA',
                'category_code' => 'youth',
                'type_code' => 'association',
                'status' => Organization::STATUS_ACTIVE,
                'established_date' => '1998-06-01',
                'theme_color' => '#2563EB',
                'email' => 'ymca.demo@parish.example',
                'description' => 'Sports, leadership camps, and service projects for young men.',
                'guests_can_hold_office' => true,
                'member_count' => 8,
                'leadership' => [
                    ['position' => 'coordinator', 'member_index' => 0, 'term' => 'active'],
                    ['position' => 'joint_secretary', 'member_index' => 1, 'term' => 'active'],
                ],
                'guest_enrollment' => true,
            ],
            [
                'code' => 'YWCA',
                'name' => 'Young Women\'s Christian Association (Parish Chapter)',
                'short_name' => 'YWCA',
                'category_code' => 'youth',
                'type_code' => 'association',
                'status' => Organization::STATUS_ACTIVE,
                'established_date' => '2001-03-15',
                'theme_color' => '#DB2777',
                'description' => 'Faith formation, fellowship, and outreach for young women.',
                'member_count' => 7,
                'leadership' => [
                    ['position' => 'president', 'member_index' => 0, 'term' => 'active'],
                    ['position' => 'secretary', 'member_index' => 1, 'term' => 'active'],
                ],
                'membership_variants' => [
                    ['member_index' => 2, 'status' => 'resigned', 'member_type' => 'regular', 'historical' => true],
                ],
            ],
            [
                'code' => 'ALTAR_BOYS',
                'name' => 'Altar Boys Association',
                'short_name' => 'Altar Boys',
                'category_code' => 'liturgical',
                'type_code' => 'association',
                'status' => Organization::STATUS_ACTIVE,
                'patron_saint' => 'St. Tarcisius',
                'established_date' => '2010-08-15',
                'theme_color' => '#7C3AED',
                'description' => 'Training and scheduling altar servers for parish liturgies.',
                'member_count' => 6,
                'default_member_type' => 'junior',
                'leadership' => [
                    ['position' => 'coordinator', 'member_index' => 0, 'term' => 'active'],
                ],
            ],
            [
                'code' => 'PARISH_CHOIR',
                'name' => 'Parish Choir',
                'short_name' => 'Choir',
                'category_code' => 'liturgical',
                'type_code' => 'choir',
                'status' => Organization::STATUS_ACTIVE,
                'established_date' => '1975-12-24',
                'theme_color' => '#B45309',
                'email' => 'choir.demo@parish.example',
                'description' => 'Music ministry for Sunday Mass and major feasts.',
                'member_count' => 10,
                'leadership' => [
                    ['position' => 'coordinator', 'member_index' => 0, 'term' => 'active', 'is_interim' => true],
                ],
                're_enroll_pool_index' => 3,
            ],
            [
                'code' => 'LEGION_MARY',
                'name' => 'Legion of Mary',
                'short_name' => 'Legion',
                'category_code' => 'spiritual',
                'type_code' => 'prayer_group',
                'status' => Organization::STATUS_ACTIVE,
                'established_date' => '1992-10-07',
                'theme_color' => '#047857',
                'description' => 'Weekly praesidium meetings and parish visitation.',
                'member_count' => 9,
                'leadership' => [
                    ['position' => 'president', 'member_index' => 0, 'term' => 'active'],
                    ['position' => 'vice_president', 'member_index' => 1, 'term' => 'active'],
                ],
                'membership_variants' => [
                    ['member_index' => 2, 'status' => 'active', 'member_type' => 'honorary'],
                ],
            ],
            [
                'code' => 'CWL',
                'name' => 'Catholic Women\'s League',
                'short_name' => 'CWL',
                'category_code' => 'social',
                'type_code' => 'fellowship',
                'status' => Organization::STATUS_ACTIVE,
                'established_date' => '1988-05-01',
                'theme_color' => '#BE123C',
                'description' => 'Women\'s fellowship, parish hospitality, and charity drives.',
                'member_count' => 11,
                'leadership' => [
                    ['position' => 'president', 'member_index' => 0, 'term' => 'active'],
                    ['position' => 'treasurer', 'member_index' => 1, 'term' => 'active'],
                ],
            ],
            [
                'code' => 'CATECHISM',
                'name' => 'Catechism Ministry',
                'short_name' => 'Catechism',
                'category_code' => 'educational',
                'type_code' => 'ministry',
                'status' => Organization::STATUS_ACTIVE,
                'established_date' => '2005-06-01',
                'theme_color' => '#0F766E',
                'description' => 'Sunday faith formation for children and sacramental preparation.',
                'member_count' => 5,
                'leadership' => [
                    ['position' => 'coordinator', 'member_index' => 0, 'term' => 'active'],
                ],
            ],
            [
                'code' => 'FINANCE_COMM',
                'name' => 'Parish Finance Committee',
                'short_name' => 'Finance',
                'category_code' => 'administrative',
                'type_code' => 'committee',
                'status' => Organization::STATUS_INACTIVE,
                'established_date' => '2018-01-01',
                'description' => 'Inactive demo committee — reorganisation pending new pastoral council term.',
                'member_count' => 0,
            ],
            [
                'code' => 'PRAYER_CELL',
                'name' => 'St. Jude Prayer Cell',
                'short_name' => 'St. Jude',
                'category_code' => 'spiritual',
                'type_code' => 'prayer_group',
                'status' => Organization::STATUS_ACTIVE,
                'member_count' => 0,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function guestMembers(): array
    {
        return [
            [
                'key' => 'guest_ymca_volunteer',
                'first_name' => 'Ethan',
                'last_name' => 'Brooks',
                'gender' => 'male',
                'email' => 'ethan.brooks.demo@example.test',
                'phone' => '+1-555-0101',
                'guest_type' => 'volunteer',
                'external_organization' => 'City YMCA Partner Network',
                'support_type' => 'Sports coach',
            ],
            [
                'key' => 'guest_ssvp_benefactor',
                'first_name' => 'Helen',
                'last_name' => 'Cartwright',
                'gender' => 'female',
                'email' => 'helen.cartwright.demo@example.test',
                'phone' => '+1-555-0102',
                'guest_type' => 'benefactor',
                'external_organization' => 'Local Business Guild',
                'support_type' => 'Food drives',
            ],
        ];
    }
}

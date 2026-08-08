<?php

namespace Modules\MinistriesAssociations\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Modules\MinistriesAssociations\Models\OrganizationMembership;

trait InteractsWithMinistriesValidation
{
    /** @var list<string> */
    protected const MEMBERSHIP_EXIT_STATUSES = [
        OrganizationMembership::STATUS_EXITED,
        OrganizationMembership::STATUS_RESIGNED,
        OrganizationMembership::STATUS_INACTIVE,
        OrganizationMembership::STATUS_DECEASED,
    ];

    /** @var list<string> */
    protected const MEMBER_TYPES = [
        'regular',
        'honorary',
        'life',
        'junior',
    ];

    /** @var list<string> */
    protected const LEADERSHIP_EXIT_REASONS = [
        'resigned',
        'term_completed',
        'removed',
        'deceased',
        'transferred',
    ];

    /** @var list<string> */
    protected const LEADERSHIP_HANDOVER_EXIT_REASONS = [
        'resigned',
        'transferred',
        'removed',
        'term_completed',
        'deceased',
    ];

    protected function tenantId(): ?int
    {
        return app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function paginationRules(int $maxPerPage = 100): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    protected function tenantExists(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column);

        if ($tenantId = $this->tenantId()) {
            $rule->where('tenant_id', $tenantId);
        }

        return $rule->whereNull('deleted_at');
    }

    protected function familyMemberExists(): Exists
    {
        $rule = Rule::exists('family_members', 'id')->whereNull('deleted_at');

        if ($tenantId = $this->tenantId()) {
            $rule->whereIn('family_id', function ($query) use ($tenantId): void {
                $query->select('id')
                    ->from('families')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at');
            });
        }

        return $rule;
    }

    protected function tenantUnique(string $table, string $column, ?string $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column);

        if ($tenantId = $this->tenantId()) {
            $rule->where('tenant_id', $tenantId);
        }

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }

        return $rule->whereNull('deleted_at');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function socialLinksRules(): array
    {
        return [
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'string', 'max:500'],
            'social_links.instagram' => ['nullable', 'string', 'max:500'],
            'social_links.whatsapp' => ['nullable', 'string', 'max:500'],
            'social_links.youtube' => ['nullable', 'string', 'max:500'],
            'social_links.telegram' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function organizationSettingsRules(bool $required = false): array
    {
        $booleanRule = $required ? ['required', 'boolean'] : ['sometimes', 'boolean'];

        return [
            'allow_multi_role_holding' => $booleanRule,
            'guests_can_hold_office' => $booleanRule,
            'settings' => ['sometimes', 'array'],
            'settings.allow_multi_role_holding' => ['sometimes', 'boolean'],
            'settings.guests_can_hold_office' => ['sometimes', 'boolean'],
        ];
    }

    protected function flattenOrganizationSettings(): void
    {
        $settings = $this->input('settings');

        if (! is_array($settings)) {
            return;
        }

        $merged = $this->all();

        foreach (['allow_multi_role_holding', 'guests_can_hold_office'] as $key) {
            if (array_key_exists($key, $settings) && ! array_key_exists($key, $merged)) {
                $merged[$key] = $settings[$key];
            }
        }

        $this->merge($merged);
    }

    /**
     * Sanitize TipTap HTML fields before validation (allowlisted markup only).
     *
     * @param  list<string>  $fields
     */
    protected function sanitizeRichTextFields(array $fields): void
    {
        $sanitizer = app(\App\Support\Html\HtmlSanitizer::class);
        $this->merge($sanitizer->sanitizeFields($this->all(), $fields));
    }
}

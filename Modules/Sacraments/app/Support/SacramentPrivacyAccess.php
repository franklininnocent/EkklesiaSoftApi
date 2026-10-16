<?php

namespace Modules\Sacraments\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Authentication\Models\User;
use Modules\Sacraments\Definitions\SacramentDefinitionRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;

/**
 * Server-side privacy gates (ADR-22 / ADR-23). FE hiding is not security.
 */
final class SacramentPrivacyAccess
{
    public const VIEW_RESTRICTED = 'sacraments.view_restricted';

    public function __construct(
        protected SacramentDefinitionRegistry $definitions
    ) {}

    public function canViewRestricted(?Authenticatable $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->hasPermission(self::VIEW_RESTRICTED);
    }

    public function privacyClassForType(?SacramentType $type): string
    {
        if (! $type) {
            return SacramentPrivacyClass::STANDARD;
        }

        $definition = $this->definitions->forTypeCode($type->code);

        return (string) ($definition['privacy_class'] ?? SacramentPrivacyClass::STANDARD);
    }

    public function isRestrictedType(?SacramentType $type): bool
    {
        return SacramentPrivacyClass::isRestricted($this->privacyClassForType($type));
    }

    public function isRestrictedSacrament(Sacrament $sacrament): bool
    {
        $sacrament->loadMissing('sacramentType');

        return $this->isRestrictedType($sacrament->sacramentType);
    }

    /**
     * @throws SacramentBusinessRuleException
     */
    public function assertCanAccessSacrament(?Authenticatable $user, Sacrament $sacrament): void
    {
        if (! $this->isRestrictedSacrament($sacrament)) {
            return;
        }

        if (! $this->canViewRestricted($user)) {
            throw new SacramentBusinessRuleException(
                'restricted_access_required',
                'This sacramental record requires restricted access permission.',
                [],
                403
            );
        }
    }

    /**
     * @throws SacramentBusinessRuleException
     */
    public function assertCanCreateType(?Authenticatable $user, SacramentType $type): void
    {
        if (! $this->isRestrictedType($type)) {
            return;
        }

        if (! $this->canViewRestricted($user)) {
            throw new SacramentBusinessRuleException(
                'restricted_access_required',
                'Creating this sacrament type requires restricted access permission.',
                [],
                403
            );
        }
    }

    /**
     * Type codes that must be excluded from list/search/export without restricted permission.
     *
     * @return list<string>
     */
    public function restrictedTypeCodes(): array
    {
        $codes = [];
        foreach ($this->definitions->all() as $definition) {
            if (SacramentPrivacyClass::isRestricted($definition['privacy_class'] ?? null)) {
                $codes[] = (string) $definition['code'];
            }
        }

        return $codes;
    }
}

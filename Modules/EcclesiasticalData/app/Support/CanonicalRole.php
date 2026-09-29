<?php

namespace Modules\EcclesiasticalData\Support;

enum CanonicalRole: string
{
    case DiocesanBishop = 'diocesan_bishop';
    case Archbishop = 'archbishop';
    case Auxiliary = 'auxiliary';
    case Coadjutor = 'coadjutor';
    case ApostolicAdministrator = 'apostolic_administrator';
    case DiocesanAdministrator = 'diocesan_administrator';

    /**
     * Roles that represent the diocesan ordinary (at most one current per diocese).
     *
     * @return list<string>
     */
    public static function ordinaryRoles(): array
    {
        return [
            self::DiocesanBishop->value,
            self::Archbishop->value,
        ];
    }

    public function isOrdinary(): bool
    {
        return in_array($this->value, self::ordinaryRoles(), true);
    }

    /**
     * Map ecclesiastical title names (lookup table) to canonical roles.
     */
    public static function fromEcclesiasticalTitle(?string $title): self
    {
        $normalized = strtolower(trim((string) $title));

        return match (true) {
            str_contains($normalized, 'coadjutor') => self::Coadjutor,
            str_contains($normalized, 'auxiliary') => self::Auxiliary,
            str_contains($normalized, 'apostolic administrator') => self::ApostolicAdministrator,
            str_contains($normalized, 'diocesan administrator') => self::DiocesanAdministrator,
            str_contains($normalized, 'archbishop') => self::Archbishop,
            default => self::DiocesanBishop,
        };
    }
}

<?php

namespace Modules\Authentication\Support;

class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public const COMPLEXITY_REGEX = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]+$/';

    public const HISTORY_LIMIT = 5;

    /**
     * @return list<string>
     */
    public static function validationRules(bool $confirmed = true): array
    {
        $rules = [
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            'regex:'.self::COMPLEXITY_REGEX,
        ];

        if ($confirmed) {
            $rules[] = 'confirmed';
        }

        return $rules;
    }
}

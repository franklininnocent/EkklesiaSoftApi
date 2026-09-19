<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Collection;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Models\NotificationPreference;

class NotificationPreferenceService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForUser(int $userId): Collection
    {
        $definitions = NotificationDefinition::query()->where('active', true)->orderBy('category')->get();
        $prefs = NotificationPreference::query()->where('user_id', $userId)->get()->keyBy('definition_code');

        return $definitions->map(function (NotificationDefinition $def) use ($prefs) {
            $userPref = $prefs->get($def->code);
            $channels = $def->channels ?? [];

            return [
                'definition_code' => $def->code,
                'category' => $def->category,
                'module' => $def->module,
                'mandatory' => $def->mandatory,
                'in_app' => $userPref?->in_app ?? ($channels['in_app'] ?? true),
                'email' => $def->mandatory ? true : ($userPref?->email ?? ($channels['email'] ?? false)),
                'push' => $userPref?->push ?? false,
                'digest' => $userPref?->digest ?? 'immediate',
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function updateForUser(int $userId, array $items): void
    {
        foreach ($items as $item) {
            $code = (string) ($item['definition_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $definition = NotificationDefinition::query()->where('code', $code)->first();
            if ($definition === null) {
                continue;
            }

            $inApp = (bool) ($item['in_app'] ?? true);
            $email = (bool) ($item['email'] ?? false);
            $push = (bool) ($item['push'] ?? false);

            if ($definition->mandatory) {
                $inApp = true;
                $email = true;
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $userId, 'definition_code' => $code],
                [
                    'category' => $definition->category,
                    'in_app' => $inApp,
                    'email' => $email,
                    'push' => $push,
                    'digest' => (string) ($item['digest'] ?? 'immediate'),
                ]
            );
        }
    }
}

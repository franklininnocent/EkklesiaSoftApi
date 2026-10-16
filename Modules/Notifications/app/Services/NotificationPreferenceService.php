<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Collection;
use Modules\Authentication\Models\User;
use Modules\Notifications\Models\NotificationDefinition;
use Modules\Notifications\Models\NotificationPreference;
use Modules\Notifications\Support\NotificationPreferenceVisibility;

class NotificationPreferenceService
{
    public function __construct(
        private readonly NotificationPreferenceVisibility $visibility,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForUser(User $user): Collection
    {
        $definitions = NotificationDefinition::query()->where('active', true)->orderBy('category')->get();
        $prefs = NotificationPreference::query()->where('user_id', $user->id)->get()->keyBy('definition_code');

        return $definitions
            ->filter(fn (NotificationDefinition $def) => $this->visibility->visibleTo($user, $def))
            ->map(function (NotificationDefinition $def) use ($prefs) {
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
            })
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function updateForUser(User $user, array $items): void
    {
        $userId = (int) $user->id;

        foreach ($items as $item) {
            $code = (string) ($item['definition_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $definition = NotificationDefinition::query()->where('code', $code)->first();
            if ($definition === null || ! $this->visibility->visibleTo($user, $definition)) {
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

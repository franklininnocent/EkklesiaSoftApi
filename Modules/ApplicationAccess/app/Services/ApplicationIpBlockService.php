<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Validation\ValidationException;
use Modules\ApplicationAccess\Models\ApplicationIpBlockRule;
use Modules\ApplicationAccess\Support\ApplicationIpBlockMatcher;
use Modules\ApplicationAccess\Support\IpAddressClassifier;
use Modules\Authentication\Models\User;
use Symfony\Component\HttpFoundation\IpUtils;

class ApplicationIpBlockService
{
    /** @var list<string> */
    private const FORBIDDEN_CIDRS = [
        '0.0.0.0/0',
        '::/0',
    ];

    public function __construct(
        private readonly ApplicationAccessPrivilegedAudit $privilegedAudit,
        private readonly IpAddressClassifier $ipClassifier,
    ) {}

    /**
     * @param  array{ip_address: string, cidr?: ?string, reason: string, expires_at?: ?string}  $data
     */
    public function create(User $actor, array $data, ?string $actorIp): ApplicationIpBlockRule
    {
        $ip = $this->ipClassifier->normalize($data['ip_address']);
        if ($ip === null) {
            throw ValidationException::withMessages([
                'ip_address' => ['A valid IP address is required.'],
            ]);
        }

        $cidr = isset($data['cidr']) && $data['cidr'] !== ''
            ? trim((string) $data['cidr'])
            : null;

        if ($cidr !== null && in_array($cidr, self::FORBIDDEN_CIDRS, true)) {
            throw ValidationException::withMessages([
                'cidr' => ['Blocking the entire internet is not allowed.'],
            ]);
        }

        $actorNormalized = $this->ipClassifier->normalize($actorIp);
        if ($actorNormalized !== null && ($actorNormalized === $ip || ($cidr && IpUtils::checkIp($actorNormalized, $cidr)))) {
            throw ValidationException::withMessages([
                'ip_address' => ['You cannot block your own IP address.'],
            ]);
        }

        $rule = ApplicationIpBlockRule::query()->create([
            'ip_address' => $ip,
            'cidr' => $cidr,
            'scope' => 'API',
            'reason' => $data['reason'],
            'created_by' => $actor->id,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        ApplicationIpBlockMatcher::flushCache();
        $this->privilegedAudit->recordIpBlock($actor, $rule);

        return $rule;
    }

    public function revoke(User $actor, ApplicationIpBlockRule $rule): ApplicationIpBlockRule
    {
        if ($rule->revoked_at !== null) {
            return $rule;
        }

        $rule->forceFill(['revoked_at' => now()])->save();
        ApplicationIpBlockMatcher::flushCache();
        $this->privilegedAudit->recordIpUnblock($actor, $rule);

        return $rule->refresh();
    }
}

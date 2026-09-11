<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\ApplicationAccess\Http\Requests\ListApplicationAccessSessionsRequest;
use Modules\ApplicationAccess\Services\ApplicationAccessPrivilegedAudit;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionQueryService;
use Modules\Authentication\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationAccessExportController extends Controller
{
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly ApplicationAccessSessionQueryService $sessions,
        private readonly ApplicationAccessPrivilegedAudit $privilegedAudit,
    ) {}

    public function sessions(ListApplicationAccessSessionsRequest $request): StreamedResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        $filters = $request->filters();
        $this->privilegedAudit->recordExport($actor, 'sessions', $filters);

        return response()->stream(function () use ($filters): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'session_reference',
                'status',
                'identity_type',
                'access_context',
                'user_id',
                'tenant_id',
                'ip_address',
                'country',
                'city',
                'started_at',
                'last_activity_at',
                'risk_level',
            ]);

            $page = 1;
            $exported = 0;

            while ($exported < self::MAX_ROWS) {
                $perPage = min(500, self::MAX_ROWS - $exported);
                $paginator = $this->sessions->paginate($filters, $page, $perPage);

                foreach ($paginator->items() as $session) {
                    fputcsv($handle, [
                        $session->session_reference,
                        $session->status,
                        $session->identity_type,
                        $session->access_context,
                        $session->user_id,
                        $session->tenant_id,
                        $session->ip_address,
                        $session->country,
                        $session->city,
                        $session->started_at?->toIso8601String(),
                        $session->last_activity_at?->toIso8601String(),
                        $session->risk_level,
                    ]);
                    $exported++;

                    if ($exported >= self::MAX_ROWS) {
                        break;
                    }
                }

                if (! $paginator->hasMorePages() || $exported >= self::MAX_ROWS) {
                    break;
                }

                $page++;
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="application-access-sessions.csv"',
        ]);
    }
}

<?php

namespace Modules\ApplicationAccess\Providers;

use App\Events\Auth\LoginFailed;
use App\Events\OAuth\AccessTokenCreated;
use App\Events\OAuth\AccessTokenRevoked;
use App\Events\OAuth\AccessTokenRotated;
use App\Events\OAuth\AllUserTokensRevoked;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\ApplicationAccess\Console\Commands\ApplicationAccessRetentionCommand;
use Modules\ApplicationAccess\Console\Commands\ApplicationAccessStatsCommand;
use Modules\ApplicationAccess\Listeners\LoginFailedListener;
use Modules\ApplicationAccess\Listeners\OAuthTokenLifecycleListener;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionLifecycleService;
use Modules\ApplicationAccess\Contracts\GeoIpReader;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Services\ApplicationAccessCaptureService;
use Modules\ApplicationAccess\Services\ApplicationAccessDashboardService;
use Modules\ApplicationAccess\Services\ApplicationAccessEventQueryService;
use Modules\ApplicationAccess\Services\ApplicationAccessInvestigationAudit;
use Modules\ApplicationAccess\Services\ApplicationAccessPrivilegedAudit;
use Modules\ApplicationAccess\Services\ApplicationAccessRecorder;
use Modules\ApplicationAccess\Services\ApplicationAccessSecurityQueryService;
use Modules\ApplicationAccess\Services\ApplicationAccessRetentionService;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionQueryService;
use Modules\ApplicationAccess\Services\ApplicationAccessSessionRevokeService;
use Modules\ApplicationAccess\Services\ApplicationAccessStatsService;
use Modules\ApplicationAccess\Services\ApplicationAccessThreatWriter;
use Modules\ApplicationAccess\Services\ApplicationIpBlockService;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamAuthorizer;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamConnectionManager;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamEventPublisher;
use Modules\ApplicationAccess\Services\ApplicationAccessStreamService;
use Modules\ApplicationAccess\Services\ApplicationSecuritySignalService;
use Modules\ApplicationAccess\Support\ApplicationAccessStreamEmitter;
use Modules\ApplicationAccess\Support\ApplicationIpBlockMatcher;
use Modules\ApplicationAccess\Support\ApplicationAccessCaptureExemptions;
use Modules\ApplicationAccess\Support\ApplicationAccessIdentityResolver;
use Modules\ApplicationAccess\Support\ApplicationAccessViewThrottle;
use Modules\ApplicationAccess\Support\ApplicationMetadataSanitizer;
use Modules\ApplicationAccess\Support\BearerTokenInspector;
use Modules\ApplicationAccess\Support\IpAddressClassifier;
use Modules\ApplicationAccess\Support\NullGeoIpReader;
use Modules\ApplicationAccess\Support\RouteNormalizer;
use Nwidart\Modules\Traits\PathNamespace;

class ApplicationAccessServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'ApplicationAccess';

    protected string $nameLower = 'applicationaccess';

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        $configPath = module_path($this->name, 'config/config.php');
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, $this->nameLower);
        }

        $this->app->singleton(GeoIpReader::class, NullGeoIpReader::class);
        $this->app->singleton(IpAddressClassifier::class);
        $this->app->singleton(RouteNormalizer::class);
        $this->app->singleton(ApplicationMetadataSanitizer::class);
        $this->app->singleton(ApplicationAccessSessionRepository::class);
        $this->app->singleton(ApplicationAccessRecorder::class);
        $this->app->singleton(ApplicationAccessSessionLifecycleService::class);
        $this->app->singleton(ApplicationAccessIdentityResolver::class);
        $this->app->singleton(ApplicationAccessCaptureExemptions::class);
        $this->app->singleton(ApplicationAccessViewThrottle::class);
        $this->app->singleton(BearerTokenInspector::class);
        $this->app->singleton(ApplicationAccessCaptureService::class);
        $this->app->singleton(ApplicationAccessDashboardService::class);
        $this->app->singleton(ApplicationAccessSessionQueryService::class);
        $this->app->singleton(ApplicationAccessEventQueryService::class);
        $this->app->singleton(ApplicationAccessSecurityQueryService::class);
        $this->app->singleton(ApplicationAccessInvestigationAudit::class);
        $this->app->singleton(ApplicationSecuritySignalService::class);
        $this->app->singleton(ApplicationAccessThreatWriter::class);
        $this->app->singleton(ApplicationAccessPrivilegedAudit::class);
        $this->app->singleton(ApplicationAccessSessionRevokeService::class);
        $this->app->singleton(ApplicationIpBlockService::class);
        $this->app->singleton(ApplicationIpBlockMatcher::class);
        $this->app->singleton(ApplicationAccessStreamEmitter::class);
        $this->app->singleton(ApplicationAccessStreamConnectionManager::class);
        $this->app->singleton(ApplicationAccessStreamAuthorizer::class);
        $this->app->singleton(ApplicationAccessStreamEventPublisher::class);
        $this->app->singleton(ApplicationAccessStreamService::class);
        $this->app->singleton(ApplicationAccessRetentionService::class);
        $this->app->singleton(ApplicationAccessStatsService::class);
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();

        $listener = OAuthTokenLifecycleListener::class;

        Event::listen(AccessTokenCreated::class, [$listener, 'handleAccessTokenCreated']);
        Event::listen(AccessTokenRotated::class, [$listener, 'handleAccessTokenRotated']);
        Event::listen(AccessTokenRevoked::class, [$listener, 'handleAccessTokenRevoked']);
        Event::listen(AllUserTokensRevoked::class, [$listener, 'handleAllUserTokensRevoked']);
        Event::listen(LoginFailed::class, LoginFailedListener::class);
    }

    protected function registerCommands(): void
    {
        $this->commands([
            ApplicationAccessRetentionCommand::class,
            ApplicationAccessStatsCommand::class,
        ]);
    }

    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            if (! config('applicationaccess.scheduler.retention_enabled', true)) {
                return;
            }

            /** @var Schedule $schedule */
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('application-access:retention')
                ->weeklyOn(
                    $this->weeklyDayNumber((string) config('applicationaccess.scheduler.retention_day', 'sunday')),
                    (string) config('applicationaccess.scheduler.retention_time', '03:30'),
                )
                ->withoutOverlapping();
        });
    }

    private function weeklyDayNumber(string $day): int
    {
        return match (strtolower($day)) {
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            default => 0,
        };
    }
}

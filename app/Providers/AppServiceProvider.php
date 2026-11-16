<?php

namespace App\Providers;

use App\Services\Health\Checks\CacheCheck;
use App\Services\Health\Checks\DatabaseCheck;
use App\Services\Health\Checks\MailTransportCheck;
use App\Services\Health\Checks\QueueCheck;
use App\Services\Health\Checks\WorkerCheck;
use App\Services\Health\HealthChecker;
use App\Services\Health\WorkerHeartbeat;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkerHeartbeat::class);

        $this->app->tag([
            DatabaseCheck::class,
            QueueCheck::class,
            CacheCheck::class,
            WorkerCheck::class,
            MailTransportCheck::class,
        ], 'health.checks');

        $this->app->bind(HealthChecker::class, fn ($app): HealthChecker => new HealthChecker($app->tagged('health.checks')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $recordHeartbeat = fn () => $this->app->make(WorkerHeartbeat::class)->record();

        Queue::looping($recordHeartbeat);
        Queue::before($recordHeartbeat);
        Queue::after($recordHeartbeat);

        Event::listen(
            JobExceptionOccurred::class,
            fn (JobExceptionOccurred $event) => Log::error('Job attempt failed', $this->describeFailedJob($event)),
        );

        Event::listen(
            JobFailed::class,
            fn (JobFailed $event) => Log::error('Job moved to dead-letter', $this->describeFailedJob($event)),
        );
    }

    /**
     * @return array{job: string, queue: string, connection: string, attempt: int, uuid: string|null, payload: array<string, mixed>, exception: \Throwable}
     */
    private function describeFailedJob(JobExceptionOccurred|JobFailed $event): array
    {
        return [
            'job' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'attempt' => $event->job->attempts(),
            'uuid' => $event->job->uuid(),
            'payload' => $event->job->payload(),
            'exception' => $event->exception,
        ];
    }
}

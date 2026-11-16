<?php

namespace App\Services\Health\Checks;

use App\Services\Health\Component;
use App\Services\Health\ComponentState;
use App\Services\Health\ComponentStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class MailTransportCheck implements HealthCheck
{
    use ReportsFailures;

    public const CACHE_KEY = 'health:mail:last_result';

    private const CACHE_TTL_SECONDS = 60;

    public function component(): Component
    {
        return Component::Mail;
    }

    public function check(bool $detailed): ComponentStatus
    {
        if ($detailed) {
            return $this->probe(true);
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->fromCache($cached);
        }

        $status = $this->probe(false);

        Cache::put(self::CACHE_KEY, [
            'state' => $status->state->value,
            'reason' => $status->reason,
            'latency_ms' => $status->latencyMs,
        ], self::CACHE_TTL_SECONDS);

        return $status;
    }

    /**
     * Opens and authenticates an SMTP session without sending any message.
     */
    private function probe(bool $detailed): ComponentStatus
    {
        $startedAt = hrtime(true);

        if (blank(config('mail.mailers.smtp.username'))) {
            return ComponentStatus::down($this->component(), 'Mail credentials not configured', 0);
        }

        $transport = null;

        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();

            if (! $transport instanceof SmtpTransport) {
                return ComponentStatus::down($this->component(), 'Mail transport is not SMTP', $this->elapsedMilliseconds($startedAt));
            }

            $transport->start();

            return ComponentStatus::up($this->component(), $this->elapsedMilliseconds($startedAt));
        } catch (Throwable $exception) {
            $reason = $this->failureReason($this->isAuthenticationFailure($exception) ? 'SMTP authentication failed' : 'SMTP connection failed', $exception, $detailed);
            $this->logFailure($reason, $exception);

            return ComponentStatus::down($this->component(), $reason, $this->elapsedMilliseconds($startedAt));
        } finally {
            if ($transport instanceof SmtpTransport) {
                $transport->stop();
            }
        }
    }

    private function isAuthenticationFailure(Throwable $exception): bool
    {
        return $exception->getCode() === 535 || str_contains(strtolower($exception->getMessage()), 'auth');
    }

    /**
     * @param  array<array-key, mixed>  $cached
     */
    private function fromCache(array $cached): ComponentStatus
    {
        $latency = isset($cached['latency_ms']) ? (int) $cached['latency_ms'] : null;

        return ComponentState::tryFrom((string) ($cached['state'] ?? '')) === ComponentState::Up
            ? ComponentStatus::up($this->component(), $latency)
            : ComponentStatus::down($this->component(), (string) ($cached['reason'] ?? 'SMTP connection failed'), $latency);
    }
}

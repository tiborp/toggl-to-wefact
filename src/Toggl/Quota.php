<?php
declare(strict_types=1);
namespace App\Toggl;

/** Tracks response-based quota blocks per scope within the current session or client workflow. */
final class Quota
{
    public function __construct(private array $state = []) {}

    public function assertAvailable(string $scope): void
    {
        foreach ([$scope, 'rate_limit'] as $key) {
            if (($this->state[$key]['blocked_until'] ?? 0) > time()) {
                throw new TogglException('Toggl-quota of requestlimiet bereikt. Wacht tot de aangegeven reset voordat je opnieuw ophaalt.');
            }
        }
    }

    /**
     * Keep missing quota unknown and honor explicit blocks instead of guessing availability.
     * HTTP 429 also creates a cross-scope rate-limit block; no retry is scheduled here.
     */
    public function record(string $scope, HttpResponse $response): void
    {
        $headers = array_change_key_case($response->headers, CASE_LOWER);
        $number = static fn (string $key): ?int => isset($headers[$key]) && preg_match('/^-?[0-9]{1,9}$/D', (string) $headers[$key]) ? (int) $headers[$key] : null;
        $remaining = $number('x-toggl-quota-remaining');
        $seconds = $number('x-toggl-quota-resets-in');
        if ($seconds !== null && $seconds < 0) { $seconds = null; }
        $retry = $number('retry-after');
        if ($retry === null && isset($headers['retry-after'])) {
            $date = strtotime($headers['retry-after']);
            if ($date !== false) { $retry = max(0, $date - time()); }
        }
        $blocked = in_array($response->status, [402, 429], true) || ($remaining !== null && $remaining <= 0);
        $this->state[$scope] = [
            'remaining' => $remaining,
            'reset_at' => $seconds !== null ? time() + $seconds : null,
            'blocked_until' => $blocked ? time() + max(1, $seconds ?? $retry ?? ($response->status === 429 ? 60 : 3600)) : 0,
        ];
        if ($response->status === 429) {
            $this->state['rate_limit'] = ['remaining' => null, 'reset_at' => null, 'blocked_until' => time() + max(1, $retry ?? 60)];
        }
    }

    public function state(): array { return $this->state; }
}

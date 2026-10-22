<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

class TurnstileRule implements ValidationRule
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        public ?string $remoteIp = null,
        public ?string $expectedAction = null
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secret = config('services.turnstile.secret');
        if (! is_string($secret) || trim($secret) === '') {
            $fail('Security verification is currently unavailable. Please try again later.');

            return;
        }

        if (! is_string($value) || trim($value) === '' || strlen(trim($value)) > 2048) {
            $fail('Please complete the security verification before submitting.');

            return;
        }

        $token = trim($value);
        $expectedHostnames = $this->resolveExpectedHostnames();

        if (empty($expectedHostnames)) {
            $fail('Security verification hostname configuration is invalid.');

            return;
        }

        $expectedAction = $this->expectedAction ?? config('services.turnstile.action', 'feedback_submit');
        $expectedAction = is_string($expectedAction) ? trim($expectedAction) : '';

        $payload = [
            'secret' => trim($secret),
            'response' => $token,
        ];

        if (is_string($this->remoteIp) && trim($this->remoteIp) !== '') {
            $payload['remoteip'] = trim($this->remoteIp);
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->connectTimeout(5)
                ->post(self::SITEVERIFY_URL, $payload);
        } catch (Throwable) {
            $fail('Unable to verify security challenge at this time. Please try again.');

            return;
        }

        if (! $response->successful()) {
            $fail('Unable to verify security challenge at this time. Please try again.');

            return;
        }

        $data = $response->json();
        if (! is_array($data)) {
            $fail('Unable to verify security challenge at this time. Please try again.');

            return;
        }

        if (($data['success'] ?? false) !== true) {
            $errorCodes = is_array($data['error-codes'] ?? null) ? $data['error-codes'] : [];
            if (in_array('timeout-or-duplicate', $errorCodes, true)) {
                $fail('The security verification token has expired or has already been used. Please complete a new challenge.');

                return;
            }

            $fail('Security verification failed. Please complete the challenge and try again.');

            return;
        }

        $returnedHostname = isset($data['hostname']) && is_string($data['hostname'])
            ? strtolower(trim($data['hostname']))
            : '';

        if ($returnedHostname === '' || ! in_array($returnedHostname, $expectedHostnames, true)) {
            $fail('Security verification failed due to an unexpected hostname.');

            return;
        }

        if ($expectedAction !== '') {
            $returnedAction = isset($data['action']) && is_string($data['action'])
                ? trim($data['action'])
                : '';

            if ($returnedAction === '' || $returnedAction !== $expectedAction) {
                $fail('Security verification failed due to an unexpected action.');

                return;
            }
        }

        $tokenHash = hash('sha256', $token);
        if (! Cache::add("turnstile_redeemed:{$tokenHash}", true, now()->addMinutes(10))) {
            $fail('The security verification token has expired or has already been used. Please complete a new challenge.');

            return;
        }
    }

    /**
     * Resolve the allowlist of expected deployment hostnames for Turnstile verification.
     *
     * @return array<int, string>
     */
    protected function resolveExpectedHostnames(): array
    {
        $isProduction = app()->environment('production') || config('app.env') === 'production';
        $configured = config('services.turnstile.hostnames');
        $rawHostnames = [];

        if (is_array($configured)) {
            $rawHostnames = $configured;
        } elseif (is_string($configured) && trim($configured) !== '') {
            $rawHostnames = explode(',', $configured);
        } else {
            $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);
            if (is_string($appUrlHost) && trim($appUrlHost) !== '') {
                $rawHostnames[] = $appUrlHost;
            }

            if (! $isProduction) {
                $rawHostnames[] = 'localhost';
                $rawHostnames[] = '127.0.0.1';
            }
        }

        $disallowedInProduction = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];

        $normalized = [];
        foreach ($rawHostnames as $host) {
            if (! is_string($host)) {
                continue;
            }

            $clean = strtolower(trim($host));
            if ($clean === '') {
                continue;
            }

            if ($isProduction && in_array($clean, $disallowedInProduction, true)) {
                continue;
            }

            $normalized[] = $clean;
        }

        return array_values(array_unique($normalized));
    }
}

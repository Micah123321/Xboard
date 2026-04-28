<?php

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 60;
    public $failOnTimeout = true;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $params = $this->params;
        if (
            $this->queue === 'send_email_mass'
            && isset($params['template_value'])
            && is_array($params['template_value'])
            && array_key_exists('content', $params['template_value'])
        ) {
            $timestamp = now()->format('Y-m-d H:i:s.u');
            $params['template_value']['content'] = (string) $params['template_value']['content'] . "\r\n\r\n[Send-Time: {$timestamp}]";
        }

        $mailLog = $this->sendEmail($params);
        if ($mailLog['error']) {
            throw new RuntimeException('Failed to send email: ' . $mailLog['error']);
        }
    }

    /**
     * Return retry delays for transient mail delivery failures.
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Record a failed send attempt without exposing the full recipient address.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Send email job failed', [
            'queue' => $this->queue,
            'email' => $this->maskedEmail(),
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Send the email payload.
     */
    protected function sendEmail(array $params): array
    {
        return MailService::sendEmail($params);
    }

    /**
     * Return a partially masked recipient address for operational logs.
     */
    private function maskedEmail(): ?string
    {
        $email = $this->params['email'] ?? null;
        if (!is_string($email) || !str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            return null;
        }

        return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
    }
}

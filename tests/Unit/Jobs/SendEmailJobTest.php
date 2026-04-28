<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendEmailJob;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SendEmailJobTest extends TestCase
{
    public function test_job_uses_smtp_safe_timeout_and_backoff(): void
    {
        $job = new SendEmailJob([
            'email' => 'user@example.com',
            'subject' => 'Subject',
            'template_name' => 'notify',
        ]);

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function test_handle_throws_when_mail_service_returns_error(): void
    {
        $job = new class([
            'email' => 'user@example.com',
            'subject' => 'Subject',
            'template_name' => 'notify',
        ]) extends SendEmailJob {
            protected function sendEmail(array $params): array
            {
                return ['error' => 'SMTP connection failed'];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to send email: SMTP connection failed');

        $job->handle();
    }

    public function test_mass_email_content_gets_send_time_marker_before_send(): void
    {
        $job = new class([
            'email' => 'user@example.com',
            'subject' => 'Subject',
            'template_name' => 'notify',
            'template_value' => [
                'content' => 'Hello',
            ],
        ], 'send_email_mass') extends SendEmailJob {
            public array $capturedParams = [];

            protected function sendEmail(array $params): array
            {
                $this->capturedParams = $params;

                return ['error' => null];
            }
        };

        $job->handle();

        $this->assertStringStartsWith('Hello', $job->capturedParams['template_value']['content']);
        $this->assertStringContainsString('[Send-Time:', $job->capturedParams['template_value']['content']);
    }
}

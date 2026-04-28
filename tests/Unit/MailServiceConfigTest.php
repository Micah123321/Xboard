<?php

namespace Tests\Unit;

use App\Services\MailRuntimeConfig;
use PHPUnit\Framework\TestCase;

class MailServiceConfigTest extends TestCase
{
    public function test_mail_log_config_masks_sensitive_values_recursively(): void
    {
        $config = [
            'host' => 'smtp.example.com',
            'password' => 'secret-password',
            'mailers' => [
                'smtp' => [
                    'username' => 'mailer',
                    'password' => 'nested-secret',
                    'timeout' => 30,
                ],
                'ses' => [
                    'key' => 'aws-key',
                    'secret' => 'aws-secret',
                ],
            ],
        ];

        $sanitized = $this->mailConfigForLog($config);

        $this->assertSame('smtp.example.com', $sanitized['host']);
        $this->assertSame('******', $sanitized['password']);
        $this->assertSame('mailer', $sanitized['mailers']['smtp']['username']);
        $this->assertSame('******', $sanitized['mailers']['smtp']['password']);
        $this->assertSame(30, $sanitized['mailers']['smtp']['timeout']);
        $this->assertSame('******', $sanitized['mailers']['ses']['key']);
        $this->assertSame('******', $sanitized['mailers']['ses']['secret']);
    }

    public function test_mail_log_config_keeps_empty_sensitive_values_empty(): void
    {
        $sanitized = $this->mailConfigForLog([
            'password' => null,
            'secret' => '',
            'timeout' => 30,
        ]);

        $this->assertNull($sanitized['password']);
        $this->assertSame('', $sanitized['secret']);
        $this->assertSame(30, $sanitized['timeout']);
    }

    private function mailConfigForLog(array $config): array
    {
        return MailRuntimeConfig::configForLog($config);
    }
}

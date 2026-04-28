<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

class MailRuntimeConfig
{
    private const DEFAULT_MAIL_TIMEOUT = 30;
    private const MAX_MAIL_TIMEOUT = 120;

    /**
     * Apply the current runtime mail configuration before sending.
     */
    public static function apply(string $appName): void
    {
        $timeout = self::normalizeTimeout(config('mail.timeout', self::DEFAULT_MAIL_TIMEOUT));
        $smtpConfig = ['timeout' => $timeout];

        if (admin_setting('email_host')) {
            Config::set('mail.default', 'smtp');
            Config::set('mail.driver', 'smtp');

            $smtpConfig = [
                'host' => admin_setting('email_host', config('mail.host')),
                'port' => admin_setting('email_port', config('mail.port')),
                'encryption' => admin_setting('email_encryption', config('mail.encryption')),
                'username' => admin_setting('email_username', config('mail.username')),
                'password' => admin_setting('email_password', config('mail.password')),
                'timeout' => self::normalizeTimeout(admin_setting('email_timeout', $timeout)),
            ];

            foreach ($smtpConfig as $key => $value) {
                Config::set("mail.{$key}", $value);
            }

            Config::set('mail.from.address', admin_setting('email_from_address', config('mail.from.address')));
        } else {
            Config::set('mail.timeout', $timeout);
        }

        Config::set('mail.from.name', $appName);
        self::setSmtpMailerConfig($smtpConfig);
        self::forgetResolvedMailers();
    }

    /**
     * Return a mail configuration snapshot safe for persistence.
     */
    public static function configForLog(array $config): array
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = self::configForLog($value);
                continue;
            }

            if (self::isSensitiveMailConfigKey((string) $key) && $value !== null && $value !== '') {
                $config[$key] = '******';
            }
        }

        return $config;
    }

    /**
     * Normalize a user-provided timeout into the supported range.
     */
    private static function normalizeTimeout(mixed $timeout): int
    {
        $timeout = (int) $timeout;
        if ($timeout <= 0) {
            return self::DEFAULT_MAIL_TIMEOUT;
        }

        return min($timeout, self::MAX_MAIL_TIMEOUT);
    }

    /**
     * Mirror legacy SMTP settings into Laravel's modern mailer config shape.
     */
    private static function setSmtpMailerConfig(array $smtpConfig): void
    {
        Config::set('mail.mailers.smtp.transport', 'smtp');

        foreach ($smtpConfig as $key => $value) {
            Config::set("mail.mailers.smtp.{$key}", $value);
        }
    }

    /**
     * Clear resolved mailers so long-running workers use fresh settings.
     */
    private static function forgetResolvedMailers(): void
    {
        $mailManager = Mail::getFacadeRoot();
        if ($mailManager && method_exists($mailManager, 'forgetMailers')) {
            $mailManager->forgetMailers();
        }
    }

    /**
     * Determine whether a mail config key contains sensitive material.
     */
    private static function isSensitiveMailConfigKey(string $key): bool
    {
        return in_array(strtolower($key), ['password', 'secret', 'token', 'key'], true);
    }
}

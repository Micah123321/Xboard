<?php

namespace App\Services;

use App\Models\MailSuppression;
use InvalidArgumentException;

class MailSuppressionService
{
    public const REASON_MAILBOX_NOT_FOUND = 'mailbox_not_found';
    public const REASON_REJECTED_BY_RECIPIENT = 'rejected_by_recipient';
    public const REASON_PERMANENT_FAILURE = 'permanent_failure';
    public const SOURCE_BOUNCE = 'bounce';
    public const SOURCE_SMTP_ERROR = 'smtp_error';
    public const SUPPRESSED_ERROR_PREFIX = 'Email suppressed:';

    private const MAX_EXCERPT_LENGTH = 1000;

    /**
     * Normalize an email address for suppression lookups.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Build the stable error text used when a suppressed recipient is skipped.
     */
    public static function suppressedError(string $email): string
    {
        return self::SUPPRESSED_ERROR_PREFIX . ' ' . self::normalizeEmail($email);
    }

    /**
     * Determine whether a mail error should stop queue retries.
     */
    public static function shouldSkipRetryForError(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }

        return str_starts_with($error, self::SUPPRESSED_ERROR_PREFIX);
    }

    /**
     * Return whether the recipient is globally suppressed.
     */
    public function isSuppressed(string $email): bool
    {
        $email = self::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return MailSuppression::where('email', $email)->exists();
    }

    /**
     * Create or update a suppression record for a recipient.
     */
    public function suppress(
        string $email,
        string $reason,
        string $source = 'manual',
        ?string $diagnosticCode = null,
        ?string $rawExcerpt = null
    ): MailSuppression {
        $email = self::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid suppression email.');
        }

        return MailSuppression::updateOrCreate(
            ['email' => $email],
            [
                'reason' => $reason,
                'source' => $source,
                'diagnostic_code' => $diagnosticCode,
                'raw_excerpt' => $this->excerpt($rawExcerpt),
            ]
        );
    }

    /**
     * Suppress the failed recipient from a mail bounce body when it is permanent.
     */
    public function suppressFromBounceMessage(
        string $message,
        string $source = self::SOURCE_BOUNCE
    ): ?MailSuppression {
        $reason = self::detectPermanentFailureReason($message);
        if ($reason === null) {
            return null;
        }

        $email = $this->extractFailedRecipient($message);
        if ($email === null) {
            return null;
        }

        return $this->suppress(
            $email,
            $reason,
            $source,
            $this->extractDiagnosticCode($message),
            $message
        );
    }

    /**
     * Record a suppression from a synchronous SMTP permanent failure.
     */
    public function suppressFromPermanentFailure(string $email, string $message): ?MailSuppression
    {
        $reason = self::detectPermanentFailureReason($message);
        if ($reason === null) {
            return null;
        }

        try {
            return $this->suppress(
                $email,
                $reason,
                self::SOURCE_SMTP_ERROR,
                $this->extractDiagnosticCode($message),
                $message
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Return true when the delivery failure is permanent.
     */
    public static function isPermanentFailure(string $message): bool
    {
        return self::detectPermanentFailureReason($message) !== null;
    }

    /**
     * Resolve the suppression reason from a permanent delivery failure.
     */
    public static function detectPermanentFailureReason(string $message): ?string
    {
        $normalized = strtolower((string) preg_replace('/\s+/', ' ', $message));
        if (!preg_match('/\b5\d{2}\b/', $normalized)) {
            return null;
        }

        if (str_contains($normalized, 'mailbox not found')
            || str_contains($normalized, 'user unknown')
            || str_contains($normalized, 'no such user')
            || str_contains($normalized, 'recipient address rejected')
        ) {
            return self::REASON_MAILBOX_NOT_FOUND;
        }

        if (str_contains($normalized, 'mail is rejected by recipients')
            || str_contains($normalized, 'rejected by recipient')
        ) {
            return self::REASON_REJECTED_BY_RECIPIENT;
        }

        return null;
    }

    /**
     * Extract the failed recipient address from common delivery-status bodies.
     */
    private function extractFailedRecipient(string $message): ?string
    {
        if (preg_match('/<([^<>\s@]+@[^<>\s@]+\.[^<>\s@]+)>\s*:/i', $message, $matches)) {
            return self::normalizeEmail($matches[1]);
        }

        if (preg_match('/final-recipient:\s*rfc822;\s*([^\s;<>]+@[^\s;<>]+)/i', $message, $matches)) {
            return self::normalizeEmail($matches[1]);
        }

        return null;
    }

    /**
     * Extract the first SMTP diagnostic status code from an error message.
     */
    private function extractDiagnosticCode(string $message): ?string
    {
        if (preg_match('/\b(5\d{2})\b/', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Collapse and truncate raw diagnostic text before persistence.
     */
    private function excerpt(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        $excerpt = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_substr($excerpt, 0, self::MAX_EXCERPT_LENGTH);
    }
}

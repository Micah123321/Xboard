<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use RuntimeException;

class MailHtmlContent
{
    /**
     * Build the inline HTML body used by notification emails.
     */
    public static function buildModernNotifyHtml(array $templateValue, string $subject, string $appName): string
    {
        $title = self::escapeHtml($subject);
        $brand = self::escapeHtml((string) ($templateValue['name'] ?? $appName));
        $content = self::escapeHtml((string) ($templateValue['content'] ?? ''));
        $content = nl2br($content, false);
        $sendTime = self::escapeHtml(now()->format('Y-m-d H:i:s'));

        $cta = '';
        $url = isset($templateValue['url']) ? (string) $templateValue['url'] : '';
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $safeUrl = self::escapeHtml($url);
            $cta = '<a href="' . $safeUrl . '" style="display:inline-block;padding:12px 20px;border-radius:10px;background:#111827;color:#ffffff;text-decoration:none;font-weight:600;">Open Link</a>';
        }

        return '<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>' . $title . '</title>
</head>
<body style="margin:0;padding:0;background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 100%);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <div style="max-width:680px;margin:0 auto;padding:32px 16px;">
    <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;box-shadow:0 16px 40px rgba(15,23,42,.08);">
      <div style="padding:24px 24px 16px;background:radial-gradient(80% 120% at 0% 0%,#dbeafe 0%,#ffffff 100%);border-bottom:1px solid #e5e7eb;">
        <div style="font-size:12px;color:#64748b;letter-spacing:.06em;text-transform:uppercase;">Message</div>
        <h1 style="margin:8px 0 0;font-size:24px;line-height:1.3;color:#0f172a;">' . $title . '</h1>
      </div>
      <div style="padding:24px;">
        <div style="font-size:15px;line-height:1.8;color:#334155;">' . $content . '</div>
        <div style="margin-top:20px;">' . $cta . '</div>
      </div>
      <div style="padding:14px 24px;border-top:1px solid #e5e7eb;background:#f8fafc;font-size:12px;color:#64748b;">
        <div>Sender: ' . $brand . '</div>
        <div>Sent at: ' . $sendTime . '</div>
      </div>
    </div>
  </div>
</body>
</html>';
    }

    /**
     * Send a pre-rendered HTML email using the available Laravel mail API.
     */
    public static function sendHtmlMail(string $email, string $subject, string $html): void
    {
        $mailer = Mail::getFacadeRoot();
        if ($mailer && method_exists($mailer, 'html')) {
            Mail::html($html, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
            return;
        }

        Mail::send([], [], function ($message) use ($email, $subject, $html) {
            $message->to($email)->subject($subject);

            if (method_exists($message, 'getSymfonyMessage')) {
                $symfonyMessage = $message->getSymfonyMessage();
                if ($symfonyMessage && method_exists($symfonyMessage, 'html')) {
                    $symfonyMessage->html($html);
                    return;
                }
            }

            if (method_exists($message, 'setBody')) {
                $message->setBody($html, 'text/html');
                return;
            }

            throw new RuntimeException('Unsupported mail message driver for html body.');
        });
    }

    /**
     * Escape text before inserting it into an HTML email template.
     */
    private static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    /**
     * 获取需要发送提醒的用户总数
     */
    public function getTotalUsersNeedRemind(): int
    {
        return User::where(function ($query) {
            $query->where('remind_expire', true)
                ->orWhere('remind_traffic', true);
        })
            ->where('banned', false)
            ->whereNotNull('email')
            ->count();
    }

    /**
     * 分块处理用户提醒邮件
     */
    public function processUsersInChunks(int $chunkSize, ?callable $progressCallback = null): array
    {
        $statistics = [
            'processed_users' => 0,
            'expire_emails' => 0,
            'traffic_emails' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        User::select('id', 'email', 'expired_at', 'transfer_enable', 'u', 'd', 'remind_expire', 'remind_traffic')
            ->where(function ($query) {
                $query->where('remind_expire', true)
                    ->orWhere('remind_traffic', true);
            })
            ->where('banned', false)
            ->whereNotNull('email')
            ->chunk($chunkSize, function ($users) use (&$statistics, $progressCallback) {
                $this->processUserChunk($users, $statistics);

                if ($progressCallback) {
                    $progressCallback();
                }

                if ($statistics['processed_users'] % 2500 === 0) {
                    gc_collect_cycles();
                }
            });

        return $statistics;
    }

    /**
     * 处理用户块
     */
    private function processUserChunk($users, array &$statistics): void
    {
        foreach ($users as $user) {
            try {
                $statistics['processed_users']++;
                $emailsSent = 0;

                if ($user->remind_expire && $this->shouldSendExpireRemind($user)) {
                    $this->remindExpire($user);
                    $statistics['expire_emails']++;
                    $emailsSent++;
                }

                if ($user->remind_traffic && $this->shouldSendTrafficRemind($user)) {
                    $this->remindTraffic($user);
                    $statistics['traffic_emails']++;
                    $emailsSent++;
                }

                if ($emailsSent === 0) {
                    $statistics['skipped']++;
                }
            } catch (\Exception $e) {
                $statistics['errors']++;

                Log::error('发送提醒邮件失败', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 检查是否应该发送过期提醒
     */
    private function shouldSendExpireRemind(User $user): bool
    {
        if ($user->expired_at === null) {
            return false;
        }

        $expiredAt = $user->expired_at;
        $now = time();
        return ($expiredAt - 86400) < $now && $expiredAt > $now;
    }

    /**
     * 检查是否应该发送流量提醒
     */
    private function shouldSendTrafficRemind(User $user): bool
    {
        if ($user->transfer_enable <= 0) {
            return false;
        }

        $usedBytes = $user->u + $user->d;
        $usageRatio = $usedBytes / $user->transfer_enable;

        return $usageRatio >= 0.8;
    }

    public function remindTraffic(User $user)
    {
        if (!$user->remind_traffic) {
            return;
        }
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) {
            return;
        }

        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag)) {
            return;
        }
        if (!Cache::put($flag, 1, 24 * 3600)) {
            return;
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 80%', [
                'app_name' => admin_setting('app_name', 'Notification Service'),
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => admin_setting('app_name', 'Notification Service'),
                'url' => admin_setting('app_url'),
            ],
        ]);
    }

    public function remindExpire(User $user)
    {
        if (!$this->shouldSendExpireRemind($user)) {
            return;
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
                'app_name' => admin_setting('app_name', 'Notification Service'),
            ]),
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => admin_setting('app_name', 'Notification Service'),
                'url' => admin_setting('app_url'),
            ],
        ]);
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud) {
            return false;
        }
        if (!$transfer_enable) {
            return false;
        }

        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 80) {
            return false;
        }
        if ($percentage >= 100) {
            return false;
        }

        return true;
    }

    /**
     * 发送邮件
     */
    public static function sendEmail(array $params)
    {
        $appName = self::sanitizeMailText((string) admin_setting('app_name', config('app.name', 'Notification Service')));
        if ($appName === '') {
            $appName = 'Notification Service';
        }

        if (admin_setting('email_host')) {
            Config::set('mail.host', admin_setting('email_host', config('mail.host')));
            Config::set('mail.port', admin_setting('email_port', config('mail.port')));
            Config::set('mail.encryption', admin_setting('email_encryption', config('mail.encryption')));
            Config::set('mail.username', admin_setting('email_username', config('mail.username')));
            Config::set('mail.password', admin_setting('email_password', config('mail.password')));
            Config::set('mail.from.address', admin_setting('email_from_address', config('mail.from.address')));
        }
        Config::set('mail.from.name', $appName);

        $params['template_value'] = isset($params['template_value']) && is_array($params['template_value'])
            ? $params['template_value']
            : [];

        if (array_key_exists('name', $params['template_value'])) {
            $params['template_value']['name'] = self::sanitizeMailText((string) $params['template_value']['name']);
        } else {
            $params['template_value']['name'] = $appName;
        }

        if ($params['template_value']['name'] === '') {
            $params['template_value']['name'] = $appName;
        }

        if (array_key_exists('content', $params['template_value'])) {
            $params['template_value']['content'] = self::sanitizeMailText((string) $params['template_value']['content']);
        }

        $email = (string) $params['email'];
        $subject = self::sanitizeMailText((string) $params['subject']);
        if ($subject === '') {
            $subject = 'Notification';
        }

        $originTemplateName = (string) $params['template_name'];
        $params['template_name'] = 'mail.' . admin_setting('email_template', 'default') . '.' . $originTemplateName;
        $logTemplateName = $params['template_name'];

        try {
            if ($originTemplateName === 'notify') {
                $html = self::buildModernNotifyHtml($params['template_value'], $subject, $appName);
                self::sendHtmlMail($email, $subject, $html);
                $logTemplateName = 'mail.modern.notify';
            } else {
                Mail::send(
                    $params['template_name'],
                    $params['template_value'],
                    function ($message) use ($email, $subject) {
                        $message->to($email)->subject($subject);
                    }
                );
            }
            $error = null;
        } catch (\Throwable $e) {
            Log::error($e);
            $error = $e->getMessage();
        }

        $log = [
            'email' => $email,
            'subject' => $subject,
            'template_name' => $logTemplateName,
            'error' => $error,
            'config' => config('mail'),
        ];
        MailLog::create($log);

        return $log;
    }

    private static function sanitizeMailText(string $text): string
    {
        $cleaned = str_ireplace(['xboard', 'v2board'], '', $text);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);

        return trim((string) $cleaned);
    }

    private static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private static function buildModernNotifyHtml(array $templateValue, string $subject, string $appName): string
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

    private static function sendHtmlMail(string $email, string $subject, string $html): void
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

            throw new \RuntimeException('Unsupported mail message driver for html body.');
        });
    }
}

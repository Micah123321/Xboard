<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    // Render {{key}} / {{key|default}} placeholders.
    private static function renderPlaceholders(string $template, array $vars): string
    {
        if ($template === '' || empty($vars)) {
            return $template;
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)(?:\|([^}]*))?\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1] ?? '';
            $default = array_key_exists(2, $m) ? trim((string) $m[2]) : null;

            if (!array_key_exists($key, $vars) || $vars[$key] === null || $vars[$key] === '') {
                return $default !== null ? $default : $m[0];
            }

            $value = $vars[$key];
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }, $template);
    }

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

        MailRuntimeConfig::apply($appName);

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
            $params['template_value']['content'] = (string) $params['template_value']['content'];
        }

        $email = (string) $params['email'];
        $originTemplateName = (string) $params['template_name'];
        $subject = (string) $params['subject'];

        $vars = is_array($params['template_value']) ? ($params['template_value']['vars'] ?? []) : [];
        $contentMode = is_array($params['template_value']) ? ($params['template_value']['content_mode'] ?? null) : null;

        if (is_array($vars) && !empty($vars)) {
            $subject = self::renderPlaceholders($subject, $vars);

            if (isset($params['template_value']['content']) && is_string($params['template_value']['content'])) {
                $params['template_value']['content'] = self::renderPlaceholders($params['template_value']['content'], $vars);
            }
        }

        $subject = self::sanitizeMailText($subject);
        if ($subject === '') {
            $subject = 'Notification';
        }

        if (array_key_exists('content', $params['template_value'])) {
            $params['template_value']['content'] = self::sanitizeMailText((string) $params['template_value']['content']);
        }

        if (
            $contentMode === 'text'
            && $originTemplateName !== 'notify'
            && isset($params['template_value']['content'])
            && is_string($params['template_value']['content'])
        ) {
            $params['template_value']['content'] = e($params['template_value']['content']);
        }

        $params['template_name'] = 'mail.' . admin_setting('email_template', 'default') . '.' . $originTemplateName;
        $logTemplateName = $params['template_name'];
        try {
            if ($originTemplateName === 'notify') {
                $html = MailHtmlContent::buildModernNotifyHtml($params['template_value'], $subject, $appName);
                MailHtmlContent::sendHtmlMail($email, $subject, $html);
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
            'config' => MailRuntimeConfig::configForLog((array) config('mail', [])),
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

}

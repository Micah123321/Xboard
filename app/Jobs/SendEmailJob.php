<?php

namespace App\Jobs;

use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;

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
    public function handle()
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

        $mailLog = MailService::sendEmail($params);
        if ($mailLog['error']) {
            $this->release(60);
        }
    }
}

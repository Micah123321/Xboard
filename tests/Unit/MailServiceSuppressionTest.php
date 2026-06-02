<?php

namespace Tests\Unit;

use App\Models\MailLog;
use App\Models\MailSuppression;
use App\Services\MailService;
use App\Services\MailSuppressionService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MailServiceSuppressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createTables();
        $this->bindSettings();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_suppressed_recipient_is_logged_without_sending(): void
    {
        MailSuppression::create([
            'email' => 'user@example.com',
            'reason' => MailSuppressionService::REASON_MAILBOX_NOT_FOUND,
            'source' => 'test',
        ]);

        $mail = Mockery::mock();
        $mail->shouldNotReceive('send');
        Mail::swap($mail);

        $log = MailService::sendEmail([
            'email' => ' User@Example.COM ',
            'subject' => 'Subject',
            'template_name' => 'verify',
            'template_value' => ['code' => '123456'],
        ]);

        $this->assertSame('user@example.com', $log['email']);
        $this->assertSame(MailSuppressionService::suppressedError('user@example.com'), $log['error']);
        $this->assertSame(1, MailLog::count());
        $this->assertSame(MailSuppressionService::suppressedError('user@example.com'), MailLog::first()->error);
    }

    public function test_permanent_transport_failure_creates_suppression(): void
    {
        $mail = Mockery::mock();
        $mail->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('550 Mailbox not found'));
        Mail::swap($mail);

        $log = MailService::sendEmail([
            'email' => 'missing@example.com',
            'subject' => 'Subject',
            'template_name' => 'verify',
            'template_value' => ['code' => '123456'],
        ]);

        $this->assertSame('550 Mailbox not found', $log['error']);
        $this->assertDatabaseHas('v2_mail_suppressions', [
            'email' => 'missing@example.com',
            'reason' => MailSuppressionService::REASON_MAILBOX_NOT_FOUND,
            'source' => MailSuppressionService::SOURCE_SMTP_ERROR,
        ]);
    }

    public function test_transient_transport_failure_does_not_create_suppression(): void
    {
        $mail = Mockery::mock();
        $mail->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Connection timed out'));
        Mail::swap($mail);

        $log = MailService::sendEmail([
            'email' => 'user@example.com',
            'subject' => 'Subject',
            'template_name' => 'verify',
            'template_value' => ['code' => '123456'],
        ]);

        $this->assertSame('Connection timed out', $log['error']);
        $this->assertSame(0, MailSuppression::count());
    }

    private function createTables(): void
    {
        Schema::dropIfExists('v2_mail_log');
        Schema::dropIfExists('v2_mail_suppressions');

        Schema::create('v2_mail_log', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('email', 191);
            $table->string('subject');
            $table->string('template_name');
            $table->text('error')->nullable();
            $table->json('config')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_mail_suppressions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('email', 191)->unique();
            $table->string('reason', 64);
            $table->string('source', 32)->default('manual');
            $table->string('diagnostic_code', 32)->nullable();
            $table->text('raw_excerpt')->nullable();
            $table->timestamps();
        });
    }

    private function bindSettings(): void
    {
        $this->app->instance(Setting::class, new class extends Setting {
            public function __construct()
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return match (strtolower($key)) {
                    'app_name' => 'Notification Service',
                    'email_template' => 'default',
                    default => $default,
                };
            }

            public function save(array $settings): bool
            {
                return true;
            }
        });
    }
}

<?php

namespace Tests\Unit;

use App\Models\MailSuppression;
use App\Services\MailSuppressionService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class MailSuppressionServiceTest extends TestCase
{
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDatabase();
        $this->resetTables();
    }

    public function test_suppresses_mailbox_not_found_bounce_recipient(): void
    {
        $message = <<<'TEXT'
This is the mail system at host mail.ikuncdn.com.

<zuoruchun@qq.com>: host mx3.qq.com[203.205.219.57] said: 550 Mailbox not
found. http://service.mail.qq.com/detail/122/169
TEXT;

        $suppression = (new MailSuppressionService())->suppressFromBounceMessage($message);

        $this->assertNotNull($suppression);
        $this->assertSame('zuoruchun@qq.com', $suppression->email);
        $this->assertSame(MailSuppressionService::REASON_MAILBOX_NOT_FOUND, $suppression->reason);
        $this->assertSame(MailSuppressionService::SOURCE_BOUNCE, $suppression->source);
        $this->assertSame('550', $suppression->diagnostic_code);
    }

    public function test_suppresses_recipient_rejected_bounce_recipient(): void
    {
        $message = <<<'TEXT'
<1816293445@qq.com>: host mx3.qq.com[203.205.219.57] said: 550 Mail is rejected
    by recipients. https://service.mail.qq.com/detail/0/92.
TEXT;

        $suppression = (new MailSuppressionService())->suppressFromBounceMessage($message);

        $this->assertNotNull($suppression);
        $this->assertSame('1816293445@qq.com', $suppression->email);
        $this->assertSame(MailSuppressionService::REASON_REJECTED_BY_RECIPIENT, $suppression->reason);
    }

    public function test_ignores_transient_failures(): void
    {
        $suppression = (new MailSuppressionService())->suppressFromBounceMessage(
            '<user@example.com>: host smtp.example.com said: 421 Temporary failure'
        );

        $this->assertNull($suppression);
        $this->assertSame(0, MailSuppression::count());
    }

    public function test_ignores_bounce_without_structured_failed_recipient(): void
    {
        $suppression = (new MailSuppressionService())->suppressFromBounceMessage(
            'Mail Delivery System <MAILER-DAEMON@mail.example.com> said: 550 Mailbox not found.'
        );

        $this->assertNull($suppression);
        $this->assertSame(0, MailSuppression::count());
    }

    public function test_suppression_is_idempotent_and_normalizes_email(): void
    {
        $service = new MailSuppressionService();

        $first = $service->suppress(' User@Example.COM ', MailSuppressionService::REASON_MAILBOX_NOT_FOUND);
        $second = $service->suppress('user@example.com', MailSuppressionService::REASON_REJECTED_BY_RECIPIENT);

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($service->isSuppressed('USER@example.com'));
        $this->assertSame(1, MailSuppression::count());
        $this->assertSame(
            MailSuppressionService::REASON_REJECTED_BY_RECIPIENT,
            MailSuppression::first()->reason
        );
    }

    private function bootDatabase(): void
    {
        if (self::$capsule) {
            return;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$capsule = $capsule;
    }

    private function resetTables(): void
    {
        $schema = self::$capsule->schema();
        $schema->dropIfExists('v2_mail_suppressions');
        $schema->create('v2_mail_suppressions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('email', 191)->unique();
            $table->string('reason', 64);
            $table->string('source', 32)->default('manual');
            $table->string('diagnostic_code', 32)->nullable();
            $table->text('raw_excerpt')->nullable();
            $table->timestamps();
        });
    }
}

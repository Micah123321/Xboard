<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Controllers\V2\Client\AppController;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use App\Support\Setting;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use PHPUnit\Framework\TestCase;

class TicketContinuousReplyTest extends TestCase
{
    private Capsule $database;
    private Container $previousContainer;
    private mixed $previousFacadeApplication;
    private mixed $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();

        $container = new Container();
        Container::setInstance($container);
        $container->instance('app', $container);
        $container->instance('config', new Repository([
            'v2board' => ['ticket_must_wait_reply' => 1],
        ]));
        $container->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $setting = $this->createMock(Setting::class);
        $setting->method('get')->willReturnCallback(
            fn (string $key) => $key === 'ticket_must_wait_reply' ? 1 : null
        );
        $container->instance(Setting::class, $setting);
        $container->instance(ResponseFactoryContract::class, new ResponseFactory(
            $this->createMock(ViewFactory::class),
            $this->createMock(Redirector::class),
        ));

        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_ticket', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('subject');
            $table->integer('level');
            $table->integer('status')->default(Ticket::STATUS_OPENING);
            $table->integer('reply_status');
            $table->integer('last_reply_user_id');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $schema->create('v2_ticket_message', function ($table) {
            $table->increments('id');
            $table->integer('ticket_id');
            $table->integer('user_id');
            $table->text('message');
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_user_can_send_consecutive_replies_even_when_legacy_setting_is_enabled(): void
    {
        $this->assertSame(1, admin_setting('ticket_must_wait_reply'));
        $ticket = $this->createTicket();
        $hookCalls = 0;
        HookManager::register('ticket.reply.user.after', function ($updated) use ($ticket, &$hookCalls) {
            $this->assertSame($ticket->id, $updated->id);
            $hookCalls++;
        });

        foreach (['More details', 'Another detail', 'One more detail'] as $message) {
            $response = (new TicketController())->reply($this->request($ticket, $message));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($response->getData(true)['data']);
            $this->assertSame(Ticket::REPLY_STATUS_WAITING, $ticket->fresh()->reply_status);
            $this->assertSame(1001, $ticket->fresh()->last_reply_user_id);
        }

        $this->assertSame(3, $hookCalls);
        $this->assertSame(
            ['Initial message', 'More details', 'Another detail', 'One more detail'],
            $ticket->messages()->orderBy('id')->pluck('message')->all(),
        );
    }

    public function test_user_can_send_multiple_replies_after_an_admin_response(): void
    {
        $ticket = $this->createTicket();
        $this->assertNotFalse((new TicketService())->reply($ticket, 'Admin response', 2002));
        $this->assertSame(Ticket::REPLY_STATUS_REPLIED, $ticket->fresh()->reply_status);

        foreach (['User response', 'Additional information'] as $message) {
            $response = (new TicketController())->reply($this->request($ticket, $message));
            $this->assertSame(200, $response->getStatusCode());
        }

        $this->assertSame(4, $ticket->messages()->count());
        $this->assertSame(Ticket::REPLY_STATUS_WAITING, $ticket->fresh()->reply_status);
    }

    public function test_reply_preserves_existing_behavior_of_reopening_closed_tickets(): void
    {
        $ticket = $this->createTicket();
        $controller = new TicketController();
        $this->assertSame(200, $controller->close($this->request($ticket))->getStatusCode());
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);

        $this->assertSame(200, $controller->reply($this->request($ticket))->getStatusCode());
        $this->assertSame(Ticket::STATUS_OPENING, $ticket->fresh()->status);
        $this->assertSame(Ticket::REPLY_STATUS_WAITING, $ticket->fresh()->reply_status);
    }

    public function test_user_cannot_reply_to_another_users_ticket(): void
    {
        $ticket = $this->createTicket();
        $response = (new TicketController())->reply($this->request($ticket, 'Other user', 1002));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_empty_reply_is_rejected_without_saving_a_message(): void
    {
        $ticket = $this->createTicket();
        $response = (new TicketController())->reply($this->request($ticket, ''));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_unknown_ticket_is_rejected_without_saving_a_message(): void
    {
        $ticket = new Ticket();
        $ticket->id = 999;
        $response = (new TicketController())->reply($this->request($ticket));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, TicketMessage::query()->count());
    }

    public function test_admin_and_client_config_disable_the_legacy_waiting_requirement(): void
    {
        $admin = (new ConfigController())->fetch(new Request(['key' => 'site']))->getData(true);
        $client = (new AppController())->getConfig(new Request())->getData(true);

        $this->assertFalse($admin['data']['site']['ticket_must_wait_reply']);
        $this->assertFalse($client['data']['features']['ticket_must_wait_reply']);
    }

    public function test_admin_settings_ignore_the_retired_waiting_option(): void
    {
        $validator = new ValidatorFactory(app('translator'), app());
        $validated = $validator->make([
            'ticket_must_wait_reply' => true,
            'app_name' => 'Example',
        ], ConfigSave::RULES)->validated();

        $this->assertSame(['app_name' => 'Example'], $validated);
    }

    private function createTicket(): Ticket
    {
        return (new TicketService())->createTicket(1001, 'Support request', 1, 'Initial message');
    }

    private function request(Ticket $ticket, string $message = 'More details', int $userId = 1001): Request
    {
        $request = Request::create('/api/v1/user/ticket/reply', 'POST', [
            'id' => $ticket->id,
            'message' => $message,
        ]);
        $request->setUserResolver(fn () => (object) ['id' => $userId]);

        return $request;
    }
}

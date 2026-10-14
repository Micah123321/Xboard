<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V1\User\NoticeController as UserNoticeController;
use App\Http\Controllers\V2\Admin\NoticeController as AdminNoticeController;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use PHPUnit\Framework\TestCase;

class NoticePopupTest extends TestCase
{
    private Capsule $database;
    private Migration $migration;
    private Container $previousContainer;
    private mixed $previousFacadeApplication;
    private mixed $previousResolver;
    private bool $previousUnguarded;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousUnguarded = Model::isUnguarded();
        Model::reguard();

        $container = new Container();
        Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $container->instance(ResponseFactoryContract::class, new ResponseFactory(
            $this->createMock(ViewFactory::class),
            $this->createMock(Redirector::class),
        ));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->database->getConnection()->getSchemaBuilder()->create('v2_notice', function ($table) {
            $table->increments('id');
            $table->integer('sort')->nullable();
            $table->string('title');
            $table->text('content');
            $table->boolean('show')->default(false);
            $table->string('img_url')->nullable();
            $table->string('tags')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $this->migration = require dirname(__DIR__, 3)
            . '/database/migrations/2026_10_14_000001_add_popup_to_v2_notice_table.php';
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Model::unguard($this->previousUnguarded);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    public function test_popup_can_be_enabled_and_disabled_and_read_by_both_clients(): void
    {
        $admin = new AdminNoticeController();
        $saved = $admin->save($this->request(['show' => true, 'popup' => true]));
        $this->assertSame(true, $saved->getData(true)['data']);

        $notice = Notice::query()->firstOrFail();
        $this->assertTrue($notice->fresh()->popup);
        $this->assertTrue($admin->fetch(new Request())->getData(true)['data'][0]['popup']);
        $this->assertTrue($this->userNotices()['data'][0]['popup']);

        $updated = $admin->save($this->request([
            'id' => $notice->id,
            'title' => 'Updated notice',
            'show' => true,
            'popup' => false,
        ]));
        $this->assertSame(true, $updated->getData(true)['data']);
        $this->assertFalse($notice->fresh()->popup);
        $this->assertSame('Updated notice', $notice->fresh()->title);
        $this->assertFalse($admin->fetch(new Request())->getData(true)['data'][0]['popup']);
        $this->assertFalse($this->userNotices()['data'][0]['popup']);
    }

    public function test_older_clients_can_omit_popup_without_clearing_it(): void
    {
        $admin = new AdminNoticeController();
        $admin->save($this->request(['popup' => true]));
        $notice = Notice::query()->firstOrFail();
        $admin->save($this->request(['id' => $notice->id, 'content' => 'New content']));

        $this->assertTrue($notice->fresh()->popup);
        $this->assertSame('New content', $notice->fresh()->content);

        $admin->save($this->request(['title' => 'Ordinary notice']));
        $this->assertFalse(Notice::query()->orderByDesc('id')->firstOrFail()->popup);
    }

    public function test_hidden_popup_notices_are_excluded_from_the_user_response(): void
    {
        $admin = new AdminNoticeController();
        $admin->save($this->request(['show' => false, 'popup' => true]));
        $admin->save($this->request(['title' => 'Visible notice', 'show' => true, 'popup' => true]));

        $response = $this->userNotices();
        $this->assertSame(1, $response['total']);
        $this->assertCount(1, $response['data']);
        $this->assertSame('Visible notice', $response['data'][0]['title']);
        $this->assertTrue($response['data'][0]['popup']);
    }

    public function test_migration_preserves_legacy_notices_defaults_to_disabled_and_is_reversible(): void
    {
        $this->migration->down();
        $this->database->getConnection()->table('v2_notice')->insert([
            'title' => 'Legacy notice',
            'content' => 'Legacy content',
            'show' => true,
            'created_at' => 100,
            'updated_at' => 100,
        ]);
        $this->migration->up();
        $this->migration->up();

        $notice = Notice::query()->firstOrFail();
        $this->assertFalse($notice->popup);
        $this->assertTrue($notice->show);
        $this->assertSame('Legacy content', $notice->content);

        $this->migration->down();
        $this->assertFalse($this->database->getConnection()->getSchemaBuilder()->hasColumn('v2_notice', 'popup'));
        $this->assertSame(1, $this->database->getConnection()->table('v2_notice')->count());
    }

    public function test_popup_validation_accepts_boolean_flags_and_rejects_malformed_values(): void
    {
        $validator = new ValidatorFactory(new Translator(new ArrayLoader(), 'en'));
        $rules = (new NoticeSave())->rules();
        foreach ([true, false, 1, 0, '1', '0'] as $popup) {
            $this->assertTrue($validator->make($this->request(['popup' => $popup])->all(), $rules)->passes());
        }
        foreach (['false', 'enabled', 2, [], null] as $popup) {
            $this->assertTrue($validator->make($this->request(['popup' => $popup])->all(), $rules)->fails());
        }
        $this->assertTrue($validator->make($this->request([])->all(), $rules)->passes());
    }

    private function request(array $payload): NoticeSave
    {
        return NoticeSave::create('/notice/save', 'POST', array_replace([
            'title' => 'Test notice',
            'content' => 'Test content',
        ], $payload));
    }

    private function userNotices(): array
    {
        $response = (new UserNoticeController())->fetch(new Request());

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

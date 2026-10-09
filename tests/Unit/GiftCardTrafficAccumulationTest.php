<?php

namespace Tests\Unit;

use App\Models\GiftCardTemplate;
use App\Models\User;
use App\Services\GiftCardRedemptionService;
use App\Services\GiftCardService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GiftCardTrafficAccumulationTest extends TestCase
{
    private $previousResolver;
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousContainer = Container::getInstance();
        Container::setInstance(new Container());
        $database = new Manager();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->bootEloquent();
        $database->getConnection()->getSchemaBuilder()->create('v2_plan', function (Blueprint $table) {
            $table->id();
            $table->integer('transfer_enable');
        });
        $database->getConnection()->table('v2_plan')->insert(['id' => 1, 'transfer_enable' => 20]);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_preview_defaults_to_traffic_for_active_users_and_plan_for_new_users(): void
    {
        $template = new GiftCardTemplate([
            'type' => GiftCardTemplate::TYPE_PLAN,
            'rewards' => ['plan_id' => 1],
        ]);
        $user = new User();
        $user->setRawAttributes([
            'plan_id' => 1,
            'banned' => false,
            'expired_at' => time() + 86400,
            'transfer_enable' => 20 * 1024 ** 3,
            'u' => 4 * 1024 ** 3,
            'd' => 6 * 1024 ** 3,
        ], true);
        $service = new GiftCardRedemptionService();
        $options = $service->getOptions($template, $user);
        self::assertSame(['traffic', 'plan'], array_column($options, 'mode'));
        self::assertSame(10 * 1024 ** 3, $options[0]['transfer_enable']);
        self::assertSame($options[0]['mode'], $service->resolveMode(null, $template, $user));

        $user->plan_id = null;
        self::assertSame(['plan'], array_column($service->getOptions($template, $user), 'mode'));
        self::assertSame('plan', $service->resolveMode(null, $template, $user));
    }

    public static function usageCases(): array
    {
        return ['unused' => [0], 'partially used' => [10], 'exhausted' => [20], 'over quota' => [25]];
    }

    #[DataProvider('usageCases')]
    public function test_each_card_adds_twenty_gib_without_deducting_usage_again(int $usedGiB): void
    {
        $user = new class extends User {
            public function save(array $options = []) { return true; }
        };
        $user->setRawAttributes([
            'plan_id' => 1,
            'banned' => false,
            'expired_at' => time() + 86400,
            'transfer_enable' => 20 * 1024 ** 3,
            'temporary_transfer_enable' => 0,
            'u' => min(2, $usedGiB) * 1024 ** 3,
            'd' => max(0, $usedGiB - 2) * 1024 ** 3,
        ], true);
        $service = new class($user) extends GiftCardService {
            public function __construct(User $user) { $this->user = $user; }
            public function applyCard(): void
            {
                $this->redemptionMode = self::REDEMPTION_MODE_TRAFFIC;
                $this->redemptionTrafficBytes = $this->user->getRemainingTraffic();
                $this->giveRewards(['plan_id' => 1, 'plan_validity_days' => 0]);
            }
        };

        for ($card = 0; $card < 3; $card++) {
            $remaining = $user->getRemainingTraffic();
            $service->applyCard();
            self::assertSame($remaining + 20 * 1024 ** 3, $user->getRemainingTraffic());
            self::assertSame($usedGiB * 1024 ** 3, $user->getTotalUsedTraffic());
            self::assertSame(20 * 1024 ** 3, $user->transfer_enable - $user->temporary_transfer_enable);
        }
    }
}

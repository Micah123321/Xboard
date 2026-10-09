<?php

namespace Tests\Unit;

use App\Services\StatisticalService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class StatisticalTrafficBucketTest extends TestCase
{
    public function test_report_uses_daily_bucket_instead_of_insertion_date(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $previousResolver = Model::getConnectionResolver();
        $container = new Container();
        $container->instance('config', new Repository());
        Container::setInstance($container);
        $database = new Manager($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->bootEloquent();
        $container->instance('db', $database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        try {
            $connection = $database->getConnection();
            foreach ([
                'CREATE TABLE v2_order (created_at INTEGER, paid_at INTEGER, status INTEGER, total_amount INTEGER)',
                'CREATE TABLE v2_commission_log (created_at INTEGER, get_amount INTEGER)',
                'CREATE TABLE v2_user (created_at INTEGER, invite_user_id INTEGER)',
                'CREATE TABLE v2_stat_server (server_id INTEGER DEFAULT 1, record_type TEXT, record_at INTEGER, created_at INTEGER, u INTEGER, d INTEGER)',
                'CREATE TABLE v2_server (id INTEGER PRIMARY KEY, name TEXT, type TEXT, parent_id INTEGER)',
            ] as $sql) {
                $connection->statement($sql);
            }
            $start = 1704067200;
            $end = $start + 86400;
            $connection->table('v2_stat_server')->insert([
                ['record_type' => 'd', 'record_at' => $start, 'created_at' => $end + 60, 'u' => 100, 'd' => 200],
                ['record_type' => 'd', 'record_at' => $start - 86400, 'created_at' => $start + 60, 'u' => 400, 'd' => 500],
                ['record_type' => 'm', 'record_at' => $start, 'created_at' => $start + 60, 'u' => 600, 'd' => 700],
            ]);
            // This report does not use Redis; skip its connection-only constructor.
            $service = (new \ReflectionClass(StatisticalService::class))->newInstanceWithoutConstructor();
            $service->setStartAt($start);
            $service->setEndAt($end);
            $this->assertSame(300, (int) $service->generateStatData()['transfer_used_total']);
            $connection->table('v2_server')->insert(['id' => 1, 'name' => 'Node', 'type' => 'vless']);
            $ranking = StatisticalService::getServerRank($start, $end);
            $this->assertCount(1, $ranking);
            $this->assertSame(100, $ranking[0]['u']);
            $this->assertSame(200, $ranking[0]['d']);
            $this->assertSame(300, $ranking[0]['total']);
            $previousNow = \Carbon\Carbon::getTestNow();
            try {
                \Carbon\Carbon::setTestNow(\Carbon\Carbon::createFromTimestamp($end + 3600, date_default_timezone_get()));
                $this->assertSame([], StatisticalService::getServerRank());
                $this->assertSame(300, StatisticalService::getServerRank('yesterday')[0]['total']);
            } finally {
                \Carbon\Carbon::setTestNow($previousNow);
            }
        } finally {
            $database->getDatabaseManager()->disconnect();
            if ($previousResolver !== null) {
                Model::setConnectionResolver($previousResolver);
            } else {
                Model::unsetConnectionResolver();
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
            Container::setInstance($previousContainer);
        }
    }
}

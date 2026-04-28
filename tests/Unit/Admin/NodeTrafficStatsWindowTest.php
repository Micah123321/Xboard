<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class NodeTrafficStatsWindowTest extends TestCase
{
    public function test_node_traffic_windows_include_today_yesterday_and_current_month(): void
    {
        $timezone = config('app.timezone');
        $reference = Carbon::create(2026, 4, 29, 13, 45, 0, $timezone)->timestamp;

        $windows = $this->resolveWindows($reference);

        $this->assertSame(Carbon::create(2026, 4, 29, 0, 0, 0, $timezone)->timestamp, $windows['today']['start']);
        $this->assertSame(Carbon::create(2026, 4, 30, 0, 0, 0, $timezone)->timestamp, $windows['today']['end']);
        $this->assertSame(Carbon::create(2026, 4, 28, 0, 0, 0, $timezone)->timestamp, $windows['yesterday']['start']);
        $this->assertSame(Carbon::create(2026, 4, 29, 0, 0, 0, $timezone)->timestamp, $windows['yesterday']['end']);
        $this->assertSame(Carbon::create(2026, 4, 1, 0, 0, 0, $timezone)->timestamp, $windows['month']['start']);
        $this->assertSame(Carbon::create(2026, 5, 1, 0, 0, 0, $timezone)->timestamp, $windows['month']['end']);
    }

    public function test_node_traffic_windows_handle_first_day_of_month(): void
    {
        $timezone = config('app.timezone');
        $reference = Carbon::create(2026, 5, 1, 8, 10, 0, $timezone)->timestamp;

        $windows = $this->resolveWindows($reference);

        $this->assertSame(Carbon::create(2026, 4, 30, 0, 0, 0, $timezone)->timestamp, $windows['yesterday']['start']);
        $this->assertSame(Carbon::create(2026, 5, 1, 0, 0, 0, $timezone)->timestamp, $windows['yesterday']['end']);
        $this->assertSame(Carbon::create(2026, 5, 1, 0, 0, 0, $timezone)->timestamp, $windows['month']['start']);
        $this->assertSame(Carbon::create(2026, 6, 1, 0, 0, 0, $timezone)->timestamp, $windows['month']['end']);
    }

    /**
     * @return array<string, array{start: int, end: int}>
     */
    private function resolveWindows(int $referenceTimestamp): array
    {
        $controller = new ManageController();
        $method = new ReflectionMethod(ManageController::class, 'resolveNodeTrafficWindows');
        $method->setAccessible(true);

        /** @var array<string, array{start: int, end: int}> $result */
        $result = $method->invoke($controller, $referenceTimestamp);

        return $result;
    }
}

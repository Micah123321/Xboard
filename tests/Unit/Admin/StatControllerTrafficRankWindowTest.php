<?php

namespace Tests\Unit\Admin;

use App\Http\Controllers\V2\Admin\StatController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class StatControllerTrafficRankWindowTest extends TestCase
{
    public function test_single_day_rank_window_compares_with_exact_previous_day(): void
    {
        $startDate = strtotime('2026-04-24 00:00:00');
        $endDate = strtotime('2026-04-24 23:59:59');

        $comparisonWindow = $this->resolveWindow($startDate, $endDate);

        $this->assertSame(strtotime('2026-04-23 00:00:00'), $comparisonWindow['start']);
        $this->assertSame($startDate, $comparisonWindow['end']);
    }

    public function test_multi_day_rank_window_keeps_existing_equal_span_behavior(): void
    {
        $startDate = strtotime('2026-04-18 00:00:00');
        $endDate = strtotime('2026-04-24 23:59:59');

        $comparisonWindow = $this->resolveWindow($startDate, $endDate);

        $this->assertSame($startDate - ($endDate - $startDate), $comparisonWindow['start']);
        $this->assertSame($startDate, $comparisonWindow['end']);
    }

    /**
     * @return array{start: int, end: int}
     */
    private function resolveWindow(int $startDate, int $endDate): array
    {
        $controller = new StatController();
        $method = new ReflectionMethod(StatController::class, 'resolveTrafficRankComparisonWindow');
        $method->setAccessible(true);

        /** @var array{start: int, end: int} $result */
        $result = $method->invoke($controller, $startDate, $endDate);

        return $result;
    }
}

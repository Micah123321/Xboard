<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\AccessTrafficQueryService;
use App\Services\AccessTrafficService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccessTrafficController extends Controller
{
    public const COLUMNS = ['id', 'user_id', 'user_email', 'node_id', 'node_name', 'domain', 'ip', 'port', 'network', 'domain_source',
        'bucket_at', 'first_seen', 'last_seen', 'raw_u', 'raw_d', 'raw_total', 'billed_u', 'billed_d', 'billed_total',
        'rate', 'service', 'category', 'confidence', 'reason', 'activity_hint', 'activity_confidence', 'activity_reason', 'connections', 'share', 'coverage'];

    public const REPORT_COLUMNS = ['采集片段', '用户 / 节点', '访问目标', '服务 / 分类（推断）', '原始上行', '原始下行', '原始合计', '计费分摊', '占比 / 倍率', '判断依据'];

    public static function reportBytes(?int $bytes): string
    {
        if ($bytes === null) return '未结算';
        $value = (float) $bytes;
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB'];
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return number_format($value, $unit ? 2 : 0) . ' ' . $units[$unit];
    }

    public static function reportTime(int $timestamp, string $timezone): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i:s');
    }

    private static function reportRow(array $row, string $timezone): array
    {
        $target = $row['domain'] ?: ($row['ip'] ?: '未归属目标');
        if ($row['port']) $target .= ':' . $row['port'];
        $rate = $row['rate'] === null ? '混合 / 未知倍率' : $row['rate'] . 'x';
        $confidence = ['high' => '高', 'medium' => '中', 'low' => '低', 'unknown' => '未知'][$row['confidence']];
        return [
            self::reportTime($row['first_seen'], $timezone) . ' 至 ' . self::reportTime($row['last_seen'], $timezone),
            '用户 #' . $row['user_id'] . (!empty($row['user_email']) ? ' (' . $row['user_email'] . ')' : '') . ' / ' . $row['node_name'], $target . ' / ' . $row['network'],
            ($row['service'] ?: '未知服务') . ' / ' . \App\Services\AccessTrafficClassifier::LABELS[$row['category']],
            self::reportBytes($row['raw_u']), self::reportBytes($row['raw_d']), self::reportBytes($row['raw_total']),
            self::reportBytes($row['billed_total']), $row['share'] . '% / ' . $rate,
            '服务识别可信度：' . $confidence . '。' . $row['reason']
                . (isset($row['activity_hint']) ? ' 流量形态（低可信度）：' . $row['activity_hint'] . '；' . $row['activity_reason'] : ''),
        ];
    }

    public function index(Request $request, AccessTrafficQueryService $service): JsonResponse
    {
        return new JsonResponse($service->index($service->filters($request->query())));
    }

    public function config(Request $request, AccessTrafficService $service): JsonResponse
    {
        return new JsonResponse($request->isMethod('POST') ? $service->saveSettings($request->all()) : $service->settings());
    }

    public function export(Request $request, AccessTrafficQueryService $service): StreamedResponse
    {
        $filters = $service->filters($request->query());
        $format = $filters['format'] ?? 'csv';
        $query = $service->rows($filters);
        $limit = (int) config('access_traffic.export_max_rows', 100000);
        if ($service->countGroups($filters, $limit + 1) > $limit) {
            throw ValidationException::withMessages(['export' => 'Export exceeds ' . $limit . ' rows; narrow the filters.']);
        }
        $context = $service->context($filters);
        return new StreamedResponse(function () use ($format, $query, $context, $service, $limit): void {
            if ($format === 'html') {
                echo view('access-traffic.export', ['context' => $context, 'columns' => self::REPORT_COLUMNS])->render();
                foreach ($query->limit($limit)->cursor() as $record) {
                    $row = $service->row($record, $context['summary']['raw_total']);
                    echo '<tr>';
                    foreach (self::reportRow($row, $context['meta']['timezone']) as $value) {
                        echo '<td>' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table></body></html>';
                return;
            }
            $output = fopen('php://output', 'wb');
            $write = static function (array $values) use ($output): void {
                fputcsv($output, array_map([AccessTrafficQueryService::class, 'csvCell'], $values), ',', '"', '');
            };
            echo "\xEF\xBB\xBF";
            $write(['节点访问流量：原始字节与实际扣费分摊；计费值空白表示未结算；分类为推断']);
            $write(['时区', $context['meta']['timezone'], '采集覆盖', $context['meta']['coverage']]);
            $write(['筛选条件', json_encode($context['meta']['filters'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $write(['覆盖说明', $context['meta']['reason']]);
            $write(['采集缺口', json_encode($context['meta']['collection_gaps'], JSON_THROW_ON_ERROR)]);
            $write(['汇总（字节）', json_encode($context['summary'], JSON_THROW_ON_ERROR)]);
            $write(self::COLUMNS);
            foreach ($query->limit($limit)->cursor() as $record) {
                $row = $service->row($record, $context['summary']['raw_total']);
                $write(array_map(fn ($column) => $row[$column] ?? null, self::COLUMNS));
            }
            fclose($output);
        }, 200, [
            'Content-Type' => $format === 'html' ? 'text/html; charset=UTF-8' : 'text/csv; charset=UTF-8',
            'Content-Disposition' => ($format === 'html' ? 'inline' : 'attachment') . '; filename="access-traffic.' . $format . '"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

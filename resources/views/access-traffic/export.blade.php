<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>用户节点访问流量报告</title>
<style>
body { font: 12px system-ui, sans-serif; color: #222; margin: 24px; }
table { border-collapse: collapse; width: 100%; }
th, td { border: 1px solid #ddd; padding: 6px; text-align: left; overflow-wrap: anywhere; }
th { background: #f3f4f6; }
.summary { display: flex; gap: 24px; flex-wrap: wrap; padding: 12px; background: #f5f6f8; }
.muted { color: #666; }
@page { size: A4 landscape; margin: 12mm; }
@media print { button { display: none; } body { margin: 0; font-size: 10px; } thead { display: table-header-group; } tr { break-inside: avoid; } }
</style>
</head>
<body>
<button type="button" onclick="window.print()">打印 / 保存 PDF</button>
<h1>用户节点访问流量报告</h1>
<p>用户：{{ $context['meta']['filters']['user_id'] ?? '全部' }} · 节点：{{ $context['meta']['filters']['node_id'] ?? '全部' }} · 时区：{{ $context['meta']['timezone'] }}</p>
<p>时间范围：{{ \App\Http\Controllers\V2\Admin\AccessTrafficController::reportTime($context['meta']['filters']['start_time'], $context['meta']['timezone']) }} 至 {{ \App\Http\Controllers\V2\Admin\AccessTrafficController::reportTime($context['meta']['filters']['end_time'], $context['meta']['timezone']) }}（含开始，不含结束）</p>
<div class="summary">
<strong>原始总量：{{ \App\Http\Controllers\V2\Admin\AccessTrafficController::reportBytes($context['summary']['raw_total']) }}</strong>
<strong>已分摊计费量：{{ \App\Http\Controllers\V2\Admin\AccessTrafficController::reportBytes($context['summary']['billed_total']) }}</strong>
<span>未结算报告：{{ $context['summary']['unsettled_report_count'] }} 份</span>
<span>未归属目标：{{ \App\Http\Controllers\V2\Admin\AccessTrafficController::reportBytes($context['summary']['unattributed_bytes']) }}</span>
</div>
<p class="muted">{{ $context['meta']['reason'] }}</p>
<p>分类依据为目标域名规则；具体页面、视频标题及用户主动操作未被记录。通用 CDN 保留用途未知。</p>
<p>缺口：{{ $context['meta']['collection_gaps']['node_billing_reports_without_details'] }} 个节点级计费批次尚无已处理明细；{{ $context['meta']['collection_gaps']['pending_detail_reports'] }} 份明细待处理；{{ $context['meta']['collection_gaps']['failed_settlement_reports'] }} 份结算关联异常。</p>
@if (count($context['analysis']))
<p>消耗构成：@foreach ($context['analysis'] as $item) {{ $item['category_label'] }}（推断） {{ $item['share'] }}% @endforeach</p>
@endif
<table>
<thead><tr>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
<tbody>

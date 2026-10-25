<?php

namespace App\Services\AiCredit;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

class AiCreditClient
{
    public function request(string $method, string $baseUrl, string $token, string $externalRef, ?string $amount = null): ?array
    {
        $url = rtrim($baseUrl, '/') . '/api/integrations/xboard/rewards';
        $request = Http::acceptJson()->asJson()->withToken($token)
            ->withHeaders(['Idempotency-Key' => $externalRef])->connectTimeout(4)->timeout(15)->withoutRedirecting();
        $response = match ($method) {
            'POST' => $request->post($url, ['external_ref' => $externalRef, 'amount_usd' => $amount]),
            'EXPIRE' => $request->withRequestMiddleware(fn ($message) => $message->withoutHeader('Content-Type'))->send('POST', $url . '/' . rawurlencode($externalRef) . '/expire'),
            default => $request->get($url . '/' . rawurlencode($externalRef)),
        };
        if ($method === 'GET' && $response->status() === 404) return null;
        if (!$response->successful()) {
            // Never propagate upstream bodies: they may contain a redeem code or credentials.
            throw new ApiException('发码服务暂未完成处理（HTTP ' . $response->status() . '），系统将核对原请求', 503);
        }
        $data = $response->json('data');
        if (!is_array($data) || ($data['external_ref'] ?? null) !== $externalRef
            || ($data['type'] ?? null) !== 'balance' || !isset($data['id'], $data['value'], $data['status'])
            || !in_array($data['status'], ['unused', 'used', 'expired'], true)
            || !is_string($data['code'] ?? null) || $data['code'] === '' || strlen($data['code']) > 256) {
            throw new ApiException('发码服务结果待核对', 503);
        }
        return $data;
    }
}

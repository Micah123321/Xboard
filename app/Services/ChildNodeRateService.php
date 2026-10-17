<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChildNodeRateService
{
    public const AUTH_ID_BASE = 1099511627776;
    public const MAX_AUTH_ID = 9007199254740991;

    public static function capabilityKey(int $parentId): string
    {
        return "node_child_rate_version:{$parentId}";
    }

    public static function recordCapability(Server $node, Request $request): void
    {
        Cache::put(self::capabilityKey((int) $node->id),
            $request->header('X-Mi-Node-Child-Rate') === '1' ? 1 : 0, 86400);
    }

    public static function supports(Server $node): bool
    {
        return PHP_INT_SIZE >= 8 && (int) Cache::get(self::capabilityKey((int) $node->id), 0) === 1;
    }

    public static function validate(Server $child, bool $requireCapability = false): void
    {
        if ($child->exists && $child->isDirty(['parent_id', 'type', 'protocol_settings'])) {
            foreach (Server::where('parent_id', $child->id)->where('child_rate_enabled', true)->get() as $dependent) {
                if ($child->parent_id || $dependent->type !== $child->type
                    || ($child->type === Server::TYPE_HYSTERIA
                        && (int) data_get($dependent->protocol_settings, 'version', 1) !== (int) data_get($child->protocol_settings, 'version', 1))
                    || ($child->type === Server::TYPE_SHADOWSOCKS
                        && data_get($dependent->protocol_settings, 'cipher') !== data_get($child->protocol_settings, 'cipher'))) {
                    throw ValidationException::withMessages([
                        'child_rate_enabled' => '请先关闭关联子节点的单独倍率，再修改父节点层级或协议',
                    ]);
                }
            }
        }
        if (!$child->child_rate_enabled) {
            return;
        }
        $parent = $child->parent_id ? Server::find($child->parent_id) : null;
        $error = null;
        if (!$parent || (int) $parent->id === (int) $child->id || $parent->parent_id) {
            $error = '子节点单独倍率需要关联一个有效的顶级父节点';
        } elseif ($child->type === Server::TYPE_SNELL || $parent->type !== $child->type) {
            $error = '子节点单独倍率需要父子节点使用相同的 mi-node 协议';
        } elseif ($child->type === Server::TYPE_HYSTERIA
            && (int) data_get($parent->protocol_settings, 'version', 1) !== (int) data_get($child->protocol_settings, 'version', 1)) {
            $error = '父子节点的 Hysteria 版本需要一致';
        } elseif ($child->type === Server::TYPE_SHADOWSOCKS
            && data_get($parent->protocol_settings, 'cipher') !== data_get($child->protocol_settings, 'cipher')) {
            $error = '父子节点的 Shadowsocks 加密方式需要一致';
        } elseif ($requireCapability && !self::supports($parent)) {
            $error = '请先更新父节点的 mi-node，等待节点同步后再开启子节点单独倍率';
        }
        if ($error !== null) {
            throw ValidationException::withMessages(['child_rate_enabled' => $error]);
        }
    }

    public static function credential(Server $child, User $user): string
    {
        $hex = substr(hash_hmac('sha256', "mi-node-child-rate:v1:{$child->parent_id}:{$child->id}", (string) $user->uuid), 0, 32);
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function appendUsers(Server $parent, Collection $users): Collection
    {
        if ($parent->parent_id || !self::supports($parent)) {
            return $users;
        }
        $children = Server::where('parent_id', $parent->id)->where('child_rate_enabled', true)
            ->where('enabled', true)->where('show', true)->where('type', $parent->type)
            ->where(function ($query): void {
                $query->whereNull('transfer_enable')->orWhere('transfer_enable', 0)->orWhereRaw('u + d < transfer_enable');
            })->with('subscriptionLabel')->get();
        foreach ($children as $child) {
            if ($child->traffic_limit_status === Server::TRAFFIC_LIMIT_STATUS_SUSPENDED) {
                continue;
            }
            $users = $users->concat(self::identities($parent, $child, ServerService::getAvailableUsers($child, false)));
        }
        return $users->values();
    }

    private static function identities(Server $parent, Server $child, Collection $users): Collection
    {
        if ($users->isEmpty()) {
            return collect();
        }
        // Stable, persisted IDs keep in-flight reports valid after a user list refresh.
        foreach ($users->chunk(500) as $chunk) {
            DB::table('v2_server_child_identity')->insertOrIgnore($chunk->map(fn ($user) => [
                'parent_id' => (int) $parent->id, 'child_id' => (int) $child->id, 'user_id' => (int) data_get($user, 'id'),
            ])->all());
        }
        $identities = DB::table('v2_server_child_identity')->where('parent_id', $parent->id)
            ->where('child_id', $child->id)->whereIn('user_id', $users->pluck('id'))->pluck('id', 'user_id');
        return $users->map(function ($user) use ($child, $identities) {
            $row = $user instanceof Arrayable ? $user->toArray() : (array) $user;
            $uid = (int) $row['id'];
            $id = self::AUTH_ID_BASE + (int) $identities[$uid];
            if ($id > self::MAX_AUTH_ID) {
                throw new \OverflowException('Child node authentication ID exceeds the wire integer range.');
            }
            $account = (new User())->forceFill(['uuid' => $row['uuid']]);
            return (object) array_replace($row, [
                'id' => $id, 'uuid' => self::credential($child, $account),
                'billing_user_id' => $uid, 'billing_node_id' => (int) $child->id,
            ]);
        });
    }

    public static function hasIdentities(array $traffic): bool
    {
        foreach (array_keys($traffic) as $id) {
            if (is_numeric($id) && (int) $id >= self::AUTH_ID_BASE) {
                return true;
            }
        }
        return false;
    }

    public static function accountIds(Collection $users): array
    {
        return $users->map(fn ($user) => (int) (data_get($user, 'billing_user_id') ?: data_get($user, 'id')))
            ->unique()->values()->all();
    }
}

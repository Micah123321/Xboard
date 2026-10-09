<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeUpdateRecord extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['token_hash','credential_hash','secret_hash','lease_token_hash','lease_token_ciphertext','claim_response_ciphertext','request_hash','claim_request_hash'];
    protected $casts = [
        'scope'=>'array','artifacts'=>'array','capability'=>'array','runtime'=>'array','versions'=>'array',
        'installed_sha256'=>'array','reported_active_attempt'=>'array','release_snapshot'=>'array',
        'payload'=>'array','response'=>'array','request_payload'=>'array',
        'enabled'=>'boolean','policy_enabled'=>'boolean','cancel_requested'=>'boolean',
        'revision'=>'integer','policy_revision'=>'integer','max_concurrency'=>'integer',
        'fail_pause_after'=>'integer','failure_baseline'=>'integer','min_agent_protocol'=>'integer',
        'last_seq'=>'integer','seq'=>'integer',
        'published_at'=>'immutable_datetime','revoked_at'=>'immutable_datetime','expires_at'=>'immutable_datetime',
        'consumed_at'=>'immutable_datetime','last_seen_at'=>'immutable_datetime','finished_at'=>'immutable_datetime',
        'lease_expires_at'=>'immutable_datetime','installing_acked_at'=>'immutable_datetime',
        'started_at'=>'immutable_datetime','received_at'=>'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            $immutable = match ($record->getTable()) {
                'v2_node_update_releases' => array_diff(array_keys($record->getDirty()), ['revoked_at','updated_at']),
                'v2_node_update_installations' => array_intersect(array_keys($record->getDirty()), ['id','scope','token_hash','credential_hash']),
                default => [],
            };
            if ($immutable) throw new \LogicException('Immutable node update identity or release');
        });
    }

    public function freshTimestamp() { return \Illuminate\Support\Carbon::now('UTC'); }

    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
        }
        return parent::asDateTime($value);
    }

    public static function table(string $name): \Illuminate\Database\Eloquent\Builder
    {
        if (!in_array($name, ['settings','releases','enrollments','installations','batches','tasks','attempts','events','requests'], true)) {
            throw new \InvalidArgumentException('Unknown update table');
        }
        $model = new static();
        $model->setTable('v2_node_update_'.$name);
        if (in_array($name, ['events','requests','settings'], true)) {
            $model->incrementing = true;
            $model->setKeyType('int');
        }
        return $model->newQuery();
    }
}

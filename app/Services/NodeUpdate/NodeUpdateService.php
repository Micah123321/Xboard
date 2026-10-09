<?php

namespace App\Services\NodeUpdate;

use App\Exceptions\NodeUpdateException;
use App\Models\NodeUpdateRecord as Record;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NodeUpdateService
{
    public function q(string $table) { return Record::table($table); }
    public function find(string $table, string $id): Record { return $this->q($table)->find($id) ?? Protocol::fail('not_found',404); }
    private function create(string $table, array $values): Record
    {
        if (!in_array($table,['events','requests'],true)) $values=['id'=>(string)Str::uuid()]+$values;
        return $this->q($table)->create($values);
    }

    public function atomic(callable $action)
    {
        $result=DB::transaction(function () use ($action) {
            // A real write takes SQLite's writer lock too; row locks serialize MySQL/PG.
            DB::table('v2_node_update_settings')->where('id',1)->update(['id'=>1]);
            $settings=$this->q('settings')->lockForUpdate()->find(1);
            if (!$settings) Protocol::fail('unavailable',503);
            // All writers take the same lock order, including maintenance and admin writes.
            $this->q('batches')->orderBy('id')->lockForUpdate()->get(['id']);
            $this->q('installations')->orderBy('id')->lockForUpdate()->get(['id']);
            $this->q('tasks')->whereNotIn('state',Protocol::TERMINAL)->orderBy('id')->lockForUpdate()->get(['id']);
            $this->sweep($settings);
            try {
                return DB::transaction(fn()=>$action($settings));
            } catch (NodeUpdateException $e) {
                // Persist timeout/revocation maintenance even when the requested action conflicts.
                return $e;
            }
        },5);
        if ($result instanceof NodeUpdateException) throw $result;
        return $result;
    }

    private function context(Record $task): array
    {
        $batch=$this->q('batches')->whereKey($task->batch_id)->lockForUpdate()->first();
        $installation=$this->q('installations')->whereKey($task->installation_id)->lockForUpdate()->first();
        $task=$this->q('tasks')->whereKey($task->id)->lockForUpdate()->first();
        return [$batch,$installation,$task];
    }

    private function credential(array $scope): ?string
    {
        if ($scope['kind']==='machine') {
            $machine=ServerMachine::find($scope['machine_id']);
            return $machine && $machine->is_active ? $machine->token : null;
        }
        $row=Setting::where('name','server_token')->first();
        $token=$row?->value ?? config('v2board.server_token');
        return is_string($token) && $token!=='' ? $token : null;
    }

    public function validateScope(array $scope): void
    {
        if (($scope['kind']??null)==='machine') {
            Protocol::fields($scope,['kind','machine_id']);
            Protocol::integer($scope['machine_id']);
            if (!ServerMachine::whereKey($scope['machine_id'])->where('is_active',true)->exists()) Protocol::fail('scope_mismatch',403);
        } elseif (($scope['kind']??null)==='legacy') {
            Protocol::fields($scope,['kind','node_ids']);
            $ids=$scope['node_ids'];
            if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids)>1000 || count(array_unique($ids,SORT_REGULAR))!==count($ids)) Protocol::fail();
            foreach ($ids as $id) Protocol::integer($id);
            $nodes=Server::whereIn('id',$ids)->get();
            if ($nodes->count()!==count($ids) || $nodes->contains(fn($n)=>!$n->enabled)) Protocol::fail('scope_mismatch',403);
            if ($nodes->pluck('machine_id')->filter()->unique()->count()>1) Protocol::fail();
            foreach ($nodes->pluck('machine_id')->filter()->unique() as $machineId) {
                if (!ServerMachine::whereKey($machineId)->where('is_active',true)->exists()) Protocol::fail('scope_mismatch',403);
            }
        } else Protocol::fail();
    }

    private function validInstallation(Record $i): bool
    {
        if ($i->revoked_at) return false;
        try { $this->validateScope($i->scope); } catch (NodeUpdateException $e) { return false; }
        $credential=$this->credential($i->scope);
        return $credential!==null && hash_equals($i->credential_hash,hash('sha256',$credential));
    }

    private function nodes(array $scope): array
    {
        return $scope['kind']==='legacy' ? $scope['node_ids'] : Server::where('machine_id',$scope['machine_id'])->pluck('id')->all();
    }

    private function overlap(array $scope): void
    {
        $nodes=$this->nodes($scope);
        foreach ($this->q('installations')->where(function ($q) { $q->whereNull('revoked_at')->orWhereNotNull('active_task_id'); })->get() as $i) {
            if (($scope['kind']==='machine' && $i->scope['kind']==='machine' && $scope['machine_id']===$i->scope['machine_id']) || array_intersect($nodes,$this->nodes($i->scope))) Protocol::fail('scope_conflict',409);
        }
    }

    public function authenticate(string $token, ?string $installationId = null): Record
    {
        $i=$this->q('installations')->where('token_hash',hash('sha256',$token))->first();
        if (!$i || !$this->validInstallation($i)) Protocol::fail('invalid_credentials',401);
        if ($installationId!==null && $installationId!==$i->id) Protocol::fail('scope_mismatch',403);
        return $i;
    }

    public function enroll(array $d): array
    {
        Protocol::fields($d,['protocol_version','enrollment_id','enrollment_secret','installation_id','agent_token','auth']);
        if ($d['protocol_version']!==1 || !is_array($d['auth'])) Protocol::fail();
        Protocol::uuid($d['enrollment_id']); Protocol::uuid($d['installation_id']);
        Protocol::secret($d['enrollment_secret']); Protocol::secret($d['agent_token']);
        $auth=$d['auth'];
        Protocol::fields($auth, ($auth['kind']??null)==='machine' ? ['kind','machine_id','token'] : ['kind','token']);
        if (!is_string($auth['token']) || !in_array($auth['kind'],['machine','legacy'],true)) Protocol::fail();
        return $this->atomic(function () use ($d,$auth) {
            $ticket=$this->q('enrollments')->find($d['enrollment_id']);
            if (!$ticket || !hash_equals($ticket->secret_hash,hash('sha256',$d['enrollment_secret']))) Protocol::fail('invalid_credentials',401);
            if ($ticket->consumed_at) {
                if (!hash_equals($ticket->request_hash,Protocol::hash($d))) Protocol::fail('idempotency_conflict',409);
                $i=$this->authenticate($d['agent_token'],$d['installation_id']);
                return ['installation_id'=>$i->id,'scope'=>$i->scope,'policy_revision'=>1];
            }
            if ($ticket->expires_at->lte(now('UTC'))) Protocol::fail('invalid_credentials',401);
            $this->validateScope($ticket->scope);
            if ($auth['kind']!==$ticket->scope['kind'] || ($auth['kind']==='machine' && $auth['machine_id']!==$ticket->scope['machine_id'])) Protocol::fail('scope_mismatch',403);
            $credential=$this->credential($ticket->scope);
            if ($credential===null || !hash_equals($credential,$auth['token'])) Protocol::fail('invalid_credentials',401);
            if ($this->q('installations')->whereKey($d['installation_id'])->exists() || $this->q('installations')->where('token_hash',hash('sha256',$d['agent_token']))->exists()) Protocol::fail('scope_conflict',409);
            $this->overlap($ticket->scope);
            $i=$this->q('installations')->create([
                'id'=>$d['installation_id'],'scope'=>$ticket->scope,'token_hash'=>hash('sha256',$d['agent_token']),
                'credential_hash'=>hash('sha256',$credential),'policy_enabled'=>false,'policy_revision'=>1,
                'runtime'=>['os'=>null,'arch'=>null,'init'=>null,'container'=>null],
                'versions'=>['mi_node'=>null,'xbctl'=>null],'installed_sha256'=>['mi_node'=>null,'xbctl'=>null],
                'capability'=>['status'=>'unsupported','reason'=>'not_reported'],
            ]);
            $ticket->forceFill(['consumed_at'=>now('UTC'),'installation_id'=>$i->id,'token_hash'=>$i->token_hash,'request_hash'=>Protocol::hash($d)])->save();
            return ['installation_id'=>$i->id,'scope'=>$i->scope,'policy_revision'=>1];
        });
    }

    private function pollInput(array $d): void
    {
        Protocol::fields($d,['protocol_version','installation_id','versions','installed_sha256','runtime','capability','active_attempt']);
        Protocol::uuid($d['installation_id']);
        if ($d['protocol_version']!==1) Protocol::fail();
        Protocol::pair($d['versions']); Protocol::pair($d['installed_sha256'],true);
        if (!is_array($d['runtime']) || !is_array($d['capability'])) Protocol::fail();
        Protocol::fields($d['runtime'],['os','arch','init','container']);
        foreach (['os','arch'] as $f) if (!is_string($d['runtime'][$f]) || strlen($d['runtime'][$f])>64 || $d['runtime'][$f]==='') Protocol::fail();
        if (!in_array($d['runtime']['init'],['systemd','openrc','other'],true)) Protocol::fail();
        Protocol::boolean($d['runtime']['container']);
        Protocol::fields($d['capability'],['status','reason']);
        if (!in_array($d['capability']['status'],['supported','unsupported'],true) || !in_array($d['capability']['reason'],[null,'docker','openrc','unsupported_os','unsupported_arch','shared_binary_layout','local_disabled','not_reported'],true)) Protocol::fail();
        if (($d['capability']['status']==='supported')!==($d['capability']['reason']===null)) Protocol::fail();
        if ($d['active_attempt']!==null) {
            if (!is_array($d['active_attempt'])) Protocol::fail();
            Protocol::fields($d['active_attempt'],['task_id','attempt_id','last_seq','phase']);
            Protocol::uuid($d['active_attempt']['task_id']); Protocol::uuid($d['active_attempt']['attempt_id']);
            Protocol::integer($d['active_attempt']['last_seq'],0);
            if (!in_array($d['active_attempt']['phase'],Protocol::STATES,true)) Protocol::fail();
        }
    }

    public function poll(string $token, array $d): array
    {
        $this->pollInput($d);
        return $this->atomic(function ($s) use ($token,$d) {
            $i=$this->authenticate($token,$d['installation_id']);
            $i->forceFill(['versions'=>$d['versions'],'installed_sha256'=>$d['installed_sha256'],'runtime'=>$d['runtime'],
                'capability'=>$d['capability'],'reported_active_attempt'=>$d['active_attempt'],'last_seen_at'=>now('UTC')])->save();
            $candidate=null;
            if (!$i->active_task_id && $d['active_attempt']===null) {
                foreach ($this->q('tasks')->where('installation_id',$i->id)->where('state','queued')->orderBy('created_at')->get() as $t) {
                    $b=$this->find('batches',$t->batch_id);
                    $r=$this->find('releases',$b->release_id);
                    $code=$this->eligibility($i,$r,$s);
                    if ($code==='already_current') { $this->finish($t,'skipped',$code); $this->aggregate($b); continue; }
                    if ($code===null && $b->state==='running' && $t->policy_revision===$i->policy_revision) {
                        $candidate=['task_id'=>$t->id,'release_id'=>$r->id,'version'=>$r->version,'policy_revision'=>$t->policy_revision]; break;
                    }
                }
            }
            return ['server_time'=>Protocol::time(now('UTC')),'poll_after_seconds'=>60,'global_enabled'=>$s->enabled,'policy'=>$this->policy($i),'candidate'=>$candidate];
        });
    }

    public function eligibility(Record $i, Record $r, Record $s): ?string
    {
        if (!$this->validInstallation($i)) return 'invalid_credentials';
        if (!$s->enabled || !$i->policy_enabled || $i->target_release_id!==$r->id) return 'policy_disabled';
        if ($r->revoked_at) return 'release_revoked';
        if ($i->active_task_id || $i->reported_active_attempt!==null) return 'task_claimed';
        $rt=$i->runtime;
        if ($i->capability['status']!=='supported' || $rt['os']!=='linux' || $rt['init']!=='systemd' || $rt['container']!==false || !in_array($rt['arch'],['amd64','arm64'],true) || $r->min_agent_protocol>1) return 'unsupported';
        $versions=$i->versions; $sha=$i->installed_sha256;
        if ($versions['mi_node']===null || $versions['xbctl']===null || $sha['mi_node']===null || $sha['xbctl']===null) return 'version_unknown';
        if ($versions['mi_node']!==$versions['xbctl']) return 'binary_version_mismatch';
        $artifacts=array_column(array_filter($r->artifacts,fn($a)=>$a['arch']===$rt['arch']),null,'component');
        if (count($artifacts)!==2) return 'arch_mismatch';
        $cmp=Protocol::compare($r->version,$versions['mi_node']);
        if ($cmp<0) return 'downgrade_forbidden';
        if (!$cmp) return $sha['mi_node']===$artifacts['mi-node']['sha256'] && $sha['xbctl']===$artifacts['xbctl']['sha256'] ? 'already_current' : 'version_conflict';
        return null;
    }

    private function owned(string $taskId, Record $i): Record
    {
        Protocol::uuid($taskId);
        return $this->q('tasks')->whereKey($taskId)->where('installation_id',$i->id)->first() ?? Protocol::fail('not_found',404);
    }

    private function gate(Record $s, Record $b, Record $i, Record $t): ?string
    {
        if ($b->cancel_requested || $b->state==='canceling') return 'batch_canceled';
        if ($t->cancel_requested) return $t->code==='batch_paused' ? 'batch_paused' : 'batch_canceled';
        if ($b->state!=='running') return 'batch_paused';
        if (!$s->enabled || !$i->policy_enabled || $i->target_release_id!==$b->release_id || $t->policy_revision!==$i->policy_revision) return 'policy_disabled';
        if ($this->find('releases',$b->release_id)->revoked_at) return 'release_revoked';
        return null;
    }

    public function claim(string $token, string $taskId, array $d): array
    {
        Protocol::fields($d,['installation_id','claim_id','policy_revision']);
        Protocol::uuid($d['installation_id']); Protocol::uuid($d['claim_id']); Protocol::integer($d['policy_revision']);
        return $this->atomic(function ($s) use ($token,$taskId,$d) {
            $i=$this->authenticate($token,$d['installation_id']);
            [$b,$i,$t]=$this->context($this->owned($taskId,$i));
            $hash=Protocol::hash(['task_id'=>$taskId]+$d);
            $prior=$this->q('attempts')->where('installation_id',$i->id)->where('claim_id',$d['claim_id'])->first();
            if ($prior) {
                if (!hash_equals($prior->claim_request_hash,$hash)) Protocol::fail('idempotency_conflict',409);
                return json_decode(Crypt::decryptString($prior->claim_response_ciphertext),true,512,JSON_THROW_ON_ERROR);
            }
            if ($t->state!=='queued' || $this->q('attempts')->where('task_id',$t->id)->exists()) Protocol::fail('task_claimed',409);
            if ($d['policy_revision']!==$i->policy_revision || $this->gate($s,$b,$i,$t)) Protocol::fail('policy_changed',409);
            $r=$this->find('releases',$b->release_id);
            $code=$this->eligibility($i,$r,$s);
            if ($code!==null) Protocol::fail($code,409);
            $occupied=$this->q('tasks')->whereNotIn('state',array_merge(['queued'],Protocol::TERMINAL))->count();
            $inBatch=$this->q('tasks')->where('batch_id',$b->id)->whereNotIn('state',array_merge(['queued'],Protocol::TERMINAL))->count();
            if ($occupied >= $s->max_concurrency || $inBatch >= $b->max_concurrency) Protocol::fail('capacity_exhausted',409);
            $lease=Protocol::token(); $attemptId=(string)Str::uuid(); $expires=now('UTC')->addSeconds(120);
            $response=['task_id'=>$t->id,'attempt_id'=>$attemptId,'lease_token'=>$lease,'lease_expires_at'=>Protocol::time($expires),
                'heartbeat_interval_seconds'=>30,'lease_seconds'=>120,'release'=>$t->release_snapshot,'state'=>'claimed'];
            $this->q('attempts')->create(['id'=>$attemptId,'task_id'=>$t->id,'installation_id'=>$i->id,'claim_id'=>$d['claim_id'],
                'claim_request_hash'=>$hash,'lease_token_hash'=>hash('sha256',$lease),'lease_token_ciphertext'=>Crypt::encryptString($lease),
                'claim_response_ciphertext'=>Crypt::encryptString(json_encode($response,JSON_THROW_ON_ERROR)),
                'lease_expires_at'=>$expires,'last_seq'=>0,'started_at'=>now('UTC')]);
            $i->forceFill(['active_task_id'=>$t->id])->save();
            $t->forceFill(['state'=>'claimed','last_phase'=>'claimed'])->save();
            $this->audit($t,['state'=>'claimed'],'server');
            return $response;
        });
    }

    private function attempt(Record $t, array $d): Record
    {
        $a=$this->q('attempts')->whereKey($d['attempt_id'])->where('task_id',$t->id)->first();
        if (!$a || !hash_equals($a->lease_token_hash,hash('sha256',$d['lease_token']))) Protocol::fail('invalid_credentials',401);
        return $a;
    }

    public function heartbeat(string $token, string $taskId, array $d): array
    {
        Protocol::fields($d,['installation_id','attempt_id','lease_token']);
        Protocol::uuid($d['installation_id']); Protocol::uuid($d['attempt_id']); Protocol::secret($d['lease_token']);
        return $this->atomic(function ($s) use ($token,$taskId,$d) {
            $i=$this->authenticate($token,$d['installation_id']);
            [$b,$i,$t]=$this->context($this->owned($taskId,$i));
            $a=$this->attempt($t,$d);
            if (in_array($t->state,Protocol::TERMINAL,true)) Protocol::fail('task_terminal',409);
            if ($t->state==='uncertain' || !$a->lease_expires_at || $a->lease_expires_at->lte(now('UTC'))) Protocol::fail('lease_expired',409);
            $a->forceFill(['lease_expires_at'=>now('UTC')->addSeconds(120)])->save();
            return ['lease_expires_at'=>Protocol::time($a->lease_expires_at),'allow_start'=>$this->gate($s,$b,$i,$t)===null];
        });
    }

    public function event(string $token, string $taskId, array $d): array
    {
        Protocol::fields($d,['installation_id','attempt_id','lease_token','seq','state','occurred_at','code','message','observed_versions']);
        Protocol::uuid($d['installation_id']); Protocol::uuid($d['attempt_id']); Protocol::secret($d['lease_token']); Protocol::integer($d['seq']);
        Protocol::pair($d['observed_versions']);
        if (!is_string($d['occurred_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$d['occurred_at']) || !($date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$d['occurred_at'])) || $date->format('Y-m-d\TH:i:s\Z')!==$d['occurred_at']) Protocol::fail();
        if (!in_array($d['state'],Protocol::STATES,true) || ($d['code']!==null && !in_array($d['code'],Protocol::CODES,true))) Protocol::fail();
        if (in_array($d['state'],Protocol::TERMINAL,true) && $d['state']!=='succeeded' && $d['code']===null) Protocol::fail();
        if ($d['state']==='succeeded' && $d['code']!==null) Protocol::fail();
        $message=Protocol::message($d['message']);
        return $this->atomic(function ($s) use ($token,$taskId,$d,$message) {
            $i=$this->authenticate($token,$d['installation_id']);
            [$b,$i,$t]=$this->context($this->owned($taskId,$i));
            $a=$this->attempt($t,$d); $hash=Protocol::hash($d);
            $prior=$this->q('events')->where('attempt_id',$a->id)->where('seq',$d['seq'])->first();
            if ($prior) {
                if (!hash_equals($prior->payload_hash,$hash)) Protocol::fail('idempotency_conflict',409);
                return $prior->response;
            }
            if (in_array($t->state,Protocol::TERMINAL,true)) Protocol::fail('task_terminal',409);
            if ($d['seq']!==$a->last_seq+1) Protocol::fail('expected_seq',409,['expected_seq'=>$a->last_seq+1]);
            $next=$d['state']; $phase=$t->last_phase ?? $t->state;
            $allowed=[
                'claimed'=>['downloading','failed','canceled','skipped'],
                'downloading'=>['verifying_artifacts','failed','canceled','skipped'],
                'verifying_artifacts'=>['installing','failed','canceled','skipped'],
                'installing'=>['verifying','rolling_back'], 'verifying'=>['succeeded','rolling_back'],
                'rolling_back'=>['rolled_back','rollback_failed'],
            ];
            if (!in_array($next,$allowed[$phase]??[],true)) Protocol::fail('invalid_transition',409);
            if ($next==='skipped' && $d['code']!=='already_current') Protocol::fail();
            if ($next==='installing') {
                if ($t->state==='uncertain' || $a->lease_expires_at->lte(now('UTC'))) Protocol::fail('lease_expired',409);
                if ($code=$this->gate($s,$b,$i,$t)) Protocol::fail($code,409);
                $a->installing_acked_at=now('UTC');
            }
            if (in_array($next,['verifying','rolling_back','succeeded','rolled_back','rollback_failed'],true) && !$a->installing_acked_at) Protocol::fail('invalid_transition',409);
            if ($next==='succeeded' && ($d['observed_versions']['mi_node']!==$t->release_snapshot['version'] || $d['observed_versions']['xbctl']!==$t->release_snapshot['version'])) Protocol::fail('binary_version_mismatch',409);
            $terminal=in_array($next,Protocol::TERMINAL,true);
            $a->last_seq=$d['seq'];
            if ($terminal) { $a->finished_at=now('UTC'); $a->lease_expires_at=null; }
            $a->save();
            if ($terminal) $this->finish($t,$next,$d['code'],$message,false);
            else $t->forceFill(['state'=>$t->state==='uncertain' ? 'uncertain' : $next,'last_phase'=>$next,'code'=>$t->cancel_requested ? $t->code : $d['code'],'message'=>$message])->save();
            $response=['accepted_seq'=>$d['seq'],'state'=>$t->state,'lease_expires_at'=>Protocol::time($a->lease_expires_at)];
            $payload=$d; unset($payload['lease_token']); $payload['message']=$message;
            $this->create('events',['task_id'=>$t->id,'attempt_id'=>$a->id,'seq'=>$d['seq'],'payload_hash'=>$hash,'payload'=>$payload,
                'response'=>$response,'source'=>'agent','received_at'=>now('UTC')]);
            $this->aggregate($b);
            return $response;
        });
    }

    private function finish(Record $t, string $state, ?string $code, ?string $message = null, bool $audit = true): void
    {
        $t->forceFill(['state'=>$state,'code'=>$code,'message'=>$message,'finished_at'=>now('UTC')])->save();
        $this->q('installations')->whereKey($t->installation_id)->where('active_task_id',$t->id)->update(['active_task_id'=>null]);
        $this->q('attempts')->where('task_id',$t->id)->update(['finished_at'=>now('UTC'),'lease_expires_at'=>null]);
        if ($audit) $this->audit($t,['state'=>$state,'code'=>$code,'message'=>$message],'server');
    }

    private function audit(Record $t, array $payload, string $source): void
    {
        $this->create('events',['task_id'=>$t->id,'payload_hash'=>Protocol::hash($payload),'payload'=>$payload,'source'=>$source,'received_at'=>now('UTC')]);
    }

    private function aggregate(Record $b): void
    {
        $counts=$this->counts($b->id);
        $failures=$counts['failed']+$counts['rolled_back']+$counts['rollback_failed'];
        $active=array_sum($counts)-array_sum(array_intersect_key($counts,array_flip(Protocol::TERMINAL)));
        if (!$active) $b->forceFill(['state'=>$b->cancel_requested?'canceled':'completed','finished_at'=>now('UTC')])->save();
        elseif ($b->state==='running' && ($counts['uncertain']>0 || $failures-$b->failure_baseline >= $b->fail_pause_after)) $this->pause($b);
    }

    private function sweep(Record $s): void
    {
        foreach ($this->q('installations')->whereNull('revoked_at')->get() as $i) {
            if (!$this->validInstallation($i)) $i->forceFill(['revoked_at'=>now('UTC'),'policy_enabled'=>false,'policy_revision'=>$i->policy_revision+1])->save();
        }
        foreach ($this->q('tasks')->whereNotIn('state',Protocol::TERMINAL)->orderBy('batch_id')->orderBy('installation_id')->get() as $candidate) {
            [$b,$i,$t]=$this->context($candidate);
            if ($t->state==='queued') {
                $release=$this->find('releases',$b->release_id);
                $code=$b->cancel_requested?'batch_canceled':($release->revoked_at?'release_revoked':((!$s->enabled || !$i->policy_enabled || $i->revoked_at || $i->target_release_id!==$b->release_id || $i->policy_revision!==$t->policy_revision)?'policy_disabled':null));
                if ($code) $this->finish($t,'canceled',$code);
            } elseif ($t->state!=='uncertain') {
                $a=$this->q('attempts')->where('task_id',$t->id)->first();
                if ($a && $a->lease_expires_at && $a->lease_expires_at->lte(now('UTC'))) {
                    $t->forceFill(['last_phase'=>$t->state,'state'=>'uncertain'])->save();
                    $this->audit($t,['state'=>'uncertain','last_phase'=>$t->last_phase,'code'=>'lease_expired'],'server');
                }
            }
            $this->aggregate($b);
        }
    }

    private function pause(Record $b): void
    {
        $b->forceFill(['state'=>'paused'])->save();
        foreach ($this->q('tasks')->where('batch_id',$b->id)->whereIn('state',['claimed','downloading','verifying_artifacts'])->get() as $t) {
            $t->forceFill(['cancel_requested'=>true,'code'=>'batch_paused'])->save();
            $this->audit($t,['cancel_requested'=>true,'code'=>'batch_paused'],'server');
        }
    }

    public function revokeCredentials(string $kind, ?int $machineId = null): void
    {
        $this->atomic(function ($s) use ($kind,$machineId) {
            foreach ($this->q('installations')->whereNull('revoked_at')->get() as $i) {
                if (($kind==='legacy' && $i->scope['kind']==='legacy') || ($kind==='machine' && (($i->scope['kind']==='machine' && $i->scope['machine_id']===$machineId) || ($i->scope['kind']==='legacy' && Server::whereIn('id',$i->scope['node_ids'])->where('machine_id',$machineId)->exists())))) {
                    $i->forceFill(['revoked_at'=>now('UTC'),'policy_enabled'=>false,'policy_revision'=>$i->policy_revision+1])->save();
                }
            }
            $this->sweep($s);
        });
    }

    public function maintain(): void { $this->atomic(fn()=>null); }
    public function policy(Record $i): array { return ['enabled'=>$i->policy_enabled,'target_release_id'=>$i->target_release_id,'revision'=>$i->policy_revision]; }
    public function counts(string $batchId): array
    {
        $counts=array_fill_keys(Protocol::STATES,0);
        foreach ($this->q('tasks')->where('batch_id',$batchId)->select('state')->selectRaw('COUNT(*) AS aggregate')->groupBy('state')->get() as $row) $counts[$row->state]=(int)$row->aggregate;
        return $counts;
    }

    public function dto(string $type, Record $r): array
    {
        if ($type==='settings') return ['enabled'=>$r->enabled,'max_concurrency'=>$r->max_concurrency,'revision'=>$r->revision];
        if ($type==='releases') return ['id'=>$r->id,'version'=>$r->version,'os'=>$r->os,'min_agent_protocol'=>$r->min_agent_protocol,'artifacts'=>$r->artifacts,'published_at'=>Protocol::time($r->published_at),'revoked_at'=>Protocol::time($r->revoked_at)];
        if ($type==='installations') return ['id'=>$r->id,'scope'=>$r->scope,'versions'=>$r->versions,'installed_sha256'=>$r->installed_sha256,'runtime'=>$r->runtime,'capability'=>$r->capability,
            'last_seen_at'=>Protocol::time($r->last_seen_at),'online'=>$r->last_seen_at!==null && $r->last_seen_at->gte(now('UTC')->subSeconds(180)),
            'policy'=>$this->policy($r),'active_task_id'=>$r->active_task_id,'revoked_at'=>Protocol::time($r->revoked_at)];
        if ($type==='batches') return ['id'=>$r->id,'release_id'=>$r->release_id,'state'=>$r->state,'max_concurrency'=>$r->max_concurrency,'fail_pause_after'=>$r->fail_pause_after,'counts'=>$this->counts($r->id),'created_at'=>Protocol::time($r->created_at),'finished_at'=>Protocol::time($r->finished_at)];
        if ($type==='tasks') {
            $a=$this->q('attempts')->where('task_id',$r->id)->first();
            return ['id'=>$r->id,'batch_id'=>$r->batch_id,'installation_id'=>$r->installation_id,'state'=>$r->state,'last_phase'=>$r->last_phase,'code'=>$r->code,'message'=>$r->message,
                'attempt_id'=>$a?->id,'lease_expires_at'=>Protocol::time($a?->lease_expires_at),'created_at'=>Protocol::time($r->created_at),'finished_at'=>Protocol::time($r->finished_at)];
        }
        if ($type==='events') return ['id'=>$r->id,'task_id'=>$r->task_id,'attempt_id'=>$r->attempt_id,'seq'=>$r->seq,'source'=>$r->source,'payload'=>$r->payload,'received_at'=>Protocol::time($r->received_at)];
        throw new \LogicException('Unknown DTO');
    }

    public function admin(int $admin, string $method, string $path, array $d, ?string $key = null): array
    {
        $write=$method!=='GET' && $path!=='batches/preview';
        if ($write) Protocol::uuid($key);
        return $this->atomic(function ($s) use ($admin,$method,$path,$d,$key,$write) {
            $hash=Protocol::hash(['method'=>$method,'path'=>$path,'body'=>$d]);
            if ($write) {
                $prior=$this->q('requests')->where('admin_id',$admin)->where('key',$key)->first();
                if ($prior) {
                    if (!hash_equals($prior->request_hash,$hash)) Protocol::fail('idempotency_conflict',409);
                    return $prior->response;
                }
            }
            $response=$this->adminAction($admin,$method,$path,$d,$s);
            if ($write) {
                $stored=$response;
                // Ticket secrets are disclosed only once; retries return the public ticket metadata.
                unset($stored['enrollment_secret']);
                $audit=$d;
                if (isset($audit['reason'])) $audit['reason']=Protocol::message($audit['reason']);
                $this->create('requests',['admin_id'=>$admin,'key'=>$key,'route'=>$method.' '.$path,'request_hash'=>$hash,'request_payload'=>$audit,'response'=>$stored]);
            }
            return $response;
        });
    }

    private function preview(array $d, Record $s): array
    {
        Protocol::uuid($d['release_id']);
        if (!is_array($d['installation_ids']) || !array_is_list($d['installation_ids']) || !$d['installation_ids'] || count($d['installation_ids'])>1000) Protocol::fail();
        $r=$this->find('releases',$d['release_id']); $eligible=[]; $excluded=[];
        foreach (array_unique($d['installation_ids']) as $id) {
            Protocol::uuid($id);
            $i=$this->q('installations')->find($id);
            $code=$i ? $this->eligibility($i,$r,$s) : 'not_found';
            if ($code===null || $code==='already_current') $eligible[]=$id;
            else $excluded[]=['installation_id'=>$id,'code'=>$code];
        }
        return ['eligible_ids'=>$eligible,'excluded'=>$excluded,'affected_installation_count'=>count($eligible)];
    }

    private function adminAction(int $admin, string $method, string $path, array $d, Record $s): array
    {
        $parts=explode('/',$path); $type=$parts[0]; $id=$parts[1]??null; $action=$parts[2]??null;
        if ($type==='settings') {
            if ($method==='GET') Protocol::fields($d,[]);
            else {
                Protocol::fields($d,['enabled','max_concurrency','revision']); Protocol::boolean($d['enabled']); Protocol::integer($d['max_concurrency'],1,100); Protocol::integer($d['revision']);
                if ($d['revision']!==$s->revision) Protocol::fail('revision_conflict',409);
                $s->forceFill(['enabled'=>$d['enabled'],'max_concurrency'=>$d['max_concurrency'],'revision'=>$s->revision+1])->save();
                $this->sweep($s);
            }
            return $this->dto('settings',$s);
        }
        if ($method==='GET') {
            if ($type==='coverage') {
                Protocol::fields($d,[],['machine_id','node_id']);
                if (count($d)!==1) Protocol::fail();
                $field=array_key_first($d); $value=$this->queryInt($d[$field],1,9007199254740991); $ids=[];
                foreach ($this->q('installations')->whereNull('revoked_at')->get() as $i) {
                    $match=$field==='machine_id' ? ($i->scope['kind']==='machine' && $i->scope['machine_id']===$value) || ($i->scope['kind']==='legacy' && Server::whereIn('id',$i->scope['node_ids'])->where('machine_id',$value)->exists()) : in_array($value,$this->nodes($i->scope));
                    if ($match) $ids[]=$i->id;
                }
                return ['status'=>$ids?'registered':'bootstrap_required','installation_ids'=>$ids,'reason'=>$ids?null:'not_enrolled'];
            }
            if ($type==='batches' && $id && !$action) { Protocol::fields($d,[]); Protocol::uuid($id); return $this->dto('batches',$this->find('batches',$id)); }
            $query=$this->q($type); $dtoType=$type;
            $filters=['page','page_size'];
            if ($type==='installations') $filters=array_merge($filters,['machine_id','node_id','capability_status']);
            if ($type==='batches' && $action==='tasks') { Protocol::uuid($id); $this->find('batches',$id); $query=$this->q('tasks')->where('batch_id',$id); $dtoType='tasks'; $filters[]='state'; }
            if ($type==='tasks' && $action==='events') { Protocol::uuid($id); $this->find('tasks',$id); $query=$this->q('events')->where('task_id',$id); $dtoType='events'; }
            Protocol::fields($d,[],$filters);
            if (isset($d['state'])) { if (!in_array($d['state'],Protocol::STATES,true)) Protocol::fail(); $query->where('state',$d['state']); }
            if ($type==='installations' && array_intersect(array_keys($d),['machine_id','node_id','capability_status'])) {
                foreach (['machine_id','node_id'] as $f) if (isset($d[$f])) $d[$f]=$this->queryInt($d[$f],1,9007199254740991);
                if (isset($d['capability_status']) && !in_array($d['capability_status'],['supported','unsupported'],true)) Protocol::fail();
                $ids=[];
                foreach ($this->q('installations')->get() as $i) {
                    if (isset($d['machine_id']) && !(($i->scope['kind']==='machine' && $i->scope['machine_id']===$d['machine_id']) || ($i->scope['kind']==='legacy' && Server::whereIn('id',$i->scope['node_ids'])->where('machine_id',$d['machine_id'])->exists()))) continue;
                    if (isset($d['node_id']) && !in_array($d['node_id'],$this->nodes($i->scope))) continue;
                    if (isset($d['capability_status']) && $i->capability['status']!==$d['capability_status']) continue;
                    $ids[]=$i->id;
                }
                $query->whereIn('id',$ids);
            }
            $page=$this->queryInt($d['page']??1,1,1000000); $size=$this->queryInt($d['page_size']??20,1,100);
            $total=$query->count();
            $rows=$query->orderBy($dtoType==='events'?'received_at':'created_at')->orderBy('id')->offset(($page-1)*$size)->limit($size)->get();
            return ['items'=>$rows->map(fn($r)=>$this->dto($dtoType,$r))->all(),'page'=>$page,'page_size'=>$size,'total'=>$total];
        }
        if ($type==='releases' && !$id) {
            Protocol::release($d);
            if ($this->q('releases')->where('version',$d['version'])->exists()) Protocol::fail('version_conflict',409);
            return $this->dto('releases',$this->create('releases',$d+['published_at'=>now('UTC'),'created_by'=>$admin]));
        }
        if ($type==='releases' && $action==='revoke') {
            Protocol::fields($d,[]); Protocol::uuid($id); $r=$this->find('releases',$id);
            if (!$r->revoked_at) $r->forceFill(['revoked_at'=>now('UTC')])->save();
            $this->sweep($s); return $this->dto('releases',$r);
        }
        if ($type==='enrollments') {
            Protocol::fields($d,['scope']); if (!is_array($d['scope'])) Protocol::fail();
            $this->validateScope($d['scope']); $this->overlap($d['scope']); $secret=Protocol::token();
            $e=$this->create('enrollments',['scope'=>$d['scope'],'secret_hash'=>hash('sha256',$secret),'expires_at'=>now('UTC')->addSeconds(900),'created_by'=>$admin]);
            return ['enrollment_id'=>$e->id,'enrollment_secret'=>$secret,'expires_at'=>Protocol::time($e->expires_at)];
        }
        if ($type==='installations') {
            Protocol::uuid($id); $i=$this->find('installations',$id);
            if ($action==='revoke') {
                Protocol::fields($d,[]);
                if (!$i->revoked_at) $i->forceFill(['revoked_at'=>now('UTC'),'policy_enabled'=>false,'policy_revision'=>$i->policy_revision+1])->save();
                $this->sweep($s); return ['id'=>$i->id,'revoked_at'=>Protocol::time($i->revoked_at)];
            }
            Protocol::fields($d,['enabled','target_release_id','revision']); Protocol::boolean($d['enabled']); Protocol::integer($d['revision']);
            if ($d['target_release_id']!==null) { Protocol::uuid($d['target_release_id']); $r=$this->find('releases',$d['target_release_id']); if ($r->revoked_at) Protocol::fail('release_revoked',409); }
            if ($d['enabled'] && ($d['target_release_id']===null || $i->revoked_at)) Protocol::fail();
            if ($d['revision']!==$i->policy_revision) Protocol::fail('revision_conflict',409);
            $i->forceFill(['policy_enabled'=>$d['enabled'],'target_release_id'=>$d['target_release_id'],'policy_revision'=>$i->policy_revision+1])->save();
            $this->sweep($s); return $this->policy($i);
        }
        if ($type==='batches' && (!$id || $id==='preview')) {
            Protocol::fields($d,['release_id','installation_ids'],$id==='preview'?[]:['max_concurrency','fail_pause_after']);
            $preview=$this->preview($d,$s);
            if ($id==='preview') return $preview;
            if ($preview['excluded']) Protocol::fail('validation_failed',422,['excluded'=>$preview['excluded']]);
            if ($this->q('batches')->whereIn('state',['running','paused','canceling'])->exists()) Protocol::fail('active_batch',409);
            $max=$d['max_concurrency']??5; $threshold=$d['fail_pause_after']??1;
            Protocol::integer($max,1,100); Protocol::integer($threshold,1,1000);
            $r=$this->find('releases',$d['release_id']);
            $b=$this->create('batches',['release_id'=>$r->id,'state'=>'running','max_concurrency'=>$max,'fail_pause_after'=>$threshold,'failure_baseline'=>0,'cancel_requested'=>false,'created_by'=>$admin]);
            foreach ($preview['eligible_ids'] as $installationId) {
                $i=$this->find('installations',$installationId);
                $t=$this->create('tasks',['batch_id'=>$b->id,'installation_id'=>$i->id,'release_snapshot'=>$this->dto('releases',$r),'policy_revision'=>$i->policy_revision,'state'=>'queued']);
                $this->audit($t,['state'=>'queued','admin_id'=>$admin],'admin');
                if ($this->eligibility($i,$r,$s)==='already_current') $this->finish($t,'skipped','already_current');
            }
            $this->aggregate($b); return $this->dto('batches',$b);
        }
        if ($type==='batches') {
            Protocol::fields($d,[]); Protocol::uuid($id); $b=$this->find('batches',$id);
            if (!in_array($b->state,['running','paused','canceling'],true)) Protocol::fail('task_terminal',409);
            if ($action==='pause') { if ($b->state==='canceling') Protocol::fail('batch_canceled',409); $this->pause($b); }
            elseif ($action==='resume') {
                if ($b->state!=='paused' || $this->counts($id)['uncertain']) Protocol::fail('batch_paused',409);
                $c=$this->counts($id); $b->forceFill(['state'=>'running','failure_baseline'=>$c['failed']+$c['rolled_back']+$c['rollback_failed']]);
            } elseif ($action==='cancel') {
                $b->forceFill(['state'=>'canceling','cancel_requested'=>true]);
                foreach ($this->q('tasks')->where('batch_id',$b->id)->whereNotIn('state',Protocol::TERMINAL)->get() as $t) {
                    if ($t->state==='queued') $this->finish($t,'canceled','batch_canceled');
                    else { $t->forceFill(['cancel_requested'=>true])->save(); $this->audit($t,['cancel_requested'=>true,'admin_id'=>$admin],'admin'); }
                }
            } else Protocol::fail('not_found',404);
            $b->save(); $this->aggregate($b); return $this->dto('batches',$b);
        }
        if ($type==='tasks' && $action==='resolve') {
            Protocol::fields($d,['state','reason','agent_stopped']); Protocol::uuid($id);
            if (!in_array($d['state'],['failed','rolled_back','rollback_failed'],true) || $d['agent_stopped']!==true || !is_string($d['reason']) || trim($d['reason'])==='') Protocol::fail();
            $reason=Protocol::message($d['reason']); [$b,$i,$t]=$this->context($this->find('tasks',$id));
            if ($t->state!=='uncertain') Protocol::fail('invalid_transition',409);
            $this->finish($t,$d['state'],'manual_resolution',$reason,false);
            $this->audit($t,['state'=>$d['state'],'reason'=>$reason,'agent_stopped'=>true,'admin_id'=>$admin],'admin');
            $this->aggregate($b); return $this->dto('tasks',$t);
        }
        Protocol::fail('not_found',404);
    }

    private function queryInt($value, int $min, int $max): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,16}$/D',$value)) $value=(int)$value;
        Protocol::integer($value,$min,$max); return $value;
    }
}

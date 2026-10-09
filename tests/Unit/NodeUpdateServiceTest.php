<?php

namespace Tests\Unit;

use App\Exceptions\NodeUpdateException;
use App\Models\NodeUpdateRecord;
use App\Models\ServerMachine;
use App\Models\Setting;
use App\Services\NodeUpdate\NodeUpdateService;
use App\Services\NodeUpdate\Protocol;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class NodeUpdateServiceTest extends TestCase
{
    private Manager $db;
    private NodeUpdateService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $app=new Container(); Container::setInstance($app); $app->instance('app',$app);
        $app->instance('config',new Repository(['app'=>['timezone'=>'UTC']]));
        $app->instance('events',new Dispatcher($app));
        $this->db=new Manager($app);
        $this->db->addConnection(['driver'=>'sqlite','database'=>':memory:','foreign_key_constraints'=>true]);
        $this->db->setAsGlobal(); $this->db->bootEloquent(); Model::clearBootedModels();
        $app->instance('db',$this->db->getDatabaseManager());
        $app->bind('db.schema',fn()=>$this->db->schema());
        $app->instance('encrypter',new Encrypter(str_repeat('k',32),'AES-256-CBC'));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        Carbon::setTestNow(Carbon::parse('2026-08-29T12:00:00Z'));
        $schema=$this->db->schema();
        $schema->create('v2_server_machine',function(Blueprint $t) {
            $t->increments('id'); $t->string('token'); $t->boolean('is_active')->default(true); $t->timestamps();
        });
        $schema->create('v2_server',function(Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('machine_id')->nullable(); $t->boolean('enabled')->default(true); $t->timestamps();
        });
        $schema->create('v2_settings',function(Blueprint $t) {
            $t->increments('id'); $t->string('name')->unique(); $t->text('value'); $t->timestamps();
        });
        (require __DIR__.'/../../database/migrations/2026_08_29_000001_create_node_update_tables.php')->up();
        DB::table('v2_settings')->insert(['name'=>'server_token','value'=>'legacy-fixture-credential']);
        for ($n=1;$n<=6;$n++) {
            DB::table('v2_server_machine')->insert(['id'=>$n,'token'=>'machine-'.$n,'is_active'=>true]);
            DB::table('v2_server')->insert(['id'=>$n,'machine_id'=>$n,'enabled'=>true]);
        }
        $this->service=new NodeUpdateService(); $app->instance(NodeUpdateService::class,$this->service);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); $this->db->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Model::clearBootedModels();
        Container::setInstance(null); parent::tearDown();
    }

    private function admin(string $path,array $d=[],string $method='POST',?string $key=null): array
    {
        return $this->service->admin(1,$method,$path,$d,$key??(string)Str::uuid());
    }

    private function error(string $code,callable $action,int $status=409): NodeUpdateException
    {
        try { $action(); self::fail('Expected '.$code); }
        catch (NodeUpdateException $e) { self::assertSame($code,$e->errorCode); self::assertSame($status,$e->status); return $e; }
    }

    private function release(string $version='v1.2.3',array $arches=['amd64']): array
    {
        $artifacts=[];
        foreach ($arches as $arch) foreach (['mi-node','xbctl'] as $component) $artifacts[]=[
            'arch'=>$arch,'component'=>$component,'https_url'=>'https://downloads.example.test/'.$version.'/'.$arch.'/'.$component,
            'sha256'=>str_repeat($component==='mi-node'?'a':'b',64),'size_bytes'=>1024,
        ];
        return $this->admin('releases',['version'=>$version,'os'=>'linux','min_agent_protocol'=>1,'artifacts'=>$artifacts]);
    }

    private function enrollment(int $machine=1,?array $scope=null): array
    {
        $scope??=['kind'=>'machine','machine_id'=>$machine];
        $ticket=$this->admin('enrollments',['scope'=>$scope]);
        $request=['protocol_version'=>1,'enrollment_id'=>$ticket['enrollment_id'],'enrollment_secret'=>$ticket['enrollment_secret'],
            'installation_id'=>(string)Str::uuid(),'agent_token'=>Protocol::token(),
            'auth'=>$scope['kind']==='machine'?['kind'=>'machine','machine_id'=>$machine,'token'=>'machine-'.$machine]:['kind'=>'legacy','token'=>'legacy-fixture-credential']];
        $this->service->enroll($request);
        return ['id'=>$request['installation_id'],'token'=>$request['agent_token'],'request'=>$request];
    }

    private function report(array $i,array $overrides=[]): array
    {
        return array_replace(['protocol_version'=>1,'installation_id'=>$i['id'],'versions'=>['mi_node'=>'v1.2.2','xbctl'=>'v1.2.2'],
            'installed_sha256'=>['mi_node'=>str_repeat('c',64),'xbctl'=>str_repeat('d',64)],
            'runtime'=>['os'=>'linux','arch'=>'amd64','init'=>'systemd','container'=>false],
            'capability'=>['status'=>'supported','reason'=>null],'active_attempt'=>null],$overrides);
    }

    private function prepare(int $count=1,int $max=5,int $threshold=1): array
    {
        $r=$this->release();
        $this->admin('settings',['enabled'=>true,'max_concurrency'=>$max,'revision'=>1],'PATCH');
        $installations=[];
        for ($n=1;$n<=$count;$n++) {
            $i=$this->enrollment($n);
            $this->service->poll($i['token'],$this->report($i));
            $this->admin('installations/'.$i['id'].'/policy',['enabled'=>true,'target_release_id'=>$r['id'],'revision'=>1],'PATCH');
            $installations[]=$i;
        }
        $b=$this->admin('batches',['release_id'=>$r['id'],'installation_ids'=>array_column($installations,'id'),'max_concurrency'=>$max,'fail_pause_after'=>$threshold]);
        $tasks=[];
        foreach ($installations as $i) $tasks[]=$this->service->q('tasks')->where('installation_id',$i['id'])->first()->id;
        return [$installations,$r,$b,$tasks];
    }

    private function claim(array $i,string $task,?string $claim=null): array
    {
        return $this->service->claim($i['token'],$task,['installation_id'=>$i['id'],'claim_id'=>$claim??(string)Str::uuid(),'policy_revision'=>2]);
    }

    private function event(array $i,string $task,array $a,int $seq,string $state,?string $code=null): array
    {
        return ['installation_id'=>$i['id'],'attempt_id'=>$a['attempt_id'],'lease_token'=>$a['lease_token'],'seq'=>$seq,'state'=>$state,
            'occurred_at'=>'2026-08-29T12:00:00Z','code'=>$code,'message'=>null,
            'observed_versions'=>['mi_node'=>$state==='succeeded'?'v1.2.3':'v1.2.2','xbctl'=>$state==='succeeded'?'v1.2.3':'v1.2.2']];
    }

    private function send(array $i,string $task,array $a,int $seq,string $state,?string $code=null): array
    {
        return $this->service->event($i['token'],$task,$this->event($i,$task,$a,$seq,$state,$code));
    }

    private function installing(array $i,string $task,array $a): void
    {
        $this->send($i,$task,$a,1,'downloading');
        $this->send($i,$task,$a,2,'verifying_artifacts');
        $this->send($i,$task,$a,3,'installing');
    }

    public function testDefaultsEnrollmentReplayAndCredentialSecrecy(): void
    {
        self::assertSame(['enabled'=>false,'max_concurrency'=>5,'revision'=>1],$this->admin('settings',[],'GET'));
        $i=$this->enrollment(); $first=$this->service->enroll($i['request']);
        Carbon::setTestNow(now()->addMinutes(20));
        self::assertSame($first,$this->service->enroll($i['request']));
        $bad=$i['request']; $bad['agent_token']=Protocol::token();
        $this->error('idempotency_conflict',fn()=>$this->service->enroll($bad));
        $dto=$this->admin('installations',[],'GET')['items'][0];
        self::assertFalse($dto['policy']['enabled']); self::assertFalse($dto['online']);
        self::assertSame('not_reported',$dto['capability']['reason']);
        self::assertStringNotContainsString($i['token'],json_encode($dto));
        self::assertStringNotContainsString($i['request']['enrollment_secret'],json_encode(DB::table('v2_node_update_enrollments')->get()));
        self::assertSame('registered',$this->admin('coverage',['node_id'=>'1'],'GET')['status']);
        self::assertSame('bootstrap_required',$this->admin('coverage',['node_id'=>'2'],'GET')['status']);
    }

    public function testEnrollmentScopeBoundariesAndNoLegacyBootstrapWithoutTicket(): void
    {
        $i=$this->enrollment();
        $this->error('scope_conflict',fn()=>$this->enrollment(1,['kind'=>'legacy','node_ids'=>[1]]));
        $this->error('validation_failed',fn()=>$this->admin('enrollments',['scope'=>['kind'=>'legacy','node_ids'=>[2,2]]]),422);
        $this->error('validation_failed',fn()=>$this->admin('enrollments',['scope'=>['kind'=>'legacy','node_ids'=>[2,3]]]),422);
        $bad=$i['request']; $bad['enrollment_id']=(string)Str::uuid();
        $this->error('invalid_credentials',fn()=>$this->service->enroll($bad),401);
        $ticket=$this->admin('enrollments',['scope'=>['kind'=>'machine','machine_id'=>2]]);
        $bad=array_replace($i['request'],['enrollment_id'=>$ticket['enrollment_id'],'enrollment_secret'=>$ticket['enrollment_secret']]);
        $this->error('scope_mismatch',fn()=>$this->service->enroll($bad),403);
    }

    public function testCredentialResetsAndDisablePermanentlyRevoke(): void
    {
        $i=$this->enrollment();
        ServerMachine::find(1)->update(['token'=>'new-machine-token']);
        ServerMachine::find(1)->update(['token'=>'machine-1']);
        $this->error('invalid_credentials',fn()=>$this->service->authenticate($i['token']),401);
        $legacy=$this->enrollment(2,['kind'=>'legacy','node_ids'=>[2]]);
        Setting::where('name','server_token')->first()->update(['value'=>'replacement']);
        $this->error('invalid_credentials',fn()=>$this->service->authenticate($legacy['token']),401);
        $j=$this->enrollment(3);
        ServerMachine::find(3)->update(['is_active'=>false]); ServerMachine::find(3)->update(['is_active'=>true]);
        $this->error('invalid_credentials',fn()=>$this->service->authenticate($j['token']),401);
    }

    public function testPublishDualArtifactsStrictVersionAndImmutableRelease(): void
    {
        $r=$this->release('v1.10.0',['amd64','arm64']); self::assertCount(4,$r['artifacts']);
        $data=array_intersect_key($r,array_flip(['version','os','min_agent_protocol','artifacts']));
        $this->error('version_conflict',fn()=>$this->admin('releases',$data));
        foreach (['latest','v01.2.3','v1.2.3-rc1'] as $version) {
            $bad=array_replace($data,['version'=>$version]);
            $this->error('validation_failed',fn()=>$this->admin('releases',$bad),422);
        }
        $data['version']='v2.0.0'; $data['artifacts'][0]['https_url']='https://user:password@example.test/x';
        $this->error('validation_failed',fn()=>$this->admin('releases',$data),422);
        $data['artifacts'][0]['https_url']='https://example.test/x'; $data['artifacts'][1]=$data['artifacts'][0];
        $this->error('validation_failed',fn()=>$this->admin('releases',$data),422);
        self::assertSame(1,Protocol::compare('v1.10.0','v1.9.999'));
        self::assertSame(-1,Protocol::compare('v1.2.99','v2.0.0'));
        self::assertSame('v1.10.0',$this->admin('releases/'.$r['id'].'/revoke')['version']);
    }

    public function testIdempotentClaimLeaseAndCrossInstallationProtection(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(2); [$i,$j]=$ii;
        $claim=(string)Str::uuid(); $a=$this->claim($i,$tasks[0],$claim);
        Carbon::setTestNow(now()->addSeconds(30));
        $this->service->heartbeat($i['token'],$tasks[0],['installation_id'=>$i['id'],'attempt_id'=>$a['attempt_id'],'lease_token'=>$a['lease_token']]);
        self::assertSame($a,$this->claim($i,$tasks[0],$claim));
        self::assertSame(1,$this->service->q('attempts')->count());
        $this->error('not_found',fn()=>$this->claim($j,$tasks[0]),404);
        $this->error('scope_mismatch',fn()=>$this->service->poll($j['token'],$this->report($i)),403);
        $this->error('task_claimed',fn()=>$this->claim($i,$tasks[0]));
        $this->error('idempotency_conflict',fn()=>$this->service->claim($i['token'],$tasks[0],['installation_id'=>$i['id'],'claim_id'=>$claim,'policy_revision'=>3]));
    }

    public function testEventSequenceExactReplayRedactionAndSuccess(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(); $i=$ii[0]; $task=$tasks[0]; $a=$this->claim($i,$task);
        $e=$this->event($i,$task,$a,2,'downloading');
        $err=$this->error('expected_seq',fn()=>$this->service->event($i['token'],$task,$e)); self::assertSame(1,$err->extra['expected_seq']);
        $e['seq']=1; $e['message']='Bearer '.$i['token'];
        $first=$this->service->event($i['token'],$task,$e);
        self::assertSame($first,$this->service->event($i['token'],$task,$e));
        $different=$e; $different['message']='changed';
        $this->error('idempotency_conflict',fn()=>$this->service->event($i['token'],$task,$different));
        $this->send($i,$task,$a,2,'verifying_artifacts'); $this->send($i,$task,$a,3,'installing');
        $this->send($i,$task,$a,4,'verifying'); $done=$this->send($i,$task,$a,5,'succeeded');
        self::assertNull($done['lease_expires_at']); self::assertSame('succeeded',$done['state']);
        self::assertSame($first,$this->service->event($i['token'],$task,$e));
        $this->error('task_terminal',fn()=>$this->send($i,$task,$a,6,'failed','download_failed'));
        self::assertNull($this->service->find('installations',$i['id'])->active_task_id);
        self::assertSame('completed',$this->admin('batches/'.$b['id'],[],'GET')['state']);
        $events=$this->admin('tasks/'.$task.'/events',[],'GET');
        self::assertStringNotContainsString($i['token'],json_encode($events));
        self::assertStringNotContainsString($a['lease_token'],json_encode($events));
    }

    public function testPauseBeforeInstallingWinsAndResumeDoesNotReopenOldAttempt(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(2); $i=$ii[0]; $task=$tasks[0]; $a=$this->claim($i,$task);
        $this->send($i,$task,$a,1,'downloading'); $this->send($i,$task,$a,2,'verifying_artifacts');
        $this->admin('batches/'.$b['id'].'/pause');
        $this->error('batch_paused',fn()=>$this->send($i,$task,$a,3,'installing'));
        self::assertNull($this->service->find('attempts',$a['attempt_id'])->installing_acked_at);
        $this->admin('batches/'.$b['id'].'/resume');
        $this->error('batch_paused',fn()=>$this->send($i,$task,$a,3,'installing'));
        $this->send($i,$task,$a,3,'canceled','batch_paused');
        self::assertSame('queued',$this->service->find('tasks',$tasks[1])->state);
        self::assertNotEmpty($this->claim($ii[1],$tasks[1])['attempt_id']);
    }

    public function testInstallingAckWinsCancelAndCanFinish(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(2); $i=$ii[0]; $task=$tasks[0]; $a=$this->claim($i,$task);
        $this->installing($i,$task,$a); $this->admin('batches/'.$b['id'].'/cancel');
        self::assertSame('canceled',$this->service->find('tasks',$tasks[1])->state);
        self::assertSame('installing',$this->service->find('tasks',$task)->state);
        $this->send($i,$task,$a,4,'verifying'); $this->send($i,$task,$a,5,'succeeded');
        self::assertSame('canceled',$this->admin('batches/'.$b['id'],[],'GET')['state']);
    }

    public function testCancelBeforeInstallingClosesGateAndKeepsCapacityUntilReport(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(); $i=$ii[0]; $task=$tasks[0]; $a=$this->claim($i,$task);
        $this->send($i,$task,$a,1,'downloading'); $this->send($i,$task,$a,2,'verifying_artifacts');
        $this->admin('batches/'.$b['id'].'/cancel');
        $this->error('batch_canceled',fn()=>$this->send($i,$task,$a,3,'installing'));
        self::assertSame($task,$this->service->find('installations',$i['id'])->active_task_id);
        $this->send($i,$task,$a,3,'canceled','batch_canceled');
        self::assertNull($this->service->find('installations',$i['id'])->active_task_id);
    }

    public function testExpiredInstallingRetainsCapacityAllowsOrderedLateCompletion(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(2,1); $i=$ii[0]; $task=$tasks[0]; $a=$this->claim($i,$task);
        $this->installing($i,$task,$a); Carbon::setTestNow(now()->addSeconds(121));
        $this->error('lease_expired',fn()=>$this->service->heartbeat($i['token'],$task,['installation_id'=>$i['id'],'attempt_id'=>$a['attempt_id'],'lease_token'=>$a['lease_token']]));
        self::assertSame('uncertain',$this->service->find('tasks',$task)->state);
        self::assertSame($task,$this->service->find('installations',$i['id'])->active_task_id);
        self::assertSame('paused',$this->service->find('batches',$b['id'])->state);
        $this->error('batch_paused',fn()=>$this->admin('batches/'.$b['id'].'/resume'));
        self::assertSame('uncertain',$this->send($i,$task,$a,4,'verifying')['state']);
        $this->send($i,$task,$a,5,'succeeded');
        $this->admin('batches/'.$b['id'].'/resume'); self::assertNotEmpty($this->claim($ii[1],$tasks[1]));
    }

    public function testExpiredBeforeAckNeverGrantsInstallingAndRequiresSafeTerminal(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(); $i=$ii[0]; $task=$tasks[0]; $a=$this->claim($i,$task);
        Carbon::setTestNow(now()->addSeconds(121));
        self::assertSame('uncertain',$this->send($i,$task,$a,1,'downloading')['state']);
        self::assertSame('uncertain',$this->send($i,$task,$a,2,'verifying_artifacts')['state']);
        $this->error('lease_expired',fn()=>$this->send($i,$task,$a,3,'installing'));
        $this->error('invalid_transition',fn()=>$this->send($i,$task,$a,3,'succeeded'));
        self::assertSame('failed',$this->send($i,$task,$a,3,'failed','lease_expired')['state']);
    }

    public function testCapacityShrinkDoesNotTerminateActiveAndNeverOverbooks(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(3,2);
        $a=$this->claim($ii[0],$tasks[0]); $this->claim($ii[1],$tasks[1]);
        $this->error('capacity_exhausted',fn()=>$this->claim($ii[2],$tasks[2]));
        $this->admin('settings',['enabled'=>true,'max_concurrency'=>1,'revision'=>2],'PATCH');
        self::assertSame(2,$this->service->q('tasks')->where('state','claimed')->count());
        $this->error('capacity_exhausted',fn()=>$this->claim($ii[2],$tasks[2]));
        $this->send($ii[0],$tasks[0],$a,1,'canceled','policy_disabled');
        $this->error('capacity_exhausted',fn()=>$this->claim($ii[2],$tasks[2]));
    }

    public function testFailurePauseBaselineAndUncertainManualResolution(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(3,3); $a=$this->claim($ii[0],$tasks[0]);
        $this->send($ii[0],$tasks[0],$a,1,'failed','download_failed');
        self::assertSame('paused',$this->service->find('batches',$b['id'])->state);
        $this->admin('batches/'.$b['id'].'/resume'); self::assertSame(1,$this->service->find('batches',$b['id'])->failure_baseline);
        $a=$this->claim($ii[1],$tasks[1]); Carbon::setTestNow(now()->addSeconds(121)); $this->service->maintain();
        $this->error('validation_failed',fn()=>$this->admin('tasks/'.$tasks[1].'/resolve',['state'=>'failed','reason'=>'verified stopped','agent_stopped'=>false]),422);
        $result=$this->admin('tasks/'.$tasks[1].'/resolve',['state'=>'failed','reason'=>'Agent stopped; original binaries verified onsite.','agent_stopped'=>true]);
        self::assertSame('manual_resolution',$result['code']);
        $this->error('task_terminal',fn()=>$this->send($ii[1],$tasks[1],$a,1,'failed','lease_expired'));
        self::assertSame('paused',$this->service->find('batches',$b['id'])->state);
    }

    public function testPolicyDisableAndReleaseRevocationCancelQueuedButPermitRecovery(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(2); $a=$this->claim($ii[0],$tasks[0]); $this->installing($ii[0],$tasks[0],$a);
        $this->admin('releases/'.$r['id'].'/revoke'); self::assertSame('canceled',$this->service->find('tasks',$tasks[1])->state);
        $this->admin('settings',['enabled'=>false,'max_concurrency'=>5,'revision'=>2],'PATCH');
        $this->send($ii[0],$tasks[0],$a,4,'rolling_back','health_failed');
        $this->send($ii[0],$tasks[0],$a,5,'rolled_back','health_failed');
        self::assertSame('rolled_back',$this->service->find('tasks',$tasks[0])->state);
    }

    public function testVersionPoliciesCapabilityAndPreviewCreateRecheck(): void
    {
        $r=$this->release(); $i=$this->enrollment();
        $this->admin('settings',['enabled'=>true,'max_concurrency'=>5,'revision'=>1],'PATCH');
        $this->admin('installations/'.$i['id'].'/policy',['enabled'=>true,'target_release_id'=>$r['id'],'revision'=>1],'PATCH');
        $preview=fn()=>$this->admin('batches/preview',['release_id'=>$r['id'],'installation_ids'=>[$i['id']]]);
        self::assertSame('unsupported',$preview()['excluded'][0]['code']);
        foreach ([['v2.0.0','v2.0.0','downgrade_forbidden'],['v1.2.3','v1.2.3','version_conflict'],['v1.2.2','v1.2.1','binary_version_mismatch'],[null,null,'version_unknown']] as [$mi,$xb,$expected]) {
            $this->service->poll($i['token'],$this->report($i,['versions'=>['mi_node'=>$mi,'xbctl'=>$xb]]));
            self::assertSame($expected,$preview()['excluded'][0]['code']);
        }
        $this->service->poll($i['token'],$this->report($i)); self::assertSame([$i['id']],$preview()['eligible_ids']);
        $this->admin('installations/'.$i['id'].'/policy',['enabled'=>false,'target_release_id'=>$r['id'],'revision'=>2],'PATCH');
        $this->error('validation_failed',fn()=>$this->admin('batches',['release_id'=>$r['id'],'installation_ids'=>[$i['id']]]),422);
    }

    public function testAdminIdempotencyRevisionPaginationUnknownFieldsAndTicketOnce(): void
    {
        $key=(string)Str::uuid(); $d=['enabled'=>true,'max_concurrency'=>3,'revision'=>1];
        $result=$this->admin('settings',$d,'PATCH',$key); self::assertSame($result,$this->admin('settings',$d,'PATCH',$key));
        $this->error('idempotency_conflict',fn()=>$this->admin('settings',array_replace($d,['max_concurrency'=>4]),'PATCH',$key));
        $this->error('revision_conflict',fn()=>$this->admin('settings',$d,'PATCH'));
        $this->error('validation_failed',fn()=>$this->admin('settings',$d+['command'=>'anything'],'PATCH'),422);
        $this->release(); $this->release('v1.2.4');
        $page=$this->admin('releases',['page'=>'2','page_size'=>'1'],'GET'); self::assertSame(2,$page['total']); self::assertCount(1,$page['items']);
        $this->error('validation_failed',fn()=>$this->admin('releases',['page_size'=>101],'GET'),422);
        $key=(string)Str::uuid(); $d=['scope'=>['kind'=>'machine','machine_id'=>1]];
        self::assertArrayHasKey('enrollment_secret',$this->admin('enrollments',$d,'POST',$key));
        self::assertArrayNotHasKey('enrollment_secret',$this->admin('enrollments',$d,'POST',$key));
        self::assertSame(1,$this->service->q('requests')->where('key',$key)->count());
        self::assertSame(1,$this->service->q('requests')->where('route','PATCH settings')->count());
    }

    public function testSameVersionMatchingHashesSkippedAndActiveBatchUnique(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(2);
        $this->error('active_batch',fn()=>$this->admin('batches',['release_id'=>$r['id'],'installation_ids'=>[$ii[0]['id']]]));
        $d=$this->report($ii[0],['versions'=>['mi_node'=>'v1.2.3','xbctl'=>'v1.2.3'],'installed_sha256'=>['mi_node'=>str_repeat('a',64),'xbctl'=>str_repeat('b',64)]]);
        self::assertNull($this->service->poll($ii[0]['token'],$d)['candidate']);
        self::assertSame('skipped',$this->service->find('tasks',$tasks[0])->state);
        self::assertSame('already_current',$this->service->find('tasks',$tasks[0])->code);
    }

    public function testPollNeverCompletesActiveTransactionAndActiveHintBlocksClaim(): void
    {
        [$ii,$r,$b,$tasks]=$this->prepare(2); $a=$this->claim($ii[0],$tasks[0]);
        $this->service->poll($ii[0]['token'],$this->report($ii[0],['capability'=>['status'=>'unsupported','reason'=>'local_disabled']]));
        self::assertSame('claimed',$this->service->find('tasks',$tasks[0])->state);
        $hint=['task_id'=>$tasks[0],'attempt_id'=>$a['attempt_id'],'last_seq'=>0,'phase'=>'claimed'];
        $this->service->poll($ii[1]['token'],$this->report($ii[1],['active_attempt'=>$hint]));
        $this->error('task_claimed',fn()=>$this->claim($ii[1],$tasks[1]));
    }
}

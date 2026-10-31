<?php

// Runs only against an isolated fixture database, never the application's configured database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use App\Models\{AiCreditSetting, AiTopupOrder, Payment, User};
use App\Services\AiCredit\AiCreditClient;
use App\Services\AiTopup\{AiTopupPolicy, AiTopupService};
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\{Bus, DB, Facade};

if (getenv('AI_TOPUP_FIXTURE_DATABASE') !== 'ai_topup_fixture') throw new RuntimeException('Isolated fixture database is required.');
$app=new Container();Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('app',$app);$app->instance('config',new Illuminate\Config\Repository(['app'=>['timezone'=>'UTC']]));
$app->instance('encrypter',new Illuminate\Encryption\Encrypter(str_repeat('x',32),'AES-256-CBC'));
$translator=new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(),'en');
$app->instance('translator',$translator);$app->instance('validator',new Illuminate\Validation\Factory($translator,$app));
$db=new Manager($app);$db->addConnection(['driver'=>'mysql','host'=>getenv('AI_TOPUP_FIXTURE_HOST'),'database'=>'ai_topup_fixture','username'=>'root','password'=>'fixture-only-password','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci']);$db->bootEloquent();
$db->getConnection()->setTransactionManager(new Illuminate\Database\DatabaseTransactionsManager());
$app->instance('db',$db->getDatabaseManager());$schema=$db->getConnection()->getSchemaBuilder();$app->instance('db.schema',$schema);
$app->instance(Illuminate\Contracts\Bus\Dispatcher::class,new class($app) extends Illuminate\Bus\Dispatcher{
    public function dispatch($command){DB::table('fixture_dispatches')->insert(['order_id'=>$command->orderId]);return null;}
});
$client=new class extends AiCreditClient{
    public function request(string $method,string $baseUrl,string $token,string $externalRef,?string $amount=null):?array{
        if(DB::transactionLevel()!==0)throw new RuntimeException('Network inside transaction');
        if($method==='POST'){
            DB::table('fixture_posts')->insert(['external_ref'=>$externalRef]);usleep(200000);
            DB::table('fixture_codes')->insertOrIgnore(['external_ref'=>$externalRef,'amount'=>$amount]);
        }
        $r=DB::table('fixture_codes')->where('external_ref',$externalRef)->first();
        return $r?['external_ref'=>$externalRef,'id'=>1,'code'=>'ISOLATED-MYSQL-NOT-REDEEMABLE','type'=>'balance','value'=>(float)$r->amount,'status'=>'unused','expires_at'=>null,'used_at'=>null]:null;
    }
};
$service=new AiTopupService($client);
$mode=$argv[1]??'run';
if($mode==='worker'){
    $action=$argv[2];$user=User::findOrFail(1);
    if($action==='create')$service->create($user,200,'75f73ac0-5105-4715-9375-a6bad288d933');
    elseif($action==='checkout'){try{$service->checkoutPayment($user,AiTopupOrder::firstOrFail()->trade_no,Payment::findOrFail(1),function(){if(DB::transactionLevel()!==0)throw new RuntimeException('Gateway inside transaction');DB::table('fixture_checkouts')->insert(['order_id'=>1]);usleep(200000);return ['type'=>1,'data'=>'https://pay.example.test/fixture'];});}catch(App\Exceptions\ApiException $e){if($e->getCode()!==409)throw $e;}}
    elseif($action==='paid'){$o=AiTopupOrder::firstOrFail();if(!$service->paid(['trade_no'=>$o->trade_no,'callback_no'=>'isolated-receipt','payment_id'=>1,'expected_amount'=>$o->total_amount+$o->handling_amount],'EPay'))throw new RuntimeException('Paid callback rejected');}
    elseif($action==='activate')$service->activateTraffic($user,AiTopupOrder::firstOrFail()->trade_no);
    elseif($action==='fulfill')$service->fulfill(AiTopupOrder::firstOrFail()->id);
    else throw new RuntimeException('Unknown worker');
    exit(0);
}
$schema->create('v2_user',function($t){$t->increments('id');$t->string('email');$t->integer('plan_id')->nullable();$t->boolean('banned')->default(false);$t->integer('expired_at')->nullable();foreach(['transfer_enable','temporary_transfer_enable','u','d']as$f)$t->bigInteger($f)->default(0);$t->integer('balance')->default(0);$t->integer('created_at');$t->integer('updated_at');});
$schema->create('v2_payment',function($t){$t->increments('id');$t->string('payment');$t->string('name')->default('Fixture');$t->boolean('enable')->default(true);$t->integer('handling_fee_fixed')->default(0);$t->decimal('handling_fee_percent',8,4)->default(0);$t->text('config')->nullable();$t->integer('created_at');$t->integer('updated_at');});
$schema->create('v2_ai_credit_settings',function($t){$t->integer('id')->primary();$t->string('instance_id');$t->text('settings');$t->text('bridge_token')->nullable();$t->integer('created_at');$t->integer('updated_at');});
foreach(['fixture_posts','fixture_dispatches','fixture_checkouts','fixture_codes']as$name)$schema->create($name,function($t)use($name){$t->increments('id');if(in_array($name,['fixture_dispatches','fixture_checkouts'],true))$t->integer('order_id');else{$t->string('external_ref');if($name==='fixture_codes'){$t->unique('external_ref');$t->string('amount');}}});
(require dirname(__DIR__,2).'/database/migrations/2026_10_25_000002_create_ai_topup_tables.php')->up();
AiCreditSetting::create(['id'=>1,'instance_id'=>'isolated-mysql-instance','settings'=>['bridge_url'=>'http://fixture.test'],'bridge_token'=>str_repeat('t',32)]);
$user=User::create(['email'=>'isolated@example.test','plan_id'=>1,'expired_at'=>time()+86400,'transfer_enable'=>200*AiTopupPolicy::GIB]);
$payment=Payment::create(['payment'=>'EPay']);$service->saveSettings(['enabled'=>true]);
$run=function($action){$processes=[];for($i=0;$i<16;$i++){$p=new Symfony\Component\Process\Process([PHP_BINARY,__FILE__,'worker',$action]);$p->setTimeout(90);$p->start();$processes[]=$p;}foreach($processes as$p){$p->wait();if(!$p->isSuccessful())throw new RuntimeException($action.' worker failed: '.$p->getErrorOutput().$p->getOutput());}};
$check=function($condition,$message){if(!$condition)throw new RuntimeException($message);};
$run('create');$check(AiTopupOrder::count()===1,'Duplicate create');$o=AiTopupOrder::firstOrFail();$service->prepareCheckout($user,$o->trade_no,$payment);
$run('checkout');$check(DB::table('fixture_checkouts')->count()===1,'Gateway checkout created more than once');
$run('paid');$check(DB::table('fixture_dispatches')->count()===1,'Payment enqueued more than once');
$run('activate');$user->refresh();$check((int)$user->transfer_enable===200*AiTopupPolicy::GIB+4831838208,'Gift applied more than once');$check((int)$user->temporary_transfer_enable===4831838208,'Temporary traffic mismatch');
$run('fulfill');$o->refresh();$check(DB::table('fixture_posts')->count()===1,'Fulfillment posted more than once');$check($o->issue_status==='issued','Not issued');
echo json_encode(['passed'=>true,'workers_per_phase'=>16,'phases'=>['idempotent-create','single-gateway-checkout','payment-callback','single-traffic-activation','leased-issuance'],'orders'=>AiTopupOrder::count(),'queue_dispatches'=>DB::table('fixture_dispatches')->count(),'upstream_posts'=>DB::table('fixture_posts')->count(),'gift_bytes'=>$user->temporary_transfer_enable]).PHP_EOL;

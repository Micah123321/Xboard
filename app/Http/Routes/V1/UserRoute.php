<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\CouponController;
use App\Http\Controllers\V1\User\GiftCardController;
use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\KnowledgeController;
use App\Http\Controllers\V1\User\NoticeController;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Controllers\V1\User\PlanController;
use App\Http\Controllers\V1\User\ServerController;
use App\Http\Controllers\V1\User\StatController;
use App\Http\Controllers\V1\User\TelegramController;
use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V1\User\UserController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get('/resetSecurity', [UserController::class, 'resetSecurity']);
            $router->get('/info', [UserController::class, 'info']);
            $router->post('/changePassword', [UserController::class, 'changePassword']);
            $router->post('/update', [UserController::class, 'update']);
            $router->get('/getSubscribe', [UserController::class, 'getSubscribe']);
            $router->get('/getStat', [UserController::class, 'getStat']);
            $router->get('/checkLogin', [UserController::class, 'checkLogin']);
            $router->post('/transfer', [UserController::class, 'transfer']);
            $router->post('/getQuickLoginUrl', [UserController::class, 'getQuickLoginUrl']);
            $router->get('/getActiveSession', [UserController::class, 'getActiveSession']);
            $router->post('/removeActiveSession', [UserController::class, 'removeActiveSession']);
            $aiCredit = \App\Http\Controllers\V1\User\AiCreditController::class;
            $router->get('/ai-credit/overview', [$aiCredit, 'overview']);
            $router->get('/ai-credit/records', [$aiCredit, 'records']);
            $router->get('/ai-credit/detail', [$aiCredit, 'detail'])->middleware('throttle:60,1');
            $router->post('/ai-credit/refresh', [$aiCredit, 'refresh'])->middleware('throttle:20,1');
            $router->post('/ai-credit/claim', [$aiCredit, 'claim'])->middleware('throttle:20,1');
            $aiTopup = \App\Http\Controllers\V1\User\AiTopupController::class;
            $router->get('/activities/fetch', [\App\Http\Controllers\V1\User\ActivityController::class, 'fetch']);
            $router->get('/ai-topup/overview', [$aiTopup, 'overview']);
            $router->get('/ai-topup/payment-methods', [$aiTopup, 'paymentMethods']);
            $router->get('/ai-topup/records', [$aiTopup, 'records']);
            $router->get('/ai-topup/detail', [$aiTopup, 'detail'])->middleware('throttle:60,1,ai-topup-detail');
            $router->post('/ai-topup/quote', [$aiTopup, 'quote'])->middleware('throttle:120,1,ai-topup-quote');
            $router->post('/ai-topup/create', [$aiTopup, 'create'])->middleware('throttle:20,1,ai-topup-create');
            $router->post('/ai-topup/checkout', [$aiTopup, 'checkout'])->middleware('throttle:20,1,ai-topup-checkout');
            $router->post('/ai-topup/cancel', [$aiTopup, 'cancel'])->middleware('throttle:20,1,ai-topup-cancel');
            $router->post('/ai-topup/traffic/activate', [$aiTopup, 'activateTraffic'])->middleware('throttle:20,1,ai-topup-traffic-activate');
            // Order
            $router->post('/order/save', [OrderController::class, 'save']);
            $router->post('/order/checkout', [OrderController::class, 'checkout']);
            $router->get('/order/check', [OrderController::class, 'check']);
            $router->get('/order/detail', [OrderController::class, 'detail']);
            $router->get('/order/fetch', [OrderController::class, 'fetch']);
            $router->get('/order/getPaymentMethod', [OrderController::class, 'getPaymentMethod']);
            $router->post('/order/cancel', [OrderController::class, 'cancel']);
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            // Invite
            $router->get('/invite/save', [InviteController::class, 'save']);
            $router->get('/invite/fetch', [InviteController::class, 'fetch']);
            $router->get('/invite/details', [InviteController::class, 'details']);
            $router->get('/invite/users', [InviteController::class, 'users']);
            // Notice
            $router->get('/notice/fetch', [NoticeController::class, 'fetch']);
            // Ticket
            $router->post('/ticket/reply', [TicketController::class, 'reply']);
            $router->post('/ticket/close', [TicketController::class, 'close']);
            $router->post('/ticket/save', [TicketController::class, 'save']);
            $router->get('/ticket/fetch', [TicketController::class, 'fetch']);
            $router->post('/ticket/withdraw', [TicketController::class, 'withdraw']);
            // Server
            $router->get('/server/fetch', [ServerController::class, 'fetch']);
            // Coupon
            $router->post('/coupon/check', [CouponController::class, 'check']);
            // Gift Card
            $router->post('/gift-card/check', [GiftCardController::class, 'check']);
            $router->post('/gift-card/redeem', [GiftCardController::class, 'redeem']);
            $router->get('/gift-card/history', [GiftCardController::class, 'history']);
            $router->get('/gift-card/detail', [GiftCardController::class, 'detail']);
            $router->get('/gift-card/types', [GiftCardController::class, 'types']);
            // Telegram
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
            $router->Post('/comm/getStripePublicKey', [CommController::class, 'getStripePublicKey']);
            // Knowledge
            $router->get('/knowledge/fetch', [KnowledgeController::class, 'fetch']);
            $router->get('/knowledge/getCategory', [KnowledgeController::class, 'getCategory']);
            // Stat
            $router->get('/stat/getTrafficLog', [StatController::class, 'getTrafficLog']);
        });
    }
}

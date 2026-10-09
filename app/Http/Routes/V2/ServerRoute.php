<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Server\ShadowsocksTidalabController;
use App\Http\Controllers\V1\Server\TrojanTidalabController;
use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Controllers\V2\Server\ServerController;
use App\Http\Controllers\V2\Server\MachineController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server/update',
            'middleware' => \App\Http\Middleware\NodeUpdateAgent::class,
        ], function ($route) {
            $controller = \App\Http\Controllers\V2\Server\NodeUpdateController::class;
            $route->post('enroll', [$controller, 'enroll']);
            $route->post('poll', [$controller, 'poll']);
            foreach (['claim', 'heartbeat', 'events'] as $action) {
                $route->post('tasks/{task_id}/'.$action, [$controller, $action]);
            }
        });

        $router->group([
            'prefix' => 'server',
            'middleware' => 'server.v2'
        ], function ($route) {
            $route->match(['GET', 'POST'], 'handshake', [ServerController::class, 'handshake']);
            $route->post('report', [ServerController::class, 'report']);
            $route->get('gfw/task', [ServerController::class, 'gfwTask']);
            $route->post('gfw/report', [ServerController::class, 'gfwReport']);
            $route->get('config', [UniProxyController::class, 'config']);
            $route->get('user', [UniProxyController::class, 'user']);
            $route->post('push', [UniProxyController::class, 'push']);
            $route->post('alive', [UniProxyController::class, 'alive']);
            $route->get('alivelist', [UniProxyController::class, 'alivelist']);
            $route->post('status', [UniProxyController::class, 'status']);
        });

        $router->group([
            'prefix' => 'server/machine',
        ], function ($route) {
            $route->post('nodes', [MachineController::class, 'nodes']);
            $route->post('status', [MachineController::class, 'status']);
        });
    }
}

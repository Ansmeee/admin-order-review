<?php

namespace Gini\Process\Engine\SJTU;

class Task
{
    public static function doUpdate($task, $description)
    {
        $instance = $task->instance;
        $orderData = (array)$instance->getVariable('data');
        $voucher = $orderData['voucher'];
        if (!$voucher) return false;

        $rpc = self::_getRPC('order');
        if (!$rpc) return false;

        try {
            $bool = $rpc->mall->order->updateOrder($voucher, [
                'description'=> $description,
                'hash_rand_key'=> date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    // 订单的更新直接向lab-orders进行提交, 因为hub-orders没有自购订单的信息
    private static $_RPCs = [];
    private static function _getRPC($type)
    {
        $confs = \Gini\Config::get('app.rpc');
        if (!isset($confs[$type])) {
            return;
        }
        $conf = $confs[$type] ?: [];
        if (!self::$_RPCs[$type]) {
            $rpc = \Gini\IoC::construct('\Gini\RPC', $conf['url']);
            self::$_RPCs[$type] = $rpc;
            $clientID = $conf['client_id'];
            $clientSecret = $conf['client_secret'];
            $token = $rpc->mall->authorize($clientID, $clientSecret);
            if (!$token) {
                \Gini\Logger::of(APP_ID)
                    ->error('Mall\\RObject getRPC: authorization failed with {client_id}/{client_secret} !',
                        ['client_id' => $clientID, 'client_secret' => $clientSecret]);
            }
        }

        return self::$_RPCs[$type];
    }
}


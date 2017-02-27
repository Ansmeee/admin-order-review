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

        $rpc = \Gini\Module\AppBase::getAppRPC('order');
        if (!$rpc) return false;

        if ($description) {
            try {
                $bool = $rpc->mall->order->updateOrder($voucher, [
                    'description'=> $description,
                    'hash_rand_key'=> date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                return false;
            }
        }
        return true;
    }
}


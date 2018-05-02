<?php

namespace Gini\Controller\API\Mall;

class Order extends \Gini\Controller\API
{
    public function actionGetComments($voucher = '')
    {
        if (!$voucher) return false;

        try {
            $conf = \Gini\Config::get('app.order_review_process');
            $engine = \Gini\BPM\Engine::of('order_review');
            $process = $engine->process($conf['name']);

            $params['key'] = $process->id;
            $params['voucher'] = $voucher;
            $driver = \Gini\Process\Driver\Engine::of('bpm2');
            $rdata  = $driver->searchInstances($params);

            if (!$rdata['total']) {
                return false;
            }

            $instances = $driver->getInstances($rdata['token'], 0, 1);
            $instanceId = current($instances)->id;
            $instance = $engine->ProcessInstance($instanceId);

            $his_comment = [];
            $params['variableName'] = 'comment';
            $rdata = $instance->getVariables($params);
            if ($rdata) {
                $his_comment = json_decode(current($rdata)['value']);
                return $his_comment;
            }
        } catch (\Gini\BPM\Exception $e) {
        }

        return false;
    }
}

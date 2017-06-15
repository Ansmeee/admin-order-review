<?php

namespace Gini\Controller\CGI\Rest;


class Order extends \Gini\Controller\CGI\Layout
{
    public function actionApprove()
    {
        $content = file_get_contents('php://input');
        $order_data = (array) json_decode($content);

        $order = a('order');
        $order->setData($order_data);

        $voucher = $order->voucher;
        if (!$voucher) return;
        $rpc = self::_getRPC('order');
        if (!$rpc) return;
        $now = date('Y-m-d H:i:s');
        try {
            $bool = $rpc->mall->order->updateOrder($voucher, [
                'status' => $order->customized ? \Gini\ORM\Order::STATUS_APPROVED : \Gini\ORM\Order::STATUS_NEED_VENDOR_APPROVE,
                'payment_status'=> \Gini\ORM\Order::PAYMENT_STATUS_PENDING,
                'description'=> [
                    'a' => $order->customized ? T('订单被审批通过, 可以进行付款') : T('订单交给供应商确认'),
                    't' => $now,
                ]
            ]);
        } catch (\Exception $e) {
        }
    }

    public function actionReject()
    {
        $content = file_get_contents('php://input');
        $order_data = json_decode($content);
        $voucher = $order_data->voucher;
        if (!$voucher) return;
        $rpc = self::_getRPC('order');
        if (!$rpc) return;
        $now = date('Y-m-d H:i:s');
        try {
            $bool = $rpc->mall->order->updateOrder($voucher, [
                'status' => \Gini\ORM\Order::STATUS_CANCELED,
                'description'=> [
                    'a' => T('**系统** 自动 **取消** 了该订单'),
                    't' => $now,
                ]
            ]);
        } catch (\Exception $e) {
        }
    }

    public function actionSendMsg()
    {
        $content = file_get_contents('php://input');
        $rdata = explode('&', $content);
        $candidateGroup = $rdata[0];
        $orderData = json_decode($rdata[1]);
        $data['vendor_name'] = $orderData->vendor_name;
        $data['customer_name']	= $orderData->customer->name;
        $data['customized'] = $orderData->customized;
        $data['price'] = $orderData->price;
        $data['note'] = $orderData->note;

        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $giniFullName = $_SERVER['GINI_SYS_PATH'].'/bin/gini';
        exec("{$giniFullName} bpm task run ".$candidateGroup." ".$data. " > /dev/null 2>&1 &");
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

<?php

namespace Gini\Controller\CGI\Rest;


class Order extends \Gini\Controller\CGI\Layout
{
    public function actionApprove()
    {
        $content = file_get_contents('php://input');
        $order_data = json_decode($content);

        $order = a('order');
        $order->setData($order_data);

        $voucher = $order_data['voucher'];
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
        $voucher = $order_data['voucher'];
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
}


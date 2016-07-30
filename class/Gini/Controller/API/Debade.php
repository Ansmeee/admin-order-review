<?php

namespace Gini\Controller\API;

class Debade extends \Gini\Controller\API
{
    public function actionGetNotified($message)
    {
        $hash = $_SERVER['HTTP_X_DEBADE_TOKEN'];
        $secret = \Gini\Config::get('app.debade_secret');
        $str = file_get_contents('php://input');

        if ($hash != \Gini\DeBaDe::hash($str, $secret)) {
            return;
        }

        $id = $message['id'];
        $data = $message['data'];
        if (($id !== 'order') || !isset($data['voucher'])) {
            return;
        }
        // {{{
        // data [
        //  voucher
        //  requester && customer && vendor [
        //      id
        //      name
        //  ]
        //  address
        //  invoice_title
        //  phone
        //  postcode
        //  email
        //  note
        //  status
        //  payment_status
        //  deliver_status
        //  label
        //  node
        //  items [
        //      [
        //          product
        //          quantity
        //          unit_price
        //          total_price
        //          deliver_status
        //          name
        //          manufacturer
        //          catalog_no
        //          package
        //          cas_no
        //      ]
        //  ]
        // ]
        // }}}

        $voucher = $data['voucher'];
        $node = $data['node'];
        if ($node != \Gini\Config::get('app.node')) return;
        $status = $data['status'];
        $followedStatus = \Gini\ORM\Order::STATUS_NEED_MANAGER_APPROVE;
        if ($status!=$followedStatus) return;

        $items = (array)$data['items'];
        $needApprove = false;
        $pNames = [];
        $pCASs = [];
        foreach ($items as $item) {
            $pCASs[] = $casNO = $item['cas_no'];
            $pNames[] = $item['name'];
            if (self::_isHazPro($casNO)) {
                $needApprove = true;
                break;
            }
        }

        if (!$needApprove) {
            return self::_approve($voucher);
        }

        $request = a('order/review/request', ['voucher'=> $voucher]);
        // 南理工只支持学院一级审核
        // if ($request->id && $request->status==\Gini\ORM\Order\Review\Request::STATUS_UNIVERS_PASSED) {
        if ($request->id && $request->status==\Gini\ORM\Order\Review\Request::STATUS_SCHOOL_PASSED) {
            return self::_approve($voucher);
        }

        if ($request->id && in_array($request->status, [
            \Gini\ORM\Order\Review\Request::STATUS_SCHOOL_FAILED,
            \Gini\ORM\Order\Review\Request::STATUS_UNIVERS_FAILED,
        ])) {
            return self::_reject($voucher);
        }

        $organization = self::_getOrganization($node, $data['customer']['id']);
        $ocode = $organization['code'];
        $oname = $organization['name'];
        if (!$ocode || !$oname) return;

        if (!$request->id) {
            $request->voucher = $voucher;
            $request->status = \Gini\ORM\Order\Review\Request::STATUS_PENDING;
            $request->ctime = date('Y-m-d H:i:s');
            $request->product_name = implode(',', array_unique($pNames));
            $request->product_cas_no = implode(',', array_unique($pCASs));
            $request->organization_code = $ocode;
            $request->organization_name = $oname;
            $request->order_items = $items;
            $request->order_group_title = $data['customer']['name'];
            $request->order_vendor_name = $data['vendor']['name'];
            $request->order_price = $data['price'];
            $request->order_status = $data['status'];
            $request->save();
        }
    }

    private static function _getOrganization($node, $groupID)
    {
        $conf = \Gini\Config::get('tag-db.rpc');
        $url = $conf['url'];
        $client = \Gini\Config::get('tag-db.client');
        $clientID = $client['id'];
        $clientSecret = $client['secret'];
        $rpc = \Gini\IoC::construct('\Gini\RPC', $url);
        $rpc->tagdb->authorize($clientID, $clientSecret);
        $tagName = "labmai-{$node}/{$groupID}";
        $data = $rpc->tagdb->data->get($tagName);
        $organization = (array)$data['organization'];
        return $organization;
    }

    private static function _approve($voucher)
    {
        $rpc = self::_getRPC('order');
        $bool = $rpc->mall->order->updateOrder($voucher, [
            'status' => \Gini\ORM\Order::STATUS_APPROVED,
        ], [
            'status' => \Gini\ORM\Order::STATUS_NEED_MANAGER_APPROVE,
        ]);
        return $bool;
    }

    private static function _reject($voucher)
    {
        $rpc = self::_getRPC('order');
        $bool = $rpc->mall->order->updateOrder($voucher, [
            'status' => \Gini\ORM\Order::STATUS_CANCELED,
        ], [
            'status' => \Gini\ORM\Order::STATUS_NEED_MANAGER_APPROVE,
        ]);
        return $bool;
    }

    private static function _isHazPro($casNO)
    {
        if (!$casNO) return;
        return !empty(\Gini\ORM\Product::getHazTypes($casNO));
    }

    private static $_RPCs = [];
    private static function _getRPC($type)
    {
        $confs = \Gini\Config::get('mall.rpc');
        if (!isset($confs[$type])) {
            $type = 'default';
        }
        $conf = $confs[$type] ?: [];
        if (!self::$_RPCs[$type]) {
            $rpc = \Gini\IoC::construct('\Gini\RPC', $conf['url']);
            self::$_RPCs[$type] = $rpc;
            $client = \Gini\Config::get('mall.client');
            $token = $rpc->mall->authorize($client['id'], $client['secret']);
            if (!$token) {
                \Gini\Logger::of(APP_ID)
                    ->error('Mall\\RObject getRPC: authorization failed with {client_id}/{client_secret} !',
                        ['client_id' => $client['id'], 'client_secret' => $client['secret']]);
            }
        }

        return self::$_RPCs[$type];
    }
}

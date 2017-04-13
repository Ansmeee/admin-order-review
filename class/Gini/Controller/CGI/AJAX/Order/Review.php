<?php
/**
* @file Request.php
* @brief 申请单列表和处理
*
* @author PiHiZi <pihizi@msn.com>
*
* @version 0.1.0
* @date 2016-04-16
 */

namespace Gini\Controller\CGI\AJAX\Order;

class Review extends \Gini\Controller\CGI
{
    public function actionMore($page = 1, $type = 'pending')
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $page = (int) max($page, 1);
        $form = $this->form();
        $q = $form['q'];
        list($total, $requests) = self::_getMoreRequest($page, $type, $q);
        if (!count($requests)) {
            return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('order/review/list-none'));
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('order/review/list', [
            'requests'=> $requests,
            'type'=> $type,
            'page'=> $page,
            'total'=> $total,
            'vTxtTitles' => \Gini\Config::get('haz.types'),
        ]));
    }

    private static function _getMoreRequest($page, $type, $querystring=null)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        $status = ($type=='pending') ? \Gini\ORM\Order\Review\Request::getAllowedPendingStatus($me, $group) : \Gini\ORM\Order\Review\Request::getAllowedDoneStatus($me, $group);
        if (empty($status)) {
            return [0, []];
        }

        $limit = 25;
        $start = ($page - 1) * $limit;
        $params = [];

        $sql = "SELECT id FROM order_review_request";

        $sts = [];
        $ocs = [];
        $i = 0;
        $where = [];
        foreach ($status as $code=>$st) {
            $tmpCodeKey = ":oc{$i}";
            $ocs[$tmpCodeKey] = "{$code}%";
            $tmpSts = [];
            foreach ($st as $j=>$s) {
                $tmpSts[":status{$i}{$j}"] = $s;
            }
            $sts = array_merge($sts, $tmpSts);
            $tmpSts = implode(',', array_keys($tmpSts));
            $where[] = "(organization_code LIKE {$tmpCodeKey} AND status in ({$tmpSts}))";
            $i++;
        }

        $params = array_merge($params, $sts);
        $params = array_merge($params, $ocs);

        $where = implode(' OR ', $where);
        $sql = "{$sql} WHERE ({$where})";

        if ($querystring) {
            $sql = "{$sql} AND (voucher=:voucher OR MATCH(product_name,product_cas_no) AGAINST(:querystring))";
            $params[':voucher'] = $params[':querystring'] = trim($querystring);
        }

        $sql = "{$sql} ORDER BY id DESC LIMIT {$start}, {$limit}";
        $requests = those('order/review/request')->query($sql, null, $params);
        $total = $requests->totalCount();

        return [ceil($total/$limit), $requests];
    }

    public function actionGetOPForm()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }
        $form = $this->form();
        $key = $form['key'];
        $id = $form['id'];
        $request = a('order/review/request', $id);
        $allowedOperators = $request->getAllowedOperators();
        if (!isset($allowedOperators[$key])) return;
        $title = $allowedOperators[$key]['title'];
        return \Gini\IoC::construct('\Gini\CGI\Response\HTML', V('order/review/op-form', [
            'id'=> $id,
            'key'=> $key,
            'title'=> $title
        ]));
    }

    /**
     * @brief 允许管理方单个审批
     *
     * @return
     */
    public function actionPost()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id) {
            return;
        }

        $form = $this->form('post');
        $key = $form['key'];
        $id = $form['id'];
        $note = $form['note'];
        $request = a('order/review/request', $id);
        $allowedOperators = $request->getAllowedOperators();
        if (!isset($allowedOperators[$key]) || $request->status!=$allowedOperators[$key]['from_status'] || !$request->isRW()) {
            return;
        }

        $operator = $allowedOperators[$key];
        $toStatus = $operator['to_status'];
        $bool = false;
        $message = '';

        $db = \Gini\Database::db();
        $db->beginTransaction();
        try {
            $rpc = \Gini\Module\AppBase::getMallRPC('order');
            $request->log = array_merge((array)$request->log, [
                [
                    'ctime'=> date('Y-m-d H:i:s'),
                    'from'=> $request->status,
                    'to'=> $toStatus,
                    'operator'=> $me,
                    'note'=> $note ?: '--'
                ]
            ]);
            $request->status = $toStatus;

            if ($request->save()) {
                if ($toStatus == \Gini\ORM\Order\Review\Request::STATUS_UNIVERS_PASSED) {
                    $bool = $rpc->mall->order->updateOrder($request->voucher, [
                        'status' => \Gini\ORM\Order::STATUS_APPROVED,
                        'mall_description'=> [
                            'a'=> H(T('订单已经被 :name(:group) 最终审核通过', [':name'=>$me->name, ':group'=>$group->title])),
                            't'=> date('Y-m-d H:i:s'),
                            'u'=> $me->id,
                            'd'=> $note ?: '--'
                        ]
                    ], [
                        'status' => \Gini\ORM\Order::STATUS_NEED_MANAGER_APPROVE,
                    ]);
                    if (!$bool) {
                        throw new \Exception();
                    }

                    $db = \Gini\Database::db('mall-old');
                    $params = [
                         'voucher' => $request->voucher,
                         'date' => date('Y-m-d H:i:s'),
                         'operator' => $me->id,
                         'type' => \Gini\ORM\Order::OPERATE_TYPE_APPROVE,
                         'description' => $task->candidate_group->title.T('审批人'),
                    ];
                    $sql = "insert into order_operate_info (voucher,operate_date,operator_id,type,description) values (:voucher, :date, :operator, :type, :description)";
                    $query = $db->query($sql, null, $params);
                }
                else if ($toStatus == \Gini\ORM\Order\Review\Request::STATUS_SCHOOL_PASSED) {
                    $bool = $rpc->mall->order->updateOrder($request->voucher, [
                        'status' => \Gini\ORM\Order::STATUS_APPROVED,
                        'mall_description'=> [
                            'a'=> H(T('订单已经被学院管理员 :name(:group) 审核通过', [':name'=>$me->name, ':group'=>$group->title])),
                            't'=> date('Y-m-d H:i:s'),
                            'u'=> $me->id,
                            'd'=> $note ?: '--'
                        ]
                    ], [
                        'status' => \Gini\ORM\Order::STATUS_NEED_MANAGER_APPROVE,
                    ]);
                    if (!$bool) {
                        throw new \Exception();
                    }

                    $db = \Gini\Database::db('mall-old');
                    $params = [
                         'voucher' => $request->voucher,
                         'date' => date('Y-m-d H:i:s'),
                         'operator' => $me->id,
                         'type' => \Gini\ORM\Order::OPERATE_TYPE_APPROVE,
                         'description' => $task->candidate_group->title.T('审批人'),
                    ];
                    $sql = "insert into order_operate_info (voucher,operate_date,operator_id,type,description) values (:voucher, :date, :operator, :type, :description)";
                    $query = $db->query($sql, null, $params);
                }
                elseif (in_array($toStatus, [
                    \Gini\ORM\Order\Review\Request::STATUS_UNIVERS_FAILED,
                    \Gini\ORM\Order\Review\Request::STATUS_SCHOOL_FAILED,
                ])) {
                    if (!$note) {
                        $message = T('请填写拒绝理由');
                        throw new \Exception();
                    }
                    $bool = $rpc->mall->order->updateOrder($request->voucher, [
                        'status' => \Gini\ORM\Order::STATUS_CANCELED,
                        'mall_description'=> [
                            'a'=> H(T('订单被 :name(:group) 拒绝', [':name'=>$me->name, ':group'=>$group->title])),
                            't'=> date('Y-m-d H:i:s'),
                            'u'=> $me->id,
                            'd'=> $note
                        ]
                    ], [
                        'status' => \Gini\ORM\Order::STATUS_NEED_MANAGER_APPROVE,
                    ]);
                    if (!$bool) {
                        throw new \Exception();
                    }
                }
                $bool = true;
                $db->commit();
            }
        } catch (\Exception $e) {
            $db->rollback();
        }

        return \Gini\IoC::construct('\Gini\CGI\Response\JSON', [
            'code' => $bool ? 0 : 1,
            'id'=> $id, // request->id
            'message' => $message ?: ($bool ? T('操作成功') : T('操作失败, 请您重试')),
        ]);
    }

}

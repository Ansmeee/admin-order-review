<?php

namespace Gini\Controller\CGI;

class Review extends Layout\Board
{
    public function __index()
    {
        return $this->redirect('review/pending');
    }

    /**
        * @brief 待审采购
        *
        *
     */
    public function actionPending()
    {
        $vars = [
            'type'=> 'pending'
        ];

        $this->view->body = V('order/review/index', $vars);
    }

    /**
        * @brief 审核历史
        *
        *
     */
    public function actionDone()
    {

        $vars = [
            'type'=> 'done'
        ];
        $this->view->body = V('order/review/index', $vars);
    }

    /**
        * @brief 设置分级审查需要审核的商品类别
        *
        * @return
     */
    public function actionSettings()
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->isAllowedTo('管理权限')) return;

        $types = [
            'hazardous' => T('危险品'),
            'drug_precursor' => T('易制毒'),
            'highly_toxic'  => T('剧毒品'),
            'explosive' => T('易制爆')
        ];

        if ($_SERVER['REQUEST_METHOD']==='POST') {
            $form = $this->form('post');
            $checked = (array)$form['types'];
            $checked = array_diff($checked, array_diff($checked, array_keys($types)));
            $oTypes = those('hazardous/review/type');
            $had = [];
            $db = \Gini\Database::db();
            $db->beginTransaction();
            try {
                foreach ($oTypes as $oType) {
                    if (!in_array($oType->key, $checked)) {
                        $oType->delete();
                        continue;
                    }
                    $had[] = $oType->key;
                }
                $need = array_diff($checked, $had);
                foreach ($need as $n) {
                    $nType = a('hazardous/review/type');
                    $nType->key = $n;
                    $nType->save();
                }
                $db->commit();
                $success = T('保存成功');
            }
            catch (\Exception $e) {
                $error = T('操作失败，请重试');
                $db->rollback();
            }
        }

        $checked = those('hazardous/review/type')->get('id', 'key');

        $vars = [
            'type'      => 'settings',
            'types'     => $types,
            'checked'=> $checked,
            'error'=> $error,
            'success'=> $success
        ];
        $this->view->body = V('order/settings/review', $vars);
    }


    public function actionInfo($requestID)
    {
        $request = a('order/review/request', $requestID);
        if (!$request->id) {
            return $this->redirect('error/404');
        }
        if (!$request->isRW()) {
            return $this->redirect('error/401');
        }

        $order = a('order', ['voucher'=> $request->voucher]);
        $this->view->body = V('order/review/info', [
            'request'=> $request,
            'order'=> $order,
            'operators'=> $request->getAllowedOperators()
        ]);
    }

    public function actionLogout()
    {
        \Gini\Gapper\Client::logout();
        $this->redirect('/');
    }

}

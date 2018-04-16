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

        // TODO 这个是不是可配置会比较好
        $types = [
            'hazardous' => T('危险品'),
            'drug_precursor' => T('易制毒'),
            'highly_toxic'  => T('剧毒品'),
            'explosive' => T('易制爆'),
            'psychotropic'=> T('精神药品'),
            'narcotic'=> T('麻醉药品'),
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
            'operators'=> $request->getAllowedOperators(),
            'vTxtTitles' => \Gini\Config::get('haz.types')
        ]);
    }

    public function actionAttachDownload($id, $type, $item_index=0, $license_index=0)
    {
        if (!in_array($type, ['license','extra','instruction'])) {
            $this->redirect('error/404');
        }

        $explode = explode('-', $id, 2);
        $approvalType = $explode[0];
        $id = $explode[1];
        if (!$id) return  $this->redirect('error/404');

        $engine = \Gini\BPM\Engine::of('order_review');

        if ($approvalType == 'pending') {
            $task = $engine->task($id);
            if (!$task || !$task->id) return  $this->redirect('error/404');
            $order = $this->_getTaskObject($task);
            if (!$order->id) return;
        } else if ($approvalType == 'history') {
            $instance = $engine->processInstance($id);
            if (!$instance || !$instance->id) return;
            $order = $this->_getInstanceObject($instance);
            if (!$order->id) return;
        } else {
            $this->redirect('error/404');
        }

        $client_id = \Gini\Config::get('app.rpc')['order']['client_id'];
    	$data = \Gini\Gapper\Client::getInfo($client_id);
    	$file_name = \Gini\URI::url(rtrim($data['url'], '/') . '/attachment/download-order-review-file', ['voucher' => $order->voucher, 'type' => $type, 'item_index' =>$item_index, 'index' => $license_index]);
    	$headers = get_headers($file_name, 1);

        if ($headers['Content-Type'] == 'image/png,image/jpg,image/jpeg,application/x-7z-compressed,application/x-rar,application/zip') {
            $name = $headers['File-Name'] ?: $order->voucher.'.jpg';
            header('Content-Disposition:attachment;filename='.$name);
            header('Content-Type: image/png,image/jpg,image/jpeg,application/x-7z-compressed,application/x-rar,application/zip');
            @readfile($file_name);
            exit;
        } else {
            $this->redirect('error/404');
        }
    }

    private function _getInstanceObject($instance, $force=false)
    {
        $params['variableName'] = 'data';
        $rdata = $instance->getVariables($params);
        $data = json_decode(current($rdata)['value']);

        if ($force) {
            $order = a('order', ['voucher' => $data->voucher]);
        }

        if (!$order || !$order->id) {
            $order = $data;
        }

        return $order;
    }

    private function _getTaskObject($task)
    {
        $rdata = $task->getVariables('data');
        $data = json_decode($rdata['value']);

        return $data;
    }
}

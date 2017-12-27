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

    public function actionAttachDownload($id, $item_index=0, $license_index=0, $type)
    {
        $explode = explode('-', $id, 2);
        $approvalType = $explode[0];
        $id = $explode[1];
        if (!$id) return;

        $conf = \Gini\Config::get('app.order_review_process');
        $engine = \Gini\BPM\Engine::of('order_review');
        $process = $engine->process($conf['name']);

        if ($approvalType == 'pending') {
            $task = $engine->getTask($id);
            if (!$task || !$task->id) return;
            $instance = $task->instance;
        } else {
            $instance = $engine->processInstance($id);
            if (!$instance->id) return;
        }

        $order = $this->_getInstanceObject($instance, true);
        $items = $order->items;
        $info  = $items[$item_index][$type.'_images'][$license_index];

        if ((\Gini\Config::get('app.is_show_order_instruction') === true) && ($type === 'instruction')) {
            $info = [
                'name' => $order->instruction['name'],
                'path' => $order->instruction['path'],
            ];
        }

        $fullpath = \Gini\Core::locateFile('data/customized/'.$info['path']);
        if(is_file($fullpath)) {
            $client = $_SERVER["HTTP_USER_AGENT"];
            $filename = $info['name'];
            $encoded_filename = urlencode($filename);
            $encoded_filename = str_replace("+", "%20", $encoded_filename);

            header('Content-Type: application/octet-stream');

            //兼容IE11
            if(preg_match("/MSIE/", $client) || preg_match("/Trident\/7.0/", $client)){
                header('Content-Disposition: attachment; filename="' . $encoded_filename . '"');
            } else if (preg_match("/Firefox/", $client)) {
                header('Content-Disposition: attachment; filename*="utf8\'\'' . $filename . '"');
            } else {
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            }
            readfile($fullpath);
            exit;
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
            $order = a('order');
            $order->setData($data);
        }

        return $order;
    }
}

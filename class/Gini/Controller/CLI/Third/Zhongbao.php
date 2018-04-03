<?php
/**
 *
 * Author: shuxin.jin
 * Mail: shuxin.jin@geneegroup.com 
 * Created Time: Mon Apr  2 12:16:59 2018
 *
 **/

namespace Gini\Controller\CLI\Third;

class Zhongbao extends \Gini\Controller\CLI
{
    private function getLock()
    {
        $pidFile = APP_PATH.'/'.DATA_DIR.'/order-push-to-zb-process.pid';
        $fh = fopen($pidFile, 'r+');
        $lock = flock($fh, LOCK_EX);
        if (!$lock) return false;
        $rawPID = (int)trim(fgets($fh));
        $success = false;
        if ($rawPID && $this->filterWorkers($rawPID)) {
            error_log("order-push-to-zb-with-lock: pid#{$rawPID} running");
        } else if ($pid = getmypid()) {
            ftruncate($fh, 0);
            fwrite($fh, $pid);
            fflush($fh);
            $success = true;
            error_log("order-push-to-zb-with-lock: pid#{$pid} new start");
        }
        @flock($fh, LOCK_UN);
        @fclose($fh);
        return $success;
    }

    private static function _getSN($url, $data)
    {
        $client = new \Gini\HTTP();
        $response = $client->post($url, $data);
        $body = @$response->body;
        if (!$body) return;
        $data = @json_decode($body, true);
        if (!$data) return;
        return @$data['sn'];
    }

    public function actionPushToZB()
    {
        if (!$this->getLock()) return;
        $third_confs = \Gini\Config::get('zhongbao.third_review_info');
        $snURL  = $third_confs['snurl'];
        $apiURL = $third_confs['apiurl'];
        if (!$snURL || !$apiURL) return;

        $start = 0;
        $limit = 20;
        $http = new \Gini\HTTP();
        while (true) {
            $infos = those('third/order/push')
                ->whose('is_push')->is(\Gini\ORM\Third\Order\Push::TYPE_PUSH)
                ->limit($start, $limit);

            if (!count($infos)) break;
            $start += $limit;

            foreach ($infos as $info) {
                $pushData = $info->push_data;
                $api = self::$apiUrl;
                $sn = self::_getSN($snURL, $pushData);
                if (!$sn) continue;
                $pushData['sn'] = $sn;
                $response = $http->post($apiURL, $pushData);
                $body = @$response->body;
                if (!$body) continue;
                $data = @json_decode($body, true);
                if (!$data) continue;
                if (!$data['success'] || $data['code'] != 1) continue;
                $info->is_push = \Gini\ORM\Order\Push::TYPE_PUSHED;
                $info->save();
            }
        }
    }

    private function filterWorkers($pid)
    {
        return file_exists("/proc/{$pid}");
    }
}

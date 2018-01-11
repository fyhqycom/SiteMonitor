<?php
/**
 * Created by PhpStorm.
 * User: shellus
 * Date: 2018/1/3
 * Time: 16:46
 */

namespace App\Service;


use App\Jobs\MonitorJob;
use App\Mail\MonitorNotice;
use App\Monitor;
use App\Snapshot;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MonitorService
{

    static public function runMonitor()
    {
        $jobs = Monitor::all();
        foreach ($jobs as $job) {
            self::joinQueue($job);
        }

        return true;
    }

    /**
     * 将一个监控任务加入列队，会自动设置延迟
     * 注意：此方法会被MonitorJob递归调用
     *
     * @param Monitor $monitor
     *
     * @return void
     */
    static public function joinQueue(Monitor $monitor)
    {
        $interval = $monitor->interval_normal;
        try {
            $lastSnapshot = $monitor->lastSnapshot();
            if ($lastSnapshot->is_error) {
                $interval = $monitor->interval_error;
            } elseif ($lastSnapshot->is_match) {
                $interval = $monitor->interval_match;
            }
        } catch (ModelNotFoundException $e) {
            // 第一次就立刻运行吧
            $interval = 0;
        }

        $time = Carbon::now()->addSecond($interval);

        dispatch((new MonitorJob($monitor))->delay($time)->onQueue('monitor'));
    }

    /**
     * 执行一个监控，返回一个监控快照
     *
     * @param Monitor $monitor
     *
     * @return Snapshot
     */
    static public function request(Monitor $monitor)
    {

        $snapshot = new Snapshot();
        $snapshot->monitor_id = $monitor->id;
        $snapshot->saveOrFail();

        $curlHandle = curl_init();

        curl_setopt($curlHandle, CURLOPT_SAFE_UPLOAD, true); // 禁止body中使用@上传文件
        curl_setopt($curlHandle, CURLOPT_FORBID_REUSE, true); // 不重用TCP连接
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true); // 返回数据，不输出
        curl_setopt($curlHandle, CURLOPT_HEADER, true); // 输出header
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true); // 跟随跳转
        curl_setopt($curlHandle, CURLOPT_MAXREDIRS, 5); // 跳转次数


        curl_setopt($curlHandle, CURLOPT_URL, $monitor->request_url);

        if ($monitor->request_nobody) {
            curl_setopt($curlHandle, CURLOPT_NOBODY, true);
        }
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, $monitor->request_method);
        if ($monitor->request_method != "GET") {
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $monitor->request_body);
        }
        // CURLOPT_AUTOREFERER 重定向时，自动设置 header 中的Referer:信息。
        // CURLOPT_CONNECT_ONLY 仅连接

        // 最大60秒超时
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($curlHandle);


        $curlInfo = curl_getinfo($curlHandle);


        $curlErrorNo = curl_errno($curlHandle);
        $curlErrorMessage = curl_error($curlHandle);


        curl_close($curlHandle);


        $snapshot->http_status_code = $curlInfo['http_code'];

        $headerSize = $curlInfo['header_size'];
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);


        $snapshot->headers = $headers;
        $snapshot->body_content = $body;

        $snapshot->time_total = bcmul($curlInfo['total_time'], 1000, 0);
        $snapshot->time_dns = bcmul($curlInfo['namelookup_time'], 1000, 0);
        $snapshot->time_connection = bcmul($curlInfo['connect_time'], 1000, 0);
        $snapshot->time_transport = bcmul($curlInfo['pretransfer_time'], 1000, 0);
        $snapshot->error_message = "";
        $snapshot->is_error = false;
        $snapshot->is_match = false;

        if ($curlErrorNo !== 0) {
            $snapshot->is_error = true;
            $snapshot->error_message = "curl error[$curlErrorNo]: " . $curlErrorMessage;
        } else {
            $matcher = $snapshot->getMatcher();
            /** @var $matcher Monitor\Match\MatchBase */
            $snapshot->is_match = $matcher->isMatch();
        }
        $snapshot->is_done = true;
        $snapshot->saveOrFail();

        $monitor->last_error = $snapshot->is_error;
        $monitor->last_match = $snapshot->is_match;
        if ($monitor->last_error) {
            $monitor->last_error_time = Carbon::now();
        }
        if ($monitor->last_match) {
            $monitor->last_match_time = Carbon::now();
        }

        $monitor->time_total_average_15minute = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subMinute(15))->avg('time_total');
        $monitor->time_total_average_30minute = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subMinute(30))->avg('time_total');
        $monitor->time_total_average_1hour = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subHour(1))->avg('time_total');
        $monitor->time_total_average_12hour = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subHour(12))->avg('time_total');
        $monitor->time_total_average_24hour = $monitor->snapshots()->whereIsDone(1)->where('created_at', '>', Carbon::now()->subHour(24))->avg('time_total');

        $monitor->saveOrFail();
        return $snapshot;
    }


    /**
     * 决定要不要通知，包括通知文本的生成都在这里
     *
     * @param Snapshot $snapshot
     */
    static public function handleSnapshot(Snapshot $snapshot)
    {
        /** @var User $user */
        $user = User::findOrFail($snapshot->monitor->user_id);

        // 取出上一个快照，判断是否变化，如果没变化，就直接return了
        try {
            $perSnapshot = Snapshot::whereMonitorId($snapshot->monitor_id)->where('id', '<', $snapshot->id)->orderBy('id', 'desc')->firstOrFail();
            if ($perSnapshot->is_match == $snapshot->is_match && $perSnapshot->is_error == $snapshot->is_error) {
                // 如果匹配状态没变化，且错误状态没变化，就不通知
                return;
            }
        } catch (ModelNotFoundException $e) {
            // 如果第一次，且没有匹配也没错误，就不通知
            if (!$snapshot->is_match && !$snapshot->is_error) {
                return;
            }
        }

        // 四个状态
        // $snapshot->is_match   $snapshot->is_error    !$snapshot->is_match   !$snapshot->is_error
        // 匹配 未匹配 请求错误 错误恢复

        // 到这里的，至于三种状态，1是错误恢复或匹配恢复，2是状态变化，3是错误变化, 如果有错误，就不考虑匹配变化
        if (!$snapshot->is_error && !$snapshot->is_match) {
            $messageText = "恢复正常";
        } else {
            if ($snapshot->is_error) {
                $messageText = "请求错误，{$snapshot->error_message}";
            } else {
                $matcher = $snapshot->getMatcher();
                $messageText = $matcher->getMessage();
            }
        }

        \Mail::to($user)->send(new MonitorNotice($messageText, $snapshot));
    }
}
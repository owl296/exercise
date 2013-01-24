<?php
class Curl{
    private $url;
    private $body;
    private $curl;
    private $mh;
    private $isDone;
    private $isClosed;
    private $errorCode;
    private $errorMessage;

    public function __construct($url = '', $timeout = 180){
        if(!is_string($url)){
            throw new InvalidArgumentException(sprintf('パラメータが文字列ではありません : $url=%s', var_export($url, true)));
        }
        if(!is_int($timeout)){
            throw new InvalidArgumentException(sprintf('パラメータが数字ではありません : $timeout=%s', var_export($timeout, true)));
        }

        $this->url  = $url;
        $this->curl = curl_init($url);
        $this->body = '';
        $this->mh   = curl_multi_init();
        $this->isDone = false;
        $this->isClosed = false;
        $this->errorCode = null;
        $this->errorMessage = '';
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $timeout);
        curl_multi_add_handle($this->mh, $this->curl);
        $this->multiExec();
    }

    function __destruct(){
        $this->close();
    }

    protected function multiExec(){
        $curlCode = curl_multi_exec($this->mh, $runningCount);
        return(array($curlCode, $runningCount));
    }

    public function getBody($suspendTime = 60){
        if($this->isDone){
            return($this->body);
        }

        $startTime = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        while(true){
            list($curlCode, $runningCount) = $this->multiExec();
            if($runningCount === 0){
                $curlErrorCode = $this->getErrorCode();
                switch($curlErrorCode) {
                    case  0 :
                        $this->body = curl_multi_getcontent($this->curl);
                        $this->close();
                        return($this->body);
                    case 28 :
                        throw new TimeoutException($this);
                    default :
                        throw new CurlException($this);
                }
            }

            // 完了していない場合の処理
            $currentTime = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
            $diff = $currentTime->diff($startTime);
            if(intval($diff->format('%s')) >= intval($suspendTime)){
                throw new SuspendException('タイムアウト');
            }
            usleep(1);
        }
    }

    public function getErrorCode(){
        if($this->errorCode === null){
            $info = curl_multi_info_read($this->mh);
            if($info === false){
                return(null);
            }
            $this->errorCode = $info['result'];
        }
        return($this->errorCode);
    }

    public function getErrorMessage(){
        if($this->errorMessage === ''){
            $this->errorMessage = curl_error($this->curl);
        }
        return($this->errorMessage);
    }

    private function close(){
        if($this->isClosed === false){
            $this->isDone = true;
            $this->isClosed = true;
            curl_close($this->curl);
            curl_multi_remove_handle($this->mh, $this->curl);
            curl_multi_close($this->mh);
        }
    }

    public static function getBodies(array $curls = array(), $timeout = 60){
        $paramCheck =
            function($elem){
                if(!is_a($elem, 'Curl')){
                    throw new InvalidArgumentException('Curlクラスオブジェクトではありません');
                }
            };
        array_map($paramCheck, $curls);

        $getBody =
            function($curl){
                try{
                    return($curl->getBody(0));
                }catch(SuspendException $e){
                    return(null);
                }catch(CurlException $e){
                    return($e);
                }
            };

        $startTime = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
        while(true){
            $result = array_map($getBody, $curls);
            if(array_search(null, $result, true) === false){
                return($result);
            }

            $currentTime = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
            $diff = $currentTime->diff($startTime);
            if(intval($diff->format('%s')) >= intval($timeout)){
                return($result);
            }
            usleep(1);
        }
    }
}

class Task{
    private static $defaultErrorAction = null;
    private $curl;
    private $url;
    private $timeout;
    private $isDone;
    private $normalAction;
    private $errorAction;

    public function __construct($url, $timeout, Closure $normalAction, Closure $errorAction = null)
    {
        if(static::$defaultErrorAction === null){
            static::$defaultErrorAction = function($exception){return $exception;};
        }
        $this->curl         = null;
        $this->url          = $url;
        $this->timeout      = $timeout;
        $this->isDone       = false;
        $this->normalAction = $normalAction;
        $this->errorAction  = ($errorAction === null)?(static::$defaultErrorAction):$errorAction;
    }

    public function execute($suspendTime = 0){
        if($this->isDone){
            throw new Exception('完了したタスクが再度実行されました');
        }
        if($this->curl === null){
            $this->curl = new Curl($this->url, $this->timeout);
        }
        try{
            $body = $this->curl->getBody($suspendTime);
            $this->finish();
            $action = $this->normalAction;
            return(new TaskResult(true, $action($body)));
        }catch(SuspendException $e){
            return(null);
        }catch(Exception $e){
            $this->finish();
            $action = $this->errorAction;
            return(new TaskResult(false, $action($e)));
        }
    }

    private function finish(){
        $this->isDone = true;
        $this->curl = null;
    }
}
class TaskResult{
    private $success;
    private $result;
    public function __construct($success, $result){
        if(!is_bool($success)) throw new InvalidArgumentException();

        $this->success = $success;
        $this->result = $result;
    }
    public function isSuccess(){
        return($this->success);
    }
    public function get(){
        return($this->result);
    }
}

class ParallelTaskExecutor{
    private static $defaultLogFunction = null;
    private static $noLogFunction = null;
    public $executingTasks;
    public $parallelNum;
    public $result;
    public $logFunction;

    public function __construct($parallelNum = 10){
        if(static::$defaultLogFunction === null){
            static::$defaultLogFunction =
                function($message){
                    $dateTime = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
                    echo sprintf("[%s] %s\n", $dateTime->format('Y-m-d H:i:s u'), $message);
                };
        }
        if(static::$noLogFunction === null){
            static::$noLogFunction = function($message){};
        }

        $this->parallelNum = $parallelNum;
        $this->result = array();
        $this->disableLog();
    }

    /**
     */
    public function execute(array $tasks){
        array_map(function($task){if(!is_a($task, 'Task')){throw new InvalidArgumentException();}}, $tasks);

        $this->result = array();
        $this->executingTasks = array();

        // 1個1個タスクを追加して、parallelNum個たまったらタスクを実行する
        foreach($tasks as $key => $task){
            $this->store($key, $task);
        }

        // 残ったタスクを実行
        while(count($this->executingTasks) > 0){
            $this->progress();
        }

        // progressメソッドでためておいた、タスクの実行結果を返す
        $result = $this->result;
        $this->result = null;
        return($result);
    }

    /**
     * ログ出力を有効化させます
     */
    public function enableLog(Closure $logFunction = null){
        $this->logFunction = ($logFunction === null)?(static::$defaultLogFunction):($logFunction);
    }

    /**
     * ログ出力を無効化させます
     */
    public function disableLog(){
        $this->logFunction = static::$noLogFunction;
    }

    /**
     * $executingTasksにタスクを1つためます。
     * タスク数がある程度たまると、progressメソッドを実行します。
     */
    protected function store($key, Task $task){
        $this->log('store. key=['.$key.']');
        $task->execute();
        $this->executingTasks[$key] = $task;
        if(count($this->executingTasks) >= $this->parallelNum){
            $this->progress();
        }
    }

    /**
     * $executingTasksにためられているタスクを最低1つ完了させます。
     */
    protected function progress(){
        $this->log(sprintf('progress. count=[%s]', count($this->executingTasks)));
        $progress = false;
        while(true){
            foreach($this->executingTasks as $key => $task){
                $result = $task->execute();
                if($result === null){
                    continue;
                }
                unset($this->executingTasks[$key]);
                $progress = true;
                $this->result[$key] = $result;
                $this->log(sprintf('finish task. key=[%s]', $key));
            }
            if($progress === true) break;
            usleep(1);
        }
    }

    protected function log($message){
        $logFunction = $this->logFunction;
        $logFunction($message);
    }
}


class CurlException extends Exception{
    public function __construct(Curl $curl, $prev = null){
        parent::__construct($curl->getErrorMessage(), $curl->getErrorCode(), $prev);
    }
}
class TimeoutException extends CurlException{
}

class SuspendException extends Exception{
}
?>
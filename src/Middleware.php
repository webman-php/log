<?php

namespace Webman\Log;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use support\Context;
use think\db\PDOConnection;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Throwable;
use RuntimeException;
use support\Db;
use support\Log;
use support\Redis;
use think\db\connector\Mysql;
use Webman\ThinkOrm\DbManager;
use support\think\Db as ThinkDb;
use think\Container as ThinkContainer;

class Middleware implements MiddlewareInterface
{

    /**
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function process(Request $request, callable $next): Response
    {
        static $initialized_db, $initialized_think_orm;

        $conf=config('plugin.webman.log.app');

        //跳过配置的模块
        if(!empty($conf['dontReport']['app']) && is_array($conf['dontReport']['app']) && in_array($request->app,$conf['dontReport']['app'],true)){
            return $next($request);
        }

        //跳过配置的path
        if(!empty($conf['dontReport']['path']) && is_array($conf['dontReport']['path'])){
            $requestPath=$request->path();
            foreach ($conf['dontReport']['path'] as $_path){
                if(strpos($requestPath,$_path)===0){
                    return $next($request);
                }
            }
        }

        //跳过配置的控制器日志记录
        if(!empty($conf['dontReport']['controller']) && is_array($conf['dontReport']['controller']) && in_array($request->controller,$conf['dontReport']['controller'],true)){
            return $next($request);
        }

        //跳过配置的方法
        if(!empty($conf['dontReport']['action']) && is_array($conf['dontReport']['action'])){
            foreach ($conf['dontReport']['action'] as $_action){
                if($_action[0]===$request->controller && $_action[1]===$request->action){
                    return $next($request);
                }
            }
        }

        // 请求开始时间
        $start_time = microtime(true);

        // 记录ip 请求等信息
        $logs = $request->getRealIp() . ' ' . $request->method() . ' ' . trim($request->fullUrl(), '/');
        Context::get()->webmanLogs = '';

        // 清理think-orm的日志
        if (class_exists(ThinkDb::class, false) && class_exists(Mysql::class, false)) {
            ThinkDb::getDbLog(true);
        }

        // 初始化数据库监听
        if (!$initialized_db) {
            $initialized_db = true;
            $this->initDbListen();
        }

        // 初始化think-orm日志监听
        if (!$initialized_think_orm) {
            try {
                ThinkDb::setLog(function ($type, $log) {
                    Context::get()->webmanLogs = (Context::get()->webmanLogs ?? '') . "[SQL]\t" . trim($log) . PHP_EOL;
                });
            } catch (Throwable $e) {}
            $initialized_think_orm = true;
        }

        // 得到响应
        $response = $next($request);
        $time_diff = substr((microtime(true) - $start_time) * 1000, 0, 7);
        $logs .= " [{$time_diff}ms] [webman/log]" . PHP_EOL;
        if ($request->method() === 'POST') {
            $logs .= "[POST]\t" . var_export($request->post(), true) . PHP_EOL;
        }
        $logs = $logs . (Context::get()->webmanLogs ?? '');

        // think-orm如果被使用，则记录think-orm的日志
        if ($loaded_think_db = (class_exists(ThinkDb::class, false) && class_exists(Mysql::class, false))) {
            $sql_logs = ThinkDb::getDbLog(true);
            if (!empty($sql_logs['sql'])) {
                foreach ($sql_logs['sql'] as $sql) {
                    $logs .= "[SQL]\t" . trim($sql) . PHP_EOL;
                }
            }
        }

        // 判断业务是否出现异常
        $exception = null;
        if (method_exists($response, 'exception')) {
            $exception = $response->exception();
        }

        // 尝试记录异常
        $method = 'info';
        if ($exception && config('plugin.webman.log.app.exception.enable', true) && !$this->shouldntReport($exception)) {
            $logs .= $exception . PHP_EOL;
            $method = 'error';
        }

        // 判断Db是否有未提交的事务
        $has_uncommitted_transaction = false;
        if (class_exists(Connection::class, false)) {
            if ($log = $this->checkDbUncommittedTransaction()) {
                $has_uncommitted_transaction = true;
                $method = 'error';
                $logs .= $log;
            }
        }

        // 判断think-orm是否有未提交的事务
        if ($loaded_think_db) {
            if ($log = $this->checkTpUncommittedTransaction()) {
                $has_uncommitted_transaction = true;
                $method = 'error';
                $logs .= $log;
            }
        }

        /**
         * 初始化redis监听
         * 注意：由于redis是延迟监听，所以第一个请求不会记录redis具体日志
         */
        $new_names = $this->tryInitRedisListen();
        foreach ($new_names as $name) {
            $logs .= "[Redis]\t[connection:{$name}] ..." . PHP_EOL;
        }

        call_user_func([Log::channel(config('plugin.webman.log.app.channel', 'default')), $method], $logs);

        if ($has_uncommitted_transaction) {
            throw new RuntimeException('Uncommitted transactions found');
        }

        return $response;
    }

    /**
     * 初始化数据库日志监听
     *
     * @return void
     */
    protected function initDbListen()
    {
        if (!class_exists(QueryExecuted::class) || !class_exists(Db::class)) {
            return;
        }
        try {
            $capsule = $this->getCapsule();
            if (!$capsule) {
                return;
            }
            $dispatcher = $capsule->getEventDispatcher();
            if (!$dispatcher) {
                if (!class_exists(Dispatcher::class)) {
                    return;
                }
                $dispatcher = new Dispatcher(new Container);
            }
            $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $query) {
                $sql = trim($query->sql);
                if (strtolower($sql) === 'select 1') {
                    return;
                }
                $sql = str_replace("?", "%s", $sql);
                foreach ($query->bindings as $i => $binding) {
                    if ($binding instanceof \DateTime) {
                        $query->bindings[$i] = $binding->format("'Y-m-d H:i:s'");
                    } else {
                        if (is_string($binding)) {
                            $query->bindings[$i] = "'$binding'";
                        }
                    }
                }
                $log = $sql;
                try {
                    $log = vsprintf($sql, $query->bindings);
                } catch (\Throwable $e) {}
                Context::get()->webmanLogs = (Context::get()->webmanLogs ?? '') . "[SQL]\t[connection:{$query->connectionName}] $log [{$query->time} ms]" . PHP_EOL;
            });
            $capsule->setEventDispatcher($dispatcher);
        } catch (\Throwable $e) {
            echo $e;
        }
    }

    /**
     * 尝试初始化redis日志监听
     *
     * @return array
     */
    protected function tryInitRedisListen(): array
    {
        static $listened;
        if (!class_exists(CommandExecuted::class) || !class_exists(Redis::class)) {
            return [];
        }
        $new_names = [];
        $listened ??= new \WeakMap();
        // Cache 目前无法监听 日志
        try {
            foreach (Redis::instance()->connections() ?: [] as $connection) {
                /* @var \Illuminate\Redis\Connections\Connection $connection */
                $name = $connection->getName();
                if (isset($listened[$connection])) {
                    continue;
                }
                $connection->listen(function (CommandExecuted $command) {
                    foreach ($command->parameters as &$item) {
                        if (is_array($item)) {
                            $item = implode('\', \'', $item);
                        }
                    }
                    Context::get()->webmanLogs = (Context::get()->webmanLogs ?? '') . "[Redis]\t[connection:{$command->connectionName}] Redis::{$command->command}('" . implode('\', \'', $command->parameters) . "') ({$command->time} ms)" . PHP_EOL;
                });
                $listened[$connection] = $name;
                $new_names[] = $name;
            }
        } catch (Throwable $e) {
        }
        return $new_names;
    }


    /**
     * 获得Db的Manager
     *
     * @return \Webman\Database\Manager
     */
    protected function getCapsule()
    {
        static $capsule;
        if (!$capsule) {
            $reflect = new \ReflectionClass(Db::class);
            $property = $reflect->getProperty('instance');
            $property->setAccessible(true);
            $capsule = $property->getValue();
        }
        return $capsule;
    }

    /**
     * 检查Db是否有未提交的事务
     *
     * @return string
     */
    protected function checkDbUncommittedTransaction(): string
    {
        $logs = '';
        $context = Context::get();
        foreach ($context as $item) {
            if ($item instanceof Connection) {
                if ($item->transactionLevel() > 0) {
                    $item->rollBack();
                    $logs .= "[ERROR]\tUncommitted transaction found and try to rollback" . PHP_EOL;
                }
            }
        }
        return $logs;
    }

    /**
     * 检查think-orm是否有未提交的事务
     *
     * @return string
     */
    protected function checkTpUncommittedTransaction(): string
    {
        static $property, $manager_instance;
        $logs = '';
        $context = Context::get();
        foreach ($context as $item) {
            if ($item instanceof PDOConnection) {
                if (method_exists($item, 'getPdo')) {
                    $pdo = $item->getPdo();
                    if ($pdo && $pdo->inTransaction()) {
                        $item->rollBack();
                        $logs .= "[ERROR]\tUncommitted transaction found and try to rollback" . PHP_EOL;
                    }
                }
            }
        }
        return $logs;
    }

    /**
     * 判断是否需要记录异常
     *
     * @param Throwable $e
     * @return bool
     */
    protected function shouldntReport($e): bool
    {
        foreach (config('plugin.webman.log.app.exception.dontReport', []) as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

}

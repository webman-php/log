<?php

namespace Webman\Log;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandExecuted;
use support\Db;
use support\Log;
use support\Redis;
use think\db\connector\Mysql;
use think\facade\Db as ThinkDb;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use RuntimeException;

class Middleware implements MiddlewareInterface
{
    /**
     * @var string
     */
    public $logs = '';

    /**
     * @param Request $request
     * @param callable $next
     * @return Response
     */
    public function process(Request $request, callable $next): Response
    {
        static $initialized_db;

        // 请求开始时间
        $start_time = microtime(true);

        // 记录ip 请求等信息
        $logs = $request->getRealIp() . ' ' . $request->method() . ' ' . trim($request->fullUrl(), '/');
        $this->logs = '';

        // 清理think-orm的日志
        if (class_exists(ThinkDb::class, false) && class_exists(Mysql::class, false)) {
            ThinkDb::getDbLog(true);
        }

        // 初始化数据库监听
        if (!$initialized_db) {
            $initialized_db = true;
            $this->initDbListen();
        }

        // 得到响应
        $response = $next($request);
        $time_diff = substr((microtime(true) - $start_time) * 1000, 0, 7);
        $logs .= " [{$time_diff}ms] [webman/log]" . PHP_EOL;
        if ($request->method() === 'POST') {
            $logs .= "[POST]\t" . var_export($request->post(), true) . PHP_EOL;
        }
        $logs .= $this->logs;

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
        $has_uncommited_transaction = false;
        if (class_exists(Connection::class, false)) {
            if ($log = $this->checkDbUncommitTransaction()) {
                $has_uncommited_transaction = true;
                $method = 'error';
                $logs .= $log;
            }
        }

        // 判断think-orm是否有未提交的事务
        if ($loaded_think_db) {
            if ($log = $this->checkTpUncommitTransaction()) {
                $has_uncommited_transaction = true;
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

        call_user_func([Log::class, $method], $logs);

        if ($has_uncommited_transaction) {
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
        if (!class_exists(QueryExecuted::class)) {
            return;
        }
        try {
            $capsule = $this->getCapsule();
            $dispatcher = new Dispatcher(new Container);
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
                $log = vsprintf($sql, $query->bindings);
                $this->logs .= "[SQL]\t[connection:{$query->connectionName}] $log [{$query->time} ms]" . PHP_EOL;
            });
            $capsule->setEventDispatcher($dispatcher);
        } catch (\Throwable $e) {
            echo $e;
        }
    }

    /**
     * 尝试初始化redis日志监听
     *
     * @return void
     */
    protected function tryInitRedisListen()
    {
        static $listened_names = [];
        if (!class_exists(CommandExecuted::class)) {
            return [];
        }
        $new_names = [];
        foreach (Redis::instance()->connections() ?: [] as $connection) {
            /* @var \Illuminate\Redis\Connections\Connection $connection */
            $name = $connection->getName();
            if (isset($listened_names[$name])) {
                continue;
            }
            $connection->listen(function (CommandExecuted $command) {
                foreach ($command->parameters as &$item) {
                    if (is_array($item)) {
                        $item = implode('\', \'', $item);
                    }
                }
                $this->logs .= "[Redis]\t[connection:{$command->connectionName}] Redis::{$command->command}('" . implode('\', \'', $command->parameters) . "') ({$command->time} ms)" . PHP_EOL;
            });
            $listened_names[$name] = $name;
            $new_names[] = $name;
        }
        return $new_names;
    }


    /**
     * 获得Db的Manager
     *
     * @return mixed
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
     * @throws Throwable
     */
    protected function checkDbUncommitTransaction()
    {
        $logs = '';
        foreach ($this->getCapsule()->getDatabaseManager()->getConnections() as $connection) {
            /* @var \Illuminate\Database\MySqlConnection $connection * */
            if (\in_array($connection->getConfig('driver'), ['mysql', 'pgsql', 'sqlite', 'sqlsrv'])) {
                $pdo = $connection->getPdo();
                if ($pdo && $pdo->inTransaction()) {
                    $connection->rollBack();
                    $method = 'error';
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
     * @throws \ReflectionException
     */
    protected function checkTpUncommitTransaction()
    {
        static $reflect, $instance;
        if (!$reflect) {
            $reflect = new \ReflectionClass(\think\facade\Db::class);
            $property = $reflect->getProperty('instance');
            $property->setAccessible(true);
            $instance = $property->getValue();
            $reflect = new \ReflectionClass($property->getValue());
        }
        $property = $reflect->getProperty('instance');
        $property->setAccessible(true);
        $instances = $property->getValue($instance);
        $logs = '';
        foreach ($instances as $connection) {
            /* @var \think\db\connector\Mysql $connection */
            $pdo = $connection->getPdo();
            if ($pdo && $pdo->inTransaction()) {
                $connection->rollBack();
                $method = 'error';
                $logs .= "[ERROR]\tUncommitted transaction found and try to rollback" . PHP_EOL;
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
    protected function shouldntReport($e)
    {
        foreach (config('plugin.webman.log.app.exception.dontReport', []) as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

}

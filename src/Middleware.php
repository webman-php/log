<?php
namespace Webman\Log;

use support\Db;
use support\Log;
use support\Redis;
use think\db\connector\Mysql;
use think\facade\Db as ThinkDb;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;
use Throwable;

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
    public function process(Request $request, callable $next) : Response
    {
        static $initialized_db, $initialized_redis;
        $start_time = microtime(true);
        $logs = $request->getRealIp() . ' ' . $request->method() . ' ' . trim($request->fullUrl(), '/');
        $this->logs = '';
        if (class_exists(ThinkDb::class, false) && class_exists(Mysql::class, false)) {
            ThinkDb::getDbLog(true);
        }
        if (!$initialized_db) {
            $initialized_db = true;
            if (class_exists(QueryExecuted::class)) {
                try {
                    Db::listen(function (QueryExecuted $query) {
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
                } catch (\Throwable $e) {echo $e;}
            }
        }

        if (!$initialized_redis) {
            $initialized_redis = true;
            if (class_exists(CommandExecuted::class)) {
                foreach (config('redis', []) as $key => $config) {
                    if (strpos($key, 'redis-queue') !== false) {
                        continue;
                    }
                    try {
                        Redis::connection($key)->listen(function (CommandExecuted $command) {
                            foreach ($command->parameters as &$item) {
                                if (is_array($item)) {
                                    $item = implode('\', \'', $item);
                                }
                            }
                            $this->logs .= "[Redis]\t[connection:{$command->connectionName}] Redis::{$command->command}('" . implode('\', \'', $command->parameters) . "') ({$command->time} ms)" . PHP_EOL;
                        });
                    } catch (\Throwable $e) {}
                }
            }
        }
        $response = $next($request);
        $time_diff = substr((microtime(true) - $start_time)*1000, 0, 7);
        $logs .= " [{$time_diff}ms] [webman/log]" . PHP_EOL;
        if ($request->method() === 'POST') {
            $logs .= "[POST]\t" . var_export($request->post(), true) . PHP_EOL;
        }
        $logs .= $this->logs;

        if ($loaded_think_db = (class_exists(ThinkDb::class, false) && class_exists(Mysql::class, false))) {
            $sql_logs = ThinkDb::getDbLog(true);
            if (!empty($sql_logs['sql'])) {
                foreach ($sql_logs['sql'] as $sql) {
                    $logs .= "[SQL]\t" . trim($sql) . PHP_EOL;
                }
            }
        }

        $exception = null;
        if (method_exists($response, 'exception')) {
            $exception = $response->exception();
        }

        $method = 'info';

        if ($exception && config('plugin.webman.log.app.exception.enable', true) && !$this->shouldntReport($exception)) {
            $logs .= $exception . PHP_EOL;
            $method = 'error';
        }

        if (class_exists(Connection::class, false)) {
            $pdo = Db::getPdo();
            if ($pdo && $pdo->inTransaction()) {
                Db::rollBack();
                $method = 'error';
                $logs .= "[ERROR]\tUncommitted transactions and rollback" . PHP_EOL;
            }
        }
        if ($loaded_think_db) {
            $pdo = ThinkDb::getPdo();
            if ($pdo && $pdo->inTransaction()) {
                Db::rollBack();
                $method = 'error';
                $logs .= "[ERROR]\tUncommitted transactions and rollback" . PHP_EOL;
            }
        }

        call_user_func([Log::class, $method], $logs);

        return $response;
    }

    /**
     * @param Throwable $e
     * @return bool
     */
    protected function shouldntReport($e) {
        foreach (config('plugin.webman.log.app.exception.dontReport', []) as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }
        return false;
    }

}

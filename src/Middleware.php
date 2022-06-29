<?php
namespace Webman\Log;

use support\Db;
use support\Log;
use support\Redis;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;

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
        static $initialized;
        $start_time = microtime(true);
        $logs = $request->getRealIp() . ' ' . $request->method() . ' ' . trim($request->fullUrl(), '/');
        $this->logs = '';
        if (class_exists(\think\facade\Db::class)) {
            \think\facade\Db::getDbLog(true);
        }
        if (!$initialized) {
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
                        $this->logs .= "[SQL] [connection:{$query->connectionName}] $log [{$query->time} ms]\r\n";
                    });
                } catch (\Throwable $e) {echo $e;}
            }
            if (class_exists(CommandExecuted::class)) {
                foreach (config('redis', []) as $key => $config) {
                    if (strpos($key, 'redis-queue') !== false) {
                        continue;
                    }
                    try {
                        Redis::connection($key)->listen(function (CommandExecuted $command) {
                            $this->logs .= "[Redis] [connection:{$command->connectionName}] Redis::{$command->command}('" . implode('\', \'', $command->parameters) . "') ({$command->time} ms)\r\n";
                        });
                    } catch (\Throwable $e) {}
                }
            }
            $initialized = true;
        }

        $response = $next($request);
        $time_diff = substr((microtime(true) - $start_time)*1000, 0, 7);
        $logs .= " [{$time_diff}ms] [webman/log]\n";
        if ($request->method() === 'POST') {
            $logs .= "[POST] " . var_export($request->post(), true) . "\n";
        }
        $logs .= $this->logs;

        if (class_exists(\think\facade\Db::class)) {
            $sql_logs = \think\facade\Db::getDbLog(true);
            if (!empty($sql_logs['sql'])) {
                foreach ($sql_logs['sql'] as $sql) {
                    $logs .= '[SQL] ' . trim($sql) . "\n";
                }
            }
        }

        if (method_exists($response, 'exception') && $exception = $response->exception()) {
            $logs .= $exception;
            Log::error($logs);
        } else {
            Log::info($logs);
        }

        return $response;
    }
    
}

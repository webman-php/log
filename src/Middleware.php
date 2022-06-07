<?php
namespace Webman\Log;

use Illuminate\Database\Events\QueryExecuted;
use support\Db;
use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class Middleware implements MiddlewareInterface
{
    /**
     * @var string
     */
    public $sqlLogs = '';

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
        $this->sqlLogs = '';
        if (!$initialized) {
            if (class_exists(QueryExecuted::class)) {
                Db::listen(function (QueryExecuted $query) {
                    $sql = trim($query->sql);
                    if (strtolower($sql) === 'select 1') {
                        return;
                    }
                    $sql = str_replace("?", "'%s'", $sql);
                    foreach ($query->bindings as $i => $binding) {
                        if ($binding instanceof \DateTime) {
                            $query->bindings[$i] = $binding->format('\'Y-m-d H:i:s\'');
                        } else {
                            if (is_string($binding)) {
                                $query->bindings[$i] = "'$binding'";
                            }
                        }
                    }
                    $log = vsprintf($sql, $query->bindings);
                    $this->sqlLogs .= "[SQL] $log [" . ($query->time/1000) . "s]\n";
                });
            }
            $initialized = true;
        }

        $response = $next($request);
        $time_diff = substr((microtime(true) - $start_time)*1000, 0, 7);
        $logs .= " [{$time_diff}ms] [webman/log]\n";
        if ($request->method() === 'POST') {
            $logs .= "[POST] " . var_export($request->post(), true) . "\n";
        }
        $logs .= $this->sqlLogs;

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

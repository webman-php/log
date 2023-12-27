<?php
return [
    'enable' => true,
    'exception' => [
        // 是否记录异常到日志
        'enable' => true,
        // 不会记录到日志的异常类
        'dontReport' => [
            support\exception\BusinessException::class
        ],
        //  不会记录到日志的controller路径  app\xxx\controller@action
        'dontPath' =>[
            'app\home\controller\TestController@test',  //屏蔽 test控制器下的test方法
            'app\test\\', //屏蔽整个 test 模块
        ]
    ]
];

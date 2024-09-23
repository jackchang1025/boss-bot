<?php

use App\Bot;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require_once __DIR__ . '/vendor/autoload.php';

// 创建一个日志实例
$logger = new Logger('boss_bot');

// 添加命令行输出处理器
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// 创建 BossBot 实例
$bossBot = new Bot($logger);

$bossBot->handle();
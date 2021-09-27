<?php
require '../vendor/autoload.php';

use Workflow\Engine\Simple;
use Workflow\Storage\Postgres;
use Workflow\Logger\Logger;

$dsn = $_ENV['WORKFLOW_DB_DSN'] ?? null;
if ($dsn === null) {
    throw new RuntimeException("Please set WORKFLOW_DB_DSN variable");
}

$conn = new PDO($dsn);

$storage = Postgres::instance($conn);

$logger = Logger::instance($storage);

$engine = Simple::instance($storage, $logger);

$engine->set_params(10, 3); // 10 cycles of workflows execution, 3 sec between cycles
$engine->run();
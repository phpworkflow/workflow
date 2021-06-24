<?php
require '../vendor/autoload.php';
use Workflow\Engine\Simple;
use Workflow\Storage\Postgres;
use Workflow\Logger\Logger;

use Doctrine\DBAL\DriverManager;

$connectionParams = array(
    'dbname' => 'workflow',
    'user' => 'dbuser',
    'password' => 'dbpassword',
    'host' => 'localhost',
    'port' => 5432,
    'driver' => 'pdo_pgsql',
);
$conn = DriverManager::getConnection($connectionParams);

$storage=Postgres::instance($conn);

$logger=Logger::instance($storage);

$engine=Simple::instance($storage, $logger);

$engine->set_params(10,3); // 10 cycles of workflows execution, 3 sec between cycles
$engine->run();
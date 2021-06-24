<?php
namespace Workflow\Engine;

use Workflow\Engine\Component\IComponent;
use Workflow\Engine\Component\Supplier;
use Workflow\Engine\Component\Worker;
use Workflow\Logger\ILogger;
use Workflow\Storage\IStorage;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server;
use Swoole\Server as TaskServer;
use Swoole\Server\Task;

class Swoole extends AbstractEngine {

    const NUM_TASK_WORKERS = 7;

    public const PORT = 9505;

    public const HOST = '127.0.0.1';

    /**
     * @var Server
     */
    protected Server $server;

    protected $workerId;

    /**
     * @var array
     */
    protected array $taskList = [];

    /**
     * HttpServer constructor.
     */
    public function __construct(IStorage $storage, ILogger $logger)
    {
        $this->server = new Server(self::HOST, self::PORT, SWOOLE_PROCESS, SWOOLE_SOCK_UDP);

        $this->server->set([
            'worker_num' => 1,
            'task_worker_num' => self::NUM_TASK_WORKERS,
            'task_enable_coroutine' => true
        ]);

        $this->server->on('receive', [$this, 'onRequest']);
        $this->server->on('packet', [$this, 'onPacket']);
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('task', [$this, 'onTask']);
        $this->server->on('finish', [$this, 'onFinish']);
        $this->server->on('workerstart', [$this, 'onWorkerstart']);
        $this->server->on('shutdown', [$this, 'onShutdown']);

        parent::__construct($storage, $logger);
    }

    public function run() {
        $this->server->start();
    }

    public function onShutdown() {
        return true;
    }

    protected function getFreeWorkers():int {
        $stats = $this->server->stats();
        return (int)($stats['task_idle_worker_num'] ?? 0);
    }


    protected function executeTask() {
        if(count($this->taskList) > 0) {
            $this->server->task([Worker::class,[
                Supplier::PARAM_TASK_ID => array_shift($this->taskList)
            ]]);
        }
    }

    /**
     * @param Server $server
     */
    public function onStart(Server $server): void
    {
        $this->server->task([
            Supplier::class,
            []
        ]);
    }

    public function onWorkerstart(Server $server, int $worker_id) {
        $this->workerId = $worker_id;

        // We need new DB connection for each worker

        $this->storage=$this->storage->clone();

        return true;
    }

    /**
     * @param TaskServer $server
     * @param Task $task
     */
    public function onTask(TaskServer $server, Task $task): void
    {
        list($class, $params) = $task->data;
        $params[IComponent::PARAM_STORAGE] = $this->storage;
        $params[IComponent::PARAM_SEVRER] = $server;

        $task = new $class($params);
        $task->run();
    }

    public function onFinish( ) {
         $this->executeTask();
    }

    /**
     * @param Request $request
     * @param Response $response
     */
    public function onRequest(Request $request, Response $response)
    {
        $response->status(200);
        $response->end("OK" . PHP_EOL);
    }

    /**
     * @param TaskServer $server
     * @param Response $data
     */
    public function onPacket(Server $server, $data)
    {
        $this->taskList = json_decode($data, true) ?? [];
        $freeWorkerNum = $this->getFreeWorkers();
        while($freeWorkerNum-- > 1) {
            $this->executeTask();
        }
    }


}
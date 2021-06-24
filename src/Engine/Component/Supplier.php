<?php
namespace Workflow\Engine\Component;
use Workflow\Engine\Swoole as Engine;
use Workflow\Storage\IStorage;
use Swoole\Server;

class Supplier implements IComponent
{
    const GET_TASKS_INTERVAL = 5000; // milliseconds

    const CLEANUP_PERIOD = 300000; // milliseconds

    /**
     * @var Server
     */
    protected Server $server;

    /**
     * @var IStorage
     */
    protected IStorage $storage;

    protected bool $isExit = false;

    /**
     * Supplier constructor.
     */
    public function __construct(array $param)
    {
        $this->server = $param[self::PARAM_SEVRER];
        $this->storage = $param[self::PARAM_STORAGE];
    }

    public function run()
    {
        $this->server->tick(self::GET_TASKS_INTERVAL, function () {
            $this->getTasks();
        });

        $this->server->tick(self::CLEANUP_PERIOD, function () {
            $this->storage->cleanup();
        });
    }

    protected function getTasks() {
        if($this->isExit) {
            return;
        }

        $taskList = $this->storage->get_active_workflow_ids();

        if(count($taskList) > 0 ) {
            $data = json_encode($taskList);
            $this->server->sendto(Engine::HOST, Engine::PORT, $data);
        }
    }

}
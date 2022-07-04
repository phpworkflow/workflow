<?php
namespace Workflow\Engine\Component;
use Workflow\Engine\Swoole as Engine;
use Workflow\Storage\IStorage;
use Swoole\Server;

class Supplier implements IComponent
{
    const GET_TASKS_INTERVAL = 5; // Seconds

    const CLEANUP_PERIOD = 300; // Seconds

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
        $cleanupWaitTime = self::CLEANUP_PERIOD;

        do {
            if($cleanupWaitTime >= self::CLEANUP_PERIOD) {
                $cleanupWaitTime=0;
                $this->storage->cleanup();
            }

            $this->getTasks();
            $cleanupWaitTime+=self::GET_TASKS_INTERVAL;
            sleep(self::GET_TASKS_INTERVAL);
        } while(!$this->isExit);
    }

    protected function getTasks() {
        if($this->isExit) {
            return;
        }

        $taskList = $this->storage->get_active_workflow_ids();
        $numTasks = count($taskList);

        if($numTasks > 0 ) {
            $this->storage->store_log("Supplier read $numTasks");
            $data = json_encode($taskList);
            $this->server->sendto(Engine::HOST, Engine::PORT, $data);
        }
    }

}
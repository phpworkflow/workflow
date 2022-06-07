<?php
declare(ticks=1);

namespace Workflow\Engine\Component;

use Workflow\Event;
use Workflow\Storage\IStorage;
use Workflow\Workflow;

class Worker implements IComponent
{
    /**
     * @var IStorage
     */
    protected IStorage $storage;

    /**
     * @var int
     */
    protected int $workflowId;

    /**
     * Worker constructor.
     */
    public function __construct(array $param)
    {
        $this->workflowId = $param[self::PARAM_TASK_ID];
        $this->storage = $param[self::PARAM_STORAGE];
        pcntl_signal(SIGTERM, [$this, "sigHandler"]);
    }

    public function run()
    {
        $this->executeWorkflow($this->workflowId);
        pcntl_signal(SIGTERM, SIG_DFL);
    }

    protected function executeWorkflow($workflowId) {

        $workflow=$this->storage->get_workflow($workflowId);

        if($workflow === null) {
            return; // Workflow is locked
        }

        if(!$workflow->is_finished()) {
            // Function is executed after successful event processing
            $workflow->set_sync_callback(function(Workflow $workflow, Event $event = null) {
                if($event !== null) {
                    $this->storage->close_event($event);
                }
                $this->storage->save_workflow($workflow, false);
            });

            $events=$this->storage->get_events($workflowId);
            $workflow->run($events);
        }
        // Save and unlock workflow
        $this->storage->save_workflow($workflow);

    }

    protected function sigHandler($signo,  $siginfo )
    {
        error_log("Signal $signo arrived. Exiting...");
    }
}
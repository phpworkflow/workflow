<?php
namespace Workflow\Engine;

use Workflow\Logger\ILogger;
use Workflow\Storage\IStorage;
use Workflow\Workflow;
use Workflow\Event;

class Simple extends AbstractEngine {
    private $num_cycles=1;
    private $sleep_time=3;

    public function __construct(IStorage $storage, ILogger $logger)
    {
        parent::__construct($storage, $logger);
        if(!function_exists('pcntl_signal')) {
            $this->logger->info("Graceful exit not supported");
            return;
        }
        
        $this->logger->info("Graceful exit ON");
        pcntl_async_signals(TRUE);
        pcntl_signal(SIGHUP,  [$this, "sigHandler"]);
        pcntl_signal(SIGINT, [$this, "sigHandler"]);
        pcntl_signal(SIGTERM, [$this, "sigHandler"]);
    }

    public function run(array $workflows = []) {
        while($this->num_cycles-- && !$this->exit) {
            $this->execute_workflows($workflows);
            sleep($this->sleep_time);
        }
    }

    private function execute_workflows(array $workflows = []) {
        $this->logger->debug("Start");
        $wf_ids=$workflows ?: $this->storage->get_active_workflow_ids();

        $numTasks = count($wf_ids);

        if($numTasks > 0 ) {
            $this->storage->store_log("Read/recieve $numTasks task(s)");
        }

        foreach($wf_ids as $id) {
            // Lock and get workflow object
            $workflow=$this->storage->get_workflow($id);
            if($workflow === null) {
                $this->logger->error("Workflow: $id was not created");
                continue; // Workflow is locked
            }

            if(!$workflow->is_finished()) {
                // Function is executed after successful event processing
                $workflow->set_sync_callback(function(Workflow $workflow, Event $event = null) {
                    if($event !== null) {
                        $this->storage->close_event($event);
                    }

                    $this->storage->save_workflow($workflow, false);
                });

                $events=$this->storage->get_events($id);
                $workflow->run($events);
            }
            // Save and unlock workflow
            $this->storage->save_workflow($workflow);

            if($this->exit) {
                return;
            }
        }

        $this->storage->cleanup();
        $this->logger->debug("Finish");
    }

    public function set_params($num_cycles, $sleep_time) {
        $this->num_cycles=$num_cycles;
        $this->sleep_time=$sleep_time;
    }

    public function sigHandler($signo)
    {
        $this->logger->info("Signal $signo. Exiting...");
        $this->exit = true;
    }
}
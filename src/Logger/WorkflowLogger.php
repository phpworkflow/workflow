<?php

namespace Workflow\Logger;

use Psr\Log\LoggerInterface;

class WorkflowLogger extends Logger implements LoggerInterface
{
    protected int $workflowId;

    /**
     * Buffer for storing logs
     * @var array
     */
    protected array $buffer = [];

    protected bool $is_batch_logs = false;

    public static function create(int $workflowId, $log_level = self::DEBUG, $log_channel = self::LOG_DATABASE): ILogger
    {
        $logger = new self($log_channel);
        $logger->workflowId = $workflowId;
        $logger->set_log_level($log_level);
        return $logger;
    }

    public function setWorkflowId($workflowId): void {
        $this->workflowId = $workflowId;
    }

    protected function store_log(string $message, int $workflow_id = 0) {
        if($this->is_batch_logs) {
            $this->buffer[] = new Message($message, $workflow_id ?: $this->workflowId);
            return;
        }
        parent::store_log($message, $workflow_id ?: $this->workflowId);
    }

    public function flush_logs(): void
    {
        if(!$this->is_batch_logs || count($this->buffer) === 0) {
            return;
        }
        $this->store_log_array($this->buffer);
        $this->buffer = [];
    }

    public function set_batch_logs(bool $is_batch_logs): void
    {
        $this->is_batch_logs = $is_batch_logs;
    }
}
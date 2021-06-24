<?php

namespace Workflow\Logger;

use Psr\Log\LoggerInterface;

class WorkflowLogger extends Logger implements LoggerInterface
{
    protected int $workflowId;

    public static function create(int $workflowId, $log_channel = self::LOG_DATABASE): ILogger
    {
        return new self($workflowId, $log_channel);
    }

    public function setWorkflowId($workflowId) {
        $this->workflowId = $workflowId;
    }

    protected function __construct(int $workflowId, $log_channel) {
        parent::__construct($log_channel);
        $this->workflowId=$workflowId;
    }

    protected function store_log($message, $workflowId = 0) {
        parent::store_log($message, $this->workflowId);
    }
}
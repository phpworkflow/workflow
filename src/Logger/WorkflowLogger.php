<?php

namespace Workflow\Logger;

use Psr\Log\LoggerInterface;

class WorkflowLogger extends Logger implements LoggerInterface
{
    protected $workflowId;

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

    protected function store_log($message, $workflowId = 0) {
        parent::store_log($message, $workflowId ?: $this->workflowId);
    }
}
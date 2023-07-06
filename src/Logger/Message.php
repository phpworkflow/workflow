<?php

namespace Workflow\Logger;
use DateTime;

class Message
{
    public const TIME_FORMAT = 'Y-m-d H:i:s.u';

    public int $workflow_id;
    public string $created_at;
    public string $log_text;
    public int $pid;
    public string $host;

    /**
     * @param $workflow_id
     * @param $log_text
     */
    public function __construct(string $log_text, int $workflow_id = 0)
    {
        $this->workflow_id = $workflow_id;
        $this->log_text = $log_text;
        $this->created_at = (new DateTime())->format(self::TIME_FORMAT);
        $this->pid = getmypid() ?: 0;
        $this->host = md5(gethostname());
    }

    public function __toString()
    {
        return "{$this->workflow_id} {$this->created_at} {$this->log_text} {$this->pid} {$this->host}";
    }

}
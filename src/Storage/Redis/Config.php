<?php

namespace Workflow\Storage\Redis;

class Config
{
    public function host(): string
    {
        $env = getenv('WF_REDIS_HOST');
        return $env ?: ($_ENV['WF_REDIS_HOST'] ?? 'localhost');
    }

    public function port(): string
    {
        $env = getenv('WF_REDIS_PORT');
        return $env ?: ($_ENV['WF_REDIS_PORT'] ?? '6379');
    }

    public function pass(): string
    {
        $env = getenv('WF_REDIS_PASSWORD');
        return $env ?: ($_ENV['WF_REDIS_PASSWORD'] ?? '');
    }

    public function eventsQueue(): string
    {
        $env = getenv('WF_REDIS_EVENTS_QUEUE');
        return $env ?: ($_ENV['WF_REDIS_EVENTS_QUEUE'] ?? 'wf_events_queue');
    }

    public function scheduleQueue(): string
    {
        $env = getenv('WF_REDIS_SCHEDULE_QUEUE');
        return $env ?: ($_ENV['WF_REDIS_SCHEDULE_QUEUE'] ?? 'wf_schedule_queue');
    }

    public function queueLength(): int
    {
        $env = (int) (getenv('WF_REDIS_EVENTS_QUEUE_LENGTH'));
        return $env ?: ($_ENV['WF_REDIS_EVENTS_QUEUE_LENGTH'] ?? 10000);
    }
}

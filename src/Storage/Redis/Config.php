<?php

namespace Workflow\Storage\Redis;

class Config
{
    public function host(): string
    {
        return getenv('WF_REDIS_HOST') ?? 'localhost';
    }

    public function port(): string
    {
        return getenv('WF_REDIS_PORT') ?? '6379';
    }

    public function pass(): string
    {
        return getenv('WF_REDIS_PASSWORD') ?? '';
    }

    public function eventsQueue(): string
    {
        return getenv('WF_REDIS_EVENTS_QUEUE') ?? '';
    }

    public function scheduleQueue(): string
    {
        return getenv('WF_REDIS_SCHEDULE_QUEUE') ?? '';
    }

    public function queueLength(): int
    {
        return (int) (getenv('WF_REDIS_EVENTS_QUEUE_LENGTH') ?? 10000);
    }
}

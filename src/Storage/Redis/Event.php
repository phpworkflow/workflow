<?php

namespace Workflow\Storage\Redis;

class Event
{
    protected int $workflow_id;

    protected string $created_at;

    protected ?int $scheduled_at = null;

    public function __construct(int $workflow_id, ?int $scheduled_at = null, string $created_at = null)
    {
        $this->workflow_id = $workflow_id;
        $this->scheduled_at = $scheduled_at;
        $this->created_at = $created_at ?? date('Y-m-d H:i:s', time());
    }

    public function toArray(): array
    {
        return [
            'workflow_id' => $this->workflow_id,
            'created_at' => $this->created_at,
            'scheduled_at' => $this->scheduled_at
        ];
    }

    public function fromArray(array $data): self
    {
        $this->workflow_id = $data['workflow_id'] ?? 0;
        $this->created_at = $data['created_at'] ?? '';
        $this->scheduled_at = $data['scheduled_at'] ?? 0;

        return $this;
    }

    public function getWorkflowId(): int
    {
        return $this->workflow_id;
    }

    public function getScheduledAt(): ?int
    {
        return $this->scheduled_at;
    }
}
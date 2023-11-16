<?php

namespace Workflow\Storage\Redis;

class Event
{
    protected int $workflow_id;

    protected ?string $workflow_type;

    protected string $created_at;

    protected ?int $scheduled_at = null;

    public function __construct(int $workflow_id, ?string $workflow_type = null, ?int $scheduled_at = null, string $created_at = null)
    {
        $this->workflow_id = $workflow_id;
        $this->workflow_type = $workflow_type;
        $this->scheduled_at = $scheduled_at;
        $this->created_at = $created_at ?? date('Y-m-d H:i:s', time());
    }

    public function toArray(): array
    {
        return [
            'workflow_id' => $this->workflow_id,
            'workflow_type' => $this->workflow_type,
            'created_at' => $this->created_at,
            'scheduled_at' => $this->scheduled_at
        ];
    }

    public function fromArray(array $data): self
    {
        $this->workflow_id = (int)$data['workflow_id'] ?? $this->workflow_id;
        $this->workflow_type = $data['workflow_type'] ?? $this->workflow_type;
        $this->scheduled_at = (int)$data['scheduled_at'] ?? $this->scheduled_at;
        $this->created_at = $data['created_at'] ?? $this->created_at;

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

    public function getWorkflowType(): ?string
    {
        return $this->workflow_type;
    }
}
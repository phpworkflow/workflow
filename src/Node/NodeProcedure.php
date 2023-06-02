<?php

namespace Workflow\Node;
use Workflow\Workflow;

class NodeProcedure extends Base {
    public const PRIORITY=1;
    public const NODE_PREFIX="proc_";

    public function __construct(array $parameters) {
        parent::__construct($parameters);
    }

    public function execute(Workflow $workflow): int {
        return $this->next_node_id;
    }
}
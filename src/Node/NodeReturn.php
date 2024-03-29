<?php
namespace Workflow\Node;
use Workflow\Workflow;
use Exception;

class NodeReturn extends Base {
    public const PRIORITY=1;
    public const NODE_PREFIX='return';

    public function __construct(array $parameters) {
        parent::__construct($parameters);
    }

    /**
     * @param Workflow $workflow
     * @return int
     * @throws Exception
     */
    public function execute(Workflow $workflow): int {
        $workflow->return_to_saved_node();
        return $workflow->get_current_node_id();
    }

    /**
     * @param string $node_name
     * @return string|null
     */
    public static function get_type_by_name(string $node_name): ?string {

        if(strcmp(self::NODE_PREFIX,$node_name) == 0) {
            return static::class;
        }

        return null;
    }

}
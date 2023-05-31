<?php
namespace Workflow\Node;
use Workflow\Workflow;
use Exception;

class NodeReturn extends Base {
    const PRIORITY=1;
    const NODE_PREFIX='return';
    protected $label;

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
     * @param $node_name
     * @return string|null
     */
    public static function get_type_by_name($node_name) {

        if(strcmp(self::NODE_PREFIX,$node_name) == 0) {
            return static::class;
        }

        return null;
    }

}
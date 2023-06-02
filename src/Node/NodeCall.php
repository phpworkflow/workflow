<?php
namespace Workflow\Node;
use Workflow\Workflow;
use Exception;

class NodeCall extends Base {
    public const PRIORITY=1;
    public const NODE_PREFIX="call_";
    protected $label;

    /**
     * @param array $parameters
     */
    public function __construct(array $parameters) {
        parent::__construct($parameters);
        $this->label=$parameters[INode::P_LABEL];
    }

    /**
     * @param Workflow $workflow
     * @return int
     * @throws Exception
     */
    public function execute(Workflow $workflow): int {
        $workflow->add_to_call_stack($this->next_node_id);
        return $workflow->get_node_id_by_name($this->label);
    }

    /**
     * @param Workflow $workflow
     * @param array $node
     * @return void
     */
    public static function fix_node(Workflow $workflow, array &$node): void {

        // Create label by node name if not exists
        if ( !isset($node[self::P_LABEL]) ) {
            $label=substr($node[self::P_NAME], strpos($node[self::P_NAME],'_')+1);
            $label=NodeProcedure::NODE_PREFIX.$label;

            $node[self::P_LABEL] = $label;
        }
    }

}
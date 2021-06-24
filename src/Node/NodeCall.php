<?php
namespace Workflow\Node;
use Workflow\Workflow;

class NodeCall extends Base {
    const PRIORITY=1;
    const NODE_PREFIX="call_";
    protected $label;

    public function __construct(array &$parameters) {
        parent::__construct($parameters);
        $this->label=$parameters[INode::P_LABEL];
    }

    public function execute(Workflow $wf) {
        $wf->add_to_call_stack($this->next_node_id);
        return $wf->get_node_id_by_name($this->label);
    }

    public static function fix_node(Workflow $workflow, array &$node) {

        // Create label by node name if not exists
        if ( !isset($node[self::P_LABEL]) ) {
            $label=substr($node[self::P_NAME], strpos($node[self::P_NAME],'_')+1);
            $label=NodeProcedure::NODE_PREFIX.$label;

            $node[self::P_LABEL] = $label;
        }
    }

}
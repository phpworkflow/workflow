<?php
namespace Workflow\Node;
use Workflow\Workflow;

class NodeGoto extends Base  {
    const PRIORITY=1;
    const NODE_PREFIX="goto_";

    protected $label;
    protected $one_time=false;
    protected $counter=false;

    public function __construct(array &$parameters) {
        parent::__construct($parameters);

        $this->label=$parameters[INode::P_LABEL];

        if(isset($parameters[INode::P_ONE_TIME])){
            $this->one_time=$parameters[INode::P_ONE_TIME];
        }

        if(isset($parameters[INode::P_COUNTER])){
            $this->counter=$parameters[INode::P_COUNTER];
        }
    }

    public function execute(Workflow $wf) {
        // If no counter we just goto label
        if($this->counter === false ) {
            return $wf->get_node_id_by_name($this->label);
        }

        $counter_name=$this->name.'_'.$this->id;
        // Get counter from WF context
        $counter=$wf->get_counter($counter_name);

        // Check if we not reach counter value
        if($counter < $this->counter) {
            $counter++;
            $wf->set_counter($counter_name, $counter);
            return $wf->get_node_id_by_name($this->label);
        }

        // Counter reaches it maximum, and we should reset it
        if(!$this->one_time) {
            $wf->set_counter($counter_name, 0);
        }

        return $this->next_node_id;

    }

    public static function fix_node(Workflow $workflow, array &$node) {
        // Create label by node name if not exists
        if ( !isset($node[self::P_LABEL]) ) {
            $label=substr($node[self::P_NAME], strpos($node[self::P_NAME],'_')+1);
            $node[self::P_LABEL] = $label;
        }
    }

}
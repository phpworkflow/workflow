<?php
namespace Workflow\Node;

use Exception;
use Workflow\Workflow;

class Validator {
    /** @var INode[] $node_types */
    static private array $node_types=[];
    private int $cnt=1;
    protected array $process_nodes;   // Array with the process description
    private array $list_nodes=[];        // Array for validation process nodes
    protected array $compiled_nodes=[];
    private Workflow $workflow;

    /**
     * @param array $process_nodes
     * @param Workflow $wf
     * @throws Exception
     */
    public function __construct(array $process_nodes, Workflow $wf) {
        $this->process_nodes = $process_nodes ?: [];
        $this->workflow=$wf;
        $this->_load_nodes();
        $this->validate();
    }

    public function get_prepared_nodes(): array {
        return $this->compiled_nodes;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function validate(): void {
        $this->_validate($this->process_nodes);
        $this->_validate_labels($this->process_nodes);
        $this->compiled_nodes[INode::LAST_NODE]=new NodeEnd();
    }

    /**
     * @param $node_arr
     * @param int $parent_next_node
     * @return void
     * @throws Exception
     */
    private function _validate(&$node_arr, int $parent_next_node=INode::LAST_NODE): void {

        // All nodes should have required parameters
        foreach($node_arr as &$node) {
            $this->_fix_node($node); // Create label, method, node type by node name
        }
        unset($node);

        $pointer=0;
        foreach($node_arr as &$node) {

            $this->list_nodes[$node[INode::P_ID]] = $node[INode::P_NAME];

            // Define name of next node
            $next_node_id=$pointer + 1 < (is_countable($node_arr) ? count($node_arr) : 0) ?
                $node_arr[$pointer + 1][INode::P_ID] : $parent_next_node;

            $pointer++;

            // This section is for Selector node because it has
            if ( isset($node[INode::P_THEN]) ) {
                $this->_validate($node[INode::P_THEN], $next_node_id);
                $this->_validate($node[INode::P_ELSE], $next_node_id);
            }

            $node[INode::P_NEXT_NODE]=$next_node_id;
            $this->compiled_nodes[$node[INode::P_ID]]=Base::node_factory($node);

        }
    }

    /**
     * Validate GOTO commands labels
     * @param array $node_arr
     * @return void
     * @throws Exception
     */
    private function _validate_labels(array &$node_arr) {

        foreach($node_arr as &$node) {
            if ( isset($node[INode::P_THEN])) { // Selector
                $this->_validate_labels($node[INode::P_THEN]);
                $this->_validate_labels($node[INode::P_ELSE]);
            }
            // check if GOTO or CALL command has right label
            elseif ( isset($node[INode::P_LABEL]) ) {

                $label=$node[INode::P_LABEL];
                $cnt=0;
                $target_node_id=INode::LAST_NODE;
                foreach($this->list_nodes as $id => $node_name) {
                    if($label == $node_name) {
                        $cnt++;
                        $target_node_id=$id;
                    }
                }

                if($cnt === 0) {
                    throw new Exception("Node in label property does not exist " . print_r($node, true));
                }

                if($cnt > 1) {
                    throw new Exception("Can't define goto target - there are several nodes with the same name $label " . print_r($node, true));
                }

                $node[INode::P_TARGET_ID]=$target_node_id;
            }
        }
    }

    /**
     * @param array $node
     * @return void
     * @throws Exception
     */
    private function _fix_node(array &$node) {
        // If description is without name key
        if(isset($node[0]) && !isset($node[INode::P_NAME])) {
            $node[INode::P_NAME]=$node[0];
            unset($node[0]);
        }

        $node[INode::P_ID]=$this->cnt++;

        // set node type if not present
        if (!isset($node[INode::P_TYPE])) {
            $node[INode::P_TYPE] = $this->_get_type_by_name($node[INode::P_NAME]);
        }

        /** @var INode $node_class */
        $node_class=$node[INode::P_TYPE];
        if(!class_exists($node_class)) {
            throw new Exception("Class of node $node_class does not exists");
        }

        $node_class::fix_node($this->workflow, $node);


    }

    /**
     * @param $node_name
     * @return string
     * @throws Exception
     */
    private function _get_type_by_name($node_name): string {
        foreach(self::$node_types as $node_type) {
            $result=$node_type::get_type_by_name($node_name);

            if($result !== null) {
                return $result;
            }
        }

        throw new Exception("Unknown type of node: $node_name");
    }

    private function _load_nodes(): void {

        if(!empty(self::$node_types)) {
            return;
        }

        $dir = opendir(__DIR__);
        if(empty($dir)) {
            return;
        }

        while( false !== ($file = readdir($dir))) {
            if(preg_match('/^(Node\w+?)\.php/',$file,$match)) {
                require_once($match[0]); // File name
                self::$node_types[]=__NAMESPACE__.'\\'.$match[1]; // Class name from file
            }
        }

        usort(self::$node_types, function($a, $b): int {
            /** @var INode $a */
            $p1=$a::get_priority();
            /** @var INode $b */
            $p2=$b::get_priority();

            if($p1 > $p2) {
                return -1;
            }

            if($p1 < $p2) {
                return 1;
            }

            return 0;
        });
    }
}
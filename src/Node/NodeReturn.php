<?php
namespace Workflow\Node;
use Workflow\Workflow;

class NodeReturn extends Base {
    const PRIORITY=1;
    const NODE_PREFIX='return';
    protected $label;

    public function __construct(array &$parameters) {
        parent::__construct($parameters);
    }

    public function execute(Workflow $wf) {
        $wf->return_to_saved_node();
        return $wf->get_current_node_id();
    }

    public static function get_type_by_name($node_name) {

        if(strcmp(self::NODE_PREFIX,$node_name) == 0) {
            return static::class;
        }

        return false;
    }

}
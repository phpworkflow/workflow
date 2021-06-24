<?php
/**
 * Created by PhpStorm.
 * User: Rus
 * Description: PhpWorkflow TODO: add description
 */


namespace Workflow\Node;

use Workflow\Workflow;
interface INode {

    // ID of the last WF node
    const LAST_NODE = -1;

    // Node parameters definition
    const P_ID='id';
    const P_NAME='name';
    const P_TYPE='type';
    const P_METHOD='method';
    const P_LABEL='label';
    const P_TIMEOUT='timeout';
    const P_TIME='time';
    const P_PARAMS='params';
    const P_THEN='then';
    const P_ELSE='else';
    const P_NEXT_NODE='next_node';
    const P_ONE_TIME='one_time';
    const P_COUNTER='counter';
    const P_TARGET_ID='target_id';

    const OP_NOT='!';

    /**
     * @param Workflow $workflow
     * @return mixed
     */
    public function execute(Workflow $workflow);

    /**
     * @return mixed
     */
    public function get_name();

    /**
     * @return mixed
     */
    public function get_id();

    /**
     * @param Workflow $workflow
     * @param array $node
     * @return void
     */
    public static function fix_node(Workflow $workflow, array &$node);

    /**
     * Define type of the command by command name: if_ - selector,  goto_ - goto, etc
     *
     * @param string $node_name
     * @return int
     */
    public static function get_type_by_name($node_name);

    /**
     * @return mixed
     */
    public static function get_priority();
}
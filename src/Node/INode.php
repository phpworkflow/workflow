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
    public const LAST_NODE = -1;

    // Node parameters definition
    public const P_ID='id';
    public const P_NAME='name';
    public const P_TYPE='type';
    public const P_METHOD='method';
    public const P_LABEL='label';
    public const P_TIMEOUT='timeout';
    public const P_TIME='time';
    public const P_PARAMS='params';
    public const P_THEN='then';
    public const P_ELSE='else';
    public const P_NEXT_NODE='next_node';
    public const P_ONE_TIME='one_time';
    public const P_COUNTER='counter';
    public const P_TARGET_ID='target_id';

    public const OP_NOT='!';

    /**
     * @param Workflow $workflow
     * @return mixed
     */
    public function execute(Workflow $workflow): int;

    /**
     * @return mixed
     */
    public function get_name(): string;

    /**
     * @return mixed
     */
    public function get_id(): int;

    /**
     * @param Workflow $workflow
     * @param array $node
     * @return void
     */
    public static function fix_node(Workflow $workflow, array &$node): void;

    /**
     * Define type of the command by command name: if_ - selector,  goto_ - goto, etc
     *
     * @param string $node_name
     * @return string | null
     */
    public static function get_type_by_name($node_name): ?string;

    /**
     * @return mixed
     */
    public static function get_priority();
}
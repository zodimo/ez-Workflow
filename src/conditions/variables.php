<?php
/**
 * File containing the ezcWorkflowConditionVariables class.
 *
 * @package Workflow
 * @version //autogen//
 * @copyright Copyright (C) 2005-2007 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**
 * Wrapper that applies a condition to two workflow variables.
 *
 * @package Workflow
 * @version //autogen//
 */
class ezcWorkflowConditionVariables implements ezcWorkflowCondition
{
    /**
     * @var string
     */
    protected $variableNameA;

    /**
     * @var string
     */
    protected $variableNameB;

    /**
     * @var string
     */
    protected $ezcWorkflowCondition;

    /**
     * Constructor.
     *
     * @param  string $variableNameA
     * @param  string $variableNameB
     * @param  ezcWorkflowCondition $condition
     * @throws ezcWorkflowInvalidWorkflowException
     */
    public function __construct( $variableNameA, $variableNameB, ezcWorkflowCondition $condition )
    {
        if ( !$condition instanceof ezcWorkflowConditionComparison )
        {
            throw new ezcWorkflowInvalidWorkflowException(
              '$condition is not an instance of ezcWorkflowConditionComparison.'
            );
        }

        $this->variableNameA = $variableNameA;
        $this->variableNameB = $variableNameB;
        $this->condition     = $condition;
    }

    /**
     * Evaluates this condition.
     *
     * @param  mixed $value
     * @return boolean true when the condition holds, false otherwise.
     * @ignore
     */
    public function evaluate( $value )
    {
        if ( is_array( $value ) &&
             isset( $value[$this->variableNameA] ) &&
             isset( $value[$this->variableNameB] ) )
        {
            $this->condition->setValue( $value[$this->variableNameA] );
            return $this->condition->evaluate( $value[$this->variableNameB] );
        }
        else
        {
            return false;
        }
    }

    /**
     * Returns the condition.
     *
     * @return ezcWorkflowCondition
     * @ignore
     */
    public function getCondition()
    {
        return $this->condition;
    }

    /**
     * Returns the names of the variables the condition is evaluated for.
     *
     * @return array
     * @ignore
     */
    public function getVariableNames()
    {
        return array( $this->variableNameA, $this->variableNameB );
    }

    /**
     * Returns a textual representation of this condition.
     *
     * @return string
     * @ignore
     */
    public function __toString()
    {
        return sprintf(
          '%s %s %s',

          $this->variableNameA,
          $this->condition->getOperator(),
          $this->variableNameB
        );
    }
}
?>
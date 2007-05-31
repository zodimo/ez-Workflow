<?php
/**
 * File containing the ezcWorkflowDefinitionStorageXml class.
 *
 * @package Workflow
 * @version //autogen//
 * @copyright Copyright (C) 2005-2007 eZ systems as. All rights reserved.
 * @license http://ez.no/licenses/new_bsd New BSD License
 */

/**
 * XML workflow definition storage handler.
 *
 * The definitions are stored inside the directory specified to the constructor with the name:
 * [workFlowName]_[workFlowVersion].xml where the name of the workflow has dots and spaces
 * replaced by '_'.
 *
 * @todo DTD for the XML file.
 * @package Workflow
 * @version //autogen//
 */
class ezcWorkflowDefinitionStorageXml implements ezcWorkflowDefinitionStorage
{
    /**
     * The directory that holds the XML files.
     *
     * @var string
     */
    protected $directory;

    /**
     * Constructs a new definition loader that loads definitions from $directory.
     *
     * $directory must contain the trailing '/'
     *
     * @param  string $directory The directory that holds the XML files.
     */
    public function __construct( $directory = '' )
    {
        $this->directory = $directory;
    }

    /**
     * Load a workflow definition by name.
     *
     * If the parameter $workflowVersion is omitted the most recent version is loaded.
     *
     * @param  string  $workflowName
     * @param  integer $workflowVersion
     * @return ezcWorkflow
     * @throws ezcWorkflowDefinitionStorageException
     */
    public function loadByName( $workflowName, $workflowVersion = 0 )
    {
        if ( $workflowVersion == 0 )
        {
            // Load the latest version of the workflow definition by default.
            $workflowVersion = $this->getCurrentVersion( $workflowName );
        }

        $filename = $this->getFilename( $workflowName, $workflowVersion );
        $document = @simplexml_load_file( $filename );

        if ( $document === false )
        {
            throw new ezcWorkflowDefinitionStorageException(
              sprintf(
                'Could not load workflow "%s" (version %d) from "%s"',
                $workflowName,
                $workflowVersion,
                $filename
              )
            );
        }

        $nodes  = array();

        // Create node objects.
        foreach ( $document->node as $node )
        {
            $id    = (int)$node['id'];
            $class = 'ezcWorkflowNode' . (string)$node['type'];

            $configuration = '';

            switch ( $class )
            {
                case 'ezcWorkflowNodeAction':
                {
                    $configuration = array(
                      'class' => (string)$node['serviceObjectClass'],
                      'arguments' => array()
                    );

                    $arguments = $node->arguments->children();

                    if ( @count( $arguments ) > 0 )
                    {
                        foreach ( $arguments as $argument )
                        {
                            $configuration['arguments'][] = $this->xmlToVariable( $argument );
                        }
                    }
                }
                break;

                case 'ezcWorkflowNodeInput':
                {
                    $configuration = array();

                    foreach ( $node->variable as $variable )
                    {
                        $configuration[(string)$variable['name']] = $this->xmlToCondition( $variable->condition );
                    }
                }
                break;

                case 'ezcWorkflowNodeSubWorkflow':
                {
                    $configuration = (string)$node['subWorkflowName'];
                }
                break;

                case 'ezcWorkflowNodeVariableSet':
                {
                    $configuration = array();

                    foreach ( $node->variable as $variable )
                    {
                        $children = $variable->children();
                        $configuration[(string)$variable['name']] = $this->xmlToVariable( $children[0] );
                    }
                }
                break;

                case 'ezcWorkflowNodeVariableUnset':
                {
                    $configuration = array();

                    foreach ( $node->variable as $variable )
                    {
                        $configuration[] = (string)$variable['name'];
                    }
                }
                break;

                case 'ezcWorkflowNodeVariableAdd':
                case 'ezcWorkflowNodeVariableSub':
                case 'ezcWorkflowNodeVariableMul':
                case 'ezcWorkflowNodeVariableDiv':
                {
                    $configuration = array(
                      'name' => (string)$node['variable'],
                      'value' => (string)$node['value']
                    );
                }
                break;

                case 'ezcWorkflowNodeVariableIncrement':
                case 'ezcWorkflowNodeVariableDecrement':
                {
                    $configuration = (string)$node['variable'];
                }
                break;
            }

            $nodes[$id] = new $class( $configuration );
            $nodes[$id]->setId( $id );

            if ( $class == 'ezcWorkflowNodeStart' )
            {
                $startNode = $nodes[$id];
            }

            else if ( $class == 'ezcWorkflowNodeEnd' &&
                      !isset( $defaultEndNode ) )
            {
                $defaultEndNode = $nodes[$id];
            }
        }

        // Connect node objects.
        foreach ( $document->node as $node )
        {
            $class = 'ezcWorkflowNode' . (string)$node['type'];
            $id    = (int)$node['id'];

            foreach ( $node->outNode as $outNode )
            {
                $nodes[$id]->addOutNode( $nodes[(int)$outNode['id']] );
            }

            if ( $class == 'ezcWorkflowNodeExclusiveChoice' || $class == 'ezcWorkflowNodeMultiChoice')
            {
                foreach ( $node->condition as $conditionNode )
                {
                    $condition = $this->xmlToCondition( $conditionNode );

                    foreach ( $conditionNode->outNode as $outNode )
                    {
                        $nodes[$id]->addConditionalOutNode(
                          $condition,
                          $nodes[(int)$outNode['id']]
                        );
                    }
                }
            }
        }

        // Create workflow object and add the node objects to it.
        $workflow = new ezcWorkflow( $workflowName, $startNode, $defaultEndNode );
        $workflow->definitionStorage = $this;
        $workflow->version = (int)$workflowVersion;

        // Handle the variable handlers.
        foreach ( $document->variableHandler as $node )
        {
            $workflow->addVariableHandler(
                (string)$node['variable'],
                (string)$node['class']
            );
        }

        // Verify the loaded workflow.
        $workflow->verify();

        return $workflow;
    }

    /**
     * Save a workflow definition.
     *
     * @param  ezcWorkflow $workflow
     * @throws ezcWorkflowDefinitionStorageException
     */
    public function save( ezcWorkflow $workflow )
    {
        $workflowVersion = $this->getCurrentVersion( $workflow->name ) + 1;
        $filename = $this->getFilename( $workflow->name, $workflowVersion );

        $document = new DOMDocument( '1.0', 'UTF-8' );
        $document->formatOutput = true;

        $root = $document->createElement( 'workflow' );
        $document->appendChild( $root );

        $root->setAttribute( 'name', $workflow->name );
        $root->setAttribute( 'version', $workflowVersion );

        $nodes    = $workflow->nodes;
        $numNodes = count( $nodes );

        foreach ( $nodes as $id => $node )
        {
            $nodeClass = get_class( $node );

            $xmlNode = $root->appendChild( $document->createElement( 'node' ) );
            $xmlNode->setAttribute( 'id', $id );
            $xmlNode->setAttribute( 'type', str_replace( 'ezcWorkflowNode', '', $nodeClass ) );

            $configuration = $node->getConfiguration();

            switch ( $nodeClass )
            {
                case 'ezcWorkflowNodeAction':
                {
                    $xmlNode->setAttribute( 'serviceObjectClass', $configuration['class'] );

                    if ( !empty( $configuration['arguments'] ) )
                    {
                        $xmlArguments = $xmlNode->appendChild(
                          $document->createElement( 'arguments' )
                        );

                        foreach ($configuration['arguments'] as $argument )
                        {
                            $xmlArguments->appendChild(
                              $this->variableToXml(
                                $argument,
                                $document
                              )
                            );
                        }

                        $xmlNode->appendChild( $xmlArguments );
                    }
                }
                break;

                case 'ezcWorkflowNodeInput':
                {
                    foreach ( $configuration as $variable => $condition )
                    {
                        $xmlVariable = $xmlNode->appendChild(
                          $document->createElement( 'variable' )
                        );

                        $xmlVariable->setAttribute( 'name', $variable );

                        $xmlCondition = $this->conditionToXml(
                          $condition,
                          $document
                        );

                        $xmlVariable->appendChild( $xmlCondition );
                    }
                }
                break;

                case 'ezcWorkflowNodeSubWorkflow':
                {
                    $xmlNode->setAttribute( 'subWorkflowName', $configuration );
                }
                break;

                case 'ezcWorkflowNodeVariableSet':
                {
                    foreach ( $configuration as $variable => $value )
                    {
                        $xmlVariable = $xmlNode->appendChild(
                          $document->createElement( 'variable' )
                        );

                        $xmlVariable->setAttribute( 'name', $variable );

                        $xmlVariable->appendChild(
                          $this->variableToXml( $value, $document )
                        );
                    }
                }
                break;

                case 'ezcWorkflowNodeVariableUnset':
                {
                    foreach ( $configuration as $variable )
                    {
                        $xmlVariable = $xmlNode->appendChild(
                          $document->createElement( 'variable' )
                        );

                        $xmlVariable->setAttribute( 'name', $variable );
                    }
                }
                break;

                case 'ezcWorkflowNodeVariableAdd':
                case 'ezcWorkflowNodeVariableSub':
                case 'ezcWorkflowNodeVariableMul':
                case 'ezcWorkflowNodeVariableDiv':
                {
                    $xmlNode->setAttribute( 'variable', $configuration['name'] );
                    $xmlNode->setAttribute( 'value', $configuration['value'] );
                }
                break;

                case 'ezcWorkflowNodeVariableIncrement':
                case 'ezcWorkflowNodeVariableDecrement':
                {
                    $xmlNode->setAttribute( 'variable', $configuration );
                }
                break;
            }

            foreach ( $node->getOutNodes() as $outNode )
            {
                foreach ( $nodes as $outNodeId => $_node )
                {
                    if ( $_node === $outNode )
                    {
                        break;
                    }
                }

                $xmlOutNode = $document->createElement( 'outNode' );
                $xmlOutNode->setAttribute( 'id', $outNodeId );

                if ( ( $nodeClass == 'ezcWorkflowNodeExclusiveChoice' ||
                       $nodeClass == 'ezcWorkflowNodeMultiChoice' ) &&
                       $condition = $node->getCondition( $outNode ) )
                {
                    $xmlCondition = $this->conditionToXml(
                      $condition,
                      $document
                    );

                    $xmlCondition->appendChild( $xmlOutNode );
                    $xmlNode->appendChild( $xmlCondition );
                }
                else
                {
                    $xmlNode->appendChild( $xmlOutNode );
                }
            }
        }

        foreach ( $workflow->getVariableHandlers() as $variable => $class )
        {
            $variableHandler = $root->appendChild(
              $document->createElement( 'variableHandler' )
            );

            $variableHandler->setAttribute( 'variable', $variable );
            $variableHandler->setAttribute( 'class', $class );
        }

        file_put_contents( $filename, $document->saveXML() );
    }

    /**
     * "Convert" an ezcWorkflowCondition object into an DOMElement object.
     *
     * @param  ezcWorkflowCondition $condition
     * @param  DOMDocument $document
     * @return DOMElement
     */
    protected function conditionToXml( ezcWorkflowCondition $condition, DOMDocument $document )
    {
        $xmlCondition = $document->createElement( 'condition' );

        $conditionClass = get_class( $condition );
        $conditionType  = str_replace( 'ezcWorkflowCondition', '', $conditionClass );

        $xmlCondition->setAttribute( 'type', $conditionType );

        switch ( $conditionClass )
        {
            case 'ezcWorkflowConditionVariable': {
                $xmlCondition->setAttribute( 'name', $condition->getVariableName() );

                $xmlCondition->appendChild(
                    $this->conditionToXml( $condition->getCondition(), $document )
                );
            }
            break;

            case 'ezcWorkflowConditionAnd':
            case 'ezcWorkflowConditionOr':
            case 'ezcWorkflowConditionXor': {
                foreach ( $condition->getConditions() as $childCondition )
                {
                    $xmlCondition->appendChild(
                      $this->conditionToXml( $childCondition, $document )
                    );
                }
            }
            break;

            case 'ezcWorkflowConditionNot': {
                $xmlCondition->appendChild(
                    $this->conditionToXml( $condition->getCondition(), $document )
                );
            }
            break;

            case 'ezcWorkflowConditionIsEqual':
            case 'ezcWorkflowConditionIsEqualOrGreaterThan':
            case 'ezcWorkflowConditionIsEqualOrLessThan':
            case 'ezcWorkflowConditionIsGreaterThan':
            case 'ezcWorkflowConditionIsLessThan':
            case 'ezcWorkflowConditionIsNotEqual': {
                $xmlCondition->setAttribute( 'value', $condition->getValue() );
            }
            break;
        }

        return $xmlCondition;
    }

    /**
     * "Convert" an SimpleXMLElement object into an ezcWorkflowCondition object.
     *
     * @param  SimpleXMLElement $node
     * @return ezcWorkflowCondition
     */
    protected function xmlToCondition( SimpleXMLElement $node )
    {
        $class = 'ezcWorkflowCondition' . (string)$node['type'];

        switch ( $class )
        {
            case 'ezcWorkflowConditionVariable': {
                return new $class(
                  (string)$node['name'],
                  $this->xmlToCondition( $node->condition )
                );
            }
            break;

            case 'ezcWorkflowConditionAnd':
            case 'ezcWorkflowConditionOr':
            case 'ezcWorkflowConditionXor': {
                $conditions = array();

                foreach ( $node->condition as $condition )
                {
                    $conditions[] = $this->xmlToCondition( $condition );
                }

                return new $class( $conditions );
            }
            break;

            case 'ezcWorkflowConditionNot': {
                return new $class( $this->xmlToCondition( $node->condition ) );
            }
            break;

            case 'ezcWorkflowConditionIsEqual':
            case 'ezcWorkflowConditionIsEqualOrGreaterThan':
            case 'ezcWorkflowConditionIsEqualOrLessThan':
            case 'ezcWorkflowConditionIsGreaterThan':
            case 'ezcWorkflowConditionIsLessThan':
            case 'ezcWorkflowConditionIsNotEqual': {
                $value = (string)$node['value'];

                return new $class( $value );
            }
            break;

            default: {
                return new $class;
            }
            break;
        }
    }

    /**
     * "Convert" a PHP variable into an DOMElement object.
     *
     * @param  mixed $variable
     * @param  DOMDocument $document
     * @return DOMElement
     */
    protected function variableToXml( $variable, DOMDocument $document )
    {
        if ( is_array( $variable ) )
        {
            $xmlResult = $document->createElement( 'array' );

            foreach ($variable as $key => $value )
            {
                $element = $document->createElement( 'element' );
                $element->setAttribute( 'key', $key );
                $element->appendChild( $this->variableToXml( $value, $document ) );

                $xmlResult->appendChild( $element );
            }
        }

        if ( is_object( $variable ) )
        {
            $xmlResult = $document->createElement( 'object' );
            $xmlResult->setAttribute( 'class', get_class( $variable ) );
        }

        if ( is_null( $variable ) )
        {
            $xmlResult = $document->createElement( 'null' );
        }

        if ( is_scalar( $variable ) )
        {
            $type = gettype( $variable );

            if ( is_bool( $variable ) )
            {
                $variable = $variable === true ? 'true' : 'false';
            }

            $xmlResult = $document->createElement( $type, $variable );
        }

        return $xmlResult;
    }

    /**
     * "Convert" an SimpleXMLElement object into a PHP variable.
     *
     * @param  SimpleXMLElement $node
     * @return mixed
     */
    protected function xmlToVariable( SimpleXMLElement $node )
    {
        $type     = $node->getName();
        $variable = null;

        switch ( $type )
        {
            case 'array': {
                $variable = array();

                foreach ( $node->element as $element )
                {
                    $children = $element->children();
                    $variable[(string)$element['key']] = $this->xmlToVariable( $children[0] );
                }
            }
            break;

            case 'object': {
                $className = (string)$node['class'];

                $arguments       = $node->arguments->children();
                $constructorArgs = array();

                if ( @count( $arguments ) > 0 )
                {
                    foreach ( $arguments as $argument )
                    {
                        $constructorArgs[] = $this->xmlToVariable( $argument );
                    }

                    $class = new ReflectionClass( $className );

                    $variable = $class->newInstanceArgs( $constructorArgs );
                    
                }
                else
                {
                    $variable = new $className;
                }
            }
            break;

            case 'boolean': {
                $variable = (string)$node == 'true' ? true : false;
            }
            break;

            case 'integer':
            case 'double':
            case 'string': {
                $variable = (string)$node;

                settype( $variable, $type );
            }
            break;
        }

        return $variable;
    }

    /**
     * Returns the current version number for a given workflow name.
     *
     * @param  string $workflowName
     * @return integer
     */
    protected function getCurrentVersion( $workflowName )
    {
        $workflowName = $this->getFilesystemWorkflowName( $workflowName );
        $files = glob( $this->directory . $workflowName . '_*.xml' );

        if ( !empty( $files ) )
        {
            return str_replace(
              array(
                $this->directory . $workflowName . '_',
                '.xml'
              ),
              '',
              $files[count( $files ) - 1]
            );
        }
        else
        {
            return 0;
        }
    }

    /**
     * Returns the filename with path for given workflow name and version.
     *
     * The name of the workflow file is of the format [workFlowName]_[workFlowVersion].xml
     *
     * @param  string  $workflowName
     * @param  integer $workflowVersion
     * @return string
     */
    protected function getFilename( $workflowName, $workflowVersion )
    {
        return sprintf(
          '%s%s_%d.xml',

          $this->directory,
          $this->getFilesystemWorkflowName( $workflowName ),
          $workflowVersion
        );
    }

    /**
     * Returns a safe filesystem name for a given workflow.
     *
     * This method replaces whitespace and '.' with '_'.
     *
     * @param  string $workflowName
     * @return string
     */
    protected function getFilesystemWorkflowName( $workflowName )
    {
        return preg_replace( '#[^\w.]#', '_', $workflowName );
    }

}
?>
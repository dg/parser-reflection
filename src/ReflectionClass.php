<?php
/**
 * Parser Reflection API
 *
 * @copyright Copyright 2015, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\ParserReflection;

use Go\ParserReflection\Traits\InternalPropertiesEmulationTrait;
use Go\ParserReflection\Traits\ReflectionClassLikeTrait;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\TraitUse;
use ReflectionClass as InternalReflectionClass;

/**
 * AST-based reflection class
 */
class ReflectionClass extends InternalReflectionClass
{
    use ReflectionClassLikeTrait, InternalPropertiesEmulationTrait;

    /**
     * Initializes reflection instance
     *
     * @param string|object $argument Class name or instance of object
     * @param ClassLike $classLikeNode AST node for class
     * @param ReflectionContext $context AST parser
     */
    public function __construct($argument, ClassLike $classLikeNode = null, ReflectionContext $context = null)
    {
        $fullClassName       = is_object($argument) ? get_class($argument) : $argument;
        $namespaceParts      = explode('\\', $fullClassName);
        $this->className     = array_pop($namespaceParts);
        // Let's unset original read-only property to have a control over it via __get
        unset($this->name);

        $this->namespaceName = join('\\', $namespaceParts);
        $this->context       = $context ?: ReflectionEngine::getReflectionContext();

        $this->classLikeNode = $classLikeNode ?: $this->context->parseClass($fullClassName);
    }

    /**
     * Parses interfaces from the concrete class node
     *
     * @param ClassLike $classLikeNode Class-like node
     * @param ReflectionContext $context AST parser
     *
     * @return array|\ReflectionClass[] List of reflections of interfaces
     */
    public static function collectInterfacesFromClassNode(ClassLike $classLikeNode, ReflectionContext $context)
    {
        $interfaces = [];

        $isInterface    = $classLikeNode instanceof Interface_;
        $interfaceField = $isInterface ? 'extends' : 'implements';
        $hasInterfaces  = in_array($interfaceField, $classLikeNode->getSubNodeNames());
        $implementsList = $hasInterfaces ? $classLikeNode->$interfaceField : [];
        if ($implementsList) {
            foreach ($implementsList as $implementNode) {
                if ($implementNode instanceof FullyQualified) {
                    $implementName  = $implementNode->toString();
                    $interface      = $context->getClassReflection($implementName);
                    $interfaces[$implementName] = $interface;
                }
            }
        }

        return $interfaces;
    }

    /**
     * Parses traits from the concrete class node
     *
     * @param ClassLike $classLikeNode Class-like node
     * @param array     $traitAdaptations List of method adaptations
     * @param ReflectionContext $context AST parser
     *
     * @return array|\ReflectionClass[] List of reflections of traits
     */
    public static function collectTraitsFromClassNode(ClassLike $classLikeNode, array &$traitAdaptations, ReflectionContext $context)
    {
        $traits = [];

        if (!empty($classLikeNode->stmts)) {
            foreach ($classLikeNode->stmts as $classLevelNode) {
                if ($classLevelNode instanceof TraitUse) {
                    foreach ($classLevelNode->traits as $classTraitName) {
                        if ($classTraitName instanceof FullyQualified) {
                            $traitName          = $classTraitName->toString();
                            $trait              = $context->getClassReflection($traitName);
                            $traits[$traitName] = $trait;
                        }
                    }
                    $traitAdaptations = $classLevelNode->adaptations;
                }
            }
        }

        return $traits;
    }

    /**
     * Emulating original behaviour of reflection
     */
    public function ___debugInfo()
    {
        return [
            'name' => $this->getName()
        ];
    }

    /**
     * Implementation of internal reflection initialization
     *
     * @return void
     */
    protected function __initialize()
    {
        parent::__construct($this->getName());
    }

    /**
     * Create a ReflectionClass for a given class name.
     *
     * @param string $className
     *     The name of the class to create a reflection for.
     *
     * @return ReflectionClass
     *     The apropriate reflection object.
     */
    protected function createReflectionForClass($className)
    {
        return class_exists($className, false) ? new parent($className) : new static($className);
    }
}

<?php
/**
 * Parser Reflection API
 *
 * @copyright Copyright 2015, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\ParserReflection\NodeVisitor;

use Go\ParserReflection\ReflectionEngine;
use Go\ParserReflection\ReflectionContext;
use Go\ParserReflection\ValueResolver\NodeExpressionResolver;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor to collect static variables in the method/function body and resove them
 */
class StaticVariablesCollector extends NodeVisitorAbstract
{
    /**
     * Reflection subject, eg. ReflectionClass, ReflectionMethod, etc
     *
     * @var mixed
     */
    private $subject;

    /**
     * @var ReflectionContext
     */
    private $context;

    private $staticVariables = [];

    /**
     * Default constructor
     *
     * @param mixed $subject Reflection subject, eg. ReflectionClass, ReflectionMethod, etc
     * @param ReflectionContext $context AST parser
     */
    public function __construct($subject, ReflectionContext $context = NULL)
    {
        $this->subject = $subject;
        $this->context = $context ?: ReflectionEngine::getReflectionContext();
    }

    /**
     * {@inheritDoc}
     */
    public function enterNode(Node $node)
    {
        // There may be internal closures, we do not need to look at them
        if ($node instanceof Node\Expr\Closure) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Node\Stmt\Static_) {
            $expressionSolver = new NodeExpressionResolver($this->subject, $this->context);
            $staticVariables  = $node->vars;
            foreach ($staticVariables as $staticVariable) {
                $expr = $staticVariable->default;
                if ($expr) {
                    $expressionSolver->process($expr);
                    $value = $expressionSolver->getValue();
                } else {
                    $value = null;
                }

                $this->staticVariables[$staticVariable->name] = $value;
            }
        }

        return null;
    }

    /**
     * Returns an associative map of static variables in the method/function body
     *
     * @return array
     */
    public function getStaticVariables()
    {
        return $this->staticVariables;
    }
}

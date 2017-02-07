<?php
/**
 * Parser Reflection API
 *
 * @copyright Copyright 2015, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\ParserReflection\ValueResolver;

use Go\ParserReflection\ReflectionClass;
use Go\ParserReflection\ReflectionContext;
use Go\ParserReflection\ReflectionException;
use Go\ParserReflection\ReflectionFileNamespace;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Scalar\MagicConst;

/**
 * Tries to resolve expression into value
 */
class NodeExpressionResolver
{

    /**
     * List of exception for constant fetch
     *
     * @var array
     */
    private static $notConstants = [
        'true'  => true,
        'false' => true,
        'null'  => true,
    ];

    /**
     * Name of the constant (if present)
     *
     * @var null|string
     */
    private $constantName = null;

    /**
     * Current reflection subject for parsing
     *
     * @var mixed|\Go\ParserReflection\ReflectionClass
     */
    private $subject;

    /**
     * @var ReflectionContext
     */
    private $context;

    /**
     * Flag if expression is constant
     *
     * @var bool
     */
    private $isConstant = false;

    /**
     * Node resolving level, 1 = top-level
     *
     * @var int
     */
    private $nodeLevel = 0;

    /**
     * @var mixed Value of expression/constant
     */
    private $value;

    public function __construct($subject, ReflectionContext $context = null)
    {
        $this->subject = $subject;
        $this->context = $context;
    }

    public function getConstantName()
    {
        return $this->constantName;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function isConstant()
    {
        return $this->isConstant;
    }

    /**
     * {@inheritDoc}
     */
    public function process(Node $node)
    {
        $this->nodeLevel    = 0;
        $this->isConstant   = false;
        $this->constantName = null;
        $this->value        = $this->resolve($node);
    }

    /**
     * Resolves node into valid value
     *
     * @param Node $node
     *
     * @return mixed
     */
    protected function resolve(Node $node)
    {
        $value = null;
        try {
            ++$this->nodeLevel;

            $nodeType   = $node->getType();
            $methodName = 'resolve' . str_replace('_', '', $nodeType);
            if (method_exists($this, $methodName)) {
                $value = $this->$methodName($node);
            }
        } finally {
            --$this->nodeLevel;
        }

        return $value;
    }

    protected function resolveScalarDNumber(Scalar\DNumber $node)
    {
        return $node->value;
    }

    protected function resolveScalarLNumber(Scalar\LNumber $node)
    {
        return $node->value;
    }

    protected function resolveScalarString(Scalar\String_ $node)
    {
        return $node->value;
    }

    protected function resolveScalarMagicConstMethod()
    {
        if ($this->subject instanceof \ReflectionMethod) {
            $fullName = $this->subject->getDeclaringClass()->getName() . '::' . $this->subject->getShortName();

            return $fullName;
        }

        return '';
    }

    protected function resolveScalarMagicConstFunction()
    {
        if ($this->subject instanceof \ReflectionFunctionAbstract) {
            return $this->subject->getName();
        }

        return '';
    }

    protected function resolveScalarMagicConstNamespace()
    {
        if (method_exists($this->subject, 'getNamespaceName')) {
            return $this->subject->getNamespaceName();
        }

        if ($this->subject instanceof ReflectionFileNamespace) {
            return $this->subject->getName();
        }

        return '';
    }

    protected function resolveScalarMagicConstClass()
    {
        if ($this->subject instanceof \ReflectionClass) {
            return $this->subject->getName();
        }
        if (method_exists($this->subject, 'getDeclaringClass')) {
            $declaringClass = $this->subject->getDeclaringClass();
            if ($declaringClass instanceof \ReflectionClass) {
                return $declaringClass->getName();
            }
        }

        return '';
    }

    protected function resolveScalarMagicConstDir()
    {
        if (method_exists($this->subject, 'getFileName')) {
            return dirname($this->subject->getFileName());
        }

        return '';
    }

    protected function resolveScalarMagicConstFile()
    {
        if (method_exists($this->subject, 'getFileName')) {
            return $this->subject->getFileName();
        }

        return '';
    }

    protected function resolveScalarMagicConstLine(MagicConst\Line $node)
    {
        return $node->hasAttribute('startLine') ? $node->getAttribute('startLine') : 0;
    }

    protected function resolveScalarMagicConstTrait()
    {
        if ($this->subject instanceof \ReflectionClass && $this->subject->isTrait()) {
            return $this->subject->getName();
        }

        return '';
    }

    protected function resolveExprConstFetch(Expr\ConstFetch $node)
    {
        $constantValue = null;
        $isResolved    = false;

        /** @var ReflectionFileNamespace|null $fileNamespace */
        $fileNamespace = null;
        $isFQNConstant = $node->name instanceof Node\Name\FullyQualified;
        $constantName  = $node->name->toString();

        if (!$isFQNConstant) {
            if (method_exists($this->subject, 'getFileName')) {
                $fileName      = $this->subject->getFileName();
                $namespaceName = $this->resolveScalarMagicConstNamespace();
                $fileNamespace = new ReflectionFileNamespace($fileName, $namespaceName, null, $this->context);
                if ($fileNamespace->hasConstant($constantName)) {
                    $constantValue = $fileNamespace->getConstant($constantName);
                    $constantName  = $fileNamespace->getName() . '\\' . $constantName;
                    $isResolved    = true;
                }
            }
        }

        if (!$isResolved && defined($constantName)) {
            $constantValue = constant($constantName);
        }

        if ($this->nodeLevel === 1 && !isset(self::$notConstants[$constantName])) {
            $this->isConstant   = true;
            $this->constantName = $constantName;
        }

        return $constantValue;
    }

    protected function resolveExprClassConstFetch(Expr\ClassConstFetch $node)
    {
        $refClass     = $this->fetchReflectionClass($node->class);
        $constantName = $node->name;

        // special handling of ::class constants
        if ('class' === $constantName) {
            return $refClass->getName();
        }

        $this->isConstant = true;
        $this->constantName = (string)$node->class . '::' . $constantName;

        return $refClass->getConstant($constantName);
    }

    protected function resolveExprArray(Expr\Array_ $node)
    {
        $result = [];
        foreach ($node->items as $itemIndex => $arrayItem) {
            $itemValue = $this->resolve($arrayItem->value);
            $itemKey   = isset($arrayItem->key) ? $this->resolve($arrayItem->key) : $itemIndex;
            $result[$itemKey] = $itemValue;
        }

        return $result;
    }

    protected function resolveExprBinaryOpPlus(Expr\BinaryOp\Plus $node)
    {
        return $this->resolve($node->left) + $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpMinus(Expr\BinaryOp\Minus $node)
    {
        return $this->resolve($node->left) - $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpMul(Expr\BinaryOp\Mul $node)
    {
        return $this->resolve($node->left) * $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpPow(Expr\BinaryOp\Pow $node)
    {
        return pow($this->resolve($node->left), $this->resolve($node->right));
    }

    protected function resolveExprBinaryOpDiv(Expr\BinaryOp\Div $node)
    {
        return $this->resolve($node->left) / $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpMod(Expr\BinaryOp\Mod $node)
    {
        return $this->resolve($node->left) % $this->resolve($node->right);
    }

    protected function resolveExprBooleanNot(Expr\BooleanNot $node)
    {
        return !$this->resolve($node->expr);
    }

    protected function resolveExprBitwiseNot(Expr\BitwiseNot $node)
    {
        return ~$this->resolve($node->expr);
    }

    protected function resolveExprBinaryOpBitwiseOr(Expr\BinaryOp\BitwiseOr $node)
    {
        return $this->resolve($node->left) | $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpBitwiseAnd(Expr\BinaryOp\BitwiseAnd $node)
    {
        return $this->resolve($node->left) & $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpBitwiseXor(Expr\BinaryOp\BitwiseXor $node)
    {
        return $this->resolve($node->left) ^ $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpShiftLeft(Expr\BinaryOp\ShiftLeft $node)
    {
        return $this->resolve($node->left) << $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpShiftRight(Expr\BinaryOp\ShiftRight $node)
    {
        return $this->resolve($node->left) >> $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpConcat(Expr\BinaryOp\Concat $node)
    {
        return $this->resolve($node->left) . $this->resolve($node->right);
    }

    protected function resolveExprTernary(Expr\Ternary $node)
    {
        if (isset($node->if)) {
            // Full syntax $a ? $b : $c;

            return $this->resolve($node->cond) ? $this->resolve($node->if) : $this->resolve($node->else);
        } else {
            // Short syntax $a ?: $c;

            return $this->resolve($node->cond) ?: $this->resolve($node->else);
        }
    }

    protected function resolveExprBinaryOpSmallerOrEqual(Expr\BinaryOp\SmallerOrEqual $node)
    {
        return $this->resolve($node->left) <= $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpGreaterOrEqual(Expr\BinaryOp\GreaterOrEqual $node)
    {
        return $this->resolve($node->left) >= $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpEqual(Expr\BinaryOp\Equal $node)
    {
        return $this->resolve($node->left) == $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpNotEqual(Expr\BinaryOp\NotEqual $node)
    {
        return $this->resolve($node->left) != $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpSmaller(Expr\BinaryOp\Smaller $node)
    {
        return $this->resolve($node->left) < $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpGreater(Expr\BinaryOp\Greater $node)
    {
        return $this->resolve($node->left) > $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpIdentical(Expr\BinaryOp\Identical $node)
    {
        return $this->resolve($node->left) === $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpNotIdentical(Expr\BinaryOp\NotIdentical $node)
    {
        return $this->resolve($node->left) !== $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpBooleanAnd(Expr\BinaryOp\BooleanAnd $node)
    {
        return $this->resolve($node->left) && $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpLogicalAnd(Expr\BinaryOp\LogicalAnd $node)
    {
        return $this->resolve($node->left) and $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpBooleanOr(Expr\BinaryOp\BooleanOr $node)
    {
        return $this->resolve($node->left) || $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpLogicalOr(Expr\BinaryOp\LogicalOr $node)
    {
        return $this->resolve($node->left) or $this->resolve($node->right);
    }

    protected function resolveExprBinaryOpLogicalXor(Expr\BinaryOp\LogicalXor $node)
    {
        return $this->resolve($node->left) xor $this->resolve($node->right);
    }

    /**
     * Utility method to fetch reflection class instance by name
     *
     * Supports:
     *   'self' keyword
     *   'parent' keyword
     *    not-FQN class names
     *
     * @param Node\Name $node Class name node
     *
     * @return bool|\ReflectionClass
     *
     * @throws ReflectionException
     */
    private function fetchReflectionClass(Node\Name $node)
    {
        $className  = $node->toString();
        $isFQNClass = $node instanceof Node\Name\FullyQualified;
        if ($isFQNClass) {
            // check to see if the class is already loaded and is safe to use
            // PHP's ReflectionClass to determine if the class is user defined
            if (class_exists($className, false)) {
                $refClass = new \ReflectionClass($className);
                if (!$refClass->isUserDefined()) {
                    return $refClass;
                }
            }
            return new ReflectionClass($className);
        }

        if ('self' === $className) {
            if ($this->subject instanceof \ReflectionClass) {
                return $this->subject;
            } elseif (method_exists($this->subject, 'getDeclaringClass')) {
                return $this->subject->getDeclaringClass();
            }
        }

        if ('parent' === $className) {
            if ($this->subject instanceof \ReflectionClass) {
                return $this->subject->getParentClass();
            } elseif (method_exists($this->subject, 'getDeclaringClass')) {
                return $this->subject->getDeclaringClass()->getParentClass();
            }
        }

        if (method_exists($this->subject, 'getFileName')) {
            /** @var ReflectionFileNamespace|null $fileNamespace */
            $fileName      = $this->subject->getFileName();
            $namespaceName = $this->resolveScalarMagicConstNamespace();

            $fileNamespace = new ReflectionFileNamespace($fileName, $namespaceName, null, $this->context);
            return $fileNamespace->getClass($className);
        }

        throw new ReflectionException("Can not resolve class $className");
    }
}

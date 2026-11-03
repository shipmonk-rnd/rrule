<?php declare(strict_types = 1);

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Expression;
use ShipMonk\CoverageGuard\Config;
use ShipMonk\CoverageGuard\Excluder\ExcludedLineRange;
use ShipMonk\CoverageGuard\Excluder\ExclusionContext;
use ShipMonk\CoverageGuard\Excluder\ExecutableLineExcluder;
use ShipMonk\CoverageGuard\Excluder\IgnoreThrowNewExceptionLineExcluder;
use ShipMonk\CoverageGuard\Rule\EnforceCoverageForMethodsRule;

$config = new Config();

// Every method must be fully covered, except for code that throws LogicException: valid states never reach it.
$config->addExecutableLineExcluder(new IgnoreThrowNewExceptionLineExcluder([LogicException::class]));

// A catch block whose only statement throws LogicException turns an impossible checked exception into one.
$config->addExecutableLineExcluder(new class implements ExecutableLineExcluder {

    public function getExcludedLineRange(
        Node $node,
        ExclusionContext $context,
    ): ?ExcludedLineRange
    {
        if (!$node instanceof Catch_ || count($node->stmts) !== 1) {
            return null;
        }

        $statement = reset($node->stmts);

        if (
            $statement instanceof Expression
            && $statement->expr instanceof Throw_
            && $statement->expr->expr instanceof New_
            && $statement->expr->expr->class instanceof Name
            && $statement->expr->expr->class->toString() === LogicException::class
        ) {
            return new ExcludedLineRange($node->getStartLine(), $node->getEndLine());
        }

        return null;
    }

});
$config->addRule(new EnforceCoverageForMethodsRule(requiredCoveragePercentage: 100));

return $config;

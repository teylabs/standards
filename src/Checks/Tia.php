<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final readonly class Tia
{
    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $path = 'tests/Pest.php';
        if (is_file($this->config->base.'/'.$path)) {
            $nodes = (new Source($this->config))->nodes($path);
            $argv = null;
            foreach ($nodes as $node) {
                if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign) {
                    $assignment = $node->expr;
                    $value = $assignment->expr;
                    if ($assignment->var instanceof Node\Expr\Variable && is_string($assignment->var->name)
                        && $value instanceof Node\Expr\BinaryOp\Coalesce && $value->left instanceof Node\Expr\ArrayDimFetch
                        && $value->left->var instanceof Node\Expr\Variable && $value->left->var->name === '_SERVER'
                        && $value->left->dim instanceof Node\Scalar\String_ && $value->left->dim->value === 'argv'
                        && $value->right instanceof Node\Expr\Array_ && $value->right->items === []) {
                        $argv = $assignment->var->name;
                    }
                }

                if (! $node instanceof Node\Stmt\If_ || $argv === null) {
                    continue;
                }

                $predicates = $this->predicates($node->cond, $argv);
                sort($predicates);
                $expected = ['--no-tia', '--tia', 'CI', 'PARATEST', 'PEST_TIA'];
                sort($expected);
                $exits = array_map(fn (Node\Stmt\Expression $statement): Node\Expr => $statement->expr, array_values(array_filter($node->stmts, fn (Node $statement): bool => $statement instanceof Node\Stmt\Expression)));
                if ($predicates === $expected && array_any($exits, fn (Node\Expr $exit): bool => $exit instanceof Node\Expr\Exit_ && $exit->expr instanceof Node\Scalar\Int_ && $exit->expr->value > 0 && $exit->expr->value < 256)) {
                    return [];
                }
            }
        }

        return [['target' => $path, 'check' => "missing local --tia/--no-tia guard with CI, PARATEST, PEST_TIA bypasses and non-zero exit; check CI as non-empty: (getenv('CI') === false || getenv('CI') === '')"]];
    }

    /** @return list<string> */
    private function predicates(Node\Expr $expression, string $argv): array
    {
        if ($expression instanceof Node\Expr\BinaryOp\BooleanAnd || $expression instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return [...$this->predicates($expression->left, $argv), ...$this->predicates($expression->right, $argv)];
        }

        // A set-but-empty CI= is not CI: accept (getenv('CI') === false || getenv('CI') === '')
        // and its negation ! (getenv('CI') !== false && getenv('CI') !== '').
        if ($expression instanceof Node\Expr\BinaryOp\BooleanOr || $expression instanceof Node\Expr\BinaryOp\LogicalOr) {
            $sides = [$this->ciComparison($expression->left, Node\Expr\BinaryOp\Identical::class), $this->ciComparison($expression->right, Node\Expr\BinaryOp\Identical::class)];
            sort($sides);

            return $sides === ['empty', 'false'] ? ['CI'] : ['unrecognised guard condition'];
        }

        if ($expression instanceof Node\Expr\BooleanNot && ($expression->expr instanceof Node\Expr\BinaryOp\BooleanAnd || $expression->expr instanceof Node\Expr\BinaryOp\LogicalAnd)) {
            $sides = [$this->ciComparison($expression->expr->left, Node\Expr\BinaryOp\NotIdentical::class), $this->ciComparison($expression->expr->right, Node\Expr\BinaryOp\NotIdentical::class)];
            sort($sides);

            return $sides === ['empty', 'false'] ? ['CI'] : ['unrecognised guard condition'];
        }

        if ($expression instanceof Node\Expr\BinaryOp\Identical) {
            foreach ([[$expression->left, $expression->right], [$expression->right, $expression->left]] as [$call, $value]) {
                if ($value instanceof Node\Expr\ConstFetch && strtolower($value->name->toString()) === 'false'
                    && $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && $call->name->toString() === 'getenv') {
                    $argument = $call->getArgs()[0]->value ?? null;
                    if ($argument instanceof Node\Scalar\String_ && in_array($argument->value, ['CI', 'PARATEST', 'PEST_TIA'], true)) {
                        return [$argument->value];
                    }
                }
            }
        }

        if ($expression instanceof Node\Expr\BooleanNot && $expression->expr instanceof Node\Expr\FuncCall) {
            $call = $expression->expr;
            $argument = $call->getArgs()[0]->value ?? null;
            $haystack = $call->getArgs()[1]->value ?? null;
            $strict = $call->getArgs()[2]->value ?? null;
            if ($haystack instanceof Node\Expr\Variable && $haystack->name === $argv
                && $strict instanceof Node\Expr\ConstFetch && strtolower($strict->name->toString()) === 'true'
                && $call->name instanceof Node\Name && $call->name->toString() === 'in_array' && $argument instanceof Node\Scalar\String_
                && in_array($argument->value, ['--tia', '--no-tia'], true)) {
                return [$argument->value];
            }
        }

        return ['unrecognised guard condition'];
    }

    /**
     * 'false' or 'empty' when $expression compares getenv('CI') with false or '' using $operator.
     *
     * @param  class-string<Node\Expr\BinaryOp>  $operator
     */
    private function ciComparison(Node\Expr $expression, string $operator): ?string
    {
        if (! $expression instanceof $operator) {
            return null;
        }

        foreach ([[$expression->left, $expression->right], [$expression->right, $expression->left]] as [$call, $value]) {
            $argument = $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && $call->name->toString() === 'getenv' ? ($call->getArgs()[0]->value ?? null) : null;

            if (! $argument instanceof Node\Scalar\String_ || $argument->value !== 'CI') {
                continue;
            }

            if ($value instanceof Node\Expr\ConstFetch && strtolower($value->name->toString()) === 'false') {
                return 'false';
            }

            if ($value instanceof Node\Scalar\String_ && $value->value === '') {
                return 'empty';
            }
        }

        return null;
    }
}

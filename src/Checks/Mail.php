<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final class Mail
{
    public function __construct(private readonly Configuration $config, private readonly Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        $finder = new NodeFinder;
        $parents = [];
        $classes = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            $nodes = $this->source->nodes($path);
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
                if ($class->extends !== null) {
                    $name = isset($class->namespacedName) ? $class->namespacedName->toString() : $path.':'.$class->getStartLine();
                    $parents[$name] = $class->extends->toString();
                    $classes[$name] = $path;
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
                if ($call->class instanceof Node\Name && $call->class->toString() === 'Illuminate\Support\Facades\Mail'
                    && $call->name instanceof Node\Identifier && in_array(strtolower($call->name->toString()), ['to', 'send', 'raw', 'queue', 'later', 'cc', 'bcc', 'html', 'plain'], true)) {
                    $violations[] = ['target' => $path, 'check' => 'uses the Mail facade'];
                }
            }
            if ($this->config->mailComposer === null) {
                continue;
            }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
                if (strtolower($method->name->toString()) !== 'tomail') {
                    continue;
                }
                $composer = false;
                $stock = false;
                foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Name::class) as $name) {
                    $composer = $composer || $name->toString() === ltrim($this->config->mailComposer, '\\');
                    $stock = $stock || ($name->toString() === 'Illuminate\Notifications\Messages\MailMessage' && $name->getAttribute('parent') instanceof Node\Expr\New_);
                }
                if (! $composer || $stock) {
                    $violations[] = ['target' => $path, 'check' => 'toMail must use '.$this->config->mailComposer.' instead of new MailMessage'];
                }
            }
        }
        foreach ($classes as $class => $path) {
            $seen = [];
            for ($parent = $parents[$class]; ! isset($seen[$parent]); $parent = $parents[$parent] ?? '') {
                if ($parent === 'Illuminate\Mail\Mailable' || ($parent !== '' && class_exists($parent) && is_subclass_of($parent, 'Illuminate\Mail\Mailable'))) {
                    $violations[] = ['target' => $path, 'check' => 'extends Mailable'];
                    break;
                }
                $seen[$parent] = true;
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }
}

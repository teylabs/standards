<?php

declare(strict_types=1);

namespace Tey\Standards;

use LogicException;

/**
 * A standards rule cannot run in this process because a dependency it needs is not loadable (Pest, pest-plugin-arch or nikic/php-parser
 * for Conformance). Only this condition means "can't run"; a rule that runs and finds problems, or fails any other way, is not it.
 */
final class RuleUnavailable extends LogicException {}

<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

use RuntimeException;

/** standards:adoption cannot give a trustworthy report (an unreadable or invalid inventory, an unknown fact or signal kind): it fails closed. */
final class AdoptionError extends RuntimeException {}

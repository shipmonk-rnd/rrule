<?php declare(strict_types = 1);

namespace ShipMonk\RRule\Internal;

use ShipMonk\RRule\Exception\SearchLimitExceededException;
use function sprintf;

/**
 * Counts expansion steps of one query.
 *
 * @internal
 */
final class SearchBudget
{

    private int $remaining;

    /**
     * @param positive-int $limit
     */
    public function __construct(
        private readonly int $limit,
    )
    {
        $this->remaining = $limit;
    }

    public function step(): void
    {
        if (--$this->remaining < 0) {
            throw new SearchLimitExceededException(sprintf(
                'The search examined %d periods without reaching an answer; raise the limit with Recurrence::withSearchLimit()',
                $this->limit,
            ));
        }
    }

}

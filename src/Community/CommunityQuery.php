<?php

namespace WiserWebSolutions\PDEClient\Community;

use ArrayIterator;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;

/**
 * Placeholder for the "community" category - no dataset has been wired up
 * yet, so every query returns empty. Reserved for a future PDE data module
 * (see the package README's "Extending to a new PDE data module" section).
 *
 *     PDE::district()->community()->get(); // always an empty collection
 *
 * @implements IteratorAggregate<int, never>
 */
class CommunityQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    /**
     * @return Collection<int, never>
     */
    public function get(): Collection
    {
        return collect();
    }

    public function first(): null
    {
        return null;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }
}

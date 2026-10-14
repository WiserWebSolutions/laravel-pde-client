<?php

namespace WiserWebSolutions\PDEClient\Community;

use IteratorAggregate;
use WiserWebSolutions\PDEClient\Concerns\HasQueryContext;
use WiserWebSolutions\PDEClient\Contracts\AcceptsQueryContext;
use WiserWebSolutions\PDEClient\Exceptions\PDEClientException;

/**
 * Reserved API for community datasets, which are not implemented.
 *
 * @implements IteratorAggregate<int, never>
 */
class CommunityQuery implements AcceptsQueryContext, IteratorAggregate
{
    use HasQueryContext;

    public function get(): never
    {
        throw new PDEClientException('Community datasets are not implemented.');
    }

    public function first(): never
    {
        $this->get();
    }

    public function getIterator(): never
    {
        $this->get();
    }
}

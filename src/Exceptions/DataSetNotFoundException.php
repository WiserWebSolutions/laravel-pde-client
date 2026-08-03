<?php

namespace WiserWebSolutions\PDEClient\Exceptions;

class DataSetNotFoundException extends PDEClientException
{
    public static function noneMatched(string $description): self
    {
        return new self("No PDE data file matched {$description}.");
    }

    public static function multipleMatched(string $description, int $count): self
    {
        return new self("Expected exactly one PDE data file matching {$description}, found {$count}.");
    }
}

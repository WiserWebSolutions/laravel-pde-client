<?php

namespace WiserWebSolutions\PDEClient\Exceptions;

class DataSetNotFoundException extends PDEClientException
{
    public function __construct(string $message, public readonly int $matches = 0)
    {
        parent::__construct($message);
    }

    public static function noneMatched(string $description): self
    {
        return new self("No PDE data matched {$description}.");
    }

    public static function multipleMatched(string $description, int $count): self
    {
        return new self("Expected exactly one PDE result matching {$description}, found {$count}.", $count);
    }
}

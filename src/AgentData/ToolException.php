<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use InvalidArgumentException;

final class ToolException extends InvalidArgumentException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

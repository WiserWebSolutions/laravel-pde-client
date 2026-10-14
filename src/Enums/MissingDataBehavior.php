<?php

namespace WiserWebSolutions\PDEClient\Enums;

use InvalidArgumentException;

enum MissingDataBehavior: string
{
    case ThrowException = 'throw';
    case ReturnNull = 'return_null';

    public static function configured(): self
    {
        $value = config('pde-client.missing_data', 'throw');

        return is_string($value) && self::tryFrom($value) !== null
            ? self::from($value)
            : throw new InvalidArgumentException('pde-client.missing_data must be "throw" or "return_null".');
    }
}

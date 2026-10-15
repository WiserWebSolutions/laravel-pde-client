<?php

namespace WiserWebSolutions\PDEClient\AgentData;

final class Result
{
    public static function make(
        array $data = [],
        array $context = [],
        array $units = [],
        array $sources = [],
        array $warnings = [],
        string $status = 'ok',
    ): array {
        return [
            'status' => $status, 'data' => $data, 'context' => $context,
            'units' => $units, 'sources' => SourceReference::unique($sources),
            'warnings' => $warnings, 'pagination' => null, 'error' => null,
        ];
    }

    public static function error(string $code, string $message): array
    {
        $result = self::make(status: 'error');
        $result['error'] = ['code' => $code, 'message' => $message];

        return $result;
    }
}

<?php

namespace WiserWebSolutions\PDEClient\AgentData;

use JsonException;

final class Paginator
{
    public function page(array $result, array $arguments): array
    {
        $limit = $arguments['limit'] ?? (int) config('pde-client.agent.page_size', 100);
        if ($limit < 1 || $limit > (int) config('pde-client.agent.page_size', 100)) {
            throw new ToolException('invalid_arguments', 'limit exceeds the configured page size.');
        }
        $query = $arguments;
        unset($query['cursor'], $query['limit']);
        $queryHash = hash('sha256', json_encode($query, JSON_THROW_ON_ERROR));
        $rows = $result['data'];
        foreach ($rows as &$row) {
            if (isset($row['sources'])) {
                $row['sources'] = $this->revisions($row['sources']);
            }
        }
        unset($row);
        $fingerprint = hash('sha256', json_encode([$rows, $this->revisions($result['sources']), $result['context']], JSON_THROW_ON_ERROR));
        $offset = 0;
        if (isset($arguments['cursor'])) {
            try {
                $decoded = base64_decode($arguments['cursor'], true);
                $cursor = $decoded === false ? null : json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $cursor = null;
            }
            if (! is_array($cursor) || ! is_int($cursor['offset'] ?? null) || $cursor['offset'] < 0
                || ($cursor['query'] ?? null) !== $queryHash) {
                throw new ToolException('invalid_cursor', 'The cursor does not match this query.');
            }
            if (($cursor['fingerprint'] ?? null) !== $fingerprint) {
                throw new ToolException('source_changed', 'The source or resolved year changed. Restart pagination.');
            }
            $offset = $cursor['offset'];
        }
        $total = count($result['data']);
        $result['data'] = array_slice($result['data'], $offset, $limit);
        $next = $offset + count($result['data']);
        $result['pagination'] = [
            'total' => $total, 'returned' => count($result['data']), 'has_more' => $next < $total,
            'next_cursor' => $next < $total ? base64_encode(json_encode([
                'offset' => $next, 'query' => $queryHash, 'fingerprint' => $fingerprint,
            ], JSON_THROW_ON_ERROR)) : null,
        ];

        return $result;
    }

    private function revisions(array $sources): array
    {
        return array_map(fn ($source) => [$source['url'], $source['sha256']], $sources);
    }
}

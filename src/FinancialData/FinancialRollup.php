<?php

namespace WiserWebSolutions\PDEClient\FinancialData;

use WiserWebSolutions\PDEClient\FinancialData\ChartOfAccounts\AccountCodeTree;

final class FinancialRollup
{
    /** @return array{0: array<string, float|null>, 1: array<string, string>} */
    public function calculate(array $raw, AccountCodeTree $tree, array $states = []): array
    {
        $effective = [];
        foreach (array_reverse($tree->codesParentsFirst()) as $code) {
            if (array_key_exists($code, $raw) && $raw[$code] !== null && (float) $raw[$code] !== 0.0) {
                $effective[$code] = $raw[$code];

                continue;
            }
            $children = array_intersect_key($effective, array_fill_keys($tree->childrenOf($code), true));
            if ($children !== []) {
                $missing = array_keys(array_filter($children, static fn ($value) => $value === null));
                $effective[$code] = $missing === [] ? round(array_sum($children), 2) : null;
                if ($missing !== []) {
                    $states[$code] = in_array('not_collected', array_intersect_key($states, array_fill_keys($missing, true)), true)
                        ? 'not_collected' : ($states[$missing[0]] ?? 'not_reported');
                } else {
                    unset($states[$code]);
                }

                continue;
            }
            if (array_key_exists($code, $raw)) {
                $effective[$code] = $raw[$code];
            }
        }
        foreach ($raw as $code => $value) {
            if (! array_key_exists($code, $effective)) {
                $effective[$code] = $value;
            }
        }

        return [$effective, $states];
    }
}

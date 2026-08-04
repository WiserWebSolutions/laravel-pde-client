<?php

namespace WiserWebSolutions\PDEClient\Enums;

/**
 * A Statement of Indebtedness fund grouping. `All` is PDE's own top-level
 * summary line (beginning/end only, not broken down by fund type).
 */
enum FundType: string
{
    case Governmental = 'governmental';
    case Proprietary = 'proprietary';
    case All = 'all';
}

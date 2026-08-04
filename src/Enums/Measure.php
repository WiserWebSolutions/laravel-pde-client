<?php

namespace WiserWebSolutions\PDEClient\Enums;

/** Whether a FinancialQuery amount came from the GFB (budget) or the AFR (actual). */
enum Measure: string
{
    case Budget = 'budget';
    case Actual = 'actual';
}

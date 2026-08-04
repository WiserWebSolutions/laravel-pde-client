<?php

namespace WiserWebSolutions\PDEClient\Enums;

/**
 * The three account groupings FinancialQuery deals in: revenue codes
 * (6000-9999), expenditure function codes (1000-5999), and the GFB's
 * beginning-of-year budgeted fund balance codes (0810-0850).
 */
enum FinancialCategory: string
{
    case Revenue = 'revenue';
    case Expenditure = 'expenditure';
    case FundBalance = 'fund_balance';
}

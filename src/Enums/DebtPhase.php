<?php

namespace WiserWebSolutions\PDEClient\Enums;

/**
 * A Statement of Indebtedness reporting phase within a fiscal year. The
 * `All` fund type only ever has `Beginning`/`End`.
 */
enum DebtPhase: string
{
    case Beginning = 'beginning';
    case Additional = 'additional';
    case Retirements = 'retirements';
    case End = 'end';
}

<?php

namespace WiserWebSolutions\PDEClient\Enums;

/** Which cohort graduation rate GraduationQuery reports: 4-, 5-, or 6-year. */
enum CohortSpan: int
{
    case FourYear = 4;
    case FiveYear = 5;
    case SixYear = 6;
}

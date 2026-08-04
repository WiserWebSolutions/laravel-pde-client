<?php

namespace WiserWebSolutions\PDEClient\Enums;

/** The two statewide assessments AssessmentQuery covers. */
enum Exam: string
{
    case Pssa = 'pssa';
    case Keystone = 'keystone';
}

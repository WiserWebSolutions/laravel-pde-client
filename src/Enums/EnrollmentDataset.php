<?php

namespace WiserWebSolutions\PDEClient\Enums;

/** Which population EnrollmentQuery is counting: general enrollment or English learners. */
enum EnrollmentDataset: string
{
    case Enrollment = 'enrollment';
    case EnglishLearners = 'english_learners';
}

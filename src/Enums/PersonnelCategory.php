<?php

namespace WiserWebSolutions\PDEClient\Enums;

/**
 * A staff category from PDE's professional staff summary report.
 * `Professional` is PDE's own "PP" total of the other four categories.
 */
enum PersonnelCategory: string
{
    case Professional = 'professional';
    case Administrator = 'administrator';
    case ClassroomTeacher = 'classroom_teacher';
    case Coordinator = 'coordinator';
    case Other = 'other';
}

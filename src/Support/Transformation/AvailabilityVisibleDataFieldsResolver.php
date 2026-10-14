<?php

namespace WiserWebSolutions\PDEClient\Support\Transformation;

use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Resolvers\VisibleDataFieldsResolver;
use Spatie\LaravelData\Support\DataClass;
use Spatie\LaravelData\Support\Transformation\TransformationContext;

final class AvailabilityVisibleDataFieldsResolver extends VisibleDataFieldsResolver
{
    public function execute(BaseData $data, DataClass $dataClass, TransformationContext $transformationContext): array
    {
        return parent::execute(
            method_exists($data, 'availabilitySnapshot') ? $data->availabilitySnapshot() : $data,
            $dataClass,
            $transformationContext,
        );
    }
}

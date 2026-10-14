<?php

namespace WiserWebSolutions\PDEClient\Support\Transformation;

use Spatie\LaravelData\Resolvers\TransformedDataResolver;
use Spatie\LaravelData\Support\DataConfig;

final class AvailabilityTransformedDataResolver extends TransformedDataResolver
{
    public function __construct(DataConfig $dataConfig, AvailabilityVisibleDataFieldsResolver $visibleDataFieldsResolver)
    {
        parent::__construct($dataConfig, $visibleDataFieldsResolver);
    }
}

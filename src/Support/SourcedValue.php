<?php

namespace WiserWebSolutions\PDEClient\Support;

use Spatie\LaravelData\Data;

/** A scalar result together with the public documents supporting it. */
final class SourcedValue extends Data
{
    public readonly ?float $value;

    public readonly DataProvenance $provenance;

    public function __construct(
        ?float $value,
        DataProvenance $provenance,
    ) {
        $this->value = $value;
        $this->provenance = DataProvenance::forValues(['value' => $value], $provenance);
    }
}

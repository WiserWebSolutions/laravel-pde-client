<?php

namespace WiserWebSolutions\PDEClient\Tests\PHPStan;

use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;
use PHPStan\Type\TypeCombinator;
use WiserWebSolutions\PDEClient\Concerns\HasDataAvailability;

/** Models the constructor/guard contract for deliberately lazy readonly DTO fields. */
final class AvailabilityPropertiesExtension implements ReadWritePropertiesExtension
{
    public function isAlwaysRead(PropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    public function isAlwaysWritten(PropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    public function isInitialized(PropertyReflection $property, string $propertyName): bool
    {
        $class = $property->getDeclaringClass();
        if (! $property->isPublic() || ! TypeCombinator::containsNull($property->getReadableType()) || ! array_key_exists(HasDataAvailability::class, $class->getTraits())) {
            return false;
        }
        foreach ($class->getNativeReflection()->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $propertyName) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Hyperf\DTO;

use Hyperf\DTO\Exception\DtoException;
use Hyperf\DTO\Scan\PropertyManager;
use Hyperf\DTO\Scan\ValidationManager;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use MabeEnum\Enum;

class ValidationDto
{
    public static bool $isValidationCustomAttributes = false;

    private ValidatorFactoryInterface $validationFactory;

    public function __construct()
    {
        $container = ApplicationContext::getContainer();
        if ($container->has(ValidatorFactoryInterface::class)) {
            $this->validationFactory = $container->get(ValidatorFactoryInterface::class);
        }
    }

    public function validate(string $className, $data)
    {
        if ($this->validationFactory == null) {
            return;
        }
        $this->validateResolved($className, $data);
    }

    private function validateResolved(string $className, $data)
    {
        if (! is_array($data)) {
            throw new DtoException('Class:' . $className . ' data must be object or array');
        }
        $notSimplePropertyArr = PropertyManager::getPropertyAndNotSimpleType($className);
        foreach ($notSimplePropertyArr as $fieldName => $property) {
            if (! empty($data[$fieldName]) && !is_subclass_of($property->className, Enum::class)) {
                $this->validateResolved($property->className, $data[$fieldName]);
            }
        }
        $validArr = ValidationManager::getData($className);
        if (empty($validArr)) {
            return;
        }
        $validator = $this->validationFactory->make(
            $data,
            $validArr['rule'],
            $validArr['messages'] ?? [],
            static::$isValidationCustomAttributes ? ($validArr['attributes'] ?? []) : []
        );
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}

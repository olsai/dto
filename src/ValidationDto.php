<?php

declare(strict_types=1);

namespace Hyperf\DTO;

use Hyperf\DTO\Scan\PropertyManager;
use Hyperf\DTO\Scan\ValidationManager;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;

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
        $validArr = ValidationManager::getData($className);
        if (empty($validArr)) {
            return;
        }

        $notSimplePropertyArr = PropertyManager::getPropertyAndNotSimpleType($className);
        foreach ($notSimplePropertyArr as $fieldName => $property) {
            if (!empty($data[$fieldName])) {
                if ($property->type === 'array') {
                    foreach ($data[$fieldName] as $item) {
                        $this->validateResolved($property->className, $item);
                    }
                } else {
                    $this->validateResolved($property->className, $data[$fieldName]);
                }
            }
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

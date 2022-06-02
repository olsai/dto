<?php

declare(strict_types=1);

namespace Hyperf\DTO\Middleware;

use Hyperf\ApiDocs\Annotation\ApiAttributeProperty;
use Hyperf\ApiDocs\Annotation\ApiFileProperty;
use Hyperf\ApiDocs\Annotation\ApiHeaderProperty;
use Hyperf\ApiDocs\Annotation\ApiQueryProperty;
use Hyperf\DTO\Entity\CommonResponse;
use Hyperf\DTO\Scan\MethodParametersManager;
use Hyperf\DTO\ValidationDto;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Utils\Codec\Json;
use Hyperf\Utils\Context;
use Hyperf\Utils\Contracts\Arrayable;
use Hyperf\Utils\Contracts\Jsonable;
use InvalidArgumentException;
use JsonMapper_Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

class CoreMiddleware extends \Hyperf\HttpServer\CoreMiddleware
{
    protected function parseMethodParameters(string $controller, string $action, array $arguments): array
    {
        $definitions = $this->getMethodDefinitionCollector()->getParameters($controller, $action);
        return $this->getInjections($definitions, "{$controller}::{$action}", $arguments);
    }

    /**
     * Transfer the non-standard response content to a standard response object.
     *
     * @param null|array|Arrayable|Jsonable|string $response
     */
    protected function transferToResponse($response, ServerRequestInterface $request): ResponseInterface
    {
        if (is_string($response)) {
            return $this->response()->withAddedHeader('content-type', 'text/plain')->withBody(new SwooleStream($response));
        }

        if (is_array($response) || $response instanceof Arrayable) {
            return $this->response()->withAddedHeader('content-type', 'application/json')->withBody(new SwooleStream(Json::encode($response)));
        }

        if ($response instanceof Jsonable) {
            return $this->response()->withAddedHeader('content-type', 'application/json')->withBody(new SwooleStream((string)$response));
        }
        //object
        if (is_object($response)) {
            $commonResponse = new CommonResponse();
            $commonResponse->data = $response;
            return $this->response()->withAddedHeader('content-type', 'application/json')->withBody(new SwooleStream(Json::encode($commonResponse->toArray())));
        }

        return $this->response()->withAddedHeader('content-type', 'text/plain')->withBody(new SwooleStream((string)$response));
    }

    private function getInjections(array $definitions, string $callableName, array $arguments): array
    {
        $injections = [];
        foreach ($definitions ?? [] as $pos => $definition) {
            $value = $arguments[$pos] ?? $arguments[$definition->getMeta('name')] ?? null;
            if ($value === null) {
                if ($definition->getMeta('defaultValueAvailable')) {
                    $injections[] = $definition->getMeta('defaultValue');
                } elseif ($definition->allowsNull()) {
                    $injections[] = null;
                } elseif ($this->container->has($definition->getName())) {
                    $obj = $this->container->get($definition->getName());
                    $injections[] = $this->validateAndMap($callableName, $definition->getMeta('name'), $definition->getName(), $obj);
                } else {
                    throw new InvalidArgumentException("Parameter '{$definition->getMeta('name')}' " . "of {$callableName} should not be null");
                }
            } else {
                $injections[] = $this->getNormalizer()->denormalize($value, $definition->getName());
            }
        }
        return $injections;
    }

    /**
     * @param string $callableName 'App\Controller\DemoController::index'
     * @param        $obj
     *
     * @throws JsonMapper_Exception
     */
    private function validateAndMap(string $callableName, string $paramName, string $className, $obj): mixed
    {
        [$controllerName, $methodName] = explode('::', $callableName);
        $methodParameter = MethodParametersManager::getMethodParameter($controllerName, $methodName, $paramName);
        if ($methodParameter == null) {
            return $obj;
        }
        $validationDTO = $this->container->get(ValidationDto::class);
        $request = Context::get(ServerRequestInterface::class);
        $param = [];
        if ($methodParameter->isRequestBody()) {
            $param = $request->getParsedBody();
        } elseif ($methodParameter->isRequestQuery()) {
            $param = $request->getQueryParams();
        } elseif ($methodParameter->isRequestFormData()) {
            $param = $request->getParsedBody();
        }

        // other property value
        $param = $this->getPropertyValue($request, $className, $param);

        //validate
        if ($methodParameter->isValid()) {
            $validationDTO->validate($className, $param);
        }

        return new $className($param);
    }

    protected function getPropertyValue(ServerRequestInterface $request, string $className, array $param): array
    {
        $reflection = new ReflectionClass($className);
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            if ($property->getAttributes(ApiHeaderProperty::class)) {
                $param[$name] = $request->getHeaderLine($name);
            }
            if ($property->getAttributes(ApiAttributeProperty::class)) {
                $param[$name] = $request->getAttribute($name);
            }
            if ($property->getAttributes(ApiQueryProperty::class)) {
                $param[$name] = $request->getQueryParams()[$name] ?? null;
            }
            if ($property->getAttributes(ApiFileProperty::class)) {
                $param[$name] = $request->getUploadedFiles()[$name] ?? null;
            }

            $type = $property->getType();
            if (!$type->isBuiltin()) {
                $subParams = $param[$name] ?? [];
                if (is_array($subParams)) {
                    $itemValue = $this->getPropertyValue($request, $type->getName(), $subParams);
                    if (!empty($itemValue)) {
                        $param[$name] = $itemValue;
                    }
                }
            }
        }
        return $param;
    }

}

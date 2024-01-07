<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Enums\SimpleType;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\InvalidStateException;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Saloon\Http\Response;

class ResourceGenerator extends Generator
{
    public function generate(ApiSpecification $specification): PhpFile|array
    {
        return $this->generateResourceClasses($specification);
    }

    /**
     * @return array|PhpFile[]
     */
    protected function generateResourceClasses(ApiSpecification $specification): array
    {
        $classes = [];

        $groupedByCollection = collect($specification->endpoints)->groupBy(function (Endpoint $endpoint) {
            return NameHelper::resourceClassName(
                $endpoint->collection ?: $this->config->fallbackResourceName
            );
        });

        foreach ($groupedByCollection as $collection => $items) {
            $classes[] = $this->generateResourceClass($collection, $items->toArray());
        }

        return $classes;
    }

    /**
     * @param  array|Endpoint[]  $endpoints
     */
    public function generateResourceClass(string $resourceName, array $endpoints): ?PhpFile
    {
        [$classFile, $namespace, $classType] = $this->makeClass($resourceName, $this->config->resourceNamespaceSuffix);

        $classType->setExtends("{$this->config->namespace}\\Resource");
        $namespace->addUse("{$this->config->namespace}\\Resource");

        $duplicateCounter = 1;

        foreach ($endpoints as $endpoint) {
            $requestClassName = NameHelper::requestClassName($endpoint->name);
            $methodName = NameHelper::safeVariableName($requestClassName);
            $requestClassNameAlias = $requestClassName == $resourceName ? "{$requestClassName}Request" : null;
            $requestNamespaceSuffix = NameHelper::optionalNamespaceSuffix($this->config->requestNamespaceSuffix);

            $requestClassFQN = "{$this->config->namespace}{$requestNamespaceSuffix}\\{$resourceName}\\{$requestClassName}";

            $namespace
                ->addUse(Response::class)
                ->addUse(
                    name: $requestClassFQN,
                    alias: $requestClassNameAlias,
                );

            try {
                $method = $classType->addMethod($methodName);
            } catch (InvalidStateException $exception) {
                // TODO: handle more gracefully in the future
                $deduplicatedMethodName = NameHelper::safeVariableName(
                    sprintf('%s%s', $methodName, 'Duplicate'.$duplicateCounter)
                );
                $duplicateCounter++;
                dump("DUPLICATE: {$requestClassName} -> {$deduplicatedMethodName}");

                $method = $classType
                    ->addMethod($deduplicatedMethodName)
                    ->addComment('@todo Fix duplicated method name');
            }

            $method->setReturnType(Response::class);

            $args = [];

            foreach ($endpoint->pathParameters as $parameter) {
                MethodGeneratorHelper::addParameterToMethod($method, $parameter);
                $args[] = new Literal(sprintf('$%s', NameHelper::safeVariableName($parameter->name)));
            }

            if ($endpoint->bodySchema) {
                $dtoNamespaceSuffix = NameHelper::optionalNamespaceSuffix($this->config->dtoNamespaceSuffix);
                $dtoNamespace = "{$this->config->namespace}{$dtoNamespaceSuffix}";

                // Don't need to import the DTO if the body is an array
                if (SimpleType::tryFrom($endpoint->bodySchema->type) !== SimpleType::ARRAY) {
                    $safeSchemaName = NameHelper::requestClassName($endpoint->bodySchema->name);
                    $bodyFQN = "{$dtoNamespace}\\{$safeSchemaName}";
                    $namespace->addUse($bodyFQN);
                }

                MethodGeneratorHelper::addParameterToMethod($method, $endpoint->bodySchema, namespace: $dtoNamespace);
                $args[] = new Literal(sprintf('$%s', NameHelper::safeVariableName($endpoint->bodySchema->name)));
            }

            foreach ($endpoint->queryParameters as $parameter) {
                if (in_array($parameter->name, $this->config->ignoredQueryParams)) {
                    continue;
                }
                MethodGeneratorHelper::addParameterToMethod($method, $parameter);
                $args[] = new Literal(sprintf('$%s', NameHelper::safeVariableName($parameter->name)));
            }

            $method->setBody(
                new Literal(sprintf(
                    'return $this->connector->send(new %s(%s));',
                    $requestClassNameAlias ?? $requestClassName,
                    implode(', ', $args)
                ))
            );
        }

        return $classFile;
    }
}

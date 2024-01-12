<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\BaseResponse;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Schema;
use Crescat\SaloonSdkGenerator\Enums\SimpleType;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;

class ResponseGenerator extends Generator
{
    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $classes = [];

        foreach ($specification->responses as $response) {
            $classes[] = $this->generateResponseClass($response);
        }

        return $classes;
    }

    public function generateResponseClass(Schema $schema): PhpFile
    {
        $className = NameHelper::responseClassName($schema->name);
        [$classFile, $namespace, $classType] = $this->makeClass($className, $this->config->responseNamespaceSuffix);

        $namespace->addUse(BaseResponse::class);

        $classType
            ->setFinal()
            ->setExtends(BaseResponse::class);

        $classConstructor = $classType->addMethod('__construct');

        $dtoNamespace = $this->config->dtoNamespace();
        $attributeMap = [];
        $complexArrayTypes = [];

        if ($schema->type === SimpleType::ARRAY->value) {
            $schema->items->name = NameHelper::safeVariableName($schema->name);
            MethodGeneratorHelper::addParameterToMethod(
                $classConstructor,
                $schema,
                namespace: $dtoNamespace,
                promote: true,
                visibility: 'public',
                readonly: true,
            );

            if ($schema->name !== $schema->rawName) {
                $attributeMap[$schema->name] = $schema->rawName;
            }

            if (! Utils::isBuiltInType($schema->items->type)) {
                $safeName = NameHelper::safeVariableName($schema->name);
                $complexArrayTypes[$safeName] = NameHelper::dtoClassName($schema->items->type);
            }
        } else {
            foreach ($schema->properties as $parameterName => $property) {
                $safeName = NameHelper::safeVariableName($parameterName);

                // Clone property before modifying to avoid any weird downstream effects
                $param = clone $property;
                // Make sure the constructor parameter is named the same thing as the parameter
                // in the original spec
                $param->name = $safeName;
                MethodGeneratorHelper::addParameterToMethod(
                    $classConstructor,
                    $param,
                    namespace: $dtoNamespace,
                    promote: true,
                    visibility: 'public',
                    readonly: true,
                );

                $type = $property->type;
                if (! Utils::isBuiltInType($type)) {
                    $safeType = NameHelper::dtoClassName($type);
                    $type = "{$dtoNamespace}\\{$safeType}";
                    $namespace->addUse($type);
                }

                if (
                    $property->type === SimpleType::ARRAY->value
                    && $property->items
                    && ! Utils::isBuiltInType($property->items->type)
                ) {
                    $complexArrayTypes[$safeName] = NameHelper::dtoClassName($property->items->type);
                }
            }

            if ($parameterName !== $safeName) {
                $attributeMap[$safeName] = $property->rawName;
            }
        }

        if ($attributeMap) {
            $classType->addProperty('attributeMap', $attributeMap)
                ->setStatic()
                ->setType('array')
                ->setProtected();
        }

        if ($complexArrayTypes) {
            foreach ($complexArrayTypes as $name => $type) {
                $dtoFQN = "{$dtoNamespace}\\{$type}";
                $namespace->addUse($dtoFQN);

                $literalType = new Literal(sprintf('%s::class', $type));
                $complexArrayTypes[$name] = [$literalType];
            }
            $classType->addProperty('complexArrayTypes', $complexArrayTypes)
                ->setStatic()
                ->setType('array')
                ->setProtected();
        }

        return $classFile;
    }
}

<?php

declare(strict_types=1);

namespace Crescat\SaloonSdkGenerator;

use Crescat\SaloonSdkGenerator\Contracts\Generator as GeneratorContract;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;

abstract class Generator implements GeneratorContract
{
    public static string $baseClsName;

    public function __construct(protected Config $config)
    {
    }

    /**
     * @return array{PhpFile, PhpNamespace, ClassType}
     */
    protected function makeClass(string $className, array|string $namespaceSuffixes = []): array
    {
        if (is_string($namespaceSuffixes)) {
            $namespaceSuffixes = [$namespaceSuffixes];
        }
        $classType = new ClassType($className);

        $classFile = new PhpFile;
        $classFile->addComment("This file is auto-generated by Saloon SDK Generator\nGenerator: " . get_class($this) . "\nDo not modify it directly.");
        $classFile->setStrictTypes();

        $suffixes = [];
        foreach ($namespaceSuffixes as $suffix) {
            $suffixes[] = NameHelper::optionalNamespaceSuffix($suffix);
        }
        $suffix = implode('', $suffixes);
        $namespace = $classFile->addNamespace("{$this->config->namespace}{$suffix}");
        $namespace->add($classType);

        return [$classFile, $namespace, $classType];
    }

    protected function baseClassFqn(?string $className = null): string
    {
        return $this->config->baseFilesNamespace().'\\'.($className ?? static::$baseClsName);
    }
}

<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\Di\Definition;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Di\Annotation\AspectCollector;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Di\Annotation\Scanner;
use Hyperf\Di\Aop\AstCollector;
use Hyperf\Di\ReflectionManager;
use Hyperf\Utils\Str;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Symfony\Component\Finder\Finder;
use function class_exists;
use function count;
use function explode;
use function feof;
use function fgets;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function fopen;
use function implode;
use function interface_exists;
use function is_callable;
use function is_dir;
use function is_readable;
use function is_string;
use function md5;
use function method_exists;
use function preg_match;
use function print_r;
use function str_replace;
use function trim;

class DefinitionSource implements DefinitionSourceInterface
{

    /**
     * @var array
     */
    private $source;

    public function __construct(array $source, ScanConfig $scanConfig)
    {
        $this->source = $this->normalizeSource($source);
    }

    /**
     * Returns the DI definition for the entry name.
     */
    public function getDefinition(string $name): ?DefinitionInterface
    {
        if (! isset($this->source[$name])) {
            $this->source[$name] = $this->autowire($name);
        }
        return $this->source[$name];
    }

    /**
     * @return array definitions indexed by their name
     */
    public function getDefinitions(): array
    {
        return $this->source;
    }

    /**
     * @param array|callable|string $definition
     */
    public function addDefinition(string $name, $definition): self
    {
        $this->source[$name] = $this->normalizeDefinition($name, $definition);
        return $this;
    }

    public function clearDefinitions(): void
    {
        $this->source = [];
    }

    /**
     * Read the type-hinting from the parameters of the function.
     */
    private function getParametersDefinition(ReflectionFunctionAbstract $constructor): array
    {
        $parameters = [];

        foreach ($constructor->getParameters() as $index => $parameter) {
            // Skip optional parameters.
            if ($parameter->isOptional()) {
                continue;
            }

            $parameterClass = $parameter->getClass();

            if ($parameterClass) {
                $parameters[$index] = new Reference($parameterClass->getName());
            }
        }

        return $parameters;
    }

    /**
     * Normaliaze the user definition source to a standard definition souce.
     */
    private function normalizeSource(array $source): array
    {
        $definitions = [];
        foreach ($source as $identifier => $definition) {
            $normalizedDefinition = $this->normalizeDefinition($identifier, $definition);
            if (! is_null($normalizedDefinition)) {
                $definitions[$identifier] = $normalizedDefinition;
            }
        }
        return $definitions;
    }

    /**
     * @param array|callable|string $definition
     */
    private function normalizeDefinition(string $identifier, $definition): ?DefinitionInterface
    {
        if (is_string($definition) && class_exists($definition)) {
            if (method_exists($definition, '__invoke')) {
                return new FactoryDefinition($identifier, $definition, []);
            }
            return $this->autowire($identifier, new ObjectDefinition($identifier, $definition));
        }
        if (is_callable($definition)) {
            return new FactoryDefinition($identifier, $definition, []);
        }
        return null;
    }

    private function autowire(string $name, ObjectDefinition $definition = null): ?ObjectDefinition
    {
        $className = $definition ? $definition->getClassName() : $name;
        if (! class_exists($className) && ! interface_exists($className)) {
            return $definition;
        }

        $definition = $definition ?: new ObjectDefinition($name);

        /**
         * Constructor.
         */
        $class = ReflectionManager::reflectClass($className);
        $constructor = $class->getConstructor();
        if ($constructor && $constructor->isPublic()) {
            $constructorInjection = new MethodInjection('__construct', $this->getParametersDefinition($constructor));
            $definition->completeConstructorInjection($constructorInjection);
        }

        /**
         * Properties.
         */
        $propertiesMetadata = AnnotationCollector::get($className);
        $propertyHandlers = PropertyHandlerManager::all();
        if (isset($propertiesMetadata['_p'])) {
            foreach ($propertiesMetadata['_p'] as $propertyName => $value) {
                // Because `@Inject` is a internal logical of DI component, so leave the code here.
                /** @var Inject $injectAnnotation */
                if ($injectAnnotation = $value[Inject::class] ?? null) {
                    $propertyInjection = new PropertyInjection($propertyName, new Reference($injectAnnotation->value));
                    $definition->addPropertyInjection($propertyInjection);
                }
                // Handle PropertyHandler mechanism.
                foreach ($value as $annotationClassName => $annotationObject) {
                    if (isset($propertyHandlers[$annotationClassName])) {
                        foreach ($propertyHandlers[$annotationClassName] ?? [] as $callback) {
                            call($callback, [$definition, $propertyName, $annotationObject]);
                        }
                    }
                }
            }
        }

        // $definition->setNeedProxy($this->isNeedProxy($class));

        return $definition;
    }

    private function printLn(string $message): void
    {
        print_r($message . PHP_EOL);
    }
}

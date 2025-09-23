<?php

declare(strict_types=1);
/**
 * This file is part of friendsofhyperf/components.
 *
 * @link     https://github.com/friendsofhyperf/components
 * @document https://github.com/friendsofhyperf/components/blob/main/README.md
 * @contact  huangdijia@gmail.com
 */

namespace FriendsOfHyperf\ValidatedDTO\Export;

use FriendsOfHyperf\ValidatedDTO\Attributes\Cast;
use FriendsOfHyperf\ValidatedDTO\Attributes\DefaultValue;
use FriendsOfHyperf\ValidatedDTO\Attributes\Map;
use FriendsOfHyperf\ValidatedDTO\Attributes\Rules;
use FriendsOfHyperf\ValidatedDTO\Casting\ArrayCast;
use FriendsOfHyperf\ValidatedDTO\Casting\BooleanCast;
use FriendsOfHyperf\ValidatedDTO\Casting\CarbonCast;
use FriendsOfHyperf\ValidatedDTO\Casting\CarbonImmutableCast;
use FriendsOfHyperf\ValidatedDTO\Casting\CollectionCast;
use FriendsOfHyperf\ValidatedDTO\Casting\DTOCast;
use FriendsOfHyperf\ValidatedDTO\Casting\DoubleCast;
use FriendsOfHyperf\ValidatedDTO\Casting\EnumCast;
use FriendsOfHyperf\ValidatedDTO\Casting\FloatCast;
use FriendsOfHyperf\ValidatedDTO\Casting\IntegerCast;
use FriendsOfHyperf\ValidatedDTO\Casting\LongCast;
use FriendsOfHyperf\ValidatedDTO\Casting\ModelCast;
use FriendsOfHyperf\ValidatedDTO\Casting\ObjectCast;
use FriendsOfHyperf\ValidatedDTO\Casting\StringCast;
use FriendsOfHyperf\ValidatedDTO\SimpleDTO;
use ReflectionClass;
use ReflectionProperty;

class TypescriptExporter
{
    protected array $processedClasses = [];
    
    protected array $typeMapping = [
        StringCast::class => 'string',
        IntegerCast::class => 'number',
        LongCast::class => 'number', // Deprecated, but maps to number
        FloatCast::class => 'number',
        DoubleCast::class => 'number', // Deprecated, but maps to number  
        BooleanCast::class => 'boolean',
        ArrayCast::class => 'any[]',
        CollectionCast::class => 'any[]',
        ObjectCast::class => 'object',
        ModelCast::class => 'object', // Models are represented as objects
        EnumCast::class => 'string', // Enums are typically represented as strings in TS
        CarbonCast::class => 'string', // ISO date string
        CarbonImmutableCast::class => 'string', // ISO date string
    ];

    public function export(string $dtoPath, string $outputPath, string $filename = 'dtos.ts'): array
    {
        $this->processedClasses = [];
        
        if (! is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $dtoClasses = $this->findDtoClasses($dtoPath);
        $interfaces = [];
        $skipped = [];

        foreach ($dtoClasses as $className) {
            try {
                $interface = $this->generateInterface($className);
                if ($interface) {
                    $interfaces[] = $interface;
                }
            } catch (\Exception $e) {
                $skipped[] = "{$className}: {$e->getMessage()}";
            }
        }

        if (empty($interfaces)) {
            return ['count' => 0, 'skipped' => $skipped, 'file' => null];
        }

        $content = $this->generateFileContent($interfaces);
        $outputFile = rtrim($outputPath, '/') . '/' . $filename;
        
        file_put_contents($outputFile, $content);

        return [
            'count' => count($interfaces),
            'file' => $outputFile,
            'skipped' => $skipped,
        ];
    }

    protected function findDtoClasses(string $dtoPath): array
    {
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dtoPath)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $className = $this->extractClassNameFromFile($file->getPathname());
                if ($className && $this->isValidDtoClass($className)) {
                    $classes[] = $className;
                }
            }
        }

        return $classes;
    }

    protected function extractClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            return null;
        }
        
        // Extract namespace
        if (! preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            return null;
        }
        
        // Extract class name
        if (! preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            return null;
        }

        return trim($namespaceMatches[1]) . '\\' . trim($classMatches[1]);
    }

    protected function isValidDtoClass(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        $reflection = new ReflectionClass($className);
        return $reflection->isSubclassOf(SimpleDTO::class) && ! $reflection->isAbstract();
    }

    protected function generateInterface(string $className): ?string
    {
        if (in_array($className, $this->processedClasses)) {
            return null;
        }

        $this->processedClasses[] = $className;
        
        $reflection = new ReflectionClass($className);
        $interfaceName = $this->getInterfaceName($className);
        
        $properties = $this->getInterfaceProperties($reflection);
        
        if (empty($properties)) {
            return null;
        }

        $propertiesString = implode("\n", array_map(function ($prop) {
            return "  {$prop};";
        }, $properties));

        return "export interface {$interfaceName} {\n{$propertiesString}\n}";
    }

    protected function getInterfaceName(string $className): string
    {
        $parts = explode('\\', $className);
        $shortName = end($parts);
        
        // Remove 'DTO' suffix if present to avoid redundancy in interface name
        if (str_ends_with($shortName, 'DTO')) {
            $shortName = substr($shortName, 0, -3);
        }
        
        return $shortName . 'Interface';
    }

    protected function getInterfaceProperties(ReflectionClass $reflection): array
    {
        $properties = [];
        $instance = $this->createDtoInstance($reflection);
        
        if (! $instance) {
            return [];
        }

        // Get properties from casts(), rules(), and attributes
        $casts = $this->getCasts($instance);
        $rules = $this->getRules($instance);
        $defaults = $this->getDefaults($instance);
        
        // Merge all property names
        $allProperties = array_unique(array_merge(
            array_keys($casts),
            array_keys($rules),
            array_keys($defaults)
        ));

        foreach ($allProperties as $propertyName) {
            $type = $this->determinePropertyType($propertyName, $casts, $rules, $reflection);
            $optional = $this->isOptionalProperty($propertyName, $rules, $defaults);
            
            $properties[] = "{$propertyName}" . ($optional ? '?' : '') . ": {$type}";
        }

        return $properties;
    }

    protected function createDtoInstance(ReflectionClass $reflection): ?object
    {
        try {
            // Try to create instance with empty data
            return $reflection->newInstance([]);
        } catch (\Exception) {
            try {
                // Try without constructor
                return $reflection->newInstanceWithoutConstructor();
            } catch (\Exception) {
                return null;
            }
        }
    }

    protected function getCasts($instance): array
    {
        try {
            $reflection = new ReflectionClass($instance);
            $method = $reflection->getMethod('casts');
            $method->setAccessible(true);
            return $method->invoke($instance) ?: [];
        } catch (\Exception) {
            return [];
        }
    }

    protected function getRules($instance): array
    {
        try {
            $reflection = new ReflectionClass($instance);
            $method = $reflection->getMethod('rules');
            $method->setAccessible(true);
            return $method->invoke($instance) ?: [];
        } catch (\Exception) {
            return [];
        }
    }

    protected function getDefaults($instance): array
    {
        try {
            $reflection = new ReflectionClass($instance);
            $method = $reflection->getMethod('defaults');
            $method->setAccessible(true);
            return $method->invoke($instance) ?: [];
        } catch (\Exception) {
            return [];
        }
    }

    protected function determinePropertyType(string $propertyName, array $casts, array $rules, ReflectionClass $reflection): string
    {
        // Check if there's a cast defined for this property
        if (isset($casts[$propertyName])) {
            $cast = $casts[$propertyName];
            return $this->mapCastToTypeScript($cast);
        }

        // Check validation rules for type hints
        if (isset($rules[$propertyName])) {
            return $this->inferTypeFromRules($rules[$propertyName]);
        }

        // Check for property attributes
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            return $this->getTypeFromAttributes($property);
        }

        return 'any';
    }

    protected function mapCastToTypeScript($cast): string
    {
        if (is_object($cast)) {
            $castClass = get_class($cast);
            
            if ($cast instanceof DTOCast) {
                $reflection = new \ReflectionClass($cast);
                $property = $reflection->getProperty('dtoClass');
                $property->setAccessible(true);
                $dtoClass = $property->getValue($cast);
                
                return $this->getInterfaceName($dtoClass);
            }
            
            if ($cast instanceof EnumCast) {
                // Try to generate union type from enum cases
                return $this->getEnumTypescriptType($cast);
            }
            
            return $this->typeMapping[$castClass] ?? 'any';
        }

        if (is_string($cast)) {
            return $this->typeMapping[$cast] ?? 'any';
        }

        return 'any';
    }

    protected function inferTypeFromRules(array $rules): string
    {
        $ruleString = implode('|', is_array($rules) ? $rules : [$rules]);

        if (str_contains($ruleString, 'integer') || str_contains($ruleString, 'numeric')) {
            return 'number';
        }
        
        if (str_contains($ruleString, 'boolean')) {
            return 'boolean';
        }
        
        if (str_contains($ruleString, 'array')) {
            return 'any[]';
        }
        
        if (str_contains($ruleString, 'date')) {
            return 'string'; // Date as ISO string
        }

        return 'string'; // Default to string for most validation rules
    }

    protected function getTypeFromAttributes(ReflectionProperty $property): string
    {
        $attributes = $property->getAttributes();
        
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === Cast::class) {
                $cast = $attribute->newInstance();
                return $this->typeMapping[$cast->type] ?? 'any';
            }
        }

        return 'any';
    }

    protected function isOptionalProperty(string $propertyName, array $rules, array $defaults): bool
    {
        // If it has a default value, it's optional
        if (array_key_exists($propertyName, $defaults)) {
            return true;
        }

        // If rules don't contain 'required', it's optional
        if (isset($rules[$propertyName])) {
            $ruleString = implode('|', is_array($rules[$propertyName]) ? $rules[$propertyName] : [$rules[$propertyName]]);
            return ! str_contains($ruleString, 'required');
        }

        return true; // Default to optional
    }

    protected function generateFileContent(array $interfaces): string
    {
        $header = "// Generated TypeScript interfaces from DTO classes\n";
        $header .= "// Generated at: " . date('Y-m-d H:i:s') . "\n";
        $header .= "// This file is auto-generated. Do not edit manually.\n\n";
        
        return $header . implode("\n\n", $interfaces) . "\n";
    }

    protected function getEnumTypescriptType(EnumCast $enumCast): string
    {
        try {
            $reflection = new \ReflectionClass($enumCast);
            $property = $reflection->getProperty('enum');
            $property->setAccessible(true);
            $enumClass = $property->getValue($enumCast);
            
            if (! class_exists($enumClass)) {
                return 'string';
            }
            
            $enumReflection = new \ReflectionClass($enumClass);
            if (! $enumReflection->isEnum()) {
                return 'string';
            }
            
            // Get enum cases
            $cases = $enumClass::cases();
            $values = [];
            
            foreach ($cases as $case) {
                if (method_exists($case, 'value')) {
                    // BackedEnum - use the backing value
                    $value = $case->value;
                    if (is_string($value)) {
                        $values[] = "'{$value}'";
                    } else {
                        $values[] = (string) $value;
                    }
                } else {
                    // UnitEnum - use the case name as string
                    $values[] = "'{$case->name}'";
                }
            }
            
            return empty($values) ? 'string' : implode(' | ', $values);
        } catch (\Exception) {
            return 'string';
        }
    }
}
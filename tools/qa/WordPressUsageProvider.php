<?php

declare(strict_types=1);

namespace AnyPressQa;

use ReflectionClassConstant;
use ReflectionMethod;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;

/**
 * Tells the dead code check about the uses it can't see in WordPress code:
 *
 * - methods WordPress calls by name: hook and REST callbacks such as [$this, 'save'] or $this->route(..., 'login'),
 *   so a public method whose name is a quoted string in its own plugin counts as used;
 * - WP-CLI commands, whose public methods are the subcommands;
 * - methods and properties a class overrides from another plugin's class (FluentCart's gateways and integrations), which that
 *   plugin calls and PHPStan can't see because the other plugin isn't installed here;
 * - members the plugin's own tests use (reset helpers, constants the tests check against).
 */
final class WordPressUsageProvider extends ReflectionBasedMemberUsageProvider
{
    /** @var array<string, string> */
    private array $sources = [];

    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        $file = $method->getDeclaringClass()->getFileName();
        if (!is_string($file) || !str_contains($file, '/packages/')) {
            return null;
        }
        $name = $method->getName();
        $class = $method->getDeclaringClass();

        if ($method->isPublic() && $this->quoted($this->plugin_source($file), $name)) {
            return VirtualUsageData::withNote('Called by name (a hook, REST route or callback)');
        }
        if ($method->isPublic() && str_ends_with($class->getShortName(), 'Command')) {
            return VirtualUsageData::withNote('WP-CLI subcommand');
        }
        if (!$method->isPrivate() && $this->extends_other_plugin($class)) {
            return VirtualUsageData::withNote('Overrides a method of another plugin\'s class');
        }
        if ($this->in_tests($file, '->' . $name . '(') || $this->in_tests($file, '::' . $name . '(')) {
            return VirtualUsageData::withNote('Used by the plugin\'s tests');
        }

        return null;
    }

    protected function shouldMarkConstantAsUsed(ReflectionClassConstant $constant): ?VirtualUsageData
    {
        $file = $constant->getDeclaringClass()->getFileName();
        if (!is_string($file) || !str_contains($file, '/packages/')) {
            return null;
        }
        if ($this->in_tests($file, '::' . $constant->getName())) {
            return VirtualUsageData::withNote('Used by the plugin\'s tests');
        }

        return null;
    }

    protected function shouldMarkPropertyAsRead(\ReflectionProperty $property): ?VirtualUsageData
    {
        if (!$property->isPrivate() && $this->extends_other_plugin($property->getDeclaringClass())) {
            return VirtualUsageData::withNote('Read by the other plugin\'s parent class');
        }

        return null;
    }

    private function quoted(string $source, string $name): bool
    {
        return str_contains($source, "'" . $name . "'") || str_contains($source, '"' . $name . '"');
    }

    private function extends_other_plugin(\ReflectionClass $class): bool
    {
        for ($parent = $class->getParentClass(); $parent; $parent = $parent->getParentClass()) {
            if (!str_starts_with($parent->getName(), 'AnyPress')) {
                return true;
            }
        }
        // An interface of a library (PSR-18's ClientInterface, say) that the library's own code calls.
        foreach ($class->getInterfaceNames() as $interface) {
            if (!str_starts_with($interface, 'AnyPress')) {
                return true;
            }
        }

        return false;
    }

    private function in_tests(string $file, string $needle): bool
    {
        $tests = $this->package_dir($file) . '/tests';

        return is_dir($tests) && str_contains($this->tree($tests), $needle);
    }

    private function plugin_source(string $file): string
    {
        $package = $this->package_dir($file);

        return $this->tree($package . '/src') . $this->tree($package, false);
    }

    private function package_dir(string $file): string
    {
        return (string) preg_replace('#(/packages/[^/]+)/.*$#', '$1', $file);
    }

    private function tree(string $dir, bool $recursive = true): string
    {
        $key = $dir . ($recursive ? '/**' : '/*');
        if (!isset($this->sources[$key])) {
            $files = $recursive
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS))
                : new \FilesystemIterator($dir);
            $source = '';
            foreach ($files as $item) {
                $path = (string) $item;
                if (preg_match('/\.(php|js)$/', $path) && !str_contains($path, '/vendor/')) {
                    $source .= $this->read($path);
                }
            }
            $this->sources[$key] = $source;
        }

        return $this->sources[$key];
    }

    private function read(string $file): string
    {
        return (string) file_get_contents($file);
    }
}

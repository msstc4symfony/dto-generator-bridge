<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * The `extensionConfig.symfony` section (bridge spec §3); null stands for "auto".
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Settings
{
    private const KEYS = ['validator', 'serializer', 'version', 'groups'];

    private ?bool $validator;

    private ?bool $serializer;

    private ?SymfonyVersion $version;

    /** @var list<non-empty-string> */
    private array $groups;

    /**
     * @param list<non-empty-string> $groups
     */
    private function __construct(?bool $validator, ?bool $serializer, ?SymfonyVersion $version, array $groups)
    {
        $this->validator = $validator;
        $this->serializer = $serializer;
        $this->version = $version;
        $this->groups = $groups;
    }

    /**
     * @param array<int|string, JsonValue> $config the section as written
     *
     * @throws InvalidArgumentException naming every setting it cannot use
     */
    public static function fromConfig(array $config): self
    {
        $problems = [];
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                $problems[] = sprintf('extensionConfig.symfony.%s is not a setting of the Symfony bridge.', $key);
            }
        }

        $validator = self::parseSwitch($config, 'validator', $problems);
        $serializer = self::parseSwitch($config, 'serializer', $problems);
        $version = self::parseVersion($config, $problems);
        $groups = self::parseGroups($config, $problems);
        if ($problems !== []) {
            throw new InvalidArgumentException(implode(' ', $problems));
        }

        return new self($validator, $serializer, $version, $groups);
    }

    public function validator(): ?bool
    {
        return $this->validator;
    }

    public function serializer(): ?bool
    {
        return $this->serializer;
    }

    public function version(): ?SymfonyVersion
    {
        return $this->version;
    }

    /**
     * @return list<non-empty-string>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @param array<int|string, JsonValue> $config
     * @param 'validator'|'serializer' $key
     * @param list<string> $problems
     */
    private static function parseSwitch(array $config, string $key, array &$problems): ?bool
    {
        $value = array_key_exists($key, $config) ? $config[$key] : 'auto';
        if ($value === 'auto') {
            return null;
        }

        if (!is_bool($value)) {
            $problems[] = sprintf('extensionConfig.symfony.%s must be auto, true or false.', $key);

            return null;
        }

        return $value;
    }

    /**
     * @param array<int|string, JsonValue> $config
     * @param list<string> $problems
     */
    private static function parseVersion(array $config, array &$problems): ?SymfonyVersion
    {
        $value = array_key_exists('version', $config) ? $config['version'] : 'auto';
        if ($value === 'auto') {
            return null;
        }

        // YAML reads an unquoted 6.4 as a float, and 7.10 as 7.1, so only a string is a version.
        $version = is_string($value) ? SymfonyVersion::tryFromString($value) : null;
        if (!$version instanceof SymfonyVersion) {
            $problems[] = 'extensionConfig.symfony.version must be auto or a version like "6.4" (quote it in YAML: \'6.4\').';

            return null;
        }

        if ($version->isSupported()) {
            return $version;
        }

        $problems[] = sprintf('extensionConfig.symfony.version must be %s or newer.', SymfonyVersion::MINIMUM);

        return null;
    }

    /**
     * @param array<int|string, JsonValue> $config
     * @param list<string> $problems
     *
     * @return list<non-empty-string>
     */
    private static function parseGroups(array $config, array &$problems): array
    {
        $value = array_key_exists('groups', $config) ? $config['groups'] : [];
        if (!is_array($value) || array_values($value) !== $value) {
            $problems[] = 'extensionConfig.symfony.groups must be a list of group names.';

            return [];
        }

        $groups = [];
        foreach ($value as $group) {
            if (!is_string($group) || $group === '') {
                $problems[] = 'extensionConfig.symfony.groups must be a list of group names.';

                return [];
            }

            if (in_array($group, $groups, true)) {
                $problems[] = sprintf('extensionConfig.symfony.groups names "%s" twice.', $group);

                return [];
            }

            $groups[] = $group;
        }

        return $groups;
    }
}

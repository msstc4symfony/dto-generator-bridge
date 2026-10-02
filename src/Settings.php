<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGeneratorBridgeSymfony;

use InvalidArgumentException;

/**
 * The `extensionConfig.symfony` section (bridge spec §3); null stands for "auto".
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
     * @param array<array-key, mixed> $config the section as written
     *
     * @throws InvalidArgumentException naming the setting it cannot use
     */
    public static function fromConfig(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException(sprintf('extensionConfig.symfony.%s is not a setting of the Symfony bridge.', $key));
            }
        }

        return new self(
            self::parseSwitch($config, 'validator'),
            self::parseSwitch($config, 'serializer'),
            self::parseVersion($config['version'] ?? 'auto'),
            self::parseGroups($config['groups'] ?? []),
        );
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
     * @param array<array-key, mixed> $config
     */
    private static function parseSwitch(array $config, string $key): ?bool
    {
        $value = $config[$key] ?? 'auto';
        if ($value === 'auto') {
            return null;
        }

        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('extensionConfig.symfony.%s must be auto, true or false.', $key));
        }

        return $value;
    }

    /**
     * @param mixed $value decoded config
     */
    private static function parseVersion($value): ?SymfonyVersion
    {
        if ($value === 'auto') {
            return null;
        }

        $version = is_string($value) ? SymfonyVersion::tryFromString($value) : null;
        if (!$version instanceof SymfonyVersion) {
            throw new InvalidArgumentException('extensionConfig.symfony.version must be auto or a version like "6.4".');
        }

        return $version;
    }

    /**
     * @param mixed $value decoded config
     *
     * @return list<non-empty-string>
     */
    private static function parseGroups($value): array
    {
        if (!is_array($value) || array_values($value) !== $value) {
            throw new InvalidArgumentException('extensionConfig.symfony.groups must be a list of group names.');
        }

        $groups = [];
        foreach ($value as $group) {
            if (!is_string($group) || $group === '') {
                throw new InvalidArgumentException('extensionConfig.symfony.groups must be a list of group names.');
            }

            $groups[] = $group;
        }

        return $groups;
    }
}

<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use Msstc4Symfony\DtoGeneratorBridge\Test\Unit\GeneratesDtos;
use PHPUnit\Framework\TestCase;

/**
 * A property several members of an allOf declare: the generator keeps one declaration, the value satisfies them all.
 */
final class MergedDeclarationsTest extends TestCase
{
    use GeneratesDtos;

    public function testChecksTheConstraintsOfEveryDeclaration(): void
    {
        $output = $this->generate(['allOf' => [
            ['type' => 'object', 'properties' => ['s' => ['type' => 'string', 'maxLength' => 3], 'n' => ['type' => 'number', 'minimum' => 1]]],
            ['type' => 'object', 'properties' => ['s' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 3], 'n' => ['type' => 'integer', 'minimum' => 1]]],
            ['properties' => ['s' => ['type' => 'string', 'pattern' => '^a', 'maxLength' => 3]]],
            ['properties' => ['s' => ['type' => 'string', 'pattern' => '^b', 'x-validator-skip' => true], 'n' => ['type' => 'number', 'maximum' => 9, 'x-validator-groups' => ['api']]]],
        ]]);
        $code = $this->code($output, 'Pet.php');

        // Each declaration keeps its own constraints; the second Length repeats a bound on purpose, merging bounds is not
        // worth what it would take.
        self::assertSame(['Assert\\Length(min: 1, max: 3)', 'Assert\\Length(max: 3)', 'Assert\\Regex(pattern: \'/^a/uD\')'], $this->attributesOf($code, 's'));
        self::assertSame(['Assert\\GreaterThanOrEqual(value: 1)'], $this->attributesOf($code, 'n'));
    }
}

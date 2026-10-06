<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Test\Unit\Validator;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use Msstc4Symfony\DtoGeneratorBridge\Validator\ConstraintSpec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Valid;

final class ConstraintSpecTest extends TestCase
{
    public function testAppendsGroupsAfterTheOwnArguments(): void
    {
        $spec = new ConstraintSpec('Length', [AttributeArgument::named('max', ArgumentValue::literal(3))]);
        $attribute = $spec->withGroups(['api', 'Default'])->toAttribute();

        self::assertSame(Length::class, $attribute->className()->fqcn());
        self::assertSame(['max', 'groups'], array_map(static fn (AttributeArgument $argument): ?string => $argument->name(), $attribute->arguments()));
        $alias = $attribute->importAlias();
        self::assertNotNull($alias);
        self::assertSame('Assert', $alias->alias());
        self::assertSame([], (new ConstraintSpec('Valid'))->withGroups([])->toAttribute()->arguments());
    }

    public function testKnowsItsNameAndBecomesANewInstance(): void
    {
        $spec = new ConstraintSpec('Valid');

        self::assertTrue($spec->is('Valid'));
        self::assertFalse($spec->is('All'));
        self::assertSame(Valid::class, $spec->toNewInstance()->className()->fqcn());
    }
}

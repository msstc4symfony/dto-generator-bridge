<?php

declare(strict_types=1);

namespace Msstc4Symfony\DtoGeneratorBridge\Validator;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Contract\SchemaReferences;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * What the validator reads of a class it enriches, or of an ancestor whose constraints it works out: the ancestor's
 * reports and marks stay apart, as its own enrichment makes them.
 *
 * @internal
 */
final class ClassScope
{
    private ClassModel $class;

    private Schema $schema;

    private TargetProfile $target;

    private InstalledPackages $packages;

    private Diagnostics $diagnostics;

    private SchemaReferences $references;

    private KeywordChecks $keywordChecks;

    /** @var list<ClassModel> nearest first */
    private array $ancestors;

    /**
     * @param list<ClassModel> $ancestors
     */
    private function __construct(ClassModel $class, Schema $schema, TargetProfile $target, InstalledPackages $packages, Diagnostics $diagnostics, SchemaReferences $references, KeywordChecks $keywordChecks, array $ancestors)
    {
        $this->class = $class;
        $this->schema = $schema;
        $this->target = $target;
        $this->packages = $packages;
        $this->diagnostics = $diagnostics;
        $this->references = $references;
        $this->keywordChecks = $keywordChecks;
        $this->ancestors = $ancestors;
    }

    public static function of(ClassContext $context): self
    {
        return new self($context->class(), $context->schema(), $context->target(), $context->packages(), $context->diagnostics(), $context->references(), $context->keywordChecks(), $context->ancestors());
    }

    /**
     * @param list<ClassModel> $above the ancestor's own ancestors, nearest first
     */
    public function ancestor(ClassModel $ancestor, Schema $schema, array $above): self
    {
        return new self($ancestor, $schema, $this->target, $this->packages, new Diagnostics(), $this->references, new KeywordChecks(), $above);
    }

    public function class(): ClassModel
    {
        return $this->class;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function target(): TargetProfile
    {
        return $this->target;
    }

    public function packages(): InstalledPackages
    {
        return $this->packages;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    public function references(): SchemaReferences
    {
        return $this->references;
    }

    public function keywordChecks(): KeywordChecks
    {
        return $this->keywordChecks;
    }

    /**
     * @return list<ClassModel>
     */
    public function ancestors(): array
    {
        return $this->ancestors;
    }
}

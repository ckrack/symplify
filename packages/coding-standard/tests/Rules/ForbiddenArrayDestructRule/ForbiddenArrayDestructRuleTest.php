<?php

declare(strict_types=1);

namespace Symplify\CodingStandard\Tests\Rules\ForbiddenArrayDestructRule;

use Iterator;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Symplify\CodingStandard\PhpParser\NodeNameResolver;
use Symplify\CodingStandard\Rules\ForbiddenArrayDestructRule;

final class ForbiddenArrayDestructRuleTest extends RuleTestCase
{
    /**
     * @dataProvider provideData()
     */
    public function testRule(string $filePath, array $expectedErrorsWithLines): void
    {
        $this->analyse([$filePath], $expectedErrorsWithLines);
    }

    public function provideData(): Iterator
    {
        yield [__DIR__ . '/Fixture/ClassWithArrayDestruct.php', [[ForbiddenArrayDestructRule::ERROR_MESSAGE, 11]]];
        yield [__DIR__ . '/Fixture/SkipSwap.php', []];
        yield [__DIR__ . '/Fixture/SkipExplode.php', []];
        yield [__DIR__ . '/Fixture/SkipStringsSplit.php', []];
    }

    protected function getRule(): Rule
    {
        return new ForbiddenArrayDestructRule(new NodeNameResolver());
    }
}

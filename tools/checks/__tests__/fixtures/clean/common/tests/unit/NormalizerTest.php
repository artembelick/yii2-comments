<?php

final class NormalizerTest
{
    #[TestDox('UNIT-NORM-01: обрезает пробелы')]
    public function testTrims(): void
    {
        $this->assertSame('a', trim(' a '));
    }
}

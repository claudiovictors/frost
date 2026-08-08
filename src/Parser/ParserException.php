<?php

declare(strict_types=1);

namespace Frost\Parser;

final class ParserException extends \RuntimeException
{
    public readonly int $sourceLine;
    public readonly int $sourceColumn;

    public function __construct(string $message, int $sourceLine, int $sourceColumn)
    {
        $this->sourceLine = $sourceLine;
        $this->sourceColumn = $sourceColumn;
        parent::__construct(sprintf('%s (linha %d, coluna %d)', $message, $sourceLine, $sourceColumn));
    }
}
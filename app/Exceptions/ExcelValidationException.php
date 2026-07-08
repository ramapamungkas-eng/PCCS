<?php

namespace App\Exceptions;

class ExcelValidationException extends \Exception
{
    /**
     * @var ExcelValidationFailure[]
     */
    private readonly array $failures;

    public function __construct(ExcelValidationFailure ...$failures)
    {
        parent::__construct('Excel import validation failed.');
        $this->failures = $failures;
    }

    /**
     * @return ExcelValidationFailure[]
     */
    public function failures(): array
    {
        return $this->failures;
    }
}

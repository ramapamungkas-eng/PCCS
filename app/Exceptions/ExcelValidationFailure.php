<?php

namespace App\Exceptions;

class ExcelValidationFailure
{
    public function __construct(
        private readonly int $row,
        private readonly string $attribute,
        private readonly array $errors,
    ) {
    }

    public function row(): int
    {
        return $this->row;
    }

    public function attribute(): string
    {
        return $this->attribute;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

<?php

namespace App\Integrations\Shopify\Exceptions;

use RuntimeException;

class ShopifyGraphqlException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $errors
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Shopify GraphQL returned one or more errors.',
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

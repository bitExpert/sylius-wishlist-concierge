<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistCreateRequest;
use BitExpert\SyliusWishlistConciergePlugin\Service\ValidationErrorFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ValidationErrorFormatterTest extends TestCase
{
    public function testFormatsViolationsIntoFieldMessageTuples(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $formatter = new ValidationErrorFormatter();

        $dto = new WishlistCreateRequest();
        $dto->name = '';

        $errors = $formatter->format($validator->validate($dto));

        self::assertIsArray($errors);
        self::assertNotEmpty($errors);
        foreach ($errors as $error) {
            self::assertArrayHasKey('field', $error);
            self::assertArrayHasKey('message', $error);
        }
    }
}

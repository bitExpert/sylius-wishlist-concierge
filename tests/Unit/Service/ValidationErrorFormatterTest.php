<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Dto\WishlistCreateRequest;
use BitExpert\SyliusWishlistConciergePlugin\Service\ValidationErrorFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validation;

final class ValidationErrorFormatterTest extends TestCase
{
    public function testFormatsViolationsIntoFieldMessageTuples(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $formatter = new ValidationErrorFormatter();

        $dto = new WishlistCreateRequest();
        $dto->name = '';
        $dto->theme = '';

        $errors = $formatter->format($validator->validate($dto));

        self::assertNotEmpty($errors);
        foreach ($errors as $error) {
            self::assertArrayHasKey('field', $error);
            self::assertArrayHasKey('message', $error);
        }
    }

    public function testFormatEmitsOneEntryPerViolationWithFieldPath(): void
    {
        $formatter = new ValidationErrorFormatter();

        $violations = new ConstraintViolationList([
            new ConstraintViolation('Name must not be blank.', null, [], null, 'name', ''),
            new ConstraintViolation('Theme must not be blank.', null, [], null, 'theme', ''),
        ]);

        $errors = $formatter->format($violations);

        self::assertCount(2, $errors);
        self::assertSame(['field' => 'name', 'message' => 'Name must not be blank.'], $errors[0]);
        self::assertSame(['field' => 'theme', 'message' => 'Theme must not be blank.'], $errors[1]);
    }
}

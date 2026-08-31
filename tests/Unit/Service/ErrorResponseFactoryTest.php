<?php

declare(strict_types=1);

namespace Tests\BitExpert\SyliusWishlistConciergePlugin\Unit\Service;

use BitExpert\SyliusWishlistConciergePlugin\Service\ErrorResponseFactory;
use BitExpert\SyliusWishlistConciergePlugin\Service\ValidationErrorFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validation;

final class ErrorResponseFactoryTest extends TestCase
{
    private ErrorResponseFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ErrorResponseFactory();
    }

    public function testInvalidJson(): void
    {
        $response = $this->factory->invalidJson();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        self::assertSame('Invalid JSON payload', $payload['error']);
        self::assertArrayNotHasKey('violations', $payload);
    }

    public function testValidationFailedShapesViolations(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $violations = $validator->validate('', [new NotBlank(), new Length(min: 5)]);

        $response = $this->factory->validationFailed($violations, new ValidationErrorFormatter());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        self::assertSame('Validation failed', $payload['error']);
        self::assertIsArray($payload['violations']);
        self::assertNotEmpty($payload['violations']);
        self::assertArrayHasKey('field', $payload['violations'][0]);
        self::assertArrayHasKey('message', $payload['violations'][0]);
    }

    public function testGenericError(): void
    {
        $response = $this->factory->error('Not found', 'The resource does not exist.', Response::HTTP_NOT_FOUND);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $payload = json_decode($response->getContent(), true);
        self::assertSame('Not found', $payload['error']);
        self::assertSame('The resource does not exist.', $payload['message']);
    }

    public function testSetContentTypeJson(): void
    {
        $response = $this->factory->invalidJson();

        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }
}

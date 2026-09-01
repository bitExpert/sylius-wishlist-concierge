<?php

/*
 * This file is part of the Sylius Wishlist Concierge package.
 *
 * (c) bitExpert AG
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class BudgetOptimizeRequest
{
    use DtoFromRequest;

    #[Assert\NotNull]
    #[Assert\Positive(message: 'budgetCents must be positive.')]
    #[Assert\LessThanOrEqual(value: 10000000, message: 'Budget too large (max $100,000).')]
    public int $budgetCents = 0;

    public bool $includePromotions = true;

    // Accepts legacy "budget" alias from earlier API clients
    public function setBudget(?int $budget): void
    {
        if (null !== $budget && 0 === $this->budgetCents) {
            $this->budgetCents = $budget;
        }
    }
}

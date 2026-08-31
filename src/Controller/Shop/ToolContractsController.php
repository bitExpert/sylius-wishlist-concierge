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

namespace BitExpert\SyliusWishlistConciergePlugin\Controller\Shop;

use BitExpert\SyliusWishlistConciergePlugin\Service\ToolContractMetadata;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ToolContractsController extends AbstractController
{
    public function __construct(
        private readonly ToolContractMetadata $metadata,
    ) {
    }

    #[Route('/concierge/contracts', name: 'bitexpert_concierge_tool_contracts', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json($this->metadata->all());
    }

    #[Route('/concierge/contracts/{tool}', name: 'bitexpert_concierge_tool_contract', methods: ['GET'])]
    public function show(string $tool): JsonResponse
    {
        $contract = $this->metadata->contract($tool);
        if (null === $contract) {
            return $this->json(['error' => 'Unknown tool'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($contract);
    }
}

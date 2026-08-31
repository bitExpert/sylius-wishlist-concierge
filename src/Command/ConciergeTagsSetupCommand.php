<?php

declare(strict_types=1);

namespace BitExpert\SyliusWishlistConciergePlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Product\Model\ProductAttribute;
use Sylius\Component\Product\Model\ProductAttributeValue;
use Sylius\Component\Product\Repository\ProductAttributeRepositoryInterface;
use Sylius\Component\Product\Repository\ProductAttributeValueRepositoryInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'bitexpert:wishlist-concierge:setup-tags',
    description: 'Creates the concierge_tags product attribute and tags FASHION_WEB products.',
)]
final class ConciergeTagsSetupCommand extends Command
{
    private const string ATTRIBUTE_CODE = 'concierge_tags';

    /**
     * product code => tags[] (attribute codes)
     *
     * @var array<string, string[]>
     */
    private const array PRODUCT_TAGS = [
        'Ethereal_Drift_T_Shirt' => ['dino', 'summer', 'casual'],
        'Peach_Sunset_Tee' => ['summer', 'casual', 'gift'],
        'Retro_Rainbow_Tee' => ['birthday', 'dino', 'gift'],
        'Neon_Drift_Tee' => ['dino', 'summer'],
        'Wanderlust_Tee' => ['casual', 'summer'],
        'Classic_Denim_Jeans' => ['casual', 'winter'],
        'Slim_Fit_Jeans' => ['formal', 'winter'],
        'Aria_Midi_Dress' => ['formal', 'gift', 'summer'],
        'Bella_Sundress' => ['summer', 'casual'],
        'Capri_Summer_Cap' => ['summer', 'dino', 'casual'],
        'Classic_Baseball_Cap' => ['dino', 'casual', 'gift'],
        'Polarized_Sun_Cap' => ['summer', 'dino'],
    ];

    public function __construct(
        private ProductAttributeRepositoryInterface $attributeRepository,
        private ProductAttributeValueRepositoryInterface $attributeValueRepository,
        private ProductRepositoryInterface $productRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('channel', 'c', InputOption::VALUE_REQUIRED, 'Channel code (default: FASHION_WEB)', 'FASHION_WEB')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without persisting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $channelCode = (string) $input->getOption('channel');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->warning('Dry-run mode: no changes will be persisted.');
        }

        // 1. Ensure attribute exists
        $attribute = $this->attributeRepository->findOneBy(['code' => self::ATTRIBUTE_CODE]);
        if (null === $attribute) {
            $io->section("Creating attribute `{$this->attributeCode()}` (type: selection, multiple: true)");
            $attribute = new ProductAttribute();
            $attribute->setCode(self::ATTRIBUTE_CODE);
            $attribute->setType('selection');
            $attribute->setMultiple(true);
            $attribute->setRequired(false);

            if (!$dryRun) {
                $this->entityManager->persist($attribute);
                $this->entityManager->flush();
            }
            $io->success('Attribute created.');
        } else {
            $io->writeln(sprintf('Attribute `%s` already exists (id: %d).', self::ATTRIBUTE_CODE, $attribute->getId()));
        }

        // 2. Ensure attribute values exist
        $tagsToCreate = [];
        foreach (self::PRODUCT_TAGS as $tags) {
            foreach ($tags as $tag) {
                $tagsToCreate[$tag] = true;
            }
        }
        $tagsToCreate = array_keys($tagsToCreate);

        $io->section("Ensuring attribute values: " . implode(', ', $tagsToCreate));
        foreach ($tagsToCreate as $tag) {
            $existing = $this->attributeValueRepository->findOneBy(['attribute' => $attribute, 'value' => $tag]);
            if (null !== $existing) {
                continue;
            }
            $io->writeln("  - creating: {$tag}");
            $av = new ProductAttributeValue();
            $av->setAttribute($attribute);
            $av->setValue($tag);

            if (!$dryRun) {
                $this->entityManager->persist($av);
                $this->entityManager->flush();
            }
        }
        $io->writeln('  All values present.');

        // 3. Tag products
        $io->section("Tagging products in channel `{$channelCode}`");

        $products = $this->productRepository->findBy([
            'enabled' => true,
        ]);

        $tagged = 0;
        foreach ($products as $product) {
            $code = $product->getCode();
            if (!isset(self::PRODUCT_TAGS[$code])) {
                continue;
            }

            // Only tag products that are enabled in the target channel
            $inChannel = false;
            foreach ($product->getChannels() as $channel) {
                if ($channel->getCode() === $channelCode) {
                    $inChannel = true;
                    break;
                }
            }
            if (!$inChannel) {
                $io->writeln(sprintf('  - skipping %s (not in channel %s)', $code, $channelCode));
                continue;
            }

            $tags = self::PRODUCT_TAGS[$code];
            $name = (string) $product->getTranslation(null)?->getName();
            $io->writeln(sprintf('  - %s (%s) => [%s]', $code, $name, implode(', ', $tags)));

            if ($dryRun) {
                $tagged++;
                continue;
            }

            // Clear existing values for this attribute on this product (collect first —
            // modifying the collection during iteration is unsafe)
            $toRemove = [];
            foreach ($product->getAttributes() as $av) {
                if ($av->getAttribute()?->getCode() === self::ATTRIBUTE_CODE) {
                    $toRemove[] = $av;
                }
            }
            foreach ($toRemove as $av) {
                $product->removeAttribute($av);
            }

            foreach ($tags as $tag) {
                $av = $this->attributeValueRepository->findOneBy(['attribute' => $attribute, 'value' => $tag]);
                if (null === $av) {
                    $io->error("Attribute value `{$tag}` not found.");
                    continue;
                }
                $product->addAttribute($av);
            }

            $this->entityManager->persist($product);
            $tagged++;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success("Tagged {$tagged} products. (Dry-run: {$dryRun})");

        return Command::SUCCESS;
    }

    private function attributeCode(): string
    {
        return self::ATTRIBUTE_CODE;
    }
}

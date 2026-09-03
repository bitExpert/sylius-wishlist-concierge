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

namespace BitExpert\SyliusWishlistConciergePlugin\Command;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Product\Model\ProductAttribute;
use Sylius\Component\Product\Model\ProductAttributeInterface;
use Sylius\Component\Product\Model\ProductAttributeValue;
use Sylius\Component\Product\Repository\ProductAttributeRepositoryInterface;
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
        'Ethereal_Drift_T_Shirt' => ['summer', 'casual'],
        'Peach_Sunset_Tee' => ['summer', 'casual', 'gift'],
        'Retro_Rainbow_Tee' => ['birthday', 'gift'],
        'Neon_Drift_Tee' => ['summer'],
        'Wanderlust_Tee' => ['casual', 'summer'],
        'Classic_Denim_Jeans' => ['casual', 'winter'],
        'Slim_Fit_Jeans' => ['formal', 'winter'],
        'Aria_Midi_Dress' => ['formal', 'gift', 'summer'],
        'Bella_Sundress' => ['summer', 'casual'],
        'Capri_Summer_Cap' => ['summer', 'casual'],
        'Classic_Baseball_Cap' => ['casual', 'gift'],
        'Polarized_Sun_Cap' => ['summer'],
    ];

    /**
     * @phpstan-param ProductAttributeRepositoryInterface<\Sylius\Component\Product\Model\ProductAttributeInterface> $attributeRepository
     * @phpstan-param ProductRepositoryInterface<\Sylius\Component\Core\Model\ProductInterface> $productRepository
     */
    public function __construct(
        private ProductAttributeRepositoryInterface $attributeRepository,
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

        $localeCode = $this->defaultLocaleCode();

        // 1. Ensure the attribute exists (select attribute, JSON storage, multi-select).
        $attribute = $this->attributeRepository->findOneBy(['code' => self::ATTRIBUTE_CODE]);
        if (null === $attribute) {
            $io->section("Creating attribute `{$this->attributeCode()}` (type: selection, multiple: true)");
            $attribute = new ProductAttribute();
            $attribute->setCode(self::ATTRIBUTE_CODE);
            $attribute->setType('select');
            $attribute->setStorageType('json');
            $attribute->setConfiguration(['multiple' => true]);

            if (!$dryRun) {
                $this->entityManager->persist($attribute);
                $this->entityManager->flush();
            }
            $io->success('Attribute created.');
        } else {
            $io->writeln(sprintf('Attribute `%s` already exists (id: %d).', self::ATTRIBUTE_CODE, $attribute->getId()));
        }

        // 2. Ensure every tag is declared as a choice of the attribute (select choices
        //    live in the attribute configuration, not as standalone attribute-value rows).
        $io->section('Ensuring attribute choices: ' . implode(', ', $this->tags()));
        $config = $attribute->getConfiguration();
        $config['multiple'] = true;
        $choices = $config['choices'] ?? [];

        $changed = false;
        foreach ($this->tags() as $tag) {
            if (isset($choices[$tag])) {
                continue;
            }
            $io->writeln("  - adding choice: {$tag}");
            $choices[$tag] = [$localeCode => $tag];
            $changed = true;
        }
        if ($changed) {
            $config['choices'] = $choices;
            $attribute->setConfiguration($config);

            if (!$dryRun) {
                $this->entityManager->persist($attribute);
                $this->entityManager->flush();
            }
        }
        $io->writeln('  All choices present.');

        // 3. Tag products.
        $io->section("Tagging products in channel `{$channelCode}`");

        /** @var array<int, ProductInterface> $products */
        $products = $this->productRepository->findBy(['enabled' => true]);

        $tagged = 0;
        foreach ($products as $product) {
            $code = $product->getCode();
            if (!isset(self::PRODUCT_TAGS[$code])) {
                continue;
            }

            // Only tag products that are enabled in the target channel.
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
            $name = (string) $product->getTranslation(null)->getName();
            $io->writeln(sprintf('  - %s (%s) => [%s]', $code, $name, implode(', ', $tags)));

            if ($dryRun) {
                ++$tagged;

                continue;
            }

            $this->setTags($product, $attribute, $tags, $localeCode);
            ++$tagged;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success("Tagged {$tagged} products. (Dry-run: {$dryRun})");

        return Command::SUCCESS;
    }

    /**
     * Replace the product's `concierge_tags` value with the given tags.
     *
     * @param string[] $tags
     */
    private function setTags(
        ProductInterface $product,
        ProductAttributeInterface $attribute,
        array $tags,
        string $localeCode,
    ): void {
        foreach ($product->getAttributes() as $existing) {
            if ($existing->getAttribute()?->getCode() === self::ATTRIBUTE_CODE) {
                $product->removeAttribute($existing);
                $this->entityManager->remove($existing);
            }
        }

        $value = new ProductAttributeValue();
        $value->setProduct($product);
        $value->setAttribute($attribute);
        $value->setLocaleCode($localeCode);
        $value->setValue($tags);

        $product->addAttribute($value);
        $this->entityManager->persist($value);
    }

    /**
     * @return string[]
     */
    private function tags(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::PRODUCT_TAGS))));
    }

    private function defaultLocaleCode(): string
    {
        return 'en_US';
    }

    private function attributeCode(): string
    {
        return self::ATTRIBUTE_CODE;
    }
}

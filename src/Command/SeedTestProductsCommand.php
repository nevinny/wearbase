<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Repository\BrandRepository;
use App\Repository\ProductCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nevinny\AdminCoreBundle\Enum\Statuses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed:test-products', description: 'Создаёт тестовые товары для проверки карточки и флоу заказа')]
class SeedTestProductsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private BrandRepository $brandRepo,
        private ProductCategoryRepository $categoryRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $brand = $this->brandRepo->findOneBy(['status' => 'active']);
        if (!$brand) {
            $io->error('Нет активных брендов. Сначала создайте бренд.');
            return Command::FAILURE;
        }

        $categories = $this->categoryRepo->findBy(['status' => 'active']);
        if (empty($categories)) {
            $io->error('Нет активных категорий.');
            return Command::FAILURE;
        }

        $testProducts = $this->buildTestProducts($brand, $categories);
        foreach ($testProducts as $data) {
            $product = new Product();
            $product->setBrand($brand);
            $product->setTitle($data['title']);
            $product->setSlug($data['slug']);
            $product->setCategory($data['category']);
            $product->setGender($data['gender']);
            $product->setAnons($data['anons']);
            $product->setDescription($data['description']);
            $product->setStatus(Statuses::Active);

            foreach ($data['variants'] as $v) {
                $variant = new ProductVariant();
                $variant->setProduct($product);
                $variant->setSize($v['size']);
                $variant->setPrice((string) $v['price']);
                if (isset($v['compare_price'])) {
                    $variant->setComparePrice((string) $v['compare_price']);
                }
                $variant->setStockQty($v['stock']);
                $variant->setStatus('active');
                $variant->setSku($data['slug'] . '-' . $v['size']);
                $this->em->persist($variant);
            }

            $this->em->persist($product);
        }

        $this->em->flush();

        $io->success('Создано ' . count($testProducts) . ' тестовых товаров для бренда "' . $brand->getTitle() . '"');

        return Command::SUCCESS;
    }

    private function buildTestProducts(mixed $brand, array $categories): array
    {
        $cat = fn(int $i) => $categories[$i % count($categories)];

        return [
            [
                'title' => 'Классическая футболка с логотипом',
                'slug' => 'test-tshirt-logo',
                'category' => $cat(0),
                'gender' => 'unisex',
                'anons' => 'Базовая футболка из органического хлопка с принтом логотипа.',
                'description' => 'Футболка прямого кроя из мягкого хлопка 180 г/м². Принт нанесён шелкографией. Состав: 100% хлопок. Доступна в размерах XS-3XL.',
                'variants' => [
                    ['size' => 'XS', 'price' => 1990, 'compare_price' => 2490, 'stock' => 5],
                    ['size' => 'S', 'price' => 1990, 'compare_price' => 2490, 'stock' => 10],
                    ['size' => 'M', 'price' => 1990, 'compare_price' => 2490, 'stock' => 15],
                    ['size' => 'L', 'price' => 1990, 'compare_price' => null, 'stock' => 12],
                    ['size' => 'XL', 'price' => 2190, 'compare_price' => null, 'stock' => 8],
                    ['size' => '2XL', 'price' => 2390, 'compare_price' => null, 'stock' => 3],
                ],
            ],
            [
                'title' => 'Худи с капюшоном Oversize',
                'slug' => 'test-hoodie-oversize',
                'category' => $cat(1),
                'gender' => 'men',
                'anons' => 'Уютное худи свободного кроя с крупным принтом на спине.',
                'description' => 'Худи из плотного футера 280 г/м² с начёсом. Капюшон двойной, карман-кенгуру спереди. Свободный крой (oversize). Состав: 80% хлопок, 20% полиэстер.',
                'variants' => [
                    ['size' => 'S', 'price' => 3990, 'compare_price' => 4990, 'stock' => 7],
                    ['size' => 'M', 'price' => 3990, 'compare_price' => 4990, 'stock' => 10],
                    ['size' => 'L', 'price' => 3990, 'compare_price' => null, 'stock' => 8],
                    ['size' => 'XL', 'price' => 4290, 'compare_price' => null, 'stock' => 5],
                ],
            ],
            [
                'title' => 'Джинсы прямого кроя',
                'slug' => 'test-jeans-straight',
                'category' => $cat(2),
                'gender' => 'men',
                'anons' => 'Классические джинсы прямого кроя из плотного денима.',
                'description' => 'Прямые джинсы средней посадки из японского денима 14 oz. Натуральный индиго, жёсткая варка (raw denim). Застёжка на пуговицах, 5 карманов.',
                'variants' => [
                    ['size' => '30', 'price' => 5990, 'compare_price' => null, 'stock' => 4],
                    ['size' => '32', 'price' => 5990, 'compare_price' => null, 'stock' => 8],
                    ['size' => '34', 'price' => 5990, 'compare_price' => null, 'stock' => 6],
                    ['size' => '36', 'price' => 6290, 'compare_price' => null, 'stock' => 2],
                ],
            ],
            [
                'title' => 'Платье-миди с цветочным принтом',
                'slug' => 'test-dress-midi-floral',
                'category' => $cat(3),
                'gender' => 'women',
                'anons' => 'Женственное платье миди с ярким цветочным принтом.',
                'description' => 'Платье миди из вискозы с цветочным принтом. Рукав-фонарик, V-образный вырез, пояс в тон. Состав: 100% вискоза. Машинная стирка при 30°C.',
                'variants' => [
                    ['size' => 'XS', 'price' => 4990, 'compare_price' => 5990, 'stock' => 3],
                    ['size' => 'S', 'price' => 4990, 'compare_price' => 5990, 'stock' => 6],
                    ['size' => 'M', 'price' => 4990, 'compare_price' => null, 'stock' => 8],
                    ['size' => 'L', 'price' => 5290, 'compare_price' => null, 'stock' => 4],
                ],
            ],
            [
                'title' => 'Спортивный костюм (джоггеры + свитшот)',
                'slug' => 'test-tracksuit-jogger',
                'category' => $cat(4),
                'gender' => 'unisex',
                'anons' => 'Комфортный спортивный костюм джоггеры и оверсайз-свитшот.',
                'description' => 'Комплект из джоггеров на резинке и свободного свитшота. Футер 240 г/м² с начёсом. Состав: 70% хлопок, 30% полиэстер. Карманы на молнии в джоггерах.',
                'variants' => [
                    ['size' => 'S', 'price' => 5490, 'compare_price' => null, 'stock' => 5],
                    ['size' => 'M', 'price' => 5490, 'compare_price' => null, 'stock' => 10],
                    ['size' => 'L', 'price' => 5490, 'compare_price' => null, 'stock' => 8],
                    ['size' => 'XL', 'price' => 5790, 'compare_price' => null, 'stock' => 0],
                ],
            ],
            [
                'title' => 'Кроссовки кожаные минималистичные',
                'slug' => 'test-sneakers-leather-minimal',
                'category' => $cat(5),
                'gender' => 'men',
                'anons' => 'Лаконичные кожаные кроссовки на толстой подошве.',
                'description' => 'Кроссовки из натуральной матовой кожи 1.4 мм. Подошва EVA + резина (высота 4 см). Стелька анатомическая. Цвет — чёрный. Комплектуются запасными шнурками.',
                'variants' => [
                    ['size' => '40', 'price' => 8990, 'compare_price' => 10990, 'stock' => 4],
                    ['size' => '41', 'price' => 8990, 'compare_price' => 10990, 'stock' => 6],
                    ['size' => '42', 'price' => 8990, 'compare_price' => null, 'stock' => 8],
                    ['size' => '43', 'price' => 8990, 'compare_price' => null, 'stock' => 5],
                    ['size' => '44', 'price' => 9290, 'compare_price' => null, 'stock' => 2],
                ],
            ],
            [
                'title' => 'Шёлковый платок Handmade',
                'slug' => 'test-scarf-silk-handmade',
                'category' => $cat(6),
                'gender' => 'women',
                'anons' => 'Платок ручной росписи из натурального шёлка.',
                'description' => 'Платок из натурального шёлка 25 мм. Края обработаны вручную. Ручная роспись — каждый платок уникален. Размер 90×90 см. Уход: ручная стирка или химчистка.',
                'variants' => [
                    ['size' => 'OS', 'price' => 3990, 'compare_price' => null, 'stock' => 15],
                ],
            ],
            [
                'title' => 'Рюкзак городской из переработанного нейлона',
                'slug' => 'test-backpack-eco-nylon',
                'category' => $cat(7),
                'gender' => 'unisex',
                'anons' => 'Экологичный рюкзак из переработанного нейлона на 25 литров.',
                'description' => 'Городской рюкзак из переработанного нейлона (100% rPET). Объём 25 л. Карман для ноутбука до 15.6", органайзер для мелочей. Лямки анатомические с дышащей сеткой. Водоотталкивающий.',
                'variants' => [
                    ['size' => 'OS', 'price' => 4990, 'compare_price' => 5990, 'stock' => 10],
                ],
            ],
        ];
    }
}

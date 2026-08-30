<?php

declare(strict_types=1);

namespace App\Controller\Cart;

/**
 * Гонка внутри транзакции оформления заказа: остаток изменился между
 * предварительной проверкой (до создания сущностей) и фактическим списанием.
 * Бросаем вместо `return`, чтобы wrapInTransaction откатил ВСЕ заказы этого
 * чекаута целиком — либо создаются все, либо ни одного.
 */
final class InsufficientStockException extends \RuntimeException
{
}

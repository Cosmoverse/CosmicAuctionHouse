<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use pocketmine\player\Player;
use Ramsey\Uuid\Uuid;
use function time;

final class AuctionHouseLog{

	public function __construct(
		readonly public string $uuid,
		readonly public int $item_id,
		readonly public AuctionHousePlayerIdentification $buyer,
		readonly public AuctionHousePlayerIdentification $seller,
		readonly public float $listing_price,
		readonly public float $purchase_price,
		readonly public int $purchase_time
	){}
}
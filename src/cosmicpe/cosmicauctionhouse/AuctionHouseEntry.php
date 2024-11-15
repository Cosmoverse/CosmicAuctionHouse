<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use pocketmine\player\Player;
use Ramsey\Uuid\Uuid;
use function time;

final class AuctionHouseEntry{

	public static function new(Player $player, float $price, int $item_id, int $expiry, ?AuctionHouseBidInfo $bid_info) : self{
		return new self(Uuid::uuid4()->getBytes(), $item_id, AuctionHousePlayerIdentification::fromPlayer($player), $price, time(), $expiry, $bid_info);
	}

	public function __construct(
		readonly public string $uuid,
		readonly public int $item_id,
		readonly public AuctionHousePlayerIdentification $player,
		readonly public float $price,
		readonly public int $listing_time,
		readonly public int $expiry_time,
		readonly public ?AuctionHouseBidInfo $bid_info
	){}
}
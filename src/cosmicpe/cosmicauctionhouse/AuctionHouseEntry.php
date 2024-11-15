<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use pocketmine\item\Item;
use pocketmine\player\Player;
use Ramsey\Uuid\Uuid;
use function time;

final class AuctionHouseEntry{

	public static function new(Player $player, float $price, Item $item, int $expiry) : self{
		return new self(Uuid::uuid4()->getBytes(), AuctionHousePlayerIdentification::fromPlayer($player), $price, $item, time(), $expiry);
	}

	public static function from(AuctionHouseEntry $entry, int $expiry) : self{
		return new self($entry->uuid, $entry->player, $entry->price, $entry->item, $entry->listing_time, $expiry);
	}

	public function __construct(
		readonly public string $uuid,
		readonly public AuctionHousePlayerIdentification $player,
		readonly public float $price,
		readonly public Item $item,
		readonly public int $listing_time,
		readonly public int $expiry_time
	){}
}
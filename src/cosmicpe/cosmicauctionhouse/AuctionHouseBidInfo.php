<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use pocketmine\player\Player;

final class AuctionHouseBidInfo{

	public static function new(Player $player) : self{
		return new self($player->getUniqueId()->getBytes(), null, null, null, null, null);
	}

	public function __construct(
		readonly public string $uuid,
		readonly public ?AuctionHousePlayerIdentification $bidder,
		readonly public ?float $offer,
		readonly public ?int $placed_timestamp,
		readonly public ?int $completed_timestamp,
		readonly public ?int $offered_timestamp
	){}
}
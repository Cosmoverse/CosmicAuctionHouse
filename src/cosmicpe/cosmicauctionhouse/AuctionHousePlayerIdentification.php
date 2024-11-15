<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use pocketmine\player\Player;

final class AuctionHousePlayerIdentification{

	public static function fromPlayer(Player $player) : self{
		return new self($player->getUniqueId()->getBytes(), $player->getName());
	}

	public function __construct(
		readonly public string $uuid,
		readonly public string $gamertag
	){}
}
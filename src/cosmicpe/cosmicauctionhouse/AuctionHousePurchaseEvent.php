<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\player\PlayerEvent;
use pocketmine\item\Item;
use pocketmine\player\Player;

final class AuctionHousePurchaseEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		readonly public AuctionHouseEntry $entry,
		readonly public Item $item
	){
		$this->player = $player;
	}
}
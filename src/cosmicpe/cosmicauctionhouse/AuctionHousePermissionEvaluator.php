<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use pocketmine\player\Player;
use RuntimeException;
use function array_column;
use function implode;

/**
 * @template T of mixed
 */
final class AuctionHousePermissionEvaluator{

	/**
	 * @param list<array{string, T}> $values
	 */
	public function __construct(
		readonly public array $values
	){}

	/**
	 * @param Player $player
	 * @return T
	 */
	public function evaluate(Player $player) : mixed{
		foreach($this->values as [$permission, $value]){
			if($player->hasPermission($permission)){
				return $value;
			}
		}
		throw new RuntimeException("No fallback value configured for evaluator - " . implode(", ", array_column($this->values, 0)));
	}
}
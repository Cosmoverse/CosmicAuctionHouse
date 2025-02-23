<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Generator;
use Ramsey\Uuid\Uuid;
use function number_format;
use function sprintf;

final class NullAuctionHouseEconomy implements AuctionHouseEconomy{

	public static function instance() : self{
		static $instance = new self();
		return $instance;
	}

	private function __construct(){
	}

	public function formatBalance(float $balance) : string{
		return number_format($balance, 2);
	}

	public function getPrecision() : int{
		return 2;
	}

	public function addBalance(string $uuid, float $balance) : Generator{
		yield from [];
		throw new AuctionHouseException("Cannot add balance ({$balance}) to " . Uuid::fromBytes($uuid)->toString());
	}

	public function removeBalance(string $uuid, float $balance) : Generator{
		yield from [];
		throw new AuctionHouseException("Cannot remove balance ({$balance}) from " . Uuid::fromBytes($uuid)->toString());
	}

	public function getBalance(string $uuid) : Generator{
		throw new AuctionHouseException("Cannot retrieve balance from " . Uuid::fromBytes($uuid)->toString());
	}
}
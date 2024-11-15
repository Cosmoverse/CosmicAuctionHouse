<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Generator;
use SOFe\AwaitGenerator\Await;

interface AuctionHouseEconomy{

	public function formatBalance(float $balance) : string;

	public function getPrecision() : int;

	/**
	 * @param string $uuid
	 * @param float $balance
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function addBalance(string $uuid, float $balance) : Generator;

	/**
	 * @param string $uuid
	 * @param float $balance
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 * @throws AuctionHouseException
	 */
	public function removeBalance(string $uuid, float $balance) : Generator;

	/**
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, float>
	 * @throws AuctionHouseException
	 */
	public function getBalance(string $uuid) : Generator;
}
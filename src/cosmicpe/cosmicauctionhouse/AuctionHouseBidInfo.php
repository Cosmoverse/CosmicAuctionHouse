<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

final class AuctionHouseBidInfo{

	public function __construct(
		readonly public string $uuid,
		readonly public ?AuctionHousePlayerIdentification $bidder,
		readonly public ?float $offer,
		readonly public ?int $placed_timestamp,
		readonly public ?int $completed_timestamp,
		readonly public ?int $offered_timestamp
	){}
}
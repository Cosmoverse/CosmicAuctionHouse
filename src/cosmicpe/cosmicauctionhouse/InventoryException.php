<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Exception;

final class InventoryException extends Exception{

	public const int ERR_PLAYER_DISCONNECTED = 100001;
	public const int ERR_INVENTORY_CLOSED = 100002;
	public const int ERR_INVENTORY_NOT_SENT = 100003;
}
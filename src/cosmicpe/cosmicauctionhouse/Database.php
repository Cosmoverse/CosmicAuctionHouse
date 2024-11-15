<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Closure;
use Generator;
use pocketmine\item\Item;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use poggit\libasynql\SqlError;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use function array_column;
use function bin2hex;
use function hex2bin;
use function yaml_parse_file;

/**
 * @internal
 */
final class Database{

	public const string STMT_INIT_AUCTION_HOUSE = "auctionhouse.init.auction_house";
	public const string STMT_INIT_AUCTION_HOUSE_LOGS = "auctionhouse.init.auction_house_logs";
	public const string STMT_INIT_PLAYERS = "auctionhouse.init.players";
	public const string STMT_LOAD = "auctionhouse.load";
	public const string STMT_ADD = "auctionhouse.add";
	public const string STMT_LIST = "auctionhouse.list";
	public const string STMT_LOG = "auctionhouse.log";
	public const string STMT_REMOVE = "auctionhouse.remove";
	public const string STMT_UPDATE_EXPIRY = "auctionhouse.update_expiry";
	public const string STMT_PLAYER_INIT = "auctionhouse.player.init";
	public const string STMT_PLAYER_LISTINGS = "auctionhouse.player.listings";
	public const string STMT_PLAYER_STATS = "auctionhouse.player.stats";
	public const string STMT_PLAYER_BINNED = "auctionhouse.player.binned";

	/** @var Closure(SqlError) : Generator<mixed, Await::RESOLVE, void, void> */
	readonly private Closure $error_handler;

	readonly private BigEndianNbtSerializer $serializer;
	private DataConnector $connector;

	public function __construct(Loader $plugin){
		$this->serializer = new BigEndianNbtSerializer();
		$this->error_handler = static function(SqlError $error) use($plugin) : Generator{
			$plugin->getLogger()->logException($error);
			$plugin->getServer()->shutdown();
			yield from Await::promise(static function($resolve) : void{});
		};
		$plugin->saveResource("database.yml");
		$config = yaml_parse_file($plugin->getDataFolder() . "database.yml")["database"];
		$this->connector = libasynql::create($plugin, $config, ["sqlite" => "sqlite.sql"]);
		$this->connector->executeGeneric(self::STMT_INIT_PLAYERS);
		$this->connector->executeGeneric(self::STMT_INIT_AUCTION_HOUSE);
		$this->connector->executeGeneric(self::STMT_INIT_AUCTION_HOUSE_LOGS);
		$this->waitAll();
	}

	public function waitAll() : void{
		$this->connector->waitAll();
	}

	/**
	 * @param string $query
	 * @param array<string, mixed> $args
	 * @return Generator<mixed, Await::RESOLVE|Await::REJECT, mixed, array[]>
	 */
	public function asyncSelect(string $query, array $args = []) : Generator{
		try{
			return yield from $this->connector->asyncSelect($query, $args);
		}catch(SqlError $error){
			yield from ($this->error_handler)($error);
			throw new RuntimeException("should not happen");
		}
	}

	/**
	 * @param string $query
	 * @param array<string, mixed> $args
	 * @return Generator<mixed, Await::RESOLVE|Await::REJECT, mixed, int>
	 */
	public function asyncChange(string $query, array $args = []) : Generator{
		try{
			return yield from $this->connector->asyncChange($query, $args);
		}catch(SqlError $error){
			yield from ($this->error_handler)($error);
			throw new RuntimeException("should not happen");
		}
	}

	/**
	 * @param string $query
	 * @param array<string, mixed> $args
	 * @return Generator<mixed, Await::RESOLVE|Await::REJECT, mixed, array{int, int}>
	 */
	public function asyncInsert(string $query, array $args = []) : Generator{
		try{
			return yield from $this->connector->asyncInsert($query, $args);
		}catch(SqlError $error){
			yield from ($this->error_handler)($error);
			throw new RuntimeException("should not happen");
		}
	}

	public function serializeItem(Item $item) : string{
		return $this->serializer->write(new TreeRoot($item->nbtSerialize()));
	}

	public function deserializeItem(string $string) : Item{
		return Item::nbtDeserialize($this->serializer->read($string)->mustGetCompoundTag());
	}

	/**
	 * @return Generator<mixed, Await::RESOLVE, void, list<AuctionHouseEntry>>
	 */
	public function loadAll() : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_LOAD);
		$entries = [];
		foreach($rows as $row){
			$entries[] = new AuctionHouseEntry($row["uuid"], new AuctionHousePlayerIdentification($row["player"], $row["gamertag"]), $row["price"], $this->deserializeItem(hex2bin($row["item"])), $row["listing_time"], $row["expiry_time"]);
		}
		return $entries;
	}

	/**
	 * @param AuctionHouseEntry $entry
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function add(AuctionHouseEntry $entry) : Generator{
		yield from $this->asyncInsert(self::STMT_ADD, [
			"uuid" => $entry->uuid,
			"player" => $entry->player->uuid,
			"price" => $entry->price,
			"listing_time" => $entry->listing_time,
			"expiry_time" => $entry->expiry_time,
			"item" => bin2hex($this->serializeItem($entry->item))
		]);
	}

	/**
	 * @param AuctionHouseEntry $entry
	 * @param string $buyer
	 * @param float $purchase_price
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function log(AuctionHouseEntry $entry, string $buyer, float $purchase_price) : Generator{
		yield from $this->asyncInsert(self::STMT_LOG, [
			"uuid" => $entry->uuid,
			"buyer" => $buyer,
			"seller" => $entry->player->uuid,
			"purchase_price" => $purchase_price,
			"listing_price" => $entry->price,
			"listing_time" => $entry->listing_time,
			"item" => bin2hex($this->serializeItem($entry->item))
		]);
	}

	/**
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function remove(string $uuid) : Generator{
		yield from $this->asyncChange(self::STMT_REMOVE, ["uuid" => $uuid]);
	}

	/**
	 * @param int $offset
	 * @param int $length
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function list(int $offset, int $length) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_LIST, ["offset" => $offset, "length" => $length]);
		return array_column($rows, "uuid");
	}

	/**
	 * @param string $uuid
	 * @param int $time
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function updateExpiryTime(string $uuid, int $time) : Generator{
		yield from $this->asyncInsert(self::STMT_UPDATE_EXPIRY, ["uuid" => $uuid, "time" => $time]);
	}

	/**
	 * @param AuctionHousePlayerIdentification $player
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function initPlayer(AuctionHousePlayerIdentification $player) : Generator{
		yield from $this->asyncInsert(self::STMT_PLAYER_INIT, ["uuid" => $player->uuid, "gamertag" => $player->gamertag]);
	}

	/**
	 * @param string $player
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function getPlayerBin(string $player) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_PLAYER_BINNED, ["player" => $player]);
		return array_column($rows, "uuid");
	}

	/**
	 * @param string $player
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function getPlayerListings(string $player) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_PLAYER_LISTINGS, ["player" => $player]);
		return array_column($rows, "uuid");
	}

	/**
	 * @param string $player
	 * @return Generator<mixed, Await::RESOLVE, void, array{binned: int, listings: int}>
	 */
	public function getPlayerStats(string $player) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_PLAYER_STATS, ["player" => $player]);
		return $rows[0];
	}

	public function close() : void{
		if(isset($this->connector)){
			$this->connector->close();
			unset($this->connector);
		}
	}
}
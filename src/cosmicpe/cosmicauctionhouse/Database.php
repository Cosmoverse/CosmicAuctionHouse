<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Closure;
use Generator;
use pocketmine\item\Item;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use poggit\libasynql\DataConnector;
use poggit\libasynql\libasynql;
use poggit\libasynql\SqlError;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use function array_column;
use function array_map;
use function bin2hex;
use function hex2bin;
use function time;
use function yaml_parse_file;

/**
 * @internal
 */
final class Database{

	public const string STMT_INIT_AUCTION_HOUSE = "auctionhouse.init.auction_house";
	public const string STMT_INIT_AUCTION_HOUSE_BIDS = "auctionhouse.init.auction_house_bids";
	public const string STMT_INIT_AUCTION_HOUSE_COLLECTION_BIN = "auctionhouse.init.auction_house_collection_bin";
	public const string STMT_INIT_AUCTION_HOUSE_ITEMS = "auctionhouse.init.auction_house_items";
	public const string STMT_INIT_AUCTION_HOUSE_LOGS = "auctionhouse.init.auction_house_logs";
	public const string STMT_INIT_PLAYERS = "auctionhouse.init.players";
	public const string STMT_LOAD = "auctionhouse.load";
	public const string STMT_ADD_ITEM = "auctionhouse.add_item";
	public const string STMT_ADD = "auctionhouse.add";
	public const string STMT_ADD_COLLECTION_BIN = "auctionhouse.add_collection_bin";
	public const string STMT_REMOVE_COLLECTION_BIN = "auctionhouse.remove_collection_bin";
	public const string STMT_LOAD_COLLECTION_BIN = "auctionhouse.load_collection_bin";
	public const string STMT_BID = "auctionhouse.bid";
	public const string STMT_COUNT = "auctionhouse.count";
	public const string STMT_COUNT_GROUP = "auctionhouse.count_group";
	public const string STMT_COUNT_GROUPS = "auctionhouse.count_groups";
	public const string STMT_REMOVE_BID = "auctionhouse.remove_bid";
	public const string STMT_EXPIRING = "auctionhouse.expiring";
	public const string STMT_UNOFFERED_BIDS = "auctionhouse.unoffered_bids";
	public const string STMT_ITEM = "auctionhouse.item";
	public const string STMT_LIST = "auctionhouse.list";
	public const string STMT_LIST_GROUP = "auctionhouse.list_group";
	public const string STMT_LIST_GROUPS = "auctionhouse.list_groups";
	public const string STMT_LOG = "auctionhouse.log";
	public const string STMT_REMOVE = "auctionhouse.remove";
	public const string STMT_PLAYER_INIT = "auctionhouse.player.init";
	public const string STMT_PLAYER_LISTINGS = "auctionhouse.player.listings";
	public const string STMT_PLAYER_STATS = "auctionhouse.player.stats";

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
		$this->connector->executeGeneric(self::STMT_INIT_AUCTION_HOUSE_BIDS);
		$this->connector->executeGeneric(self::STMT_INIT_AUCTION_HOUSE_COLLECTION_BIN);
		$this->connector->executeGeneric(self::STMT_INIT_AUCTION_HOUSE_ITEMS);
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
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, AuctionHouseEntry|null>
	 */
	public function get(string $uuid) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_LOAD, ["uuid" => Uuid::fromBytes($uuid)->toString()]);
		if(count($rows) === 0){
			return null;
		}
		$row = $rows[0];
		$player = new AuctionHousePlayerIdentification(Uuid::fromString($row["player"])->getBytes(), $row["gamertag"]);
		$bid_info = $row["bidding"] ? new AuctionHouseBidInfo(Uuid::fromString($row["uuid"])->getBytes(), new AuctionHousePlayerIdentification(Uuid::fromString($row["bidder_uuid"])->getBytes(), $row["bidder_gamertag"]),
			$row["bidder_offer"], $row["bidder_placed"], $row["bidder_completed"], $row["bidder_offered"]) : null;
		return new AuctionHouseEntry(Uuid::fromString($row["uuid"])->getBytes(), $row["item_id"], $player, $row["price"], $row["listing_time"], $row["expiry_time"], $bid_info);
	}

	/**
	 * @param Item $item
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function addItem(Item $item) : Generator{
		$type = GlobalItemDataHandlers::getSerializer()->serializeType($item);
		[$id, ] = yield from $this->asyncInsert(self::STMT_ADD_ITEM, [
			"item" => bin2hex($this->serializeItem($item)),
			"item_name" => $type->getName(),
			"item_meta" => $type->getMeta()
		]);
		return $id;
	}

	/**
	 * @param int $id
	 * @return Generator<mixed, Await::RESOLVE, void, Item|null>
	 */
	public function getItem(int $id) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_ITEM, ["id" => $id]);
		if(count($rows) === 0){
			return null;
		}
		return $this->deserializeItem(hex2bin($rows[0]["item"]));
	}

	/**
	 * @param AuctionHouseEntry $entry
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function add(AuctionHouseEntry $entry) : Generator{
		yield from $this->asyncInsert(self::STMT_ADD, [
			"uuid" => Uuid::fromBytes($entry->uuid)->toString(),
			"item_id" => $entry->item_id,
			"player" => Uuid::fromBytes($entry->player->uuid)->toString(),
			"price" => $entry->price,
			"listing_time" => $entry->listing_time,
			"expiry_time" => $entry->expiry_time
		]);
	}

	/**
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function remove(string $uuid) : Generator{
		yield from $this->asyncChange(self::STMT_REMOVE, ["uuid" => Uuid::fromBytes($uuid)->toString()]);
	}

	/**
	 * @param string $uuid
	 * @param int $item_id
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function addToCollectionBin(string $uuid, int $item_id) : Generator{
		yield from $this->asyncInsert(self::STMT_ADD_COLLECTION_BIN, ["uuid" => Uuid::fromBytes($uuid)->toString(), "item_id" => $item_id, "placement_time" => time()]);
	}

	/**
	 * @param string $uuid
	 * @param int $item_id
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function removeFromCollectionBin(string $uuid, int $item_id) : Generator{
		yield from $this->asyncChange(self::STMT_REMOVE_COLLECTION_BIN, ["uuid" => Uuid::fromBytes($uuid)->toString(), "item_id" => $item_id]);
	}

	/**
	 * @param AuctionHouseBidInfo $info
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function bid(AuctionHouseBidInfo $info) : Generator{
		yield from $this->asyncInsert(self::STMT_BID, [
			"uuid" => Uuid::fromBytes($info->uuid)->toString(),
			"bidder" => Uuid::fromBytes($info->bidder->uuid)->toString(),
			"offer" => $info->offer,
			"placed" => $info->placed_timestamp,
			"completed" => $info->completed_timestamp,
			"offered" => $info->offered_timestamp
		]);
	}

	/**
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function removeBid(string $uuid) : Generator{
		yield from $this->asyncSelect(self::STMT_REMOVE_BID, ["uuid" => Uuid::fromBytes($uuid)->toString()]);
	}

	/**
	 * @param int $remaining
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function expiring(int $remaining) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_EXPIRING, ["remaining" => $remaining]);
		return array_map(static fn($x) => Uuid::fromString($x)->getBytes(), array_column($rows, "uuid"));
	}

	/**
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function unofferedBids() : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_UNOFFERED_BIDS);
		return array_map(static fn($x) => Uuid::fromString($x)->getBytes(), array_column($rows, "uuid"));
	}

	/**
	 * @param AuctionHouseEntry $entry
	 * @param string $buyer
	 * @param float $purchase_price
	 * @param int $purchase_time
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function log(AuctionHouseEntry $entry, string $buyer, float $purchase_price, int $purchase_time) : Generator{
		yield from $this->asyncInsert(self::STMT_LOG, [
			"uuid" => Uuid::fromBytes($entry->uuid)->toString(),
			"item_id" => $entry->item_id,
			"buyer" => $buyer,
			"seller" => Uuid::fromBytes($entry->player->uuid)->toString(),
			"listing_price" => $entry->price,
			"purchase_price" => $purchase_price,
			"purchase_time" => $purchase_time
		]);
	}

	/**
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function count() : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_COUNT);
		return $rows[0]["c"];
	}

	/**
	 * @param int $offset
	 * @param int $length
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function list(int $offset, int $length) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_LIST, ["offset" => $offset, "length" => $length]);
		return array_map(static fn($x) => Uuid::fromString($x)->getBytes(), array_column($rows, "uuid"));
	}

	/**
	 * @param string $item_name
	 * @param int $item_meta
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function countGroup(string $item_name, int $item_meta) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_COUNT_GROUP, ["item_name" => $item_name, "item_meta" => $item_meta]);
		return $rows[0]["c"] ?? 0;
	}

	/**
	 * @param string $item_name
	 * @param int $item_meta
	 * @param int $offset
	 * @param int $length
	 * @return Generator<mixed, Await::RESOLVE, void, list<array{string, int}>>
	 */
	public function listGroup(string $item_name, int $item_meta, int $offset, int $length) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_LIST_GROUP, ["item_name" => $item_name, "item_meta" => $item_meta, "offset" => $offset, "length" => $length]);
		return array_map(static fn($x) => Uuid::fromString($x)->getBytes(), array_column($rows, "uuid"));
	}

	/**
	 * @return Generator<mixed, Await::RESOLVE, void, int>
	 */
	public function countGroups() : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_COUNT_GROUPS);
		return $rows[0]["c"] ?? 0;
	}

	/**
	 * @param int $offset
	 * @param int $length
	 * @return Generator<mixed, Await::RESOLVE, void, list<array{string, string, int, int}>>
	 */
	public function listGroups(int $offset, int $length) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_LIST_GROUPS, ["offset" => $offset, "length" => $length]);
		$result = [];
		foreach($rows as ["uuid" => $uuid, "item_name" => $item_name, "item_meta" => $item_meta, "c" => $count]){
			$result[] = [Uuid::fromString($uuid)->getBytes(), $item_name, $item_meta, $count];
		}
		return $result;
	}

	/**
	 * @param AuctionHousePlayerIdentification $player
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function initPlayer(AuctionHousePlayerIdentification $player) : Generator{
		yield from $this->asyncInsert(self::STMT_PLAYER_INIT, ["uuid" => Uuid::fromBytes($player->uuid)->toString(), "gamertag" => $player->gamertag]);
	}

	/**
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, list<array{int, int}>>
	 */
	public function getCollectionBin(string $uuid) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_LOAD_COLLECTION_BIN, ["uuid" => Uuid::fromBytes($uuid)->toString()]);
		$result = [];
		foreach($rows as ["item_id" => $item_id, "placement_time" => $placement_time]){
			$result[] = [$item_id, $placement_time];
		}
		return $result;
	}

	/**
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, list<string>>
	 */
	public function getPlayerListings(string $uuid) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_PLAYER_LISTINGS, ["player" => Uuid::fromBytes($uuid)->toString()]);
		return array_map(static fn($x) => Uuid::fromString($x)->getBytes(), array_column($rows, "uuid"));
	}

	/**
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, array{binned: int, listings: int}>
	 */
	public function getPlayerStats(string $uuid) : Generator{
		$rows = yield from $this->asyncSelect(self::STMT_PLAYER_STATS, ["player" => Uuid::fromBytes($uuid)->toString()]);
		return $rows[0];
	}

	public function close() : void{
		if(isset($this->connector)){
			$this->connector->close();
			unset($this->connector);
		}
	}
}
<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Generator;
use InvalidArgumentException;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\transaction\DeterministicInvMenuTransaction;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskScheduler;
use pocketmine\utils\TextFormat;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\Mutex;
use function array_column;
use function array_keys;
use function array_map;
use function array_push;
use function array_values;
use function assert;
use function ceil;
use function count;
use function min;
use function sprintf;
use function str_replace;
use function strtr;
use function time;
use function usort;

final class AuctionHouse{

	public const ENTRIES_PER_PAGE = 45;
	public const ITEM_ID_COLLECTION_BIN = "__collection_bin:item_preview";
	public const ITEM_ID_CONFIRM_BID = "__confirm_bid:item_preview";
	public const ITEM_ID_CONFIRM_BUY = "__confirm_buy:item_preview";
	public const ITEM_ID_CONFIRM_SELL = "__confirm_sell:item_preview";
	public const ITEM_ID_MAIN_MENU_NORMAL = "__main_menu:item_preview_normal";
	public const ITEM_ID_MAIN_MENU_GROUPED = "__main_menu:item_preview_grouped";
	public const ITEM_ID_MAIN_MENU_BID = "__main_menu:item_preview_bid";
	public const ITEM_ID_PERSONAL_LISTING = "__personal_listing:item_preview";

	readonly private Mutex $lock;

	/** @var array<int, Item> */
	private array $entry_cache = [];

	/** @var array<int, Item> */
	private array $item_cache = [];

	/**
	 * @param TaskScheduler $scheduler
	 * @param array<non-empty-string, Item> $item_registry
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_main_menu
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_personal_listing
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_collection_bin
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_confirm_bid
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_confirm_buy
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_confirm_sell
	 * @param array{string, string} $message_purchase_failed_listing_no_longer_available
	 * @param array{string, string} $message_withdraw_failed_listing_no_longer_available
	 * @param array{string, string} $message_bid_success
	 * @param array{string, string} $message_purchase_success
	 * @param array{string, string} $message_listing_failed_exceed_limit
	 * @param array{string, string} $message_listing_failed_not_enough_balance_tax
	 * @param array{string, string} $message_listing_success
	 * @param Database $database
	 * @param AuctionHousePermissionEvaluator<float> $sell_price_min
	 * @param AuctionHousePermissionEvaluator<float> $sell_price_max
	 * @param AuctionHousePermissionEvaluator<float> $sell_tax_rate
	 * @param AuctionHousePermissionEvaluator<int> $max_listings
	 * @param AuctionHousePermissionEvaluator<int> $expiry_duration
	 * @param int $bid_duration_min
	 * @param AuctionHousePermissionEvaluator<int> $bid_duration_max
	 * @param AuctionHouseEconomy $economy
	 */
	public function __construct(
		readonly private TaskScheduler $scheduler,
		readonly public array $item_registry,
		readonly public array $layout_main_menu,
		readonly public array $layout_personal_listing,
		readonly public array $layout_collection_bin,
		readonly public array $layout_confirm_bid,
		readonly public array $layout_confirm_buy,
		readonly public array $layout_confirm_sell,
		readonly public array $message_purchase_failed_listing_no_longer_available,
		readonly public array $message_withdraw_failed_listing_no_longer_available,
		readonly public array $message_bid_success,
		readonly public array $message_purchase_success,
		readonly public array $message_listing_failed_exceed_limit,
		readonly public array $message_listing_failed_not_enough_balance_tax,
		readonly public array $message_listing_success,
		readonly public Database $database,
		public AuctionHousePermissionEvaluator $sell_price_min,
		public AuctionHousePermissionEvaluator $sell_price_max,
		public AuctionHousePermissionEvaluator $sell_tax_rate,
		public AuctionHousePermissionEvaluator $max_listings,
		public AuctionHousePermissionEvaluator $expiry_duration,
		public int $bid_duration_min,
		public AuctionHousePermissionEvaluator $bid_duration_max,
		public AuctionHouseEconomy $economy
	){
		$this->lock = new Mutex();
		$this->database->waitAll();
		Await::g2c($this->runScheduler());
	}

	public function setMaxListings(string $permission, int $value) : void{
		$values = $this->max_listings->values;
		$values[] = [$permission, $value];
		usort($values, static fn($a, $b) => $b[1] <=> $a[1]);
		$this->max_listings = new AuctionHousePermissionEvaluator($values);
	}

	public function setSellTaxRate(string $permission, float $value) : void{
		$values = $this->sell_tax_rate->values;
		$values[] = [$permission, $value];
		usort($values, static fn($a, $b) => $a[1] <=> $b[1]);
		$this->sell_tax_rate = new AuctionHousePermissionEvaluator($values);
	}

	/**
	 * @param positive-int $ticks
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function sleep(int $ticks) : Generator{
		yield from Await::promise(fn($resolve) => $this->scheduler->scheduleDelayedTask(new ClosureTask($resolve), $ticks));
	}

	/**
	 * @param list<int> $ids
	 * @return Generator<mixed, Await::RESOLVE, void, array<int, Item>>
	 */
	private function loadItems(array $ids) : Generator{
		$result = [];
		yield from $this->lock->acquire();
		try{
			$tasks = [];
			foreach($ids as $id){
				if(isset($this->item_cache[$id])){
					$result[$id] = $this->item_cache[$id];
				}else{
					$tasks[$id] = $this->database->getItem($id);
				}
			}
			$loaded = yield from Await::all($tasks);
			foreach($loaded as $id => $item){
				if($item !== null){
					$this->item_cache[$id] = $item;
					$result[$id] = $item;
				}
			}
		}finally{
			$this->lock->release();
		}
		return $result;
	}

	/**
	 * @param list<string> $ids
	 * @return Generator<mixed, Await::RESOLVE, void, array<string, AuctionHouseEntry>>
	 */
	private function loadEntries(array $ids) : Generator{
		$result = [];
		$tasks = [];
		foreach($ids as $id){
			if(isset($this->entry_cache[$id])){
				$result[$id] = $this->entry_cache[$id];
			}else{
				$result[$id] = null;
				$tasks[$id] = $this->database->get($id);
			}
		}
		if(count($tasks) > 0){
			$loaded = yield from Await::all($tasks);
			foreach($loaded as $id => $item){
				if($item !== null){
					$this->entry_cache[$id] = $item;
					$result[$id] = $item;
				}else{
					unset($result[$id]);
				}
			}
		}
		return $result;
	}

	/**
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function runScheduler() : Generator{
		$state = "expiring";
		$next_runs = [];
		while(true){
			if($state === "expiring"){
				yield from $this->lock->acquire();
				try{
					/** @var array<string, AuctionHouseEntry> $entries */
					$entries = yield from $this->loadEntries(yield from $this->database->expiring(60));
					$next = null;
					$now = time();
					$tasks = [];
					foreach($entries as $entry){
						if($entry->expiry_time >= $now){
							$next = $entry;
							break;
						}
						unset($this->entry_cache[$entry->uuid]);
						if($entry->bid_info !== null && $entry->bid_info->bidder !== null){
							$tasks[] = $this->database->bid(new AuctionHouseBidInfo($entry->bid_info->uuid, $entry->bid_info->bidder, $entry->bid_info->offer, $entry->bid_info->placed_timestamp, $now, $entry->bid_info->offered_timestamp));
						}else{
							$tasks[] = $this->database->addToCollectionBin($entry->player->uuid, $entry->item_id);
						}
						$tasks[] = $this->database->remove($entry->uuid);
					}
					if(count($tasks) > 0){
						yield from Await::all($tasks);
					}
				}finally{
					$this->lock->release();
				}
				if($next !== null){
					$next_runs[] = 1 + ($next->expiry_time - $now);
				}
				$state = "unoffered_bids";
			}elseif($state === "unoffered_bids"){
				$uuids = yield from $this->database->unofferedBids();
				if(count($uuids) > 0){
					yield from Await::all(array_map($this->processBidExpiration(...), $uuids));
				}
				$state = "wait";
			}elseif($state === "wait"){
				yield from $this->sleep(20 * (count($next_runs) === 0 ? 60 : min($next_runs)));
				$next_runs = [];
				$state = "expiring";
			}else{
				throw new RuntimeException("Invalid state: {$state}");
			}
		}
	}

	/**
	 * @param string $uuid
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function processBidExpiration(string $uuid) : Generator{
		/** @var array<string, AuctionHouseEntry> $entries */
		$entries = yield from $this->loadEntries([$uuid]);
		if(count($entries) === 0){
			return;
		}

		$entry = $entries[$uuid];
		if($entry->bid_info === null || $entry->bid_info->bidder === null || $entry->bid_info->completed_timestamp !== null){
			return;
		}

		yield from Await::all([
			$this->database->bid(new AuctionHouseBidInfo($entry->bid_info->uuid, $entry->bid_info->bidder, $entry->bid_info->offer, $entry->bid_info->placed_timestamp, time(), $entry->bid_info->offered_timestamp)),
			$this->database->addToCollectionBin($entry->bid_info->bidder->uuid, $entry->item_id),
			$this->economy->addBalance($entry->uuid, $entry->bid_info->offer)
		]);
	}

	/**
	 * @param self::ITEM_ID_* $item_id
	 * @param array<string, string> $replacement_pairs
	 * @return Item
	 */
	private function formatItem(string $item_id, array $replacement_pairs) : Item{
		$item = clone $this->item_registry[$item_id];
		$item->setLore(str_replace(array_keys($replacement_pairs), array_values($replacement_pairs), $item->getLore()));
		return $item;
	}

	/**
	 * @param Item $item
	 * @param self::ITEM_ID_* $item_id
	 * @param array<string, string> $replacement_pairs
	 * @return Item
	 */
	private function formatInternalItem(Item $item, string $item_id, array $replacement_pairs) : Item{
		if(count($replacement_pairs) > 0){
			$extra_lore = str_replace(array_keys($replacement_pairs), array_values($replacement_pairs), $this->item_registry[$item_id]->getLore());
		}else{
			$extra_lore = $this->item_registry[$item_id]->getLore();
		}
		$item = clone $item;
		$lore = $item->getLore();
		array_push($lore, ...$extra_lore);
		$item->setLore($lore);
		return $item;
	}

	/**
	 * @param Player $player
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	public function send(Player $player) : Generator{
		$menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
		$menu->setName("Auction House");
		$menu->setListener(InvMenu::readonly());
		$state = "main_menu";
		while($state !== null){
			$state = match($state){
				"main_menu" =>  yield from $this->sendMainMenu($player, $menu),
				"personal_listings" => yield from $this->sendPersonalListings($player, $menu),
				"collection_bin" => yield from $this->sendCollectionBin($player, $menu),
				default => throw new InvalidArgumentException("Invalid state: {$state}")
			};
		}
	}

	/**
	 * @param Player $player
	 * @param InvMenu $menu
	 * @return Generator<mixed, Await::RESOLVE, void, string|null>
	 */
	private function sendMainMenu(Player $player, InvMenu $menu) : Generator{
		$page = 1;
		$total_pages = -1;
		$slot = -1;
		$binned = -1;
		$listings = -1;
		$contents = [];
		$uuids = [];
		$uuid_groups = [];
		$group = null;
		$categorized = false;
		$state = "refresh";
		while(true){
			if($state === "refresh"){
				$state = match(true){
					$categorized && $group !== null => "refresh_group",
					$categorized && $group === null => "refresh_categories",
					default => "refresh_flat"
				};
			}elseif($state === "refresh_flat"){
				[$count, $uuids, ["binned" => $binned, "listings" => $listings]] = yield from Await::all([
					$this->database->count(),
					$this->database->list(($page - 1) * self::ENTRIES_PER_PAGE, self::ENTRIES_PER_PAGE),
					$this->database->getPlayerStats($player->getUniqueId()->getBytes())
				]);
				$total_pages = (int) ceil($count / self::ENTRIES_PER_PAGE);
				if($page > 1 && count($uuids) === 0){
					$page = 1;
					continue;
				}
				$state = "display_flat";
			}elseif($state === "display_flat"){
				yield from $this->lock->acquire();
				try{
					/** @var array<string, AuctionHouseEntry> $entries */
					$entries = yield from $this->loadEntries($uuids);
				}finally{
					$this->lock->release();
				}
				$uuids = array_keys($entries);
				$items = yield from $this->loadItems(array_map(static fn($e) => $e->item_id, $entries));
				$contents = [];
				foreach($entries as $entry){
					$item = $items[$entry->item_id];
					$contents[] = $entry->bid_info === null ? $this->formatInternalItem($item, self::ITEM_ID_MAIN_MENU_NORMAL, [
						"{price}" => $this->economy->formatBalance($entry->price),
						"{seller}" => $entry->player->gamertag
					]) : $this->formatInternalItem($item, self::ITEM_ID_MAIN_MENU_BID, [
						"{price}" => $this->economy->formatBalance($entry->price),
						"{seller}" => $entry->player->gamertag,
						"{bidder}" => $entry->bid_info->bidder?->gamertag ?? "-",
						"{expiry}" => Utils::formatTimeDiff($entry->expiry_time - time())
					]);
				}
				$state = "populate_menu";
			}elseif($state === "refresh_categories"){
				[$count, $uuid_groups, ["binned" => $binned, "listings" => $listings]] = yield from Await::all([
					$this->database->countGroups(),
					$this->database->listGroups(($page - 1) * self::ENTRIES_PER_PAGE, self::ENTRIES_PER_PAGE),
					$this->database->getPlayerStats($player->getUniqueId()->getBytes())
				]);
				$total_pages = (int) ceil($count / self::ENTRIES_PER_PAGE);
				if($page > 1 && count($uuid_groups) === 0){
					$page = 1;
					continue;
				}
				$uuid_groups_indexed = array_column($uuid_groups, null, 0);
				yield from $this->lock->acquire();
				try{
					/** @var array<string, AuctionHouseEntry> $entries */
					$entries = yield from $this->loadEntries(array_keys($uuid_groups_indexed));
				}finally{
					$this->lock->release();
				}
				$items = yield from $this->loadItems(array_map(static fn($e) => $e->item_id, $entries));
				$uuid_groups = [];
				$contents = [];
				foreach($entries as $uuid => $entry){
					$uuid_groups[] = $uuid_groups_indexed[$uuid];
					$item = $items[$entry->item_id];
					$item = $this->formatInternalItem((clone $item)->setLore([]), self::ITEM_ID_MAIN_MENU_GROUPED, ["{count}" => $uuid_groups_indexed[$entry->uuid][3]]);
					$item->setCustomName(TextFormat::RESET . TextFormat::BOLD . $item->getVanillaName());
					$contents[] = $item;
				}
				$state = "populate_menu";
			}elseif($state === "populate_menu"){
				$replacement_pairs = ["{binned}" => $binned, "{listings}" => $listings];
				$replacement_find = array_keys($replacement_pairs);
				$replacement_replace = array_values($replacement_pairs);
				foreach($this->layout_main_menu as $slot => [$identifier, ]){
					$button = clone $this->item_registry[$identifier];
					$button->setLore(str_replace($replacement_find, $replacement_replace, $button->getLore()));
					$contents[$slot] = $button;
				}
				$menu->setListener(InvMenu::readonly());
				$menu->getInventory()->setContents($contents);
				$state = "menu";
			}elseif($state === "refresh_group"){
				$item_name = $group[1];
				$item_meta = $group[2];
				[$count, $uuids, ["binned" => $binned, "listings" => $listings]] = yield from Await::all([
					$this->database->countGroup($item_name, $item_meta),
					$this->database->listGroup($item_name, $item_meta, ($page - 1) * self::ENTRIES_PER_PAGE, self::ENTRIES_PER_PAGE),
					$this->database->getPlayerStats($player->getUniqueId()->getBytes())
				]);
				$total_pages = (int) ceil($count / self::ENTRIES_PER_PAGE);
				if($page > 1 && count($uuids) === 0){
					$page = 1;
					continue;
				}
				if(count($uuids) > 0){
					$state = "display_flat";
				}else{
					$group = null;
					$state = "refresh";
				}
			}elseif($state === "wait_and_refresh"){
				$refreshing_icon = VanillaBlocks::BARRIER()->asItem()
					->setCustomName(TextFormat::RESET . TextFormat::BOLD . TextFormat::RED . "Refreshing...")
					->setLore([TextFormat::RESET . TextFormat::GRAY . "Please wait."]);
				foreach($this->layout_main_menu as $slot => [, $action]){
					if($action === "refresh"){
						$menu->getInventory()->setItem($slot, $refreshing_icon);
					}
				}
				$menu->setListener(InvMenu::readonly());
				yield from $this->sleep(20);
				$state = "refresh";
			}elseif($state === "menu"){
				try{
					/** @var DeterministicInvMenuTransaction $transaction */
					$transaction = yield from Utils::waitTransaction($menu, $player);
				}catch(InventoryException){
					break;
				}
				$menu->setListener(InvMenu::readonly());
				$slot = $transaction->getAction()->getSlot();
				$action = $this->layout_main_menu[$slot][1] ?? null;
				if($action === "page_next"){
					if($page + 1 > $total_pages){
						if($page !== 1){
							$page = 1;
							$state = "refresh";
						}
					}else{
						$page++;
						$state = "refresh";
					}
				}elseif($action === "page_previous"){
					if($page - 1 < 1){
						if($page !== $total_pages){
							$page = $total_pages;
							$state = "refresh";
						}
					}else{
						$page--;
						$state = "refresh";
					}
				}elseif($action === "refresh"){
					$state = "wait_and_refresh";
				}elseif($action === "personal_listings"){
					return "personal_listings";
				}elseif($action === "collection_bin"){
					return "collection_bin";
				}elseif($action === "category_view"){
					$page = 1;
					if($categorized){
						if($group === null){
							$categorized = false;
						}
					}else{
						$categorized = true;
					}
					$group = null;
					$state = "refresh";
				}else{
					$state = $categorized ? "menu_select_categories" : "menu_select_flat";
				}
			}elseif($state === "menu_select_categories"){
				if(isset($uuid_groups[$slot]) && $group === null){
					$page = 1;
					$group = $uuid_groups[$slot];
					$state = "refresh_group";
				}else{
					$state = "menu_select_flat";
				}
			}elseif($state === "menu_select_flat"){
				if(!isset($uuids[$slot])){
					$state = "menu";
					continue;
				}
				$uuid = $uuids[$slot];
				yield from $this->lock->acquire();
				try{
					/** @var array<string, AuctionHouseEntry> $entries */
					$entries = yield from $this->loadEntries([$uuid]);
				}finally{
					$this->lock->release();
				}
				if(isset($entries[$uuid])){
					try{
						yield from $this->sendPurchaseConfirmation($player, $menu, $entries[$uuid]);
					}catch(InventoryException){
						break;
					}
				}elseif($player->isConnected()){
					$player->sendToastNotification($this->message_purchase_failed_listing_no_longer_available[0], $this->message_purchase_failed_listing_no_longer_available[1]);
				}
				$total_pages = -1;
				$state = "refresh";
			}
		}
		return null;
	}

	/**
	 * @param Player $player
	 * @param InvMenu $menu
	 * @return Generator<mixed, Await::RESOLVE, void, string|null>
	 */
	private function sendPersonalListings(Player $player, InvMenu $menu) : Generator{
		$uuids = [];
		$state = "refresh";
		while(true){
			if($state === "refresh"){
				$uuids = yield from $this->database->getPlayerListings($player->getUniqueId()->getBytes());
				yield from $this->lock->acquire();
				try{
					/** @var array<string, AuctionHouseEntry> $entries */
					$entries = yield from $this->loadEntries($uuids);
				}finally{
					$this->lock->release();
				}
				$uuids = array_keys($uuids);
				$items = yield from $this->loadItems(array_map(static fn($e) => $e->item_id, $entries));

				$contents = array_map(fn($uuid) => $this->formatInternalItem($items[$entries[$uuid]->item_id], self::ITEM_ID_PERSONAL_LISTING, [
					"{price}" => $this->economy->formatBalance($entries[$uuid]->price),
					"{expire}" => Utils::formatTimeDiff($entries[$uuid]->expiry_time - time())
				]), $uuids);
				foreach($this->layout_personal_listing as $slot => [$identifier, ]){
					$contents[$slot] = $this->item_registry[$identifier];
				}
				$menu->setListener(InvMenu::readonly());
				$menu->getInventory()->setContents($contents);
				$state = "menu";
			}elseif($state === "menu"){
				try{
					/** @var DeterministicInvMenuTransaction $transaction */
					$transaction = yield from Utils::waitTransaction($menu, $player);
				}catch(InventoryException){
					break;
				}
				$menu->setListener(InvMenu::readonly());
				$slot = $transaction->getAction()->getSlot();
				$action = $this->layout_personal_listing[$slot][1] ?? null;
				if($action === "back"){
					return "main_menu";
				}
				if(!isset($uuids[$slot])){
					continue;
				}
				$uuid = $uuids[$slot];
				yield from $this->lock->acquire(); // someone could buy the item before user gets to unlist it
				try{
					$entries = yield from $this->loadEntries([$uuid]);
					if(count($entries) > 0){
						$entry = $entries[$uuids[$slot]];
						unset($this->entry_cache[$uuids[$slot]]);
						yield from Await::all([
							$this->database->remove($uuids[$slot]),
							$this->database->addToCollectionBin($entry->player->uuid, $entry->item_id)
						]);
					}elseif($player->isConnected()){
						$player->sendToastNotification($this->message_withdraw_failed_listing_no_longer_available[0], $this->message_withdraw_failed_listing_no_longer_available[1]);
					}
				}finally{
					$this->lock->release();
				}
				$state = "refresh";
			}
		}
		return null;
	}

	/**
	 * @param Player $player
	 * @param InvMenu $menu
	 * @return Generator<mixed, Await::RESOLVE, void, string|null>
	 */
	private function sendCollectionBin(Player $player, InvMenu $menu) : Generator{
		$player_uuid = $player->getUniqueId()->getBytes();
		$binned_items = [];
		$items = [];
		$state = "refresh";
		while(true){
			if($state === "refresh"){
				$binned_items = yield from $this->database->getCollectionBin($player_uuid);
				$items = yield from $this->loadItems(array_column($binned_items, 0));
				$contents = array_map(fn($e) => $this->formatInternalItem($items[$e[0]], self::ITEM_ID_COLLECTION_BIN, []), $binned_items);
				foreach($this->layout_collection_bin as $slot => [$identifier, ]){
					$contents[$slot] = $this->item_registry[$identifier];
				}
				$menu->setListener(InvMenu::readonly());
				$menu->getInventory()->setContents($contents);
				$state = "menu";
			}elseif($state === "menu"){
				try{
					/** @var DeterministicInvMenuTransaction $transaction */
					$transaction = yield from Utils::waitTransaction($menu, $player);
				}catch(InventoryException){
					break;
				}
				$menu->setListener(InvMenu::readonly());
				$slot = $transaction->getAction()->getSlot();
				$action = $this->layout_collection_bin[$slot][1] ?? null;
				if($action === "back"){
					return "main_menu";
				}
				if($action === "claim_all"){
					if(count($binned_items) === 0){
						continue;
					}
					yield from Await::all(array_map(fn($e) => $this->database->removeFromCollectionBin($player_uuid, $e[0]), $binned_items));
					$collected = [];
					foreach($binned_items as [$item_id,]){
						$collected[] = $items[$item_id];
					}
					$player->getInventory()->addItem(...$collected);
					$state = "refresh";
				}elseif(isset($binned_items[$slot])){
					$item_id = $binned_items[$slot][0];
					yield from $this->database->removeFromCollectionBin($player_uuid, $item_id);
					$player->getInventory()->addItem($items[$item_id]);
					$state = "refresh";
				}
			}
		}
		return null;
	}

	/**
	 * @param Player $player
	 * @param InvMenu $menu
	 * @param AuctionHouseEntry $entry
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 * @throws InventoryException
	 */
	private function sendPurchaseConfirmation(Player $player, InvMenu $menu, AuctionHouseEntry $entry) : Generator{
		$items = yield from $this->loadItems([$entry->item_id]);
		$item = $items[$entry->item_id];
		$price = $entry->bid_info?->offer !== null ? $entry->bid_info->offer : $entry->price;
		$replacement_pairs = [
			"{price}" => $this->economy->formatBalance($price), "{seller}" => $entry->player->gamertag, "{item}" => $item->getName(), "{count}" => $item->getCount(),
			"{bidder}" => $entry->bid_info?->bidder?->gamertag ?? "-", "{expiry}" => Utils::formatTimeDiff($entry->expiry_time - time())
		];
		$layout = $entry->bid_info !== null ? $this->layout_confirm_bid : $this->layout_confirm_buy;
		$contents = [];
		foreach($layout as $slot => [$identifier, ]){
			$contents[$slot] = match($identifier){
				self::ITEM_ID_CONFIRM_BID => $this->formatInternalItem($item, self::ITEM_ID_CONFIRM_BID, $replacement_pairs),
				self::ITEM_ID_CONFIRM_BUY => $this->formatInternalItem($item, self::ITEM_ID_CONFIRM_BUY, $replacement_pairs),
				default => $this->formatItem($identifier, $replacement_pairs)
			};
		}
		$menu->getInventory()->setContents($contents);
		while(true){
			/** @var DeterministicInvMenuTransaction $transaction */
			$transaction = yield from Utils::waitTransaction($menu, $player);
			$menu->setListener(InvMenu::readonly());

			$slot = $transaction->getAction()->getSlot();
			if(!isset($layout[$slot])){
				continue;
			}
			$action = $layout[$slot][1];
			if($action === null){
				continue;
			}
			if($action === "deny"){
				break;
			}
			assert($action === "confirm");
			yield from $this->lock->acquire(); // multiple users can view purchase confirmation screen
			try{
				$entries = yield from $this->loadEntries([$entry->uuid]);
				if(count($entries) === 0 || $entry->expiry_time <= time()){
					$player->sendToastNotification($this->message_purchase_failed_listing_no_longer_available[0], $this->message_purchase_failed_listing_no_longer_available[1]);
					break;
				}
				$price_ = $entry->bid_info?->offer !== null ? $entry->bid_info->offer : $entry->price;
				if($price_ !== $price){
					$player->sendToastNotification($this->message_purchase_failed_listing_no_longer_available[0], $this->message_purchase_failed_listing_no_longer_available[1]);
					break;
				}
				try{
					yield from $this->economy->removeBalance($player->getUniqueId()->getBytes(), $price);
				}catch(AuctionHouseException $e){
					if($player->isConnected()){
						$player->sendToastNotification(TextFormat::RED . TextFormat::BOLD . ($entry->bid_info !== null ? "Bid Failed" : "Purchase Failed"), TextFormat::RED . $e->getMessage());
					}
					break;
				}
				$ev = new AuctionHousePurchaseEvent($player, $entry, $item);
				try{
					$ev->call();
				}finally{
					if($ev->isCancelled()){
						yield from $this->economy->addBalance($player->getUniqueId()->getBytes(), $price);
					}
				}
				if($ev->isCancelled()){
					break;
				}
				if($entry->bid_info === null){
					if($player->isConnected()){
						$player->getInventory()->addItem($item);
						$player->sendToastNotification($this->message_purchase_success[0], strtr($this->message_purchase_success[1], ["{item}" => $item->getName(), "{count}" => $item->getCount(), "{price}" => $this->economy->formatBalance($price)]));
					}else{ // player is offline: place in their collection bin
						yield from $this->database->addToCollectionBin($player->getUniqueId()->getBytes(), $entry->item_id);
					}
					unset($this->entry_cache[$entry->uuid]);
					yield from Await::all([
						$this->database->log($entry, $player->getUniqueId()->getBytes(), $price, time()),
						$this->database->remove($entry->uuid),
						$this->economy->addBalance($entry->player->uuid, $price)
					]);
				}else{
					yield from $this->database->bid(new AuctionHouseBidInfo($entry->bid_info->uuid, AuctionHousePlayerIdentification::fromPlayer($player), $price, time(), $entry->bid_info->completed_timestamp, $entry->bid_info->offered_timestamp));
					unset($this->entry_cache[$entry->uuid]);
					if($player->isConnected()){
						$player->sendToastNotification($this->message_bid_success[0], strtr($this->message_bid_success[1], ["{item}" => $item->getName(), "{count}" => $item->getCount(), "{price}" => $this->economy->formatBalance($price)]));
					}
				}
			}finally{
				$this->lock->release();
			}
			break;
		}
	}

	/**
	 * @param Player $player
	 * @param Item $item
	 * @param float $price
	 * @param int|null $bid_duration
	 * @return Generator<mixed, Await::RESOLVE, void, bool>
	 */
	public function sendSellConfirmation(Player $player, Item $item, float $price, ?int $bid_duration) : Generator{
		$sell_price_min = $this->sell_price_min->evaluate($player);
		$sell_price_max = $this->sell_price_max->evaluate($player);
		$price >= $sell_price_min || throw new InvalidArgumentException("Sell price ({$this->economy->formatBalance($price)}) must be at least \${$this->economy->formatBalance($sell_price_min)}.");
		$price <= $sell_price_max || throw new InvalidArgumentException("Sell price ({$this->economy->formatBalance($price)}) must not exceed \${$this->economy->formatBalance($sell_price_max)}.");
		if($bid_duration !== null){
			$bid_duration_max = $this->bid_duration_max->evaluate($player);
			$bid_duration_max > 0 || throw new InvalidArgumentException("You do not have permission to place bids.");
			$bid_duration >= $this->bid_duration_min || throw new InvalidArgumentException("Bid duration (" . Utils::formatTimeDiff($bid_duration) . ") must be at least " . Utils::formatTimeDiff($this->bid_duration_min) . ".");
			$bid_duration <= $bid_duration_max || throw new InvalidArgumentException("Bid duration (" . Utils::formatTimeDiff($bid_duration) . ") must not exceed " . Utils::formatTimeDiff($bid_duration_max) . ".");
		}
		$tax_rate = $this->sell_tax_rate->evaluate($player) * 0.01;
		$tax_fee = $tax_rate * $price;
		$max_listings = $this->max_listings->evaluate($player);
		$expiry_duration = $this->expiry_duration->evaluate($player);
		$replacement_pairs = [
			"{price}" => $this->economy->formatBalance($price), "{seller}" => $player->getName(), "{item}" => $item->getName(), "{count}" => $item->getCount(),
			"{fee_value}" => $this->economy->formatBalance($tax_fee), "{fee_pct}" => sprintf("%.2f", $tax_rate * 100)
		];
		$contents = [];
		foreach($this->layout_confirm_sell as $slot => [$identifier, ]){
			$contents[$slot] = match($identifier){
				self::ITEM_ID_CONFIRM_SELL => $this->formatInternalItem($item, self::ITEM_ID_CONFIRM_SELL, $replacement_pairs),
				default => $this->formatItem($identifier, $replacement_pairs)
			};
		}

		$menu = InvMenu::create(InvMenu::TYPE_HOPPER);
		$menu->setName("Confirm Listing");
		$menu->getInventory()->setContents($contents);
		$result = null;
		while($result === null){
			try{
				/** @var DeterministicInvMenuTransaction $transaction */
				$transaction = yield from Utils::waitTransaction($menu, $player);
			}catch(InventoryException){
				$result = false;
				continue;
			}
			$slot = $transaction->getAction()->getSlot();
			if(!isset($this->layout_confirm_sell[$slot])){
				continue;
			}
			$action = $this->layout_confirm_sell[$slot][1];
			if($action === null){
				continue;
			}
			if($action === "deny"){
				$result = false;
				continue;
			}
			assert($action === "confirm");

			["binned" => $binned, "listings" => $listings] = yield from $this->database->getPlayerStats($player->getUniqueId()->getBytes());
			if($binned + $listings >= $max_listings){
				$result = false;
				if($player->isConnected()){
					$player->sendToastNotification($this->message_listing_failed_exceed_limit[0], strtr($this->message_listing_failed_exceed_limit[1], ["{limit}" => $max_listings]));
				}
				continue;
			}

			$menu->setListener(InvMenu::readonly());
			yield from $this->lock->acquire();
			try{
				try{
					yield from $this->economy->removeBalance($player->getUniqueId()->getBytes(), $tax_fee);
				}catch(AuctionHouseException){
					if($player->isConnected()){
						$player->sendToastNotification($this->message_listing_failed_not_enough_balance_tax[0], strtr($this->message_listing_failed_not_enough_balance_tax[1], $replacement_pairs));
					}
					$result = false;
					continue;
				}
				$item_id = yield from $this->database->addItem($item);
				yield from $this->database->add($bid_duration !== null ?
					AuctionHouseEntry::new($player, $price, $item_id, time() + $bid_duration, AuctionHouseBidInfo::new($player)) :
					AuctionHouseEntry::new($player, $price, $item_id, time() + $expiry_duration, null));
			}finally{
				$this->lock->release();
			}
			$result = true;
			if($player->isConnected()){
				$player->sendToastNotification($this->message_listing_success[0], strtr($this->message_listing_success[1], $replacement_pairs));
			}
		}
		if($player->isConnected()){
			$player->removeCurrentWindow();
		}
		if(!$result){
			if($player->isConnected()){
				$player->getInventory()->addItem($item);
			}else{
				$item_id = yield from $this->database->addItem($item);
				yield from $this->database->addToCollectionBin($player->getUniqueId()->getBytes(), $item_id);
			}
		}
		return $result;
	}
}
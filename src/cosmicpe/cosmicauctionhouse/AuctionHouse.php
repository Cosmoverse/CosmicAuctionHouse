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
use SOFe\AwaitGenerator\Await;
use SOFe\AwaitGenerator\Mutex;
use function array_combine;
use function array_keys;
use function array_map;
use function array_push;
use function array_values;
use function assert;
use function ceil;
use function count;
use function sprintf;
use function str_replace;
use function strtr;
use function time;

final class AuctionHouse{

	public const int ENTRIES_PER_PAGE = 45;
	public const string ITEM_ID_COLLECTION_BIN = "__collection_bin:item_preview";
	public const string ITEM_ID_CONFIRM_BUY = "__confirm_buy:item_preview";
	public const string ITEM_ID_CONFIRM_SELL = "__confirm_sell:item_preview";
	public const string ITEM_ID_MAIN_MENU = "__main_menu:item_preview";
	public const string ITEM_ID_PERSONAL_LISTING = "__personal_listing:item_preview";

	readonly private Mutex $lock_purchase;

	/** @var array<non-empty-string, AuctionHouseEntry> */
	private array $entries;

	/**
	 * @param TaskScheduler $scheduler
	 * @param float $sell_price_min
	 * @param float $sell_price_max
	 * @param float $sell_tax_rate
	 * @param int $max_listings
	 * @param int $expiry_duration
	 * @param int $deletion_duration
	 * @param array<non-empty-string, Item> $item_registry
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_main_menu
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_personal_listing
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_collection_bin
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_confirm_buy
	 * @param array<int, array{non-empty-string, non-empty-string|null}> $layout_confirm_sell
	 * @param array{string, string} $message_purchase_failed_listing_no_longer_available
	 * @param array{string, string} $message_withdraw_failed_listing_no_longer_available
	 * @param array{string, string} $message_purchase_success
	 * @param Database $database
	 * @param AuctionHouseEconomy $economy
	 */
	public function __construct(
		readonly private TaskScheduler $scheduler,
		readonly public float $sell_price_min,
		readonly public float $sell_price_max,
		readonly public float $sell_tax_rate,
		readonly public int $max_listings,
		readonly public int $expiry_duration,
		readonly public int $deletion_duration,
		readonly public array $item_registry,
		readonly public array $layout_main_menu,
		readonly public array $layout_personal_listing,
		readonly public array $layout_collection_bin,
		readonly public array $layout_confirm_buy,
		readonly public array $layout_confirm_sell,
		readonly public array $message_purchase_failed_listing_no_longer_available,
		readonly public array $message_withdraw_failed_listing_no_longer_available,
		readonly public array $message_purchase_success,
		readonly public Database $database,
		public AuctionHouseEconomy $economy
	){
		$this->lock_purchase = new Mutex();
		Await::g2c($this->loadEntries());
		$this->database->waitAll();
	}

	/**
	 * @param positive-int $ticks
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function sleep(int $ticks) : Generator{
		yield from Await::promise(fn($resolve) => $this->scheduler->scheduleDelayedTask(new ClosureTask($resolve), $ticks));
	}

	/**
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function loadEntries() : Generator{
		/** @var list<AuctionHouseEntry> $entries */
		$entries = yield from $this->database->loadAll();
		$keys = array_map(static fn($entry) => $entry->uuid, $entries);
		$this->entries = array_combine($keys, $entries);
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
		$extra_lore = str_replace(array_keys($replacement_pairs), array_values($replacement_pairs), $this->item_registry[$item_id]->getLore());
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
				"main_menu_categorized" => yield from $this->sendMainMenu($player, $menu),
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
		$uuids = [];
		$state = "refresh";
		while(true){
			if($state === "refresh"){
				[$uuids, ["binned" => $binned, "listings" => $listings]] = yield from Await::all([
					$this->database->list(($page - 1) * self::ENTRIES_PER_PAGE, self::ENTRIES_PER_PAGE),
					$this->database->getPlayerStats($player->getUniqueId()->getBytes())
				]);
				$total_pages = (int) ceil(count($this->entries) / self::ENTRIES_PER_PAGE);
				if($page > 1 && count($uuids) === 0){
					$page = 1;
					continue;
				}
				$contents = [];
				foreach($uuids as $uuid){
					if(isset($this->entries[$uuid])){
						$entry = $this->entries[$uuid];
						$contents[] = $this->formatInternalItem($entry->item, self::ITEM_ID_MAIN_MENU, ["{price}" => $entry->price, "{seller}" => $entry->player->gamertag]);
					}
				}
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
					return "main_menu_categorized";
				}elseif(isset($uuids[$slot])){
					$uuid = $uuids[$slot];
					if(isset($this->entries[$uuid])){
						try{
							yield from $this->sendPurchaseConfirmation($player, $menu, $this->entries[$uuid]);
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
				$contents = array_map(fn($uuid) => $this->formatInternalItem($this->entries[$uuid]->item, self::ITEM_ID_PERSONAL_LISTING, [
					"{price}" => $this->entries[$uuid]->price,
					"{expire}" => Utils::formatTimeDiff($this->entries[$uuid]->expiry_time - time())
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
				yield from $this->lock_purchase->acquire(); // someone could buy the item before user gets to unlist it
				try{
					if(isset($this->entries[$uuid])){
						$time = time() - 1;
						$this->entries[$uuid] = AuctionHouseEntry::from($this->entries[$uuid], $time);
						yield from $this->database->updateExpiryTime($uuids[$slot], $time);
					}elseif($player->isConnected()){
						$player->sendToastNotification($this->message_withdraw_failed_listing_no_longer_available[0], $this->message_withdraw_failed_listing_no_longer_available[1]);
					}
				}finally{
					$this->lock_purchase->release();
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
		$uuids = [];
		$state = "refresh";
		while(true){
			if($state === "refresh"){
				$uuids = yield from $this->database->getPlayerBin($player->getUniqueId()->getBytes());
				$contents = array_map(fn($uuid) => $this->formatInternalItem($this->entries[$uuid]->item, self::ITEM_ID_COLLECTION_BIN, [
					"{price}" => $this->entries[$uuid]->price,
					"{deletion}" => Utils::formatTimeDiff(($this->entries[$uuid]->expiry_time + $this->deletion_duration) - time())
				]), $uuids);
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
					if(count($uuids) === 0){
						continue;
					}
					yield from Await::all(array_map($this->database->remove(...), $uuids));
					$items = [];
					foreach($uuids as $uuid){
						$entry = $this->entries[$uuid];
						unset($this->entries[$uuid]);
						$items[] = $entry->item;
					}
					$player->getInventory()->addItem(...$items);
					$state = "refresh";
				}elseif(isset($uuids[$slot])){
					$entry = $this->entries[$uuids[$slot]];
					yield from $this->database->remove($uuids[$slot]);
					$player->getInventory()->addItem($entry->item);
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
		$replacement_pairs = ["{price}" => $entry->price, "{seller}" => $entry->player->gamertag, "{item}" => $entry->item->getName(), "{count}" => $entry->item->getCount()];
		$contents = [];
		foreach($this->layout_confirm_buy as $slot => [$identifier, ]){
			$contents[$slot] = match($identifier){
				self::ITEM_ID_CONFIRM_BUY => $this->formatInternalItem($entry->item, self::ITEM_ID_CONFIRM_BUY, $replacement_pairs),
				default => $this->formatItem($identifier, $replacement_pairs)
			};
		}
		$menu->getInventory()->setContents($contents);
		while(true){
			/** @var DeterministicInvMenuTransaction $transaction */
			$transaction = yield from Utils::waitTransaction($menu, $player);
			$menu->setListener(InvMenu::readonly());

			$slot = $transaction->getAction()->getSlot();
			if(!isset($this->layout_confirm_buy[$slot])){
				continue;
			}
			$action = $this->layout_confirm_buy[$slot][1];
			if($action === null){
				continue;
			}
			if($action === "deny"){
				break;
			}
			assert($action === "confirm");
			yield from $this->lock_purchase->acquire(); // multiple users can view purchase confirmation screen
			try{
				if(!isset($this->entries[$entry->uuid]) || $entry->expiry_time <= time()){
					$player->sendToastNotification($this->message_purchase_failed_listing_no_longer_available[0], $this->message_purchase_failed_listing_no_longer_available[1]);
					break;
				}
				try{
					yield from $this->economy->removeBalance($player->getUniqueId()->getBytes(), $entry->price);
				}catch(AuctionHouseException $e){
					if($player->isConnected()){
						$player->sendToastNotification(TextFormat::RED . TextFormat::BOLD . "Purchase Failed", TextFormat::RED . $e->getMessage());
					}
					break;
				}
				if(!$player->isConnected()){ // refund money
					yield from $this->economy->addBalance($player->getUniqueId()->getBytes(), $entry->price);
					break;
				}
				$player->getInventory()->addItem($entry->item);
				$player->sendToastNotification($this->message_purchase_success[0], strtr($this->message_purchase_success[1], [
					"{item}" => $entry->item->getName(),
					"{count}" => $entry->item->getCount()
				]));
				yield from Await::all([
					$this->database->log($entry, $player->getUniqueId()->getBytes(), $entry->price),
					$this->database->remove($entry->uuid),
					$this->economy->addBalance($entry->player->uuid, $entry->price)
				]);
			}finally{
				$this->lock_purchase->release();
			}
			break;
		}
	}

	/**
	 * @param Player $player
	 * @param Item $item
	 * @param float $price
	 * @return Generator<mixed, Await::RESOLVE, void, bool>
	 */
	public function sendSellConfirmation(Player $player, Item $item, float $price) : Generator{
		$replacement_pairs = [
			"{price}" => $price, "{seller}" => $player->getName(), "{item}" => $item->getName(), "{count}" => $item->getCount(),
			"{fee_value}" => sprintf("%.2f", $this->sell_tax_rate * $price), "{fee_pct}" => sprintf("%.2f", $this->sell_tax_rate * 100)
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
			$menu->setListener(InvMenu::readonly());
			$entry = AuctionHouseEntry::new($player, $price, $item, time() + $this->expiry_duration);
			yield from $this->database->add($entry);
			$this->entries[$entry->uuid] = $entry;
			$result = true;
		}
		if($player->isConnected()){
			$player->removeCurrentWindow();
		}
		return $result;
	}
}
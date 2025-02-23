<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Generator;
use InvalidArgumentException;
use JsonException;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Filesystem;
use pocketmine\utils\TextFormat;
use SOFe\AwaitGenerator\Await;
use Symfony\Component\Filesystem\Path;
use function array_diff_key;
use function array_flip;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function count;
use function get_debug_type;
use function gettype;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function spl_object_id;
use function strtotime;
use function time;
use const JSON_THROW_ON_ERROR;

final class Loader extends PluginBase{

	/** @var array<int, true> */
	private array $_processing_senders = [];

	private Database $database;
	private AuctionHouse $auction_house;

	protected function onEnable() : void{
		$this->saveResource("config.json");
		$this->database = new Database($this);
		try{
			$this->auction_house = $this->loadAuctionHouseFromConfig(Path::join($this->getDataFolder(), "config.json"));
		}catch(InvalidArgumentException $e){
			$this->getLogger()->warning("Failed to read config.json");
			$this->getLogger()->warning($e->getMessage());
			$this->getLogger()->warning("{$this->getName()} will use the default config.json as fallback");
			$this->getLogger()->warning("To hide this warning, please correct your config.json file, or delete it so a fresh config is generated");
			$this->auction_house = $this->loadAuctionHouseFromConfig($this->getResourcePath("config.json"));
		}
		$this->getServer()->getPluginManager()->registerEvent(PlayerLoginEvent::class, function(PlayerLoginEvent $event) : void{
			Await::g2c($this->database->initPlayer(AuctionHousePlayerIdentification::fromPlayer($event->getPlayer())));
		}, EventPriority::MONITOR, $this);
	}

	protected function onDisable() : void{
		$this->database->close();
	}

	private function loadAuctionHouseFromConfig(string $path) : AuctionHouse{
		$data = Filesystem::fileGetContents($path);
		try{
			$data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
		}catch(JsonException $e){
			throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
		}

		/**
		 * @param string $identifier
		 * @param "int"|"float" $type
		 * @return AuctionHousePermissionEvaluator<int>|AuctionHousePermissionEvaluator<float>
		 */
		$read_positive_numeric_evaluator = function(string $identifier, string $type) use($data) : AuctionHousePermissionEvaluator{
			isset($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive not found");
			is_array($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive must be an array, got " . get_debug_type($data[$identifier]));
			array_is_list($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive must be a list");
			$values = [];
			foreach($data[$identifier] as $index => $entry){
				is_array($entry) || throw new InvalidArgumentException("Entry in '{$identifier}' directive must be an array (at {$index}), got " . get_debug_type($entry));
				isset($entry["permission"]) || throw new InvalidArgumentException("'name' in '{$identifier}' directive (at {$index}) not specified");
				is_string($entry["permission"]) || throw new InvalidArgumentException("'permission' in '{$identifier}' directive (at {$index}) must be string, got " . get_debug_type($entry["permission"]));
				isset($entry["value"]) || throw new InvalidArgumentException("'value' in '{$identifier}' directive (at {$index}) not specified");
				match($type){
					"int" => is_int($entry["value"]),
					"float" => is_float($entry["value"]) || is_int($entry["value"]),
					default => throw new InvalidArgumentException("Invalid type: {$type}")
				} || throw new InvalidArgumentException("'value' in '{$identifier}' directive (at {$index}) must be {$type}, got " . get_debug_type($entry["value"]));
				$entry["value"] >= 0 || throw new InvalidArgumentException("'value' in '{$identifier}' directive (at {$index}) must be >= 0, got " . $entry["value"]);
				$values[] = [$entry["permission"], $entry["value"]];
			}
			return new AuctionHousePermissionEvaluator($values);
		};

		/**
		 * @param string $identifier
		 * @return AuctionHousePermissionEvaluator<int>
		 */
		$read_relative_time = function(string $identifier) use($data) : AuctionHousePermissionEvaluator{
			isset($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive not found");
			is_array($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive must be an array, got " . get_debug_type($data[$identifier]));
			array_is_list($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive must be a list");
			$values = [];
			foreach($data[$identifier] as $index => $entry){
				is_array($entry) || throw new InvalidArgumentException("Entry in '{$identifier}' directive must be an array (at {$index}), got " . get_debug_type($entry));
				isset($entry["permission"]) || throw new InvalidArgumentException("'name' in '{$identifier}' directive (at {$index}) not specified");
				is_string($entry["permission"]) || throw new InvalidArgumentException("'permission' in '{$identifier}' directive (at {$index}) must be string, got " . get_debug_type($entry["permission"]));
				isset($entry["value"]) || throw new InvalidArgumentException("'value' in '{$identifier}' directive (at {$index}) not specified");
				is_string($entry["value"]) || throw new InvalidArgumentException("'value' in '{$identifier}' directive (at {$index}) must be string, got " . get_debug_type($entry["value"]));

				$now = time();
				$value = strtotime("+{$entry["value"]}");
				($value !== false && $value >= $now) || throw new InvalidArgumentException("'value' in '{$identifier}' directive (at {$index}) is improperly formatted, got " . $entry["value"]);
				$value -= $now;
				$values[] = [$entry["permission"], $value];
			}
			return new AuctionHousePermissionEvaluator($values);
		};

		$sell_price_min = $read_positive_numeric_evaluator("sell_price_min", "float");
		$sell_price_max = $read_positive_numeric_evaluator("sell_price_max", "float");
		$sell_tax_rate = $read_positive_numeric_evaluator("sell_tax_rate", "float");
		$max_listings = $read_positive_numeric_evaluator("max_listings", "int");
		$expiry_duration = $read_relative_time("expiry_duration");
		$max_bid_duration = $read_relative_time("max_bid_duration");

		isset($data["min_bid_duration"]) || throw new InvalidArgumentException("'min_bid_duration' directive not found");
		is_string($data["min_bid_duration"]) || throw new InvalidArgumentException("'min_bid_duration' directive must be a string, got " . get_debug_type($data["min_bid_duration"]));
		$now = time();
		$value = strtotime("+{$data["min_bid_duration"]}");
		($value !== false && $value >= $now) || throw new InvalidArgumentException("'min_bid_duration' is improperly formatted, got " . $data["min_bid_duration"]);
		$value -= $now;
		$min_bid_duration = $value;

		$internal_items = [AuctionHouse::ITEM_ID_PERSONAL_LISTING, AuctionHouse::ITEM_ID_CONFIRM_BID, AuctionHouse::ITEM_ID_CONFIRM_BUY,
			AuctionHouse::ITEM_ID_CONFIRM_SELL, AuctionHouse::ITEM_ID_MAIN_MENU_NORMAL, AuctionHouse::ITEM_ID_MAIN_MENU_GROUPED, AuctionHouse::ITEM_ID_MAIN_MENU_BID, AuctionHouse::ITEM_ID_COLLECTION_BIN];
		isset($data["item_registry"]) || throw new InvalidArgumentException("'item_registry' directive not found");
		is_array($data["item_registry"]) || throw new InvalidArgumentException("'item_registry' must be an array, got " . gettype($data["item_registry"]));
		$item_registry = [];
		$parser = StringToItemParser::getInstance();
		foreach($data["item_registry"] as $identifier => $value){
			if(in_array($identifier, $internal_items, true)){
				$value["id"] = "apple";
				$value["name"] = "";
			}

			is_string($identifier) || throw new InvalidArgumentException("Identifiers must be string, got '{$identifier}'");
			is_array($value) || throw new InvalidArgumentException("Button configurations must be an array, got " . get_debug_type($value));
			isset($value["id"], $value["name"], $value["lore"]) || throw new InvalidArgumentException("'id', 'name', and 'lore' must be specified in {$identifier}");
			is_string($value["id"]) || throw new InvalidArgumentException("'id' must be a string for {$identifier}, got " . get_debug_type($value["id"]));
			is_string($value["name"]) || throw new InvalidArgumentException("'name' must be a string for {$identifier}, got " . get_debug_type($value["name"]));
			is_array($value["lore"]) || throw new InvalidArgumentException("'lore' must be an array for {$identifier}, got " . get_debug_type($value["lore"]));
			array_is_list($value["lore"]) || throw new InvalidArgumentException("'lore' must be a list for {$identifier}");
			count($value["lore"]) === 0 || array_unique(array_map(is_string(...), $value["lore"])) === [true] || throw new InvalidArgumentException("'lore' must be a list of strings for {$identifier}");

			$item = $parser->parse($value["id"]);
			$item !== null || throw new InvalidArgumentException("Unknown item id '{$value["id"]} specified for {$identifier}");
			$item->setCustomName(TextFormat::colorize($value["name"]));
			$item->setLore(array_map(TextFormat::colorize(...), $value["lore"]));
			$item_registry[$identifier] = $item;
		}
		$undefined_internal_items = array_diff_key(array_flip($internal_items), $item_registry);
		count($undefined_internal_items) === 0 || throw new InvalidArgumentException("No configuration specified for hardcoded item " . implode(", ", array_keys($undefined_internal_items)));

		isset($data["menu_layouts"]) || throw new InvalidArgumentException("'menu_layouts' directive not found");
		is_array($data["menu_layouts"]) || throw new InvalidArgumentException("'menu_layouts' must be an array, got " . gettype($data["menu_layouts"]));
		$known_layouts = [
			"main_menu" => ["personal_listings", "collection_bin", "page_previous", "refresh", "page_next", "category_view", "none"],
			"personal_listing" => ["back", "none"],
			"collection_bin" => ["back", "claim_all", "guide", "none"],
			"confirm_bid" => ["confirm", "deny", "none"],
			"confirm_buy" => ["confirm", "deny", "none"],
			"confirm_sell" => ["confirm", "deny", "none"]
		];
		$layouts = [];
		foreach($data["menu_layouts"] as $layout_identifier => $layout){
			isset($known_layouts[$layout_identifier]) || throw new InvalidArgumentException("Unexpected layout identifier '{$layout_identifier}', expected one of: " . implode(", ", array_keys($known_layouts)));
			is_array($layout) || throw new InvalidArgumentException("'layout' must be an array, got " . get_debug_type($layout));
			array_is_list($layout) || throw new InvalidArgumentException("'layout' must be a list for {$layout_identifier}");
			foreach($layout as $index => $entry){
				is_array($entry) || throw new InvalidArgumentException("'layout' must be an array for {$layout_identifier} (at {$index}), got " . get_debug_type($entry));
				isset($entry["slot"]) || throw new InvalidArgumentException("'layout' must contain a 'slot' configuration for {$layout_identifier} (at {$index})");
				is_int($entry["slot"]) || throw new InvalidArgumentException("'slot' in layout {$layout_identifier} (at {$index})  must be an integer, got " . get_debug_type($entry["slot"]));
				$entry["slot"] >= 0 || throw new InvalidArgumentException("'slot' in layout {$layout_identifier} (at {$index})  must be >= 0, got {$entry["slot"]}");
				$entry["slot"] < 54 || throw new InvalidArgumentException("'slot' in layout {$layout_identifier} (at {$index})  must be < 54, got {$entry["slot"]}");
				isset($entry["item"]) || throw new InvalidArgumentException("'layout' must contain an 'item' configuration for {$layout_identifier} (at {$index})");
				is_string($entry["item"]) || throw new InvalidArgumentException("'item' in layout {$layout_identifier} (at {$index})  must be a string, got " . get_debug_type($entry["item"]));
				isset($item_registry[$entry["item"]]) || throw new InvalidArgumentException("'slot' in layout {$layout_identifier} (at {$index})  must be defined in 'item_registry', got {$entry["item"]}");
				isset($entry["action"]) || throw new InvalidArgumentException("'layout' must contain an 'action' configuration for {$layout_identifier} (at {$index})");
				is_string($entry["action"]) || throw new InvalidArgumentException("'action' in layout {$layout_identifier} (at {$index})  must be a string, got " . get_debug_type($entry["action"]));
				in_array($entry["action"], $known_layouts[$layout_identifier], true) || throw new InvalidArgumentException("Unexpected 'action' value '{$entry["action"]}' in layout {$layout_identifier} (at {$index}), expected one of: " . implode(", ", $known_layouts[$layout_identifier]));
				$layouts[$layout_identifier][$entry["slot"]] = [$entry["item"], $entry["action"] === "none" ? null : $entry["action"]];
			}
		}

		isset($data["messages"]) || throw new InvalidArgumentException("'messages' directive not found");
		is_array($data["messages"]) || throw new InvalidArgumentException("'messages' must be an array, got " . gettype($data["messages"]));
		$known_messages = ["purchase_failed_listing_no_longer_available" => null, "withdraw_failed_listing_no_longer_available" => null,
			"bid_success" => null, "purchase_success" => null, "listing_failed_exceed_limit" => null,
			"listing_failed_not_enough_balance_tax" => null, "listing_success" => null];
		foreach($data["messages"] as $identifier => $message){
			array_key_exists($identifier, $known_messages) || throw new InvalidArgumentException("Unexpected message identifier '{$identifier}', expected one of: " . implode(", ", array_keys($known_messages)));
			is_array($message) || throw new InvalidArgumentException("'message' must be an array for {$identifier}, got " . get_debug_type($message));
			count($message) === 2 || throw new InvalidArgumentException("'message' must have exactly 2 elements for {$identifier}, got " . get_debug_type($message));
			array_unique(array_map(is_string(...), $message)) === [true] || throw new InvalidArgumentException("'message' must be a list of strings for {$identifier}");
			$known_messages[$identifier] = array_map(TextFormat::colorize(...), $message);
		}
		foreach($known_messages as $identifier => $message){
			$message ?? throw new InvalidArgumentException("No configuration specified for message {$identifier}");
		}

		$undefined_layout_identifiers = array_diff_key($known_layouts, $layouts);
		count($undefined_layout_identifiers) === 0 || throw new InvalidArgumentException("No configuration specified for menu layout " . implode(", ", array_keys($undefined_layout_identifiers)));
		return new AuctionHouse($this->getScheduler(), $item_registry, $layouts["main_menu"], $layouts["personal_listing"], $layouts["collection_bin"], $layouts["confirm_bid"],
			$layouts["confirm_buy"], $layouts["confirm_sell"], $known_messages["purchase_failed_listing_no_longer_available"], $known_messages["withdraw_failed_listing_no_longer_available"],
			$known_messages["bid_success"], $known_messages["purchase_success"], $known_messages["listing_failed_exceed_limit"], $known_messages["listing_failed_not_enough_balance_tax"],
			$known_messages["listing_success"], $this->database, $sell_price_min, $sell_price_max, $sell_tax_rate, $max_listings, $expiry_duration,
			$min_bid_duration, $max_bid_duration, NullAuctionHouseEconomy::instance());
	}

	public function getAuctionHouse() : AuctionHouse{
		return $this->auction_house;
	}

	/**
	 * @param Player $player
	 * @param string $label
	 * @param string[] $args
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function onCommandAsync(Player $player, string $label, array $args) : Generator{
		if(isset($this->_processing_senders[$id = spl_object_id($player)])){ // spam protection
			return;
		}

		$this->_processing_senders[$id] = true;
		try{
			if(count($args) === 0){
				yield from $this->auction_house->send($player);
				return;
			}
			if($args[0] === "sell"){
				if(isset($args[1])){
					$item = $player->getInventory()->getItemInHand();
					if($item->isNull()){
						$player->sendMessage(TextFormat::RED . "Please hold an item in hand.");
						return;
					}

					$price = (float) $args[1];
					if($price < 0){
						$player->sendMessage(TextFormat::RED . "Please enter a valid price.");
						return;
					}

					$player->getInventory()->setItemInHand(VanillaItems::AIR());
					try{
						yield from $this->auction_house->sendSellConfirmation($player, $item, $price, null);
					}catch(InvalidArgumentException $e){
						if($player->isConnected()){
							$player->sendMessage(TextFormat::RED . $e->getMessage());
						}
					}
				}
			}
		}finally{
			unset($this->_processing_senders[$id]);
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(!($sender instanceof Player)){
			$sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
			return true;
		}
		Await::g2c($this->onCommandAsync($sender, $label, $args));
		return true;
	}
}
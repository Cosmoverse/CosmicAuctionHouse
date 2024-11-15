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
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
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
		try{
			$config = $this->loadConfig(Path::join($this->getDataFolder(), "config.json"));
		}catch(InvalidArgumentException $e){
			$this->getLogger()->warning("Failed to read config.json");
			$this->getLogger()->warning($e->getMessage());
			$this->getLogger()->warning("{$this->getName()} will use the default config.json as fallback");
			$this->getLogger()->warning("To hide this warning, please correct your config.json file, or delete it so a fresh config is generated");
			$config = $this->loadConfig($this->getResourcePath("config.json"));
		}

		[$sell_price_min, $sell_price_max, $sell_tax_rate, $max_listings, $expiry_duration, $deletion_duration, $item_registry, $layout_main_menu, $layout_personal_listing, $layout_collection_bin,
			$layout_confirm_buy, $layout_confirm_sell, $message_purchase_failed_listing_no_longer_available,
			$message_withdraw_failed_listing_no_longer_available, $message_purchase_success, $message_listing_failed_exceed_limit] = $config;
		$this->database = new Database($this);
		$this->auction_house = new AuctionHouse($this->getScheduler(), $sell_price_min, $sell_price_max, $sell_tax_rate, $max_listings, $expiry_duration, $deletion_duration,
			$item_registry,  $layout_main_menu, $layout_personal_listing, $layout_collection_bin, $layout_confirm_buy, $layout_confirm_sell,
			$message_purchase_failed_listing_no_longer_available, $message_withdraw_failed_listing_no_longer_available, $message_purchase_success, $message_listing_failed_exceed_limit,
			$this->database, NullAuctionHouseEconomy::instance());

		$this->getServer()->getPluginManager()->registerEvent(PlayerLoginEvent::class, function(PlayerLoginEvent $event) : void{
			Await::g2c($this->database->initPlayer(AuctionHousePlayerIdentification::fromPlayer($event->getPlayer())));
		}, EventPriority::MONITOR, $this);
	}

	protected function onDisable() : void{
		$this->database->close();
	}

	/**
	 * @return array{float, float, float, int, int, int,
	 *     array<string, Item>,
	 *     array<int, array{string, string|null}>,
	 *     array<int, array{string, string|null}>,
	 *     array<int, array{string, string|null}>,
	 *     array{string, string},
	 *     array{string, string},
	 *     array{string, string}}
	 */
	private function loadConfig(string $path) : array{
		$data = Filesystem::fileGetContents($path);
		try{
			$data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
		}catch(JsonException $e){
			throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
		}

		$read_positive_numeric_simple = function(string $identifier) use($data) : int|float{
			isset($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive not found");
			is_int($data[$identifier]) || is_float($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' must be an int|float, got " . gettype($data[$identifier]));
			$data[$identifier] >= 0 || throw new InvalidArgumentException("'{$identifier}' must be >= 0, got {$data[$identifier]}");
			return $data[$identifier];
		};

		$read_relative_time = function(string $identifier) use($data) : int{
			isset($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' directive not found");
			is_string($data[$identifier]) || throw new InvalidArgumentException("'{$identifier}' must be a string, got " . gettype($data[$identifier]));
			$now = time();
			$value = strtotime("+{$data[$identifier]}");
			($value !== false && $value >= $now) || throw new InvalidArgumentException("Improperly formatted expiry duration: {$data[$identifier]}");
			$value -= $now;
			return $value;
		};

		$sell_price_min = $read_positive_numeric_simple("sell_price_min");
		$sell_price_max = $read_positive_numeric_simple("sell_price_max");
		$sell_tax_rate = $read_positive_numeric_simple("sell_tax_rate");
		$max_listings = $read_positive_numeric_simple("max_listings");

		$expiry_duration = $read_relative_time("expiry_duration");
		$deletion_duration = $read_relative_time("deletion_duration");

		$sell_price_max >= $sell_price_min || throw new InvalidArgumentException("'sell_price_max' ({$sell_price_max}) must be >= 'sell_price_min' ({$sell_price_min}})");
		is_int($max_listings) || throw new InvalidArgumentException("'max_listings' must be an integer");
		$sell_tax_rate /= 100.0;

		$internal_items = [AuctionHouse::ITEM_ID_PERSONAL_LISTING, AuctionHouse::ITEM_ID_CONFIRM_BUY, AuctionHouse::ITEM_ID_CONFIRM_SELL, AuctionHouse::ITEM_ID_MAIN_MENU, AuctionHouse::ITEM_ID_COLLECTION_BIN];
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
		$known_messages = ["purchase_failed_listing_no_longer_available" => null, "withdraw_failed_listing_no_longer_available" => null, "purchase_success" => null, "listing_failed_exceed_limit" => null];
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
		return [$sell_price_min, $sell_price_max, $sell_tax_rate, $max_listings, $expiry_duration, $deletion_duration, $item_registry,
			$layouts["main_menu"], $layouts["personal_listing"], $layouts["collection_bin"],  $layouts["confirm_buy"],
			$layouts["confirm_sell"], $known_messages["purchase_failed_listing_no_longer_available"],
			$known_messages["withdraw_failed_listing_no_longer_available"], $known_messages["purchase_success"], $known_messages["listing_failed_exceed_limit"]];
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

					yield from $this->auction_house->sendSellConfirmation($player, $item, $price);
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
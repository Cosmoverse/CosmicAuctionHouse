<?php

declare(strict_types=1);

namespace cosmicpe\cosmicauctionhouse;

use Closure;
use Generator;
use InvalidArgumentException;
use JsonException;
use muqsit\invmenu\InvMenuHandler;
use pocketmine\block\utils\RecordType;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\item\GoatHornType;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Filesystem;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\AmethystBlockChimeSound;
use pocketmine\world\sound\AnvilBreakSound;
use pocketmine\world\sound\AnvilFallSound;
use pocketmine\world\sound\AnvilUseSound;
use pocketmine\world\sound\ArmorEquipChainSound;
use pocketmine\world\sound\ArmorEquipDiamondSound;
use pocketmine\world\sound\ArmorEquipGenericSound;
use pocketmine\world\sound\ArmorEquipGoldSound;
use pocketmine\world\sound\ArmorEquipIronSound;
use pocketmine\world\sound\ArmorEquipLeatherSound;
use pocketmine\world\sound\ArmorEquipNetheriteSound;
use pocketmine\world\sound\ArrowHitSound;
use pocketmine\world\sound\BarrelCloseSound;
use pocketmine\world\sound\BarrelOpenSound;
use pocketmine\world\sound\BellRingSound;
use pocketmine\world\sound\BlastFurnaceSound;
use pocketmine\world\sound\BlazeShootSound;
use pocketmine\world\sound\BlockBreakSound;
use pocketmine\world\sound\BlockPlaceSound;
use pocketmine\world\sound\BlockPunchSound;
use pocketmine\world\sound\BottleEmptySound;
use pocketmine\world\sound\BowShootSound;
use pocketmine\world\sound\BucketEmptyLavaSound;
use pocketmine\world\sound\BucketEmptyWaterSound;
use pocketmine\world\sound\BucketFillLavaSound;
use pocketmine\world\sound\BucketFillWaterSound;
use pocketmine\world\sound\BurpSound;
use pocketmine\world\sound\CampfireSound;
use pocketmine\world\sound\CauldronAddDyeSound;
use pocketmine\world\sound\CauldronCleanItemSound;
use pocketmine\world\sound\CauldronDyeItemSound;
use pocketmine\world\sound\CauldronEmptyLavaSound;
use pocketmine\world\sound\CauldronEmptyPotionSound;
use pocketmine\world\sound\CauldronEmptyPowderSnowSound;
use pocketmine\world\sound\CauldronEmptyWaterSound;
use pocketmine\world\sound\CauldronFillLavaSound;
use pocketmine\world\sound\CauldronFillPotionSound;
use pocketmine\world\sound\CauldronFillPowderSnowSound;
use pocketmine\world\sound\CauldronFillWaterSound;
use pocketmine\world\sound\ChestCloseSound;
use pocketmine\world\sound\ChestOpenSound;
use pocketmine\world\sound\ChorusFlowerDieSound;
use pocketmine\world\sound\ChorusFlowerGrowSound;
use pocketmine\world\sound\ClickSound;
use pocketmine\world\sound\CopperWaxApplySound;
use pocketmine\world\sound\CopperWaxRemoveSound;
use pocketmine\world\sound\DoorBumpSound;
use pocketmine\world\sound\DoorCrashSound;
use pocketmine\world\sound\DoorSound;
use pocketmine\world\sound\DripleafTiltDownSound;
use pocketmine\world\sound\DripleafTiltUpSound;
use pocketmine\world\sound\DyeUseSound;
use pocketmine\world\sound\EnderChestCloseSound;
use pocketmine\world\sound\EnderChestOpenSound;
use pocketmine\world\sound\EndermanTeleportSound;
use pocketmine\world\sound\EntityAttackNoDamageSound;
use pocketmine\world\sound\EntityAttackSound;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\sound\FireExtinguishSound;
use pocketmine\world\sound\FizzSound;
use pocketmine\world\sound\FlintSteelSound;
use pocketmine\world\sound\FurnaceSound;
use pocketmine\world\sound\GhastShootSound;
use pocketmine\world\sound\GhastSound;
use pocketmine\world\sound\GlowBerriesPickSound;
use pocketmine\world\sound\GoatHornSound;
use pocketmine\world\sound\IceBombHitSound;
use pocketmine\world\sound\IgniteSound;
use pocketmine\world\sound\InkSacUseSound;
use pocketmine\world\sound\ItemBreakSound;
use pocketmine\world\sound\ItemFrameAddItemSound;
use pocketmine\world\sound\ItemFrameRemoveItemSound;
use pocketmine\world\sound\ItemFrameRotateItemSound;
use pocketmine\world\sound\ItemUseOnBlockSound;
use pocketmine\world\sound\LaunchSound;
use pocketmine\world\sound\LecternPlaceBookSound;
use pocketmine\world\sound\NoteInstrument;
use pocketmine\world\sound\NoteSound;
use pocketmine\world\sound\PaintingPlaceSound;
use pocketmine\world\sound\PopSound;
use pocketmine\world\sound\PotionFinishBrewingSound;
use pocketmine\world\sound\PotionSplashSound;
use pocketmine\world\sound\PressurePlateActivateSound;
use pocketmine\world\sound\PressurePlateDeactivateSound;
use pocketmine\world\sound\RecordSound;
use pocketmine\world\sound\RecordStopSound;
use pocketmine\world\sound\RedstonePowerOffSound;
use pocketmine\world\sound\RedstonePowerOnSound;
use pocketmine\world\sound\RespawnAnchorChargeSound;
use pocketmine\world\sound\RespawnAnchorDepleteSound;
use pocketmine\world\sound\RespawnAnchorSetSpawnSound;
use pocketmine\world\sound\ScrapeSound;
use pocketmine\world\sound\ShulkerBoxCloseSound;
use pocketmine\world\sound\ShulkerBoxOpenSound;
use pocketmine\world\sound\SmokerSound;
use pocketmine\world\sound\Sound;
use pocketmine\world\sound\SweetBerriesPickSound;
use pocketmine\world\sound\ThrowSound;
use pocketmine\world\sound\TotemUseSound;
use pocketmine\world\sound\WaterSplashSound;
use pocketmine\world\sound\XpCollectSound;
use pocketmine\world\sound\XpLevelUpSound;
use ReflectionClass;
use RuntimeException;
use SOFe\AwaitGenerator\Await;
use Symfony\Component\Filesystem\Path;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function count;
use function get_debug_type;
use function gettype;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function spl_object_id;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtotime;
use function substr;
use function time;
use const ARRAY_FILTER_USE_KEY;
use const JSON_THROW_ON_ERROR;

final class Loader extends PluginBase{

	private const BALANCE_SUFFIXES = [
		"k" => 10 ** 3,
		"m" => 10 ** 6,
		"b" => 10 ** 9
	];

	/** @var array<int, true> */
	private array $_processing_senders = [];

	private Database $database;
	private AuctionHouse $auction_house;
	private Permission $log_permission;

	protected function onEnable() : void{
		$this->log_permission = PermissionManager::getInstance()->getPermission("cosmicpe.command.auctionhouse.logs") ?? throw new RuntimeException();
		if(!InvMenuHandler::isRegistered()){
			InvMenuHandler::register($this);
		}

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
		$this->getServer()->getPluginManager()->registerEvent(PlayerJoinEvent::class, function(PlayerJoinEvent $event) : void{
			$player = $event->getPlayer();
			Await::f2c(function() use($player) : Generator{
				$last_login = $player->getLastPlayed();
				if($last_login !== null){
					$sold = yield from $this->database->getPlayerListingsSold($player->getUniqueId()->getBytes(), intdiv($last_login, 1000), time());
					if($player->isConnected()){
						foreach($sold as [$buyer, $item, $purchase_price, $purchase_time]){
							$this->auction_house->notifySellerAboutPurchase($player, $buyer, $item, $purchase_price, $purchase_time);
						}
					}
				}
			});
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
			AuctionHouse::ITEM_ID_CONFIRM_SELL, AuctionHouse::ITEM_ID_MAIN_MENU_NORMAL, AuctionHouse::ITEM_ID_LOG_MENU_NORMAL,
			AuctionHouse::ITEM_ID_MAIN_MENU_GROUPED, AuctionHouse::ITEM_ID_MAIN_MENU_BID, AuctionHouse::ITEM_ID_COLLECTION_BIN];
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
			"log_menu" => ["page_previous", "refresh", "page_next", "none"],
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
			"bid_success" => null, "collection_failed_inventory_full" => null, "purchase_success" => null, "purchase_success_seller" => null,
			"listing_failed_exceed_limit" => null, "listing_failed_not_enough_balance_tax" => null, "listing_success" => null];
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

		$known_sound_events = (new ReflectionClass(AuctionHouse::class))->getConstants();
		$known_sound_events = array_filter($known_sound_events, static fn($key) => str_starts_with($key, "EVENT_"), ARRAY_FILTER_USE_KEY);
		$known_sound_events = array_flip($known_sound_events);
		$event_sounds = [];
		$sound_registry = $this->buildSoundRegistry();
		isset($data["sounds"]) || throw new InvalidArgumentException("'sounds' directive not found");
		is_array($data["sounds"]) || throw new InvalidArgumentException("'sounds' must be an array, got " . gettype($data["sounds"]));
		foreach($data["sounds"] as $identifier => $sound_data){
			array_key_exists($identifier, $known_sound_events) || throw new InvalidArgumentException("Unexpected sound event '{$identifier}', expected one of: " . implode(", ", array_keys($known_sound_events)));
			if(is_string($sound_data)){
				$sound = $sound_registry[$sound_data] ?? throw new InvalidArgumentException("Unknown sound " . json_encode($sound_data) . " specified for {$identifier}");
				$sound = $sound();
			}elseif(is_array($sound_data)){
				isset($sound_data["name"]) || throw new InvalidArgumentException("sound 'name' must be specified for {$identifier}");
				is_string($sound_data["name"]) || throw new InvalidArgumentException("sound 'name' must be a string for {$identifier}, got " . get_debug_type($sound_data["name"]));
				$args = $sound_data;
				unset($args["name"]);
				$sound = $sound_registry[$sound_data["name"]] ?? throw new InvalidArgumentException("Unknown sound " . json_encode($sound_data) . " specified for {$identifier}");
				$sound = $sound(...$args);
			}else{
				throw new InvalidArgumentException("sound '{$identifier}' must be a string or an array, got " . get_debug_type($sound_data));
			}
			$event_sounds[$identifier] = $sound;
		}

		$undefined_layout_identifiers = array_diff_key($known_layouts, $layouts);
		count($undefined_layout_identifiers) === 0 || throw new InvalidArgumentException("No configuration specified for menu layout " . implode(", ", array_keys($undefined_layout_identifiers)));
		return new AuctionHouse($this->getServer(), $this->getScheduler(), $item_registry, $layouts["log_menu"], $layouts["main_menu"], $layouts["personal_listing"], $layouts["collection_bin"], $layouts["confirm_bid"],
			$layouts["confirm_buy"], $layouts["confirm_sell"], $known_messages["purchase_failed_listing_no_longer_available"], $known_messages["withdraw_failed_listing_no_longer_available"],
			$known_messages["collection_failed_inventory_full"], $known_messages["bid_success"], $known_messages["purchase_success"], implode(TextFormat::EOL, $known_messages["purchase_success_seller"]), $known_messages["listing_failed_exceed_limit"], $known_messages["listing_failed_not_enough_balance_tax"],
			$known_messages["listing_success"], $event_sounds, $this->database, $sell_price_min, $sell_price_max, $sell_tax_rate, $max_listings, $expiry_duration,
			$min_bid_duration, $max_bid_duration, NullAuctionHouseEconomy::instance());
	}

	public function getAuctionHouse() : AuctionHouse{
		return $this->auction_house;
	}

	/**
	 * @return array<string, Closure(...$args) : Sound>
	 */
	public function buildSoundRegistry() : array{
		// TODO: use a pm sound virion for this instead. we cant use PlaySoundPacket because it is dimensionId dependent.
		$block = static fn($args) => StringToItemParser::getInstance()->parse($args["block"] ?? "stone")?->getBlock() ?? VanillaBlocks::STONE();
		return [
			"amethyst_block_chime_sound" => static fn(...$args) => new AmethystBlockChimeSound(),
			"anvil_break_sound" => static fn(...$args) => new AnvilBreakSound(),
			"anvil_fall_sound" => static fn(...$args) => new AnvilFallSound(),
			"anvil_use_sound" => static fn(...$args) => new AnvilUseSound(),
			"armor_equip_chain_sound" => static fn(...$args) => new ArmorEquipChainSound(),
			"armor_equip_diamond_sound" => static fn(...$args) => new ArmorEquipDiamondSound(),
			"armor_equip_generic_sound" => static fn(...$args) => new ArmorEquipGenericSound(),
			"armor_equip_gold_sound" => static fn(...$args) => new ArmorEquipGoldSound(),
			"armor_equip_iron_sound" => static fn(...$args) => new ArmorEquipIronSound(),
			"armor_equip_leather_sound" => static fn(...$args) => new ArmorEquipLeatherSound(),
			"armor_equip_netherite_sound" => static fn(...$args) => new ArmorEquipNetheriteSound(),
			"arrow_hit_sound" => static fn(...$args) => new ArrowHitSound(),
			"barrel_close_sound" => static fn(...$args) => new BarrelCloseSound(),
			"barrel_open_sound" => static fn(...$args) => new BarrelOpenSound(),
			"bell_ring_sound" => static fn(...$args) => new BellRingSound(),
			"blast_furnace_sound" => static fn(...$args) => new BlastFurnaceSound(),
			"blaze_shoot_sound" => static fn(...$args) => new BlazeShootSound(),
			"block_break_sound" => static fn(...$args) => new BlockBreakSound($block($args)),
			"block_place_sound" => static fn(...$args) => new BlockPlaceSound($block($args)),
			"block_punch_sound" => static fn(...$args) => new BlockPunchSound($block($args)),
			"bottle_empty_sound" => static fn(...$args) => new BottleEmptySound(),
			"bow_shoot_sound" => static fn(...$args) => new BowShootSound(),
			"bucket_empty_lava_sound" => static fn(...$args) => new BucketEmptyLavaSound(),
			"bucket_empty_water_sound" => static fn(...$args) => new BucketEmptyWaterSound(),
			"bucket_fill_lava_sound" => static fn(...$args) => new BucketFillLavaSound(),
			"bucket_fill_water_sound" => static fn(...$args) => new BucketFillWaterSound(),
			"burp_sound" => static fn(...$args) => new BurpSound(),
			"campfire_sound" => static fn(...$args) => new CampfireSound(),
			"cauldron_add_dye_sound" => static fn(...$args) => new CauldronAddDyeSound(),
			"cauldron_clean_item_sound" => static fn(...$args) => new CauldronCleanItemSound(),
			"cauldron_dye_item_sound" => static fn(...$args) => new CauldronDyeItemSound(),
			"cauldron_empty_lava_sound" => static fn(...$args) => new CauldronEmptyLavaSound(),
			"cauldron_empty_potion_sound" => static fn(...$args) => new CauldronEmptyPotionSound(),
			"cauldron_empty_powder_snow_sound" => static fn(...$args) => new CauldronEmptyPowderSnowSound(),
			"cauldron_empty_water_sound" => static fn(...$args) => new CauldronEmptyWaterSound(),
			"cauldron_fill_lava_sound" => static fn(...$args) => new CauldronFillLavaSound(),
			"cauldron_fill_potion_sound" => static fn(...$args) => new CauldronFillPotionSound(),
			"cauldron_fill_powder_snow_sound" => static fn(...$args) => new CauldronFillPowderSnowSound(),
			"cauldron_fill_water_sound" => static fn(...$args) => new CauldronFillWaterSound(),
			"chest_close_sound" => static fn(...$args) => new ChestCloseSound(),
			"chest_open_sound" => static fn(...$args) => new ChestOpenSound(),
			"chorus_flower_die_sound" => static fn(...$args) => new ChorusFlowerDieSound(),
			"chorus_flower_grow_sound" => static fn(...$args) => new ChorusFlowerGrowSound(),
			"click_sound" => static fn(...$args) => new ClickSound($args["pitch"] ?? 0),
			"copper_wax_apply_sound" => static fn(...$args) => new CopperWaxApplySound(),
			"copper_wax_remove_sound" => static fn(...$args) => new CopperWaxRemoveSound(),
			"door_bump_sound" => static fn(...$args) => new DoorBumpSound(),
			"door_crash_sound" => static fn(...$args) => new DoorCrashSound(),
			"door_sound" => static fn(...$args) => new DoorSound($args["pitch"] ?? 0),
			"dripleaf_tilt_down_sound" => static fn(...$args) => new DripleafTiltDownSound(),
			"dripleaf_tilt_up_sound" => static fn(...$args) => new DripleafTiltUpSound(),
			"dye_use_sound" => static fn(...$args) => new DyeUseSound(),
			"ender_chest_close_sound" => static fn(...$args) => new EnderChestCloseSound(),
			"ender_chest_open_sound" => static fn(...$args) => new EnderChestOpenSound(),
			"enderman_teleport_sound" => static fn(...$args) => new EndermanTeleportSound(),
			"entity_attack_no_damage_sound" => static fn(...$args) => new EntityAttackNoDamageSound(),
			"entity_attack_sound" => static fn(...$args) => new EntityAttackSound(),
			"explode_sound" => static fn(...$args) => new ExplodeSound(),
			"fire_extinguish_sound" => static fn(...$args) => new FireExtinguishSound(),
			"fizz_sound" => static fn(...$args) => new FizzSound($args["pitch"] ?? 0),
			"flint_steel_sound" => static fn(...$args) => new FlintSteelSound(),
			"furnace_sound" => static fn(...$args) => new FurnaceSound(),
			"ghast_shoot_sound" => static fn(...$args) => new GhastShootSound(),
			"ghast_sound" => static fn(...$args) => new GhastSound(),
			"glow_berries_pick_sound" => static fn(...$args) => new GlowBerriesPickSound(),
			"goat_horn_sound" => static fn(...$args) => new GoatHornSound(match($args["type"] ?? ""){
				"sing" => GoatHornType::SING,
				"seek" => GoatHornType::SEEK,
				"feel" => GoatHornType::FEEL,
				"admire" => GoatHornType::ADMIRE,
				"call" => GoatHornType::CALL,
				"yearn" => GoatHornType::YEARN,
				"dream" => GoatHornType::DREAM,
				default => GoatHornType::PONDER,
			}),
			"ice_bomb_hit_sound" => static fn(...$args) => new IceBombHitSound(),
			"ignite_sound" => static fn(...$args) => new IgniteSound(),
			"ink_sac_use_sound" => static fn(...$args) => new InkSacUseSound(),
			"item_break_sound" => static fn(...$args) => new ItemBreakSound(),
			"item_frame_add_item_sound" => static fn(...$args) => new ItemFrameAddItemSound(),
			"item_frame_remove_item_sound" => static fn(...$args) => new ItemFrameRemoveItemSound(),
			"item_frame_rotate_item_sound" => static fn(...$args) => new ItemFrameRotateItemSound(),
			"item_use_on_block_sound" => static fn(...$args) => new ItemUseOnBlockSound($block($args)),
			"launch_sound" => static fn(...$args) => new LaunchSound($args["pitch"] ?? 0),
			"lectern_place_book_sound" => static fn(...$args) => new LecternPlaceBookSound(),
			"note_sound" => static fn(...$args) => new NoteSound(match($args["instrument"] ?? ""){
				"piano" => NoteInstrument::PIANO,
				"bass_drum" => NoteInstrument::BASS_DRUM,
				"snare" => NoteInstrument::SNARE,
				"clicks_and_sticks" => NoteInstrument::CLICKS_AND_STICKS,
				"double_bass" => NoteInstrument::DOUBLE_BASS,
				"bell" => NoteInstrument::BELL,
				"flute" => NoteInstrument::FLUTE,
				"chime" => NoteInstrument::CHIME,
				"guitar" => NoteInstrument::GUITAR,
				"xylophone" => NoteInstrument::XYLOPHONE,
				"iron_xylophone" => NoteInstrument::IRON_XYLOPHONE,
				"cow_bell" => NoteInstrument::COW_BELL,
				"didgeridoo" => NoteInstrument::DIDGERIDOO,
				"bit" => NoteInstrument::BIT,
				"banjo" => NoteInstrument::BANJO,
				default => NoteInstrument::PLING,
			}, $args["note"] ?? 0),
			"painting_place_sound" => static fn(...$args) => new PaintingPlaceSound(),
			"pop_sound" => static fn(...$args) => new PopSound($args["pitch"] ?? 0),
			"potion_finish_brewing_sound" => static fn(...$args) => new PotionFinishBrewingSound(),
			"potion_splash_sound" => static fn(...$args) => new PotionSplashSound(),
			"pressure_plate_activate_sound" => static fn(...$args) => new PressurePlateActivateSound($block($args)),
			"pressure_plate_deactivate_sound" => static fn(...$args) => new PressurePlateDeactivateSound($block($args)),
			"record_sound" => static fn(...$args) => new RecordSound(match($args["type"] ?? ""){
				"disk_5" => RecordType::DISK_5,
				"disk_cat" => RecordType::DISK_CAT,
				"disk_blocks" => RecordType::DISK_BLOCKS,
				"disk_chirp" => RecordType::DISK_CHIRP,
				"disk_creator" => RecordType::DISK_CREATOR,
				"disk_creator_music_box" => RecordType::DISK_CREATOR_MUSIC_BOX,
				"disk_far" => RecordType::DISK_FAR,
				"disk_mall" => RecordType::DISK_MALL,
				"disk_mellohi" => RecordType::DISK_MELLOHI,
				"disk_otherside" => RecordType::DISK_OTHERSIDE,
				"disk_pigstep" => RecordType::DISK_PIGSTEP,
				"disk_precipice" => RecordType::DISK_PRECIPICE,
				"disk_relic" => RecordType::DISK_RELIC,
				"disk_stal" => RecordType::DISK_STAL,
				"disk_strad" => RecordType::DISK_STRAD,
				"disk_ward" => RecordType::DISK_WARD,
				"disk_11" => RecordType::DISK_11,
				"disk_wait" => RecordType::DISK_WAIT,
				default => RecordType::DISK_13,
			}),
			"record_stop_sound" => static fn(...$args) => new RecordStopSound(),
			"redstone_power_off_sound" => static fn(...$args) => new RedstonePowerOffSound(),
			"redstone_power_on_sound" => static fn(...$args) => new RedstonePowerOnSound(),
			"respawn_anchor_charge_sound" => static fn(...$args) => new RespawnAnchorChargeSound(),
			"respawn_anchor_deplete_sound" => static fn(...$args) => new RespawnAnchorDepleteSound(),
			"respawn_anchor_set_spawn_sound" => static fn(...$args) => new RespawnAnchorSetSpawnSound(),
			"scrape_sound" => static fn(...$args) => new ScrapeSound(),
			"shulker_box_close_sound" => static fn(...$args) => new ShulkerBoxCloseSound(),
			"shulker_box_open_sound" => static fn(...$args) => new ShulkerBoxOpenSound(),
			"smoker_sound" => static fn(...$args) => new SmokerSound(),
			"sweet_berries_pick_sound" => static fn(...$args) => new SweetBerriesPickSound(),
			"throw_sound" => static fn(...$args) => new ThrowSound(),
			"totem_use_sound" => static fn(...$args) => new TotemUseSound(),
			"water_splash_sound" => static fn(...$args) => new WaterSplashSound($args["volume"] ?? 200.0),
			"xp_collect_sound" => static fn(...$args) => new XpCollectSound(),
			"xp_level_up_sound" => static fn(...$args) => new XpLevelUpSound($args["level"] ?? 1)
		];
	}

	private function validatePositiveFormattedFloat(string $name, string $param) : float{
		$param_len = strlen($param);
		$param_len > 0 || throw new InvalidArgumentException("Improperly formatted {$name}, expected number > 0");
		$suffix = strtolower($param[$param_len - 1]);
		if(isset(self::BALANCE_SUFFIXES[$suffix])){
			$multiplier = self::BALANCE_SUFFIXES[$suffix];
			$param = substr($param, 0, -1);
		}else{
			$multiplier = 1;
		}
		$float = (float) $param;
		if($float == 0.0 && $param != 0.0 && $param !== "0"){
			throw new InvalidArgumentException("{$name} must be an integer");
		}
		$result = $float;
		$result *= $multiplier;
		$result > 0 || throw new InvalidArgumentException("{$name} must be > 0");
		return $result;
	}


	/**
	 * @param Player $player
	 * @param string $label
	 * @param string[] $args
	 * @return Generator<mixed, Await::RESOLVE, void, void>
	 */
	private function onCommandAsync(Player $player, string $label, array $args) : Generator{
		yield from [];
		if(isset($this->_processing_senders[$id = spl_object_id($player)])){ // spam protection
			return;
		}

		$this->_processing_senders[$id] = true;
		try{
			if(count($args) === 0){
				yield from $this->auction_house->send($player);
				return;
			}
			if($args[0] === "sell" && isset($args[1])){
				$item = $player->getInventory()->getItemInHand();
				if($item->isNull()){
					$player->sendMessage(TextFormat::RED . "Please hold an item in hand.");
					return;
				}

				try{
					$price = $this->validatePositiveFormattedFloat("price", $args[1]);
				}catch(InvalidArgumentException $e){
					$player->sendMessage(TextFormat::RED . "Please enter a valid price ({$e->getMessage()}).");
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
				return;
			}
			if($args[0] === "logs" && $player->hasPermission($this->log_permission)){
				$profile = null;
				if(count($args) > 1){
					$gamertag = implode(" ", array_slice($args, 1));
					/** @var AuctionHousePlayerIdentification|null $profile */
					$profile = yield from $this->database->lookupGamertag($gamertag);
					if($profile === null){
						$player->sendMessage(TextFormat::RED . "No records of {$gamertag} were found.");
						return;
					}
				}
				yield from $this->auction_house->sendLogs($player, $profile);
				return;
			}
			$player->sendMessage(TextFormat::WHITE . "/{$label} sell <price>" . TextFormat::GRAY . " - Sell the item in your hand for the specified price.");
			if($player->hasPermission($this->log_permission)){
				$player->sendMessage(TextFormat::WHITE . "/{$label} logs" . TextFormat::GRAY . " - View auction house transaction logs.");
				$player->sendMessage(TextFormat::WHITE . "/{$label} logs <player>" . TextFormat::GRAY . " - View auction house transaction logs involving the specified player.");
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
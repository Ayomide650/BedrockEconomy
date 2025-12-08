<?php

declare(strict_types=1);

namespace BedrockEconomy;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private Config $economy;
    private Config $data;
    private array $dailyTransfers = [];
    
    private const STARTING_BALANCE = 1000;
    private const LOGIN_BONUS = 100;
    private const PVP_WIN_REWARD = 500;
    private const MAX_DAILY_TRANSFER = 1000000;

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        @mkdir($this->getDataFolder());
        $this->economy = new Config($this->getDataFolder() . "economy.yml", Config::YAML);
        $this->data = new Config($this->getDataFolder() . "data.yml", Config::YAML);
        
        $this->getLogger()->info(TF::GREEN . "BedrockEconomy by Firekid846 enabled!");
        $this->getLogger()->info(TF::YELLOW . "Login Bonus: $" . self::LOGIN_BONUS);
        $this->getLogger()->info(TF::YELLOW . "PVP Win Reward: $" . self::PVP_WIN_REWARD);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        
        switch ($command->getName()) {
            case "bal":
            case "balance":
                return $this->handleBalance($sender, $args);
                
            case "richest":
            case "baltop":
                return $this->handleRichest($sender);
                
            case "pay":
                return $this->handlePay($sender, $args);
                
            case "eco":
                return $this->handleEco($sender, $args);
                
            case "shop":
                return $this->handleShop($sender);
        }

        return false;
    }

    private function handleBalance(CommandSender $sender, array $args): bool {
        if (count($args) === 0) {
            if (!$sender instanceof Player) {
                $sender->sendMessage(TF::RED . "Please specify a player!");
                return true;
            }
            
            $balance = $this->getMoney($sender->getName());
            $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $sender->sendMessage(TF::YELLOW . "Your Balance: " . TF::GREEN . "$" . number_format($balance));
            $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            return true;
        }

        $target = $args[0];
        $balance = $this->getMoney($target);
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $sender->sendMessage(TF::YELLOW . $target . "'s Balance: " . TF::GREEN . "$" . number_format($balance));
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        
        return true;
    }

    private function handleRichest(CommandSender $sender): bool {
        $allBalances = $this->economy->getAll();
        arsort($allBalances);
        
        $top10 = array_slice($allBalances, 0, 10, true);
        
        $sender->sendMessage(TF::GOLD . "━━━━━━━ " . TF::YELLOW . "TOP 10 RICHEST PLAYERS" . TF::GOLD . " ━━━━━━━");
        
        $position = 1;
        foreach ($top10 as $player => $balance) {
            $medal = match($position) {
                1 => TF::GOLD . "🥇",
                2 => TF::GRAY . "🥈",
                3 => TF::YELLOW . "🥉",
                default => TF::WHITE . "#$position"
            };
            
            $sender->sendMessage($medal . TF::AQUA . " $player " . TF::WHITE . "- " . TF::GREEN . "$" . number_format($balance));
            $position++;
        }
        
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        
        return true;
    }

    private function handlePay(CommandSender $sender, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "This command can only be used in-game!");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /pay <player> <amount>");
            return true;
        }

        $target = $this->getServer()->getPlayerByPrefix($args[0]);
        if ($target === null) {
            $sender->sendMessage(TF::RED . "Player not found!");
            return true;
        }

        if (!is_numeric($args[1]) || $args[1] <= 0) {
            $sender->sendMessage(TF::RED . "Amount must be a positive number!");
            return true;
        }

        $amount = (int)$args[1];
        
        if ($target->getName() === $sender->getName()) {
            $sender->sendMessage(TF::RED . "You cannot pay yourself!");
            return true;
        }

        $senderBalance = $this->getMoney($sender->getName());
        
        if ($senderBalance < $amount) {
            $sender->sendMessage(TF::RED . "You don't have enough money!");
            $sender->sendMessage(TF::YELLOW . "Your balance: " . TF::GREEN . "$" . number_format($senderBalance));
            return true;
        }

        $senderName = $sender->getName();
        $today = date("Y-m-d");
        
        if (!isset($this->dailyTransfers[$senderName])) {
            $this->dailyTransfers[$senderName] = [];
        }
        
        if (!isset($this->dailyTransfers[$senderName][$today])) {
            $this->dailyTransfers[$senderName][$today] = 0;
        }
        
        $dailyTotal = $this->dailyTransfers[$senderName][$today] + $amount;
        
        if ($dailyTotal > self::MAX_DAILY_TRANSFER) {
            $remaining = self::MAX_DAILY_TRANSFER - $this->dailyTransfers[$senderName][$today];
            $sender->sendMessage(TF::RED . "Daily transfer limit reached!");
            $sender->sendMessage(TF::YELLOW . "You can still transfer: " . TF::GREEN . "$" . number_format($remaining) . TF::YELLOW . " today");
            $sender->sendMessage(TF::GRAY . "Limit resets at midnight");
            return true;
        }

        $this->takeMoney($sender->getName(), $amount);
        $this->addMoney($target->getName(), $amount);
        
        $this->dailyTransfers[$senderName][$today] = $dailyTotal;

        $sender->sendMessage(TF::GREEN . "✓ You sent $" . number_format($amount) . " to " . $target->getName());
        $sender->sendMessage(TF::GRAY . "New balance: " . TF::GREEN . "$" . number_format($this->getMoney($sender->getName())));
        
        $target->sendMessage(TF::GREEN . "✓ You received $" . number_format($amount) . " from " . $sender->getName());
        $target->sendMessage(TF::GRAY . "New balance: " . TF::GREEN . "$" . number_format($this->getMoney($target->getName())));

        return true;
    }

    private function handleEco(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("bedrockeconomy.admin")) {
            $sender->sendMessage(TF::RED . "You don't have permission to use this command!");
            return true;
        }

        if (count($args) < 2) {
            $sender->sendMessage(TF::YELLOW . "Usage: /eco <give|take|set|reset> <player> [amount]");
            return true;
        }

        $action = strtolower($args[0]);
        $playerName = $args[1];

        switch ($action) {
            case "give":
                if (!isset($args[2]) || !is_numeric($args[2])) {
                    $sender->sendMessage(TF::RED . "Please specify a valid amount!");
                    return true;
                }
                $amount = (int)$args[2];
                $this->addMoney($playerName, $amount);
                $sender->sendMessage(TF::GREEN . "✓ Gave $" . number_format($amount) . " to " . $playerName);
                $sender->sendMessage(TF::GRAY . "New balance: $" . number_format($this->getMoney($playerName)));
                
                $target = $this->getServer()->getPlayerExact($playerName);
                if ($target !== null) {
                    $target->sendMessage(TF::GREEN . "✓ You received $" . number_format($amount) . " from an admin");
                }
                break;

            case "take":
                if (!isset($args[2]) || !is_numeric($args[2])) {
                    $sender->sendMessage(TF::RED . "Please specify a valid amount!");
                    return true;
                }
                $amount = (int)$args[2];
                $this->takeMoney($playerName, $amount);
                $sender->sendMessage(TF::GREEN . "✓ Took $" . number_format($amount) . " from " . $playerName);
                $sender->sendMessage(TF::GRAY . "New balance: $" . number_format($this->getMoney($playerName)));
                
                $target = $this->getServer()->getPlayerExact($playerName);
                if ($target !== null) {
                    $target->sendMessage(TF::RED . "✗ $" . number_format($amount) . " was removed from your account");
                }
                break;

            case "set":
                if (!isset($args[2]) || !is_numeric($args[2])) {
                    $sender->sendMessage(TF::RED . "Please specify a valid amount!");
                    return true;
                }
                $amount = (int)$args[2];
                $this->setMoney($playerName, $amount);
                $sender->sendMessage(TF::GREEN . "✓ Set " . $playerName . "'s balance to $" . number_format($amount));
                
                $target = $this->getServer()->getPlayerExact($playerName);
                if ($target !== null) {
                    $target->sendMessage(TF::YELLOW . "Your balance was set to $" . number_format($amount) . " by an admin");
                }
                break;

            case "reset":
                $this->setMoney($playerName, self::STARTING_BALANCE);
                $sender->sendMessage(TF::GREEN . "✓ Reset " . $playerName . "'s balance to $" . number_format(self::STARTING_BALANCE));
                
                $target = $this->getServer()->getPlayerExact($playerName);
                if ($target !== null) {
                    $target->sendMessage(TF::YELLOW . "Your balance was reset to $" . number_format(self::STARTING_BALANCE));
                }
                break;

            default:
                $sender->sendMessage(TF::RED . "Invalid action! Use: give, take, set, or reset");
                return true;
        }

        return true;
    }

    private function handleShop(CommandSender $sender): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "Please use the Shop NPC in the lobby!");
            return true;
        }

        $sender->sendMessage(TF::GOLD . "━━━━━━━━━ " . TF::YELLOW . "SERVER SHOP" . TF::GOLD . " ━━━━━━━━━");
        $sender->sendMessage(TF::YELLOW . "Please use the Shop NPC in the lobby!");
        $sender->sendMessage(TF::GRAY . "Right-click the NPC to open the shop");
        $sender->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        return true;
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $name = $player->getName();

        if (!$this->economy->exists($name)) {
            $this->setMoney($name, self::STARTING_BALANCE);
            $player->sendMessage(TF::GREEN . "Welcome to the server!");
            $player->sendMessage(TF::YELLOW . "You received " . TF::GREEN . "$" . number_format(self::STARTING_BALANCE) . TF::YELLOW . " as starting money!");
        } else {
            $lastLogin = $this->data->get($name . "_lastlogin", "");
            $today = date("Y-m-d");
            
            if ($lastLogin !== $today) {
                $this->addMoney($name, self::LOGIN_BONUS);
                $player->sendMessage(TF::GREEN . "✓ Daily Login Bonus: " . TF::YELLOW . "$" . number_format(self::LOGIN_BONUS));
                $player->sendMessage(TF::GRAY . "Your balance: " . TF::GREEN . "$" . number_format($this->getMoney($name)));
            }
        }

        $this->data->set($name . "_lastlogin", date("Y-m-d"));
        $this->data->save();
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $cause = $player->getLastDamageCause();

        if ($cause instanceof \pocketmine\event\entity\EntityDamageByEntityEvent) {
            $killer = $cause->getDamager();
            
            if ($killer instanceof Player) {
                $this->addMoney($killer->getName(), self::PVP_WIN_REWARD);
                
                $killer->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $killer->sendMessage(TF::GREEN . "✓ PVP KILL!");
                $killer->sendMessage(TF::YELLOW . "Reward: " . TF::GREEN . "+$" . number_format(self::PVP_WIN_REWARD));
                $killer->sendMessage(TF::GRAY . "Balance: " . TF::GREEN . "$" . number_format($this->getMoney($killer->getName())));
                $killer->sendMessage(TF::GOLD . "━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            }
        }
    }

    public function getMoney(string $player): int {
        return $this->economy->get(strtolower($player), self::STARTING_BALANCE);
    }

    public function setMoney(string $player, int $amount): void {
        if ($amount < 0) $amount = 0;
        $this->economy->set(strtolower($player), $amount);
        $this->economy->save();
    }

    public function addMoney(string $player, int $amount): void {
        $current = $this->getMoney($player);
        $this->setMoney($player, $current + $amount);
    }

    public function takeMoney(string $player, int $amount): void {
        $current = $this->getMoney($player);
        $new = $current - $amount;
        if ($new < 0) $new = 0;
        $this->setMoney($player, $new);
    }

    protected function onDisable(): void {
        $this->economy->save();
        $this->data->save();
    }
}

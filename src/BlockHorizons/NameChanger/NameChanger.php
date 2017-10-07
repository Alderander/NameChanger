<?php

namespace BlockHorizons\NameChanger;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\ServerSettingsRequestPacket;
use pocketmine\network\mcpe\protocol\ServerSettingsResponsePacket;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\utils\UUID;

class NameChanger extends PluginBase implements Listener {

	/** @var PlayerSession[] */
	private $sessions = [];
	/** @var array */
	private $userNameChanged = [];

	public function onEnable() {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		if(!is_dir($this->getDataFolder())) {
			mkdir($this->getDataFolder());
		}
		$this->saveResource("NameChangeSettings.json");
		if(file_exists($path = $this->getDataFolder() . "sessions.yml")) {
			foreach(yaml_parse_file($path) as $clientUUID => $serializedSession) {
				$this->sessions[$clientUUID] = unserialize($serializedSession);
			}
			unlink($path);
		}
	}

	public function onDisable() {
		$data = [];
		foreach($this->sessions as $clientUUID => $session) {
			$data[$clientUUID] = serialize($session);
		}
		yaml_emit_file($this->getDataFolder() . "sessions.yml", $data);
	}

	/**
	 * @param PlayerJoinEvent $event
	 */
	public function onJoin(PlayerJoinEvent $event) {
		if(isset($this->userNameChanged[$event->getPlayer()->getName()])) {
			$event->getPlayer()->sendMessage(TextFormat::GREEN . "§dJust to let you know, Your username has been changed to§5 " . $event->getPlayer()->getName());
			unset($this->userNameChanged[$event->getPlayer()->getName()]);
		} else {
			$event->getPlayer()->sendMessage(TextFormat::AQUA . "§bDid you know, you can change your MCPE username in game! 1. &3Go to pause menu. 2. Go to settings. 3. Click VoidMinerPE Name changer menu 4. Enter any username you want. Then toggle on confirm custom name. And you're all done!");
		}
	}

	/**
	 * @param DataPacketReceiveEvent $event
	 */
	public function onDataPacket(DataPacketReceiveEvent $event) {
		$packet = $event->getPacket();
		if($packet instanceof ServerSettingsRequestPacket) {
			$packet = new ServerSettingsResponsePacket();
			$packet->formData = file_get_contents($this->getDataFolder() . "NameChangeSettings.json");
			$packet->formId = 3218; // For future readers, this ID should be something other plugins won't use, and is only for yourself to recognize your response packets.
			$event->getPlayer()->dataPacket($packet);
		} elseif($packet instanceof ModalFormResponsePacket) {
			$formId = $packet->formId;
			if($formId !== 3218) {
				return;
			}
			$formData = (array) json_decode($packet->formData, true);
			if(!$confirmed = $formData[2]) {
				$event->getPlayer()->sendMessage(TextFormat::RED . "§2You did not click the name change confirm button.");
				return;
			}
			if(strtolower($formData[1]) === $event->getPlayer()->getLowerCaseName()) {
				$event->getPlayer()->sendMessage(TextFormat::RED . "You can't change your name to your current name.");
				return;
			}
			$this->sessions[$event->getPlayer()->getUniqueId()->toString()]->setUserName($formData[1]);
			$event->getPlayer()->transfer($this->sessions[$event->getPlayer()->getUniqueId()->toString()]->getAddress(), $this->sessions[$event->getPlayer()->getUniqueId()->toString()]->getPort(), "Username is being changed.");
		} elseif($packet instanceof LoginPacket) {
			if($packet->clientUUID === null) {
				return;
			}
			if(!isset($this->sessions[UUID::fromString($packet->clientUUID)->toString()])) {
				$this->sessions[UUID::fromString($packet->clientUUID)->toString()] = (new PlayerSession($packet->clientUUID, $packet->serverAddress))->setUserName($packet->username);
				return;
			}
			if($this->sessions[UUID::fromString($packet->clientUUID)->toString()]->getUserName() !== $packet->username) {
				$packet->username = $this->sessions[UUID::fromString($packet->clientUUID)->toString()]->getUserName();
				$this->userNameChanged[$packet->username] = true;
			}
		}
	}
}

<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

namespace iTXTech\Synapse\RakNet;

use Co\Server;
use iTXTech\SimpleFramework\Console\Logger;
use iTXTech\Synapse\Util\Binary;
use iTXTech\Synapse\Util\InternetAddress;
use iTXTech\Synapse\RakNet\Protocol\ACK;
use iTXTech\Synapse\RakNet\Protocol\AdvertiseSystem;
use iTXTech\Synapse\RakNet\Protocol\Datagram;
use iTXTech\Synapse\RakNet\Protocol\EncapsulatedPacket;
use iTXTech\Synapse\RakNet\Protocol\NACK;
use iTXTech\Synapse\RakNet\Protocol\OfflineMessage;
use iTXTech\Synapse\RakNet\Protocol\OpenConnectionReply1;
use iTXTech\Synapse\RakNet\Protocol\OpenConnectionReply2;
use iTXTech\Synapse\RakNet\Protocol\OpenConnectionRequest1;
use iTXTech\Synapse\RakNet\Protocol\OpenConnectionRequest2;
use iTXTech\Synapse\RakNet\Protocol\Packet;
use iTXTech\Synapse\RakNet\Protocol\UnconnectedPing;
use iTXTech\Synapse\RakNet\Protocol\UnconnectedPingOpenConnections;
use iTXTech\Synapse\RakNet\Protocol\UnconnectedPong;
use Swoole\Channel;

class SessionManager{

	/** @var \SplFixedArray<Packet|null> */
	protected $packetPool;

	/** @var int */
	protected $receiveBytes = 0;
	/** @var int */
	protected $sendBytes = 0;

	/** @var Session[] */
	protected $sessions = [];

	/** @var OfflineMessageHandler */
	protected $offlineMessageHandler;
	/** @var string */
	protected $name;

	/** @var int */
	protected $packetLimit = 200;

	/** @var bool */
	protected $shutdown = false;

	/** @var int */
	protected $ticks = 0;
	/** @var float */
	protected $lastMeasure;

	/** @var int[] string (address) => int (unblock time) */
	protected $block = [];
	/** @var int[] string (address) => int (number of packets) */
	protected $ipSec = [];

	public $portChecking = false;

	/** @var int */
	protected $startTimeMS;

	/** @var int */
	protected $maxMtuSize;

	/** @var InternetAddress */
	protected $reusableAddress;

	/** @var Channel */
	private $rChan;
	/** @var Channel */
	private $kChan;

	private $protocolVersion;
	/** @var Server */
	private $server;
	private $id;

	public function __construct(InternetAddress $address, Channel $rChan, Channel $kChan, Server $server,
	                            string $serverName, int $serverId, int $maxMtuSize = 1492,
	                            int $protocolVersion = Properties::DEFAULT_PROTOCOL_VERSION){
		$this->rChan = $rChan;
		$this->kChan = $kChan;
		$this->startTimeMS = (int) (microtime(true) * 1000);
		$this->maxMtuSize = $maxMtuSize;
		$this->offlineMessageHandler = new OfflineMessageHandler($this);
		$this->reusableAddress = $address;

		$this->protocolVersion = $protocolVersion;
		$this->server = $server;
		$this->name = $serverName;
		$this->id = $serverId;

		$this->registerPackets();
	}

	/**
	 * Returns the time in milliseconds since server start.
	 * @return int
	 */
	public function getRakNetTimeMS(): int{
		return ((int) (microtime(true) * 1000)) - $this->startTimeMS;
	}

	public function getPort(): int{
		return $this->reusableAddress->port;
	}

	public function getMaxMtuSize(): int{
		return $this->maxMtuSize;
	}

	public function getProtocolVersion(): int{
		return $this->protocolVersion;
	}

	public function tick(): void{
		while($this->receiveStream());

		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
		}

		$this->ipSec = [];

		if($this->sendBytes > 0 or $this->receiveBytes > 0){
			$diff = max(0.005, $time - $this->lastMeasure);
			$this->streamOption("bandwidth", serialize([
				"up" => $this->sendBytes / $diff,
				"down" => $this->receiveBytes / $diff
			]));
			$this->sendBytes = 0;
			$this->receiveBytes = 0;
		}
		$this->lastMeasure = $time;

		if(count($this->block) > 0){
			asort($this->block);
			$now = time();
			foreach($this->block as $address => $timeout){
				if($timeout <= $now){
					unset($this->block[$address]);
				}else{
					break;
				}
			}
		}
	}


	public function receivePacket(string $addr, int $port, string $buffer): bool{
		$this->reusableAddress->ip = $addr;
		$this->reusableAddress->port = $port;
		$address = $this->reusableAddress;

		//$this->receiveBytes += $len;
		if(isset($this->block[$address->ip])){
			return true;
		}

		if(isset($this->ipSec[$address->ip])){
			if(++$this->ipSec[$address->ip] >= $this->packetLimit){
				$this->blockAddress($address->ip);
				return true;
			}
		}else{
			$this->ipSec[$address->ip] = 1;
		}

		/*if($len < 1){
			return true;
		}*/

		try{
			$pid = ord($buffer{0});

			$session = $this->getSession($address);
			if($session !== null){
				if(($pid & Datagram::BITFLAG_VALID) !== 0){
					if($pid & Datagram::BITFLAG_ACK){
						$session->handlePacket(new ACK($buffer));
					}elseif($pid & Datagram::BITFLAG_NAK){
						$session->handlePacket(new NACK($buffer));
					}else{
						$session->handlePacket(new Datagram($buffer));
					}
				}else{
					Logger::debug("Ignored unconnected packet from $address due to session already opened (0x" . dechex($pid) . ")");
				}
			}elseif(($pk = $this->getPacketFromPool($pid, $buffer)) instanceof OfflineMessage){
				/** @var OfflineMessage $pk */

				do{
					try{
						$pk->decode();
						if(!$pk->isValid()){
							throw new \InvalidArgumentException("Packet magic is invalid");
						}
					}catch(\Throwable $e){
						Logger::debug("Received garbage message from $address (" . $e->getMessage() . "): " . bin2hex($pk->buffer));
						Logger::logException($e);
						$this->blockAddress($address->ip, 5);
						break;
					}

					if(!$this->offlineMessageHandler->handle($pk, $address)){
						Logger::debug("Unhandled unconnected packet " . get_class($pk) . " received from $address");
					}
				}while(false);
			}elseif(($pid & Datagram::BITFLAG_VALID) !== 0 and ($pid & 0x03) === 0){
				// Loose datagram, don't relay it as a raw packet
				// RakNet does not currently use the 0x02 or 0x01 bitflags on any datagram header, so we can use
				// this to identify the difference between loose datagrams and packets like Query.
				Logger::debug("Ignored connected packet from $address due to no session opened (0x" . dechex($pid) . ")");
			}else{
				$this->streamRaw($address, $buffer);
			}
		}catch(\Throwable $e){
			Logger::debug("Packet from $address (" . strlen($buffer) . " bytes): 0x" . bin2hex($buffer));
			Logger::logException($e);
			$this->blockAddress($address->ip, 5);
		}

		return true;
	}

	public function sendPacket(Packet $packet, InternetAddress $address): void{
		$packet->encode();
		$this->server->sendto($address->ip, $address->port, $packet->buffer);
	}

	public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, int $flags = Properties::PRIORITY_NORMAL): void{
		$id = $session->getAddress()->toString();
		$buffer = chr(Properties::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . chr($flags) . $packet->toInternalBinary();
		$this->rChan->push($buffer);
	}

	public function streamRaw(InternetAddress $source, string $payload): void{
		$buffer = chr(Properties::PACKET_RAW) . chr(strlen($source->ip)) . $source->ip . Binary::writeShort($source->port) . $payload;
		$this->rChan->push($buffer);
	}

	protected function streamClose(string $identifier, string $reason): void{
		$buffer = chr(Properties::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
		$this->rChan->push($buffer);
	}

	protected function streamInvalid(string $identifier): void{
		$buffer = chr(Properties::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		$this->rChan->push($buffer);
	}

	protected function streamOpen(Session $session): void{
		$address = $session->getAddress();
		$identifier = $address->toString();
		$buffer = chr(Properties::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . $identifier . chr(strlen($address->ip)) . $address->ip . Binary::writeShort($address->port) . Binary::writeLong($session->getID());
		$this->rChan->push($buffer);
	}

	protected function streamACK(string $identifier, int $identifierACK): void{
		$buffer = chr(Properties::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . $identifier . Binary::writeInt($identifierACK);
		$this->rChan->push($buffer);
	}

	/**
	 * @param string $name
	 * @param mixed $value
	 */
	protected function streamOption(string $name, $value): void{
		$buffer = chr(Properties::PACKET_SET_OPTION) . chr(strlen($name)) . $name . $value;
		$this->rChan->push($buffer);
	}

	public function streamPingMeasure(Session $session, int $pingMS): void{
		$identifier = $session->getAddress()->toString();
		$buffer = chr(Properties::PACKET_REPORT_PING) . chr(strlen($identifier)) . $identifier . Binary::writeInt($pingMS);
		$this->rChan->push($buffer);
	}

	public function receiveStream(): bool{
		if(($packet = $this->kChan->pop()) !== false){
			$id = ord($packet{0});
			$offset = 1;
			if($id === Properties::PACKET_ENCAPSULATED){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$session = $this->sessions[$identifier] ?? null;
				if($session !== null and $session->isConnected()){
					$flags = ord($packet{$offset++});
					$buffer = substr($packet, $offset);
					$session->addEncapsulatedToQueue(EncapsulatedPacket::fromInternalBinary($buffer), $flags);
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === Properties::PACKET_RAW){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
				$this->server->sendto($address, $port, $payload);
			}elseif($id === Properties::PACKET_CLOSE_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->sessions[$identifier]->flagForDisconnection();
				}else{
					$this->streamInvalid($identifier);
				}
			}elseif($id === Properties::PACKET_INVALID_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				if(isset($this->sessions[$identifier])){
					$this->removeSession($this->sessions[$identifier]);
				}
			}elseif($id === Properties::PACKET_SET_OPTION){
				$len = ord($packet{$offset++});
				$name = substr($packet, $offset, $len);
				$offset += $len;
				$value = substr($packet, $offset);
				switch($name){
					case "name":
						$this->name = $value;
						break;
					case "portChecking":
						$this->portChecking = (bool) $value;
						break;
					case "packetLimit":
						$this->packetLimit = (int) $value;
						break;
				}
			}elseif($id === Properties::PACKET_BLOCK_ADDRESS){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$timeout = Binary::readInt(substr($packet, $offset, 4));
				$this->blockAddress($address, $timeout);
			}elseif($id === Properties::PACKET_UNBLOCK_ADDRESS){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$this->unblockAddress($address);
			}elseif($id === Properties::PACKET_SHUTDOWN){
				foreach($this->sessions as $session){
					$this->removeSession($session);
				}

				//$this->socket->close();
				$this->shutdown = true;
			}elseif($id === Properties::PACKET_EMERGENCY_SHUTDOWN){
				$this->shutdown = true;
			}else{
				Logger::debug("Unknown RakLib internal packet (ID 0x" . dechex($id) . ") received from main thread");
			}

			return true;
		}

		return false;
	}

	public function blockAddress(string $address, int $timeout = 300): void{
		$final = time() + $timeout;
		if(!isset($this->block[$address]) or $timeout === -1){
			if($timeout === -1){
				$final = PHP_INT_MAX;
			}else{
				Logger::notice("Blocked $address for $timeout seconds");
			}
			$this->block[$address] = $final;
		}elseif($this->block[$address] < $final){
			$this->block[$address] = $final;
		}
	}

	public function unblockAddress(string $address): void{
		unset($this->block[$address]);
		Logger::debug("Unblocked $address");
	}

	/**
	 * @param InternetAddress $address
	 *
	 * @return Session|null
	 */
	public function getSession(InternetAddress $address): ?Session{
		return $this->sessions[$address->toString()] ?? null;
	}

	public function sessionExists(InternetAddress $address): bool{
		return isset($this->sessions[$address->toString()]);
	}

	public function createSession(InternetAddress $address, int $clientId, int $mtuSize): Session{
		$this->checkSessions();

		$this->sessions[$address->toString()] = $session = new Session($this, clone $address, $clientId, $mtuSize);
		Logger::debug("Created session for $address with MTU size $mtuSize");

		return $session;
	}

	public function removeSession(Session $session, string $reason = "unknown"): void{
		$id = $session->getAddress()->toString();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->close();
			$this->removeSessionInternal($session);
			$this->streamClose($id, $reason);
		}
	}

	public function removeSessionInternal(Session $session): void{
		unset($this->sessions[$session->getAddress()->toString()]);
	}

	public function openSession(Session $session): void{
		$this->streamOpen($session);
	}

	private function checkSessions(): void{
		if(count($this->sessions) > 4096){
			foreach($this->sessions as $i => $s){
				if($s->isTemporal()){
					unset($this->sessions[$i]);
					if(count($this->sessions) <= 4096){
						break;
					}
				}
			}
		}
	}

	public function notifyACK(Session $session, int $identifierACK): void{
		$this->streamACK($session->getAddress()->toString(), $identifierACK);
	}

	public function getName(): string{
		return $this->name;
	}

	public function getId(): int{
		return $this->id;
	}

	/**
	 * @param int $id
	 * @param string $class
	 */
	private function registerPacket(int $id, string $class): void{
		$this->packetPool[$id] = new $class;
	}

	/**
	 * @param int $id
	 * @param string $buffer
	 *
	 * @return Packet|null
	 */
	public function getPacketFromPool(int $id, string $buffer = ""): ?Packet{
		$pk = $this->packetPool[$id];
		if($pk !== null){
			$pk = clone $pk;
			$pk->buffer = $buffer;
			return $pk;
		}

		return null;
	}

	private function registerPackets(): void{
		$this->packetPool = new \SplFixedArray(256);

		$this->registerPacket(UnconnectedPing::$ID, UnconnectedPing::class);
		$this->registerPacket(UnconnectedPingOpenConnections::$ID, UnconnectedPingOpenConnections::class);
		$this->registerPacket(OpenConnectionRequest1::$ID, OpenConnectionRequest1::class);
		$this->registerPacket(OpenConnectionReply1::$ID, OpenConnectionReply1::class);
		$this->registerPacket(OpenConnectionRequest2::$ID, OpenConnectionRequest2::class);
		$this->registerPacket(OpenConnectionReply2::$ID, OpenConnectionReply2::class);
		$this->registerPacket(UnconnectedPong::$ID, UnconnectedPong::class);
		$this->registerPacket(AdvertiseSystem::$ID, AdvertiseSystem::class);
	}
}

<?php
declare(strict_types=1);
namespace MyPlot\provider;

use MyPlot\MyPlot;
use MyPlot\Plot;
use pocketmine\Server;

class MySQLProvider extends DataProvider
{
	/** @var \mysqli $db */
	protected $db;
	/** @var mixed[] $settings */
	protected $settings;
	/** @var \mysqli_stmt $sqlGetPlot */
	protected $sqlGetPlot;
	/** @var \mysqli_stmt $sqlSavePlot */
	protected $sqlSavePlot;
	/** @var \mysqli_stmt $sqlSavePlotById */
	protected $sqlSavePlotById;
	/** @var \mysqli_stmt $sqlRemovePlot */
	protected $sqlRemovePlot;
	/** @var \mysqli_stmt $sqlRemovePlotById */
	protected $sqlRemovePlotById;
	/** @var \mysqli_stmt $sqlGetPlotsByOwner */
	protected $sqlGetPlotsByOwner;
	/** @var \mysqli_stmt $sqlGetPlotsByOwnerAndLevel */
	protected $sqlGetPlotsByOwnerAndLevel;
	/** @var \mysqli_stmt $sqlGetExistingXZ */
	protected $sqlGetExistingXZ;

	/**
	 * MySQLProvider constructor.
	 *
	 * @param MyPlot $plugin
	 * @param int $cacheSize
	 * @param mixed[] $settings
	 */
	public function __construct(MyPlot $plugin, int $cacheSize = 0, array $settings = []) {
		ini_set("mysqli.reconnect", "1");
		ini_set('mysqli.allow_persistent', "1");
		ini_set('mysql.connect_timeout', "300");
		ini_set('default_socket_timeout', "300");
		$this->plugin = $plugin;
		parent::__construct($plugin, $cacheSize);
		$this->settings = $settings;
		$this->db = new \mysqli($settings['Host'], $settings['Username'], $settings['Password'], $settings['DatabaseName'], $settings['Port']);
		if($this->db->connect_error !== '')
			throw new \RuntimeException("Failed to connect to the MySQL database: " . $this->db->connect_error);
		$this->db->query("CREATE TABLE IF NOT EXISTS plots (id INT PRIMARY KEY AUTO_INCREMENT, level TEXT, X INT, Z INT, name TEXT, owner TEXT, helpers TEXT, denied TEXT, biome TEXT, pvp INT, price FLOAT, merged_plots TEXT, flags TEXT);");
		try{
			$this->db->query("ALTER TABLE plots ADD COLUMN pvp INT AFTER biome;");
		}catch(\Exception $e) {}
		try{
			$this->db->query("ALTER TABLE plots ADD COLUMN price FLOAT AFTER pvp;");
		}catch(\Exception $e) {}
        try{
            $this->db->query("ALTER TABLE plots ADD COLUMN merged_plots TEXT AFTER price;");
        }catch(\Exception $e) {}
        try{
            $this->db->query("ALTER TABLE plots ADD COLUMN flags TEXT AFTER merged_plots;");
        }catch(\Exception $e) {}
		$this->prepare();
		$this->plugin->getLogger()->debug("MySQL data provider registered");
	}

	public function savePlot(Plot $plot) : bool {
		$this->reconnect();
		$helpers = implode(',', $plot->helpers);
		$denied = implode(',', $plot->denied);
        $merged_plots = implode(',', $plot->merged_plots);
        $flags = json_encode($plot->flags, JSON_FORCE_OBJECT);
        if($plot->id >= 0) {
			$stmt = $this->sqlSavePlotById;
			$stmt->bind_param('isiisssssidss', $plot->id, $plot->levelName, $plot->X, $plot->Z, $plot->name, $plot->owner, $helpers, $denied, $plot->biome, $plot->pvp, $plot->price, $merged_plots, $flags);
		}else{
			$stmt = $this->sqlSavePlot;
			$stmt->bind_param('siisiisssssidss', $plot->levelName, $plot->X, $plot->Z, $plot->levelName, $plot->X, $plot->Z, $plot->name, $plot->owner, $helpers, $denied, $plot->biome, $plot->pvp, $plot->price, $merged_plots, $flags);
		}
		$result = $stmt->execute();
		if($result === false) {
			$this->plugin->getLogger()->error($stmt->error);
			return false;
		}
		$this->cachePlot($plot);
		return true;
	}

	public function deletePlot(Plot $plot) : bool {
		$this->reconnect();
		if($plot->id >= 0) {
			$stmt = $this->sqlRemovePlotById;
			$stmt->bind_param('i', $plot->id);
		}else{
			$stmt = $this->sqlRemovePlot;
			$stmt->bind_param('sii', $plot->levelName, $plot->X, $plot->Z);
		}
		$result = $stmt->execute();
		if($result === false) {
			$this->plugin->getLogger()->error($stmt->error);
			return false;
		}
		$plot = new Plot($plot->levelName, $plot->X, $plot->Z);
		$this->cachePlot($plot);
		return true;
	}

	public function getPlot(string $levelName, int $X, int $Z) : Plot {
		$this->reconnect();
		if(($plot = $this->getPlotFromCache($levelName, $X, $Z)) != null) {
			return $plot;
		}
		$stmt = $this->sqlGetPlot;
		$stmt->bind_param('sii', $levelName, $X, $Z);
		$result = $stmt->execute();
		if($result === false) {
			$this->plugin->getLogger()->error($stmt->error);
			return new Plot($levelName, $X, $Z);
		}
		$result = $stmt->get_result();
		if($result !== false and ($val = $result->fetch_array(MYSQLI_ASSOC)) !== null) {
			if($val["helpers"] === '') {
				$helpers = [];
			}else{
				$helpers = explode(",", (string) $val["helpers"]);
			}
			if($val["denied"] === '') {
				$denied = [];
			}else{
				$denied = explode(",", (string) $val["denied"]);
			}
			$pvp = is_numeric($val["pvp"]) ? (bool)$val["pvp"] : null;
            if($val["merged_plots"] === '') {
                $merged_plots = [];
            }else{
                $merged_plots = explode(",", (string) $val["merged_plots"]);
            }
            if ($val['flags'] === '{}' or $val['flags'] === '') {
                $flags = [];
            } else $flags = json_decode($val['flags'], true);
			$plot = new Plot($levelName, $X, $Z, (string) $val["name"], (string) $val["owner"], $helpers, $denied, (string) $val["biome"], $pvp, (float) $val["price"], $merged_plots, $flags, (int) $val["id"]);
		}else{
			$plot = new Plot($levelName, $X, $Z);
		}
		$this->cachePlot($plot);
		return $plot;
	}

	/**
	 * @param string $owner
	 * @param string $levelName
	 *
	 * @return Plot[]
	 */
	public function getPlotsByOwner(string $owner, string $levelName = "") : array {
		$this->reconnect();
		if($levelName === '') {
			$stmt = $this->sqlGetPlotsByOwner;
			$stmt->bind_param('s', $owner);
		}else{
			$stmt = $this->sqlGetPlotsByOwnerAndLevel;
			$stmt->bind_param('ss', $owner, $levelName);
		}
		$plots = [];
		$result = $stmt->execute();
		if($result === false) {
			$this->plugin->getLogger()->error($stmt->error);
			return $plots;
		}
		$result = $stmt->get_result();
		while($result !== false and ($val = $result->fetch_array()) !== null) {
			$helpers = explode(",", (string) $val["helpers"]);
			$denied = explode(",", (string) $val["denied"]);
			$pvp = is_numeric($val["pvp"]) ? (bool)$val["pvp"] : null;
            $merged_plots = explode(",", (string) $val["merged_plots"]);
            if ($val['flags'] === '{}' or $val['flags'] === '') {
                $flags = [];
            } else $flags = json_decode($val['flags'], true);
			$plots[] = new Plot((string) $val["level"], (int) $val["X"], (int) $val["Z"], (string) $val["name"], (string) $val["owner"], $helpers, $denied, (string) $val["biome"], $pvp, (float) $val["price"], $merged_plots, $flags, (int) $val["id"]);
		}
		// Remove unloaded plots
		$plots = array_filter($plots, function(Plot $plot) : bool {
			return $this->plugin->isLevelLoaded($plot->levelName);
		});
		// Sort plots by level
		usort($plots, function(Plot $plot1, Plot $plot2) : int {
			return strcmp($plot1->levelName, $plot2->levelName);
		});
		return $plots;
	}

	public function getNextFreePlot(string $levelName, int $limitXZ = 0) : ?Plot {
		$this->reconnect();
		$i = 0;
		for(; $limitXZ <= 0 or $i < $limitXZ; $i++) {
			$stmt = $this->sqlGetExistingXZ;
			$stmt->bind_param('siiii', $levelName, $i, $i, $i, $i);
			$result = $stmt->execute();
			if($result === false) {
				$this->plugin->getLogger()->error($stmt->error);
				continue;
			}
			$result = $stmt->get_result();
			$plots = [];
			while($result !== false and ($val = $result->fetch_array(MYSQLI_NUM)) !== null) {
				$plots[$val[0]][$val[1]] = true;
			}
			if(count($plots) === max(1, 8 * $i)) {
				continue;
			}
			if(($ret = self::findEmptyPlotSquared(0, $i, $plots)) !== null) {
				[$X, $Z] = $ret;
				$plot = new Plot($levelName, $X, $Z);
				$this->cachePlot($plot);
				return $plot;
			}
			for($a = 1; $a < $i; $a++) {
				if(($ret = self::findEmptyPlotSquared($a, $i, $plots)) !== null) {
					[$X, $Z] = $ret;
					$plot = new Plot($levelName, $X, $Z);
					$this->cachePlot($plot);
					return $plot;
				}
			}
			if(($ret = self::findEmptyPlotSquared($i, $i, $plots)) !== null) {
				[$X, $Z] = $ret;
				$plot = new Plot($levelName, $X, $Z);
				$this->cachePlot($plot);
				return $plot;
			}
		}
		return null;
	}

	public function close() : void {
		if($this->db->close())
			$this->plugin->getLogger()->debug("MySQL database closed!");
	}

	private function reconnect() : bool {
		if(!$this->db->ping()) {
			$this->plugin->getLogger()->error("The MySQL server can not be reached! Trying to reconnect!");
			$this->close();
			$this->db->connect($this->settings['Host'], $this->settings['Username'], $this->settings['Password'], $this->settings['DatabaseName'], $this->settings['Port']);
			$this->prepare();
			if($this->db->ping()) {
				$this->plugin->getLogger()->notice("The MySQL connection has been re-established!");
				return true;
			}else{
				$this->plugin->getLogger()->critical("The MySQL connection could not be re-established!");
				$this->plugin->getLogger()->critical("Closing level to prevent griefing!");
				foreach($this->plugin->getPlotLevels() as $levelName => $settings) {
					$level = $this->plugin->getServer()->getWorldManager()->getWorldByName($levelName);
					if($level !== null) {
						$level->save(); // don't force in case owner doesn't want it saved
						Server::getInstance()->getWorldManager()->unloadWorld($level, true); // force unload to prevent possible griefing
					}
				}
				if($this->db->connect_error !== '')
					$this->plugin->getLogger()->critical("Failed to connect to the MySQL database: " . $this->db->connect_error);
				if((bool)$this->plugin->getConfig()->getNested("MySQLSettings.ShutdownOnFailure", false)) {
					$this->plugin->getServer()->shutdown();
				}
				return false;
			}
		}
		return true;
	}

	private function prepare() : void {
		$stmt = $this->db->prepare("SELECT id, name, owner, helpers, denied, biome, pvp, price, merged_plots, flags FROM plots WHERE level = ? AND X = ? AND Z = ?;");
		if($stmt === false)
			throw new \Exception();
		$this->sqlGetPlot = $stmt;
		$stmt = $this->db->prepare("INSERT INTO plots (`id`, `level`, `X`, `Z`, `name`, `owner`, `helpers`, `denied`, `biome`, `pvp`, `price`, `merged_plots`, `flags`) VALUES((SELECT id FROM plots p WHERE p.level = ? AND X = ? AND Z = ?),?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), owner = VALUES(owner), helpers = VALUES(helpers), denied = VALUES(denied), biome = VALUES(biome), pvp = VALUES(pvp), price = VALUES(price), merged_plots = VALUES(merged_plots), flags = VALUES(flags);");
		if($stmt === false)
			throw new \Exception();
		$this->sqlSavePlot = $stmt;
		$stmt = $this->db->prepare("UPDATE plots SET id = ?, level = ?, X = ?, Z = ?, name = ?, owner = ?, helpers = ?, denied = ?, biome = ?, pvp = ?, price = ?, merged_plots = ?, flags = ? WHERE id = VALUES(id);");
		if($stmt === false)
			throw new \Exception();
		$this->sqlSavePlotById = $stmt;
		$stmt = $this->db->prepare("DELETE FROM plots WHERE id = ?;");
		if($stmt === false)
			throw new \Exception();
		$this->sqlRemovePlotById = $stmt;
		$stmt = $this->db->prepare("DELETE FROM plots WHERE level = ? AND X = ? AND Z = ?;");
		if($stmt === false)
			throw new \Exception();
		$this->sqlRemovePlot = $stmt;
		$stmt = $this->db->prepare("SELECT * FROM plots WHERE owner = ?;");
		if($stmt === false)
			throw new \Exception();
		$this->sqlGetPlotsByOwner = $stmt;
		$stmt = $this->db->prepare("SELECT * FROM plots WHERE owner = ? AND level = ?;");
		if($stmt === false)
			throw new \Exception();
		$this->sqlGetPlotsByOwnerAndLevel = $stmt;
		$stmt = $this->db->prepare("SELECT X, Z FROM plots WHERE (level = ? AND ((abs(X) = ? AND abs(Z) <= ?) OR (abs(Z) = ? AND abs(X) <= ?)));");
		if($stmt === false)
			throw new \Exception();
		$this->sqlGetExistingXZ = $stmt;
	}
}

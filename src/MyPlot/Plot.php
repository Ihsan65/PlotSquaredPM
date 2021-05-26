<?php
declare(strict_types=1);
namespace MyPlot;

use pocketmine\level\Position;
use pocketmine\math\Vector3;

class Plot
{

	/** @var string $levelName */
	public $levelName = "";
	/** @var int $X */
	public $X = -0;
	/** @var int $Z */
	public $Z = -0;
    /** @var string $description */
    public $description = "";
	/** @var string $name */
	public $name = "";
	/** @var string $owner */
	public $owner = "";
	/** @var string[] $helpers */
	public $helpers = [];
	/** @var string[] $denied */
	public $denied = [];
	/** @var string $biome */
	public $biome = "PLAINS";
	/** @var bool $pvp */
	public $pvp = true;
	/** @var float $price */
	public $price = 0.0;
    /** @var array $merged_plots */
	public $merged_plots = [];
    /** @var array $flags */
    public $flags = [];
    /** @var Position|null $spawn */
    public $spawn = null;
    /** @var bool $chat */
    public $chat = false;
	/** @var int $id */
	public $id = -1;

	/**
	 * Plot constructor.
	 *
	 * @param string $levelName
	 * @param int $X
	 * @param int $Z
     * @param string $description
	 * @param string $name
	 * @param string $owner
	 * @param string[] $helpers
	 * @param string[] $denied
	 * @param string $biome
	 * @param bool|null $pvp
	 * @param float $price
     * @param array $merged_plots
     * @param array $flags
     * @param Position|null $spawn
     * @param bool $chat
	 * @param int $id
	 */
	public function __construct(string $levelName, int $X, int $Z, string $description = "", string $name = "", string $owner = "", array $helpers = [], array $denied = [], string $biome = "PLAINS", ?bool $pvp = null, float $price = -1, array $merged_plots = [], array $flags = [], ?Position $spawn = null, bool $chat = false, int $id = -1) {
		$this->levelName = $levelName;
		$this->X = $X;
		$this->Z = $Z;
		$this->description = $description;
		$this->name = $name;
		$this->owner = $owner;
		$this->helpers = $helpers;
		$this->denied = $denied;
		$this->biome = strtoupper($biome);
		if (MyPlot::getInstance()->isLevelLoaded($levelName)) {
            $settings = MyPlot::getInstance()->getLevelSettings($levelName);
        } else {
            $settings = new PlotLevelSettings($levelName, ["Fake" => '5:0']);
        }
		if(!isset($pvp)) {
			$this->pvp = !$settings->restrictPVP;
		}else{
			$this->pvp = $pvp;
		}
		$this->price = $price < 0 ? $settings->claimPrice : $price;
		$this->merged_plots = $merged_plots;
		$this->flags = $flags;
		$this->spawn = $spawn;
		$this->chat = $chat;
		$this->id = $id;
	}

	/**
	 * @api
	 *
	 * @param string $username
	 *
	 * @return bool
	 */
	public function isHelper(string $username) : bool {
		return in_array($username, $this->helpers, true);
	}

	/**
	 * @api
	 *
	 * @param string $username
	 *
	 * @return bool
	 */
	public function addHelper(string $username) : bool {
		if(!$this->isHelper($username)) {
			$this->unDenyPlayer($username);
			$this->helpers[] = $username;
			return true;
		}
		return false;
	}

	/**
	 * @api
	 *
	 * @param string $username
	 *
	 * @return bool
	 */
	public function removeHelper(string $username) : bool {
		if(!$this->isHelper($username)) {
			return false;
		}
		$key = array_search($username, $this->helpers, true);
		if($key === false) {
			return false;
		}
		unset($this->helpers[$key]);
		return true;
	}

	/**
	 * @api
	 *
	 * @param string $username
	 *
	 * @return bool
	 */
	public function isDenied(string $username) : bool {
		return in_array($username, $this->denied, true);
	}

	/**
	 * @api
	 *
	 * @param string $username
	 *
	 * @return bool
	 */
	public function denyPlayer(string $username) : bool {
		if(!$this->isDenied($username)) {
			$this->removeHelper($username);
			$this->denied[] = $username;
			return true;
		}
		return false;
	}

	/**
	 * @api
	 *
	 * @param string $username
	 *
	 * @return bool
	 */
	public function unDenyPlayer(string $username) : bool {
		if(!$this->isDenied($username)) {
			return false;
		}
		$key = array_search($username, $this->denied, true);
		if($key === false) {
			return false;
		}
		unset($this->denied[$key]);
		return true;
	}

    /**
     * @api
     *
     * @param string $flag_name
     *
     * @return false|mixed
     */
    public function getFlag(string $flag_name) {
        if (isset($this->flags[$flag_name])) return $this->flags[$flag_name];
        return false;
    }

    /**
     * @api
     *
     * @param string $flag
     * @param $value
     *
     * @return bool
     */
    public function setFlag(string $flag, $value) : bool {
        $this->flags[$flag] = $value;
        return true;
    }

    /**
     * @api
     *
     * @param string $flag
     *
     * @return bool
     */
    public function removeFlag(string $flag) : bool {
        if(!$this->getFlag($flag)) {
            return false;
        }
        unset($this->flags[$flag]);
        return true;
    }

	/**
	 * @api
	 *
	 * @param Plot $plot
	 *
	 * @return bool
	 */
	public function isSame(Plot $plot) : bool {
		return $this->X === $plot->X and $this->Z === $plot->Z and $this->levelName === $plot->levelName;
	}

	public function getSide(int $side, int $step = 1) : Plot {
		$levelSettings = MyPlot::getInstance()->getLevelSettings($this->levelName);
		$pos = MyPlot::getInstance()->getPlotPosition($this);
		$sidePos = $pos->getSide($side, $step * ($levelSettings->plotSize + $levelSettings->roadWidth));
		$sidePlot = MyPlot::getInstance()->getPlotByPosition($sidePos);
		if($sidePlot === null) {
			switch($side) {
				case Vector3::SIDE_NORTH:
					$sidePlot = new self($this->levelName, $this->X, $this->Z - $step);
				break;
				case Vector3::SIDE_SOUTH:
					$sidePlot = new self($this->levelName, $this->X, $this->Z + $step);
				break;
				case Vector3::SIDE_WEST:
					$sidePlot = new self($this->levelName, $this->X - $step, $this->Z);
				break;
				case Vector3::SIDE_EAST:
					$sidePlot = new self($this->levelName, $this->X + $step, $this->Z);
				break;
				default:
					return clone $this;
			}
		}
		return $sidePlot;
	}

	public function __toString() : string {
		return "(" . $this->X . ";" . $this->Z . ")";
	}
}
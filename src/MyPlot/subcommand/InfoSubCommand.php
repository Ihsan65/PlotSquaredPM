<?php
declare(strict_types=1);
namespace MyPlot\subcommand;

use MyPlot\forms\MyPlotForm;
use MyPlot\forms\subforms\InfoForm;
use MyPlot\MyPlot;
use MyPlot\Plot;
use pocketmine\command\CommandSender;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class InfoSubCommand extends SubCommand
{
	public function canUse(CommandSender $sender) : bool {
		return ($sender instanceof Player) and $sender->hasPermission("myplot.command.info");
	}

	/**
	 * @param Player $sender
	 * @param string[] $args
	 *
	 * @return bool
	 */
	public function execute(CommandSender $sender, array $args) : bool {
		if(isset($args[0])) {
			if(isset($args[1]) and is_numeric($args[1])) {
				$key = ((int) $args[1] - 1) < 1 ? 1 : ((int) $args[1] - 1);
				/** @var Plot[] $plots */
				$plots = [];
				foreach($this->getPlugin()->getPlotLevels() as $levelName => $settings) {
					$plots = array_merge($plots, $this->getPlugin()->getPlotsOfPlayer($args[0], $levelName));
				}
				if(isset($plots[$key])) {
					$plot = $plots[$key];
					$form = new InfoForm($sender);
					$form->setPlot($plot);
					$sender->sendForm($form);
				}else{
					$sender->sendMessage(MyPlot::getPrefix() . TextFormat::RED . $this->translateString("info.notfound"));
				}
			}else{
				return false;
			}
		}else{
			$plot = $this->getPlugin()->getPlotByPosition($sender);
			if($plot === null) {
				$sender->sendMessage(MyPlot::getPrefix() . TextFormat::RED . $this->translateString("notinplot"));
				return true;
			}
            $form = new InfoForm($sender);
            $form->setPlot($plot);
            $sender->sendForm($form);
		}
		return true;
	}

	public function getForm(?Player $player = null) : ?MyPlotForm {
		if($player !== null and $this->getPlugin()->getPlotByPosition($player) instanceof Plot)
			return new InfoForm($player);
		return null;
	}
}
<?php

namespace MCTeam;

use FML\Controls\Control;
use FML\Controls\Frame;
use FML\Controls\Gauge;
use FML\Controls\Label;
use FML\Controls\Labels\Label_Button;
use FML\Controls\Labels\Label_Text;
use FML\Controls\Quad;
use FML\Controls\Quads\Quad_BgsPlayerCard;
use FML\Controls\Quads\Quad_Icons128x32_1;
use FML\Controls\Quads\Quad_Icons64x64_1;
use FML\Controls\Quads\Quad_UIConstruction_Buttons;
use FML\ManiaLink;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\CallbackManager;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\ColorUtil;
use ManiaControl\Commands\CommandListener;
use ManiaControl\ManiaControl;
use ManiaControl\Manialinks\ManialinkPageAnswerListener;
use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Server\Server;
use ManiaControl\Server\ServerCommands;
use Maniaplanet\DedicatedServer\Structures\VoteRatio;
use Maniaplanet\DedicatedServer\Xmlrpc\NotInScriptModeException;
use FML\Script\Features\KeyAction;


/**
 * ManiaControl Custom-Votes Plugin
 *
 * @author kremsy
 * @copyright ManiaControl Copyright © 2014 ManiaControl Team
 * @license http://www.gnu.org/licenses/ GNU General Public License, Version 3
 */
class CustomVotesPlugin implements CommandListener, CallbackListener, ManialinkPageAnswerListener, TimerListener, Plugin {
	/*
	 * Constants
	 */
	const PLUGIN_ID      = 5;
	const PLUGIN_VERSION = 0.1;
	const PLUGIN_NAME    = 'CustomVotesPlugin';
	const PLUGIN_AUTHOR  = 'kremsy';

	const SETTING_VOTE_ICON_POSX   = 'Vote-Icon-Position: X';
	const SETTING_VOTE_ICON_POSY   = 'Vote-Icon-Position: Y';
	const SETTING_VOTE_ICON_WIDTH  = 'Vote-Icon-Size: Width';
	const SETTING_VOTE_ICON_HEIGHT = 'Vote-Icon-Size: Height';

	const SETTING_WIDGET_POSX                = 'Widget-Position: X';
	const SETTING_WIDGET_POSY                = 'Widget-Position: Y';
	const SETTING_WIDGET_WIDTH               = 'Widget-Size: Width';
	const SETTING_WIDGET_HEIGHT              = 'Widget-Size: Height';
	const SETTING_VOTE_TIME                  = 'Voting Time';
	const SETTING_DEFAULT_PLAYER_RATIO       = 'Minimum Player Voters Ratio';
	const SETTING_DEFAULT_RATIO              = 'Default Success Ratio';
	const SETTING_SPECTATOR_ALLOW_VOTE       = 'Allow Specators to vote';
	const SETTING_SPECTATOR_ALLOW_START_VOTE = 'Allow Specators to start a vote';

	const MLID_WIDGET = 'CustomVotesPlugin.WidgetId';
	const MLID_ICON   = 'CustomVotesPlugin.IconWidgetId';


	const ACTION_POSITIVE_VOTE = 'CustomVotesPlugin.PositiveVote';
	const ACTION_NEGATIVE_VOTE = 'CustomVotesPlugin.NegativeVote';
	const ACTION_START_VOTE    = 'CustomVotesPlugin.StartVote.';


	const CB_CUSTOM_VOTE_FINISHED = 'CustomVotesPlugin.CustomVoteFinished';

	/**
	 * Private properties
	 */
	/** @var maniaControl $maniaControl */
	private $maniaControl = null;
	private $voteCommands = array();
	private $voteMenuItems = array();
	/** @var CurrentVote $currentVote */
	private $currentVote = null;

	/**
	 * Prepares the Plugin
	 *
	 * @param ManiaControl $maniaControl
	 * @return mixed
	 */
	public static function prepare(ManiaControl $maniaControl) {
		//do nothing
	}

	/**
	 * Load the plugin
	 *
	 * @param \ManiaControl\ManiaControl $maniaControl
	 * @return bool
	 */
	public function load(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;

		$this->maniaControl->commandManager->registerCommandListener('vote', $this, 'chat_vote', false, 'Starts a new vote.');
		$this->maniaControl->timerManager->registerTimerListening($this, 'handle1Second', 1000);
		$this->maniaControl->callbackManager->registerCallbackListener(ServerCommands::CB_VOTE_CANCELED, $this, 'handleVoteCanceled');
		$this->maniaControl->manialinkManager->registerManialinkPageAnswerListener(self::ACTION_POSITIVE_VOTE, $this, 'handlePositiveVote');
		$this->maniaControl->manialinkManager->registerManialinkPageAnswerListener(self::ACTION_NEGATIVE_VOTE, $this, 'handleNegativeVote');

		$this->maniaControl->callbackManager->registerCallbackListener(CallbackManager::CB_MP_PLAYERMANIALINKPAGEANSWER, $this, 'handleManialinkPageAnswer');
		$this->maniaControl->callbackManager->registerCallbackListener(self::CB_CUSTOM_VOTE_FINISHED, $this, 'handleVoteFinished');
		$this->maniaControl->callbackManager->registerCallbackListener(PlayerManager::CB_PLAYERCONNECT, $this, 'handlePlayerConnect');
		$this->maniaControl->callbackManager->registerCallbackListener(Server::CB_TEAM_MODE_CHANGED, $this, 'constructMenu');

		//Settings
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_VOTE_ICON_POSX, 156.);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_VOTE_ICON_POSY, -38.6);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_VOTE_ICON_WIDTH, 6);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_VOTE_ICON_HEIGHT, 6);

		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_POSX, -80); //160 -15
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_POSY, 80); //-15
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_WIDTH, 50); //30
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_WIDGET_HEIGHT, 20); //25

		$this->maniaControl->settingManager->initSetting($this, self::SETTING_DEFAULT_RATIO, 0.75);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_DEFAULT_PLAYER_RATIO, 0.65);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_SPECTATOR_ALLOW_VOTE, false);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_SPECTATOR_ALLOW_START_VOTE, false);
		$this->maniaControl->settingManager->initSetting($this, self::SETTING_VOTE_TIME, 60);

		//Define Votes
		$this->defineVote("teambalance", "Vote for Team Balance");
		$this->defineVote("skipmap", "Vote for Skip Map");
		$this->defineVote("nextmap", "Vote for Skip Map");
		$this->defineVote("skip", "Vote for Skip Map");
		$this->defineVote("restartmap", "Vote for Restart Map");
		$this->defineVote("restart", "Vote for Restart Map");
		$this->defineVote("pausegame", "Vote for Pause Game");
		$this->defineVote("replay", "Vote to replay current map");

		foreach($this->voteCommands as $name => $voteCommand) {
			$this->maniaControl->commandManager->registerCommandListener($name, $this, 'handleChatVote', false, $voteCommand->name);
		}

		/* Disable Standard Votes */
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_BAN, -1.);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_KICK, -1.);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_RESTART_MAP, -1.);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_TEAM_BALANCE, -1.);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_NEXT_MAP, -1.);

		$this->maniaControl->client->setCallVoteRatios($ratioArray, false);

		$this->constructMenu();
		return true;
	}

	/**
	 * Unload the plugin and its resources
	 */
	public function unload() {
		//Enable Standard Votes
		$defaultRatio = $this->maniaControl->client->getCallVoteRatio();

		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_BAN, $defaultRatio);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_KICK, $defaultRatio);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_RESTART_MAP, $defaultRatio);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_TEAM_BALANCE, $defaultRatio);
		$ratioArray[] = new VoteRatio(VoteRatio::COMMAND_NEXT_MAP, $defaultRatio);

		$this->maniaControl->client->setCallVoteRatios($ratioArray, false);

		$this->destroyVote();
		$emptyManialink = new ManiaLink(self::MLID_ICON);
		$this->maniaControl->manialinkManager->sendManialink($emptyManialink);
		$this->maniaControl->commandManager->unregisterCommandListener($this);
		$this->maniaControl->callbackManager->unregisterCallbackListener($this);
		$this->maniaControl->manialinkManager->unregisterManialinkPageAnswerListener($this);
		$this->maniaControl->timerManager->unregisterTimerListenings($this);
		unset($this->maniaControl);
	}

	/**
	 * Handle PlayerConnect callback
	 *
	 * @param Player $player
	 */
	public function handlePlayerConnect(Player $player) {
		$this->showIcon($player->login);
	}

	/**
	 * Add a new Vote Menu Item
	 *
	 * @param Control $control
	 * @param int     $order
	 * @param string  $description
	 */
	public function addVoteMenuItem(Control $control, $order = 0, $description = null) {
		if (!isset($this->voteMenuItems[$order])) {
			$this->voteMenuItems[$order] = array();
			array_push($this->voteMenuItems[$order], array($control, $description));
			krsort($this->voteMenuItems);
		}
	}

	/**
	 * Chat Vote
	 *
	 * @param array  $chat
	 * @param Player $player
	 */
	public function chat_vote(array $chat, Player $player) {
		$command = explode(" ", $chat[1][2]);
		if (isset($command[1])) {
			if (isset($this->voteCommands[$command[1]])) {
				$this->startVote($player, strtolower($command[1]));
			}
		}
	}

	/**
	 * Handle ManiaControl OnInit callback
	 *
	 * @internal param array $callback
	 */
	public function constructMenu() {
		// Menu RestartMap
		$itemQuad = new Quad_UIConstruction_Buttons();
		$itemQuad->setSubStyle($itemQuad::SUBSTYLE_Reload);
		$itemQuad->setAction(self::ACTION_START_VOTE . 'restartmap');
		$this->addVoteMenuItem($itemQuad, 5, 'Vote for Restart-Map');

		//Check if Pause exists in current GameMode
		try {
			$scriptInfos = $this->maniaControl->client->getModeScriptInfo();

			$pauseExists = false;
			foreach($scriptInfos->commandDescs as $param) {
				if ($param->name == "Command_ForceWarmUp") {
					$pauseExists = true;
					break;
				}
			}

			// Menu Pause
			if ($pauseExists) {
				$itemQuad = new Quad_Icons128x32_1();
				$itemQuad->setSubStyle($itemQuad::SUBSTYLE_ManiaLinkSwitch);
				$itemQuad->setAction(self::ACTION_START_VOTE . 'pausegame');
				$this->addVoteMenuItem($itemQuad, 10, 'Vote for a pause of Current Game');
			}
		} catch(NotInScriptModeException $e) {
		}

		//Menu SkipMap
		$itemQuad = new Quad_Icons64x64_1();
		$itemQuad->setSubStyle($itemQuad::SUBSTYLE_ArrowFastNext);
		$itemQuad->setAction(self::ACTION_START_VOTE . 'skipmap');
		$this->addVoteMenuItem($itemQuad, 15, 'Vote for a Mapskip');

		if ($this->maniaControl->server->isTeamMode()) {
			//Menu TeamBalance
			$itemQuad = new Quad_Icons128x32_1();
			$itemQuad->setSubStyle($itemQuad::SUBSTYLE_RT_Team);
			$itemQuad->setAction(self::ACTION_START_VOTE . 'teambalance');
			$this->addVoteMenuItem($itemQuad, 20, 'Vote for Team-Balance');
		}
		//Show the Menu's icon
		$this->showIcon();
	}

	/**
	 * Destroy the Vote on Canceled Callback
	 *
	 * @param Player $player
	 */
	public function handleVoteCanceled(Player $player) {
		//reset vote
		$this->destroyVote();
	}

	/**
	 * Handle Standard Votes
	 *
	 * @param $voteName
	 * @param $voteResult
	 */
	public function handleVoteFinished($voteName, $voteResult) {
		if ($voteResult >= $this->currentVote->neededRatio) {
			// Call Closure if one exists
			if (is_callable($this->currentVote->function)) {
				call_user_func($this->currentVote->function, $voteResult);
				return;
			}

			switch($voteName) {
				case 'teambalance':
					$this->maniaControl->client->autoTeamBalance();
					$this->maniaControl->chat->sendInformation('$f8fVote to $fffbalance the teams$f8f has been successfull!');
					break;
				case 'skipmap':
				case 'skip':
				case 'nextmap':
					$this->maniaControl->client->nextMap();
					$this->maniaControl->chat->sendInformation('$f8fVote to $fffskip the map$f8f has been successfull!');
					break;
				case 'restartmap':
					$this->maniaControl->client->restartMap();
					$this->maniaControl->chat->sendInformation('$f8fVote to $fffrestart the map$f8f has been successfull!');
					break;
				case 'pausegame':
					$this->maniaControl->client->sendModeScriptCommands(array('Command_ForceWarmUp' => True));
					$this->maniaControl->chat->sendInformation('$f8fVote to $fffpause the current game$f8f has been successfull!');
					break;
				case 'replay':
					$this->maniaControl->mapManager->mapQueue->addFirstMapToMapQueue($this->currentVote->voter, $this->maniaControl->mapManager->getCurrentMap());
					$this->maniaControl->chat->sendInformation('$f8fVote to $fffreplay the map$f8f has been successfull!');
					break;
			}
		} else {
			$this->maniaControl->chat->sendError('Vote Failed!');
		}
	}

	/**
	 * Handles the ManialinkPageAnswers and start a vote if a button in the panel got clicked
	 *
	 * @param array $callback
	 */
	public function handleManialinkPageAnswer(array $callback) {
		$actionId    = $callback[1][2];
		$actionArray = explode('.', $actionId);
		if (count($actionArray) <= 2) {
			return;
		}

		$voteIndex = $actionArray[2];
		if (isset($this->voteCommands[$voteIndex])) {
			$login  = $callback[1][1];
			$player = $this->maniaControl->playerManager->getPlayer($login);
			$this->startVote($player, $voteIndex);
		}
	}

	public function handleChatVote(array $chat, Player $player) {
		$chatCommand = explode(' ', $chat[1][2]);
		$chatCommand = $chatCommand[0];
		$chatCommand = str_replace('/', '', $chatCommand);

		if (isset($this->voteCommands[$chatCommand])) {
			$this->startVote($player, $chatCommand);
		}
	}

	/**
	 * Defines a Vote
	 *
	 * @param      $voteIndex
	 * @param      $voteName
	 * @param bool $idBased
	 * @param      $neededRatio
	 */
	public function defineVote($voteIndex, $voteName, $idBased = false, $startText = '', $neededRatio = -1) {
		if ($neededRatio == -1) {
			$neededRatio = $this->maniaControl->settingManager->getSetting($this, self::SETTING_DEFAULT_RATIO);
		}
		$voteCommand                    = new VoteCommand($voteIndex, $voteName, $idBased, $neededRatio);
		$voteCommand->startText         = $startText;
		$this->voteCommands[$voteIndex] = $voteCommand;
	}

	/**
	 * Undefines a Vote
	 *
	 * @param $voteIndex
	 */
	public function undefineVote($voteIndex) {
		unset($this->voteCommands[$voteIndex]);
	}


	/**
	 * Starts a vote
	 *
	 * @param \ManiaControl\Players\Player $player
	 * @param                              $voteIndex
	 * @param                              $action
	 */
	public function startVote(Player $player, $voteIndex, $function = null) {
		//Player is muted
		if ($this->maniaControl->playerManager->playerActions->isPlayerMuted($player)) {
			$this->maniaControl->chat->sendError('Muted Players are not allowed to start a vote.', $player->login);
			return;
		}

		//Specators are not allowed to start a vote
		if ($player->isSpectator && !$this->maniaControl->settingManager->getSetting($this, self::SETTING_SPECTATOR_ALLOW_START_VOTE)) {
			$this->maniaControl->chat->sendError('Spectators are not allowed to start a vote.', $player->login);
			return;
		}

		//Vote does not exist
		if (!isset($this->voteCommands[$voteIndex])) {
			$this->maniaControl->chat->sendError('Undefined vote.', $player->login);
			return;
		}

		//A vote is currently running
		if (isset($this->currentVote)) {
			$this->maniaControl->chat->sendError('There is currently another vote running.', $player->login);
			return;
		}

		$maxTime = $this->maniaControl->settingManager->getSetting($this, self::SETTING_VOTE_TIME);

		$this->currentVote = $this->voteCommands[$voteIndex];

		$this->currentVote                    = new CurrentVote($this->voteCommands[$voteIndex], $player, time() + $maxTime);
		$this->currentVote->neededRatio       = floatval($this->maniaControl->settingManager->getSetting($this, self::SETTING_DEFAULT_RATIO));
		$this->currentVote->neededPlayerRatio = floatval($this->maniaControl->settingManager->getSetting($this, self::SETTING_DEFAULT_PLAYER_RATIO));
		$this->currentVote->function          = $function;

		if ($this->currentVote->voteCommand->startText != '') {
			$message = $this->currentVote->voteCommand->startText;
		} else {
			$message = '$fff$<' . $player->nickname . '$>$s$f8f started a $fff$<' . $this->currentVote->voteCommand->name . '$>$f8f!';
		}

		$this->maniaControl->chat->sendSuccess($message);
	}

	/**
	 * Handles a Positive Vote
	 *
	 * @param array  $callback
	 * @param Player $player
	 */
	public function handlePositiveVote(array $callback, Player $player) {
		if (!isset($this->currentVote) || $player->isSpectator && !$this->maniaControl->settingManager->getSetting($this, self::SETTING_SPECTATOR_ALLOW_VOTE)) {
			return;
		}

		$this->currentVote->votePositive($player->login);
	}

	/**
	 * Handles a negative Vote
	 *
	 * @param array  $callback
	 * @param Player $player
	 */
	public function handleNegativeVote(array $callback, Player $player) {
		if (!isset($this->currentVote) || $player->isSpectator && !$this->maniaControl->settingManager->getSetting($this, self::SETTING_SPECTATOR_ALLOW_VOTE)) {
			return;
		}

		$this->currentVote->voteNegative($player->login);
	}

	/**
	 * Handle ManiaControl 1 Second callback
	 *
	 * @param $time
	 */
	public function handle1Second($time) {
		if (!isset($this->currentVote)) {
			return;
		}

		$votePercentage = $this->currentVote->positiveVotes / $this->currentVote->getVoteCount();

		$timeUntilExpire = $this->currentVote->expireTime - time();
		$this->showVoteWidget($timeUntilExpire, $votePercentage);

		$playerCount      = $this->maniaControl->playerManager->getPlayerCount();
		$playersVoteRatio = (100 / $playerCount * $this->currentVote->getVoteCount()) / 100;

		//Check if vote is over
		if ($timeUntilExpire <= 0 || (($playersVoteRatio >= $this->currentVote->neededPlayerRatio) && (($votePercentage >= $this->currentVote->neededRatio) || ($votePercentage <= 1 - $this->currentVote->neededRatio)))) {
			// Trigger callback
			$this->maniaControl->callbackManager->triggerCallback(self::CB_CUSTOM_VOTE_FINISHED, $this->currentVote->voteCommand->index, $votePercentage);

			//reset vote
			$this->destroyVote();
		}
	}

	/**
	 * Destroys the current Vote
	 */
	private function destroyVote() {
		$emptyManialink = new ManiaLink(self::MLID_WIDGET);
		$this->maniaControl->manialinkManager->sendManialink($emptyManialink);

		unset($this->currentVote);
	}

	/**
	 * Shows the vote widget
	 *
	 * @param $timeUntilExpire
	 * @param $votePercentage
	 */
	private function showVoteWidget($timeUntilExpire, $votePercentage) {
		$pos_x   = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_POSX);
		$pos_y   = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_POSY);
		$width   = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_WIDTH);
		$height  = $this->maniaControl->settingManager->getSetting($this, self::SETTING_WIDGET_HEIGHT);
		$maxTime = $this->maniaControl->settingManager->getSetting($this, self::SETTING_VOTE_TIME);

		$quadStyle    = $this->maniaControl->manialinkManager->styleManager->getDefaultQuadStyle();
		$quadSubstyle = $this->maniaControl->manialinkManager->styleManager->getDefaultQuadSubstyle();
		$labelStyle   = $this->maniaControl->manialinkManager->styleManager->getDefaultLabelStyle();

		$maniaLink = new ManiaLink(self::MLID_WIDGET);

		// mainframe
		$frame = new Frame();
		$maniaLink->add($frame);
		$frame->setSize($width, $height);
		$frame->setPosition($pos_x, $pos_y, 30);

		// Background Quad
		$backgroundQuad = new Quad();
		$frame->add($backgroundQuad);
		$backgroundQuad->setSize($width, $height);
		$backgroundQuad->setStyles($quadStyle, $quadSubstyle);

		//Vote for label
		$label = new Label_Text();
		$frame->add($label);
		$label->setY($height / 2 - 3);
		$label->setAlign(Control::CENTER, Control::CENTER);
		$label->setSize($width - 5, $height);
		$label->setTextSize(1.3);
		$label->setText('$s ' . $this->currentVote->voteCommand->name);

		//Started by nick
		$label = new Label_Text();
		$frame->add($label);
		$label->setY($height / 2 - 6);
		$label->setAlign(Control::CENTER, Control::CENTER);
		$label->setSize($width - 5, 2);
		$label->setTextSize(1);
		$label->setTextColor("F80");
		$label->setText('$sStarted by ' . $this->currentVote->voter->nickname);

		//Time Gauge
		$timeGauge = new Gauge();
		$frame->add($timeGauge);
		$timeGauge->setY(1.5);
		$timeGauge->setSize($width * 0.95, 6);
		$timeGauge->setDrawBg(false);
		if (!$timeUntilExpire) $timeUntilExpire = 1;
		$timeGaugeRatio = (100 / $maxTime * $timeUntilExpire) / 100;
		$timeGauge->setRatio($timeGaugeRatio + 0.15 - $timeGaugeRatio * 0.15);
		$gaugeColor = ColorUtil::floatToStatusColor($timeGaugeRatio);
		$timeGauge->setColor($gaugeColor . '9');

		//Time Left
		$label = new Label_Text();
		$frame->add($label);
		$label->setY(0);
		$label->setAlign(Control::CENTER, Control::CENTER);
		$label->setSize($width - 5, $height);
		$label->setTextSize(1.1);
		$label->setText('$sTime left: ' . $timeUntilExpire . "s");
		$label->setTextColor("FFF");

		//Vote Gauge
		$voteGauge = new Gauge();
		$frame->add($voteGauge);
		$voteGauge->setY(-4);
		$voteGauge->setSize($width * 0.65, 12);
		$voteGauge->setDrawBg(false);
		$voteGauge->setRatio($votePercentage + 0.10 - $votePercentage * 0.10);
		$gaugeColor = ColorUtil::floatToStatusColor($votePercentage);
		$voteGauge->setColor($gaugeColor . '6');

		$y         = -4.4;
		$voteLabel = new Label();
		$frame->add($voteLabel);
		$voteLabel->setY($y);
		$voteLabel->setSize($width * 0.65, 12);
		$voteLabel->setStyle($labelStyle);
		$voteLabel->setTextSize(1);
		$voteLabel->setText('  ' . round($votePercentage * 100.) . '% (' . $this->currentVote->getVoteCount() . ')');


		$positiveQuad = new Quad_BgsPlayerCard();
		$frame->add($positiveQuad);
		$positiveQuad->setPosition(-$width / 2 + 6, $y);
		$positiveQuad->setSubStyle($positiveQuad::SUBSTYLE_BgPlayerCardBig);
		$positiveQuad->setSize(5, 5);

		$positiveLabel = new Label_Button();
		$frame->add($positiveLabel);
		$positiveLabel->setPosition(-$width / 2 + 6, $y);
		$positiveLabel->setStyle($labelStyle);
		$positiveLabel->setTextSize(1);
		$positiveLabel->setSize(3, 3);
		$positiveLabel->setTextColor("0F0");
		$positiveLabel->setText("F1");

		$negativeQuad = clone $positiveQuad;
		$frame->add($negativeQuad);
		$negativeQuad->setX($width / 2 - 6);

		$negativeLabel = clone $positiveLabel;
		$frame->add($negativeLabel);
		$negativeLabel->setX($width / 2 - 6);
		$negativeLabel->setTextColor("F00");
		$negativeLabel->setText("F2");

		// Voting Actions
		$positiveQuad->addActionTriggerFeature(self::ACTION_POSITIVE_VOTE);
		$negativeQuad->addActionTriggerFeature(self::ACTION_NEGATIVE_VOTE);

		$script = $maniaLink->getScript();
		$keyActionPositive = new KeyAction(self::ACTION_POSITIVE_VOTE, 'F1');
		$script->addFeature($keyActionPositive);
		$keyActionNegative = new KeyAction(self::ACTION_NEGATIVE_VOTE, 'F2');
		$script->addFeature($keyActionNegative);

		// Send manialink
		$this->maniaControl->manialinkManager->sendManialink($maniaLink);
	}

	/**
	 * Shows the Icon Widget
	 *
	 * @param bool $login
	 */
	private function showIcon($login = false) {
		$posX              = $this->maniaControl->settingManager->getSetting($this, self::SETTING_VOTE_ICON_POSX);
		$posY              = $this->maniaControl->settingManager->getSetting($this, self::SETTING_VOTE_ICON_POSY);
		$width             = $this->maniaControl->settingManager->getSetting($this, self::SETTING_VOTE_ICON_WIDTH);
		$height            = $this->maniaControl->settingManager->getSetting($this, self::SETTING_VOTE_ICON_HEIGHT);
		$shootManiaOffset  = $this->maniaControl->manialinkManager->styleManager->getDefaultIconOffsetSM();
		$quadStyle         = $this->maniaControl->manialinkManager->styleManager->getDefaultQuadStyle();
		$quadSubstyle      = $this->maniaControl->manialinkManager->styleManager->getDefaultQuadSubstyle();
		$itemMarginFactorX = 1.3;
		$itemMarginFactorY = 1.2;

		//If game is shootmania lower the icons position by 20
		if($this->maniaControl->mapManager->getCurrentMap()->getGame() == 'sm') {
			$posY -= $shootManiaOffset;
		}

		$itemSize = $width;

		$maniaLink = new ManiaLink(self::MLID_ICON);

		//Custom Vote Menu Iconsframe
		$frame = new Frame();
		$maniaLink->add($frame);
		$frame->setPosition($posX, $posY);

		$backgroundQuad = new Quad();
		$frame->add($backgroundQuad);
		$backgroundQuad->setSize($width * $itemMarginFactorX, $height * $itemMarginFactorY);
		$backgroundQuad->setStyles($quadStyle, $quadSubstyle);

		$iconFrame = new Frame();
		$frame->add($iconFrame);

		$iconFrame->setSize($itemSize, $itemSize);
		$itemQuad = new Quad_UIConstruction_Buttons();
		$itemQuad->setSubStyle($itemQuad::SUBSTYLE_Add);
		$itemQuad->setSize($itemSize, $itemSize);
		$iconFrame->add($itemQuad);

		//Define Description Label
		$menuEntries      = count($this->voteMenuItems);
		$descriptionFrame = new Frame();
		$maniaLink->add($descriptionFrame);
		$descriptionFrame->setPosition($posX - $menuEntries * $itemSize * 1.15 - 6, $posY);

		$descriptionLabel = new Label();
		$descriptionFrame->add($descriptionLabel);
		$descriptionLabel->setAlign(Control::RIGHT, Control::TOP);
		$descriptionLabel->setSize(40, 4);
		$descriptionLabel->setTextSize(1.4);
		$descriptionLabel->setTextColor('fff');

		//Popout Frame
		$popoutFrame = new Frame();
		$maniaLink->add($popoutFrame);
		$popoutFrame->setPosition($posX - $itemSize * 0.5, $posY);
		$popoutFrame->setHAlign(Control::RIGHT);
		$popoutFrame->setSize(4 * $itemSize * $itemMarginFactorX, $itemSize * $itemMarginFactorY);
		$popoutFrame->setVisible(false);

		$backgroundQuad = new Quad();
		$popoutFrame->add($backgroundQuad);
		$backgroundQuad->setHAlign(Control::RIGHT);
		$backgroundQuad->setStyles($quadStyle, $quadSubstyle);
		$backgroundQuad->setSize($menuEntries * $itemSize * 1.15 + 2, $itemSize * $itemMarginFactorY);

        $itemQuad->addToggleFeature($popoutFrame);

		// Add items
		$x = -1;
		foreach($this->voteMenuItems as $menuItems) {
			foreach($menuItems as $menuItem) {
				$menuQuad = $menuItem[0];
				/**
				 * @var Quad $menuQuad
				 */
				$popoutFrame->add($menuQuad);
				$menuQuad->setSize($itemSize, $itemSize);
				$menuQuad->setX($x);
				$menuQuad->setHAlign(Control::RIGHT);
				$x -= $itemSize * 1.05;

				if ($menuItem[1]) {
						$menuQuad->removeScriptFeatures();
					$description = '$s' . $menuItem[1];
                    $menuQuad->addTooltipLabelFeature($descriptionLabel, $description);
				}
			}
		}


		// Send manialink
		$this->maniaControl->manialinkManager->sendManialink($maniaLink, $login);
	}


	/**
	 * Get plugin id
	 *
	 * @return int
	 */
	public static function getId() {
		return self::PLUGIN_ID;
	}

	/**
	 * Get Plugin Name
	 *
	 * @return string
	 */
	public static function getName() {
		return self::PLUGIN_NAME;
	}

	/**
	 * Get Plugin Version
	 *
	 * @return float,,
	 */
	public static function getVersion() {
		return self::PLUGIN_VERSION;
	}

	/**
	 * Get Plugin Author
	 *
	 * @return string
	 */
	public static function getAuthor() {
		return self::PLUGIN_AUTHOR;
	}

	/**
	 * Get Plugin Description
	 *
	 * @return string
	 */
	public static function getDescription() {
		return 'Plugin offers your Custom Votes like Restart, Skip, Balance...';
	}
}

/**
 * Vote Command Structure
 */
class VoteCommand {
	public $index = '';
	public $name = '';
	public $neededRatio = 0;
	public $idBased = false;
	public $startText = '';

	public function __construct($index, $name, $idBased, $neededRatio) {
		$this->index       = $index;
		$this->name        = $name;
		$this->idBased     = $idBased;
		$this->neededRatio = $neededRatio;
	}
}

/**
 * Current Vote Structure
 */
class CurrentVote {
	const VOTE_FOR_ACTION     = '1';
	const VOTE_AGAINST_ACTION = '-1';

	public $voteCommand = null;
	public $expireTime = 0;
	public $positiveVotes = 0;
	public $neededRatio = 0;
	public $neededPlayerRatio = 0;
	public $voter = null;
	public $map = null;
	public $player = null;
	public $function = null;

	private $playersVoted = array();

	public function __construct(VoteCommand $voteCommand, Player $voter, $expireTime) {
		$this->expireTime  = $expireTime;
		$this->voteCommand = $voteCommand;
		$this->voter       = $voter;
		$this->votePositive($voter->login);
	}

	public function votePositive($login) {
		if (isset($this->playersVoted[$login])) {
			if ($this->playersVoted[$login] == self::VOTE_AGAINST_ACTION) {
				$this->playersVoted[$login] = self::VOTE_FOR_ACTION;
				$this->positiveVotes++;
			}
		} else {
			$this->playersVoted[$login] = self::VOTE_FOR_ACTION;
			$this->positiveVotes++;
		}
	}

	public function voteNegative($login) {
		if (isset($this->playersVoted[$login])) {
			if ($this->playersVoted[$login] == self::VOTE_FOR_ACTION) {
				$this->playersVoted[$login] = self::VOTE_AGAINST_ACTION;
				$this->positiveVotes--;
			}
		} else {
			$this->playersVoted[$login] = self::VOTE_AGAINST_ACTION;
		}
	}

	public function getVoteCount() {
		return count($this->playersVoted);
	}

}
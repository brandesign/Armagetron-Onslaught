<?php

/**
 * This parser is based on the original script by ed.
 * http://plantpeanuts.co.uk/files/onslaught/onslaught_parser_0.0.4.php.txt
 */

namespace Parser;

use Armagetron\Event\Event;
use Armagetron\GameObject\Player;
use Armagetron\GameObject\Team;
use Armagetron\LadderLog\LadderLog;
use Armagetron\Parser\ParserInterface;
use Armagetron\Server\Command;

class Onslaught implements ParserInterface
{
    const ROLE_DEFENSE  = 'defense';
    const ROLE_ATTACK   = 'attack';

    protected $spawn_status = false;
    protected $conquered    = false;

    /* @var Team $team_defense */
    protected $team_defense = null;
    /* @var Team $team_attack */
    protected $team_attack  = null;

    protected $round_time   = 180;
    protected $bonus_time   = 60;
    protected $bonus_score  = 5;

    /**
     * @param int $bonus_score
     */
    public function setBonusScore( $bonus_score )
    {
        $this->bonus_score = $bonus_score;

        return $this;
    }

    /**
     * @param int $bonus_time
     */
    public function setBonusTime( $bonus_time )
    {
        $this->bonus_time = $bonus_time;

        return $this;
    }

    /**
     * @param int $round_time
     */
    public function setRoundTime( $round_time )
    {
        $this->round_time = $round_time;

        return $this;
    }

    public function nextRound(Event $event)
    {
        $this->spawn_status = $this->conquered = false;
    }

    public function spawnPositionTeam(Event $event)
    {
        /* @var Team $team */
        $team   = $event->team;
        $pos    = $event->position;

        if( $pos == 0 )
        {
            $team->setProperty('role', self::ROLE_DEFENSE);
            $this->team_defense = $team;
        }
        else
        {
            $team->setProperty('role', self::ROLE_ATTACK);
            $this->team_attack = $team;
        }
    }

    public function teamCreated(Event $event)
    {
        /* @var Team $team */
        $team = $event->team;

        switch( $team->getId() )
        {
            case 'team_blue':
                $team->setProperty('color', '0x4488ff');
                $team->name = 'Team Blue';
                break;
            case 'team_gold':
                $team->setProperty('color', '0xffff44');
                $team->name = 'Team Gold';
                break;
        }
    }

    /**
     * PLAYER_GRIDPOS <player> <xpos> <ypos> <xdir> <ydir> <team>
     */
    public function gridPos(Event $event)
    {
        if( LadderLog::getInstance()->getGameTime() > 0 )
        {
            return;
        }

        $event->player->setProperty('role', $event->team->getProperty('role'));
    }

    public function gameTime(Event $event)
    {
        if( ! $this->spawn_status )
        {
            /**
             * spawn zones as early as possible
             * zones could easily be included in the map, but when spawned from script they are spawned earlier
             * let helps let people know their role quicker and plan their actions
             */

            Command::raw("SPAWN_ZONE fortress no_team 250 50 40 0 0 0 false");
            Command::raw("SPAWN_ZONE fortress no_team 250 600 1 0 0 0 false");

            $this->spawn_status = true;

            $this->attackDefendMessage();
        }

        $this->timeMessage();
        $this->handleBonus();

        if( $this->getTimeRemaining() <= 0 && ! $this->conquered )
        {
            // if the attacking team didn't conquer the zone within round_time destroy them - losers!

            /* @var Player $player */
            foreach( $this->team_attack->getPlayers() as $player )
            {
                $player->kill();
            }
        }
    }

    public function baseZoneConquered(Event $event)
    {
        $this->conquered = true;
    }

    protected function getTimeRemaining()
    {
        return $this->round_time - LadderLog::getInstance()->getGameTime();
    }

    protected function timeMessage()
    {
        if( $this->conquered )
        {
            return;
        }

        $remaining = $this->getTimeRemaining();

        if( $remaining % 60 == 0 )
        {
            $time = $remaining / 60;
            $unit = 'minutes';
        }
        else
        {
            $time = $remaining;
            $unit = 'seconds';
        }

        if( $remaining % 60 == 0 || $remaining == 30 || $remaining == 10 || $remaining == 5 )
        {
            Command::consoleMessage(sprintf("%d %s remaining.", $time, $unit));
        }
    }

    protected function attackDefendMessage()
    {
        $defense_color  = $this->team_defense->getProperty('color');
        $defense_name   = $this->team_defense->name;
        $attack_color   = $this->team_attack->getProperty('color');
        $attack_name    = $this->team_attack->name;

        Command::consoleMessage(sprintf("%s%s", $defense_color, str_repeat('*', 40)));
        Command::consoleMessage(sprintf("%s%s%s Defend! %s", $defense_color, str_repeat('*', 10), $defense_name, str_repeat('*', 10)));
        Command::consoleMessage(sprintf("%s%s", $attack_color, str_repeat('*', 40)));
        Command::consoleMessage(sprintf("%s%s%s Defend! %s", $attack_color, str_repeat('*', 10), $attack_name, str_repeat('*', 10)));
    }

    protected function handleBonus()
    {
        if( ! $this->conquered && $this->getTimeRemaining() % $this->bonus_time == 0 && LadderLog::getInstance()->getGameTime() >= $this->bonus_time )
        {
            $this->team_defense->addScore($this->bonus_score);
            Command::consoleMessage(sprintf("%d bonus points awarded to %s", $this->bonus_score, $this->team_defense->getId()));
        }
    }
}
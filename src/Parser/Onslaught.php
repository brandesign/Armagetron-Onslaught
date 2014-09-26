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
    protected $num_respawns = 0;
    protected $spawn_radius = 50;
    protected $die_messages = array();

    public function __construct()
    {
        $this->team_defense = new Team('team_blue');
        $this->team_defense->setProperty('color', '0x4488ff');
        $this->team_defense->name = 'Team Blue';

        $this->team_attack = new Team('team_gold');
        $this->team_attack->setProperty('color', '0xffff44');
        $this->team_attack->name = 'Team Gold';

        LadderLog::getInstance()->getGameObjects()->add($this->team_defense)->add($this->team_attack);
    }

    /**
     * @param int $bonus_score
     * @return $this
     */
    public function setBonusScore( $bonus_score )
    {
        $this->bonus_score = $bonus_score;

        return $this;
    }

    /**
     * @param int $bonus_time
     * @return $this
     */
    public function setBonusTime( $bonus_time )
    {
        $this->bonus_time = $bonus_time;

        return $this;
    }

    /**
     * @param int $round_time
     * @return $this
     */
    public function setRoundTime( $round_time )
    {
        $this->round_time = $round_time;

        return $this;
    }

    /**
     * @param int $num_respawns
     * @return $this
     */
    public function setNumRespawns( $num_respawns )
    {
        $this->num_respawns = $num_respawns;

        return $this;
    }

    /**
     * @param int $spawn_radius
     * @return $this
     */
    public function setSpawnRadius( $spawn_radius )
    {
        $this->spawn_radius = $spawn_radius;

        return $this;
    }

    /**
     * @param array $messages
     * @return $this
     */
    public function setDieMessages(array $messages)
    {
        $this->die_messages = $messages;

        return $this;
    }

    public function nextRound(Event $event)
    {
        $this->spawn_status = $this->conquered = false;

        /* @var Player $player */
        foreach( $event->getGameObjects()->getPlayers() as $player )
        {
            $player->setProperty('respawns', $this->num_respawns);
        }
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

    public function deathFrag(Event $event)
    {
        $this->handleRespawn($event->prey);
    }

    public function deathSuicide(Event $event)
    {
        $this->handleRespawn($event->player);
    }

    public function deathTeamkill(Event $event)
    {
        $this->handleRespawn($event->prey);
    }

    /**
     * Helper methods
     */

    protected function handleRespawn(Player $player)
    {
        $respawns   = $player->getProperty('respawns');
        $team       = $player->getTeam();

        if( $respawns > 0 && $team )
        {
            $x = 250;
            $y = 450;
            $r = $this->spawn_radius;
            $x = mt_rand($x - $r, $x + $r);
            $y = mt_rand($y - $r, $y + $r);

            $player->respawn($x, $y, 0, -1);
            $player->setProperty('respawns', --$respawns);

            $color = $team->getProperty('color');

            Command::consoleMessage(sprintf("%s%s 0xffffffhas been respawned. 0x00ff00%s 0xffffffrespawns remaining.", $color, $player->getScreenName(), $respawns));
        }
        else
        {
            $this->dieMessage($player);
        }
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
        Command::consoleMessage(sprintf("%s%s %s Defend! %s", $defense_color, str_repeat('*', 10), $defense_name, str_repeat('*', 11)));
        Command::consoleMessage(sprintf("%s%s", $defense_color, str_repeat('*', 40)));
        Command::consoleMessage(sprintf("%s%s", $attack_color, str_repeat('*', 40)));
        Command::consoleMessage(sprintf("%s%s %s Defend! %s", $attack_color, str_repeat('*', 10), $attack_name, str_repeat('*', 11)));
        Command::consoleMessage(sprintf("%s%s", $attack_color, str_repeat('*', 40)));
    }

    protected function dieMessage(Player $player)
    {
        if( empty($this->die_messages) )
        {
            $message = "died";
        }
        else
        {
            $message = $this->die_messages[array_rand($this->die_messages)];
        }

        Command::consoleMessage(sprintf("%s%s 0xffffff%s", $player->getTeam()->getProperty('color'), $player->getScreenName(), $message));
    }

    protected function handleBonus()
    {
        if( ! $this->conquered && $this->getTimeRemaining() % $this->bonus_time == 0 && LadderLog::getInstance()->getGameTime() >= $this->bonus_time )
        {
            $this->team_defense->addScore($this->bonus_score);
            Command::consoleMessage(sprintf("%d bonus points awarded to %s%s", $this->bonus_score, $this->team_defense->getProperty('color'), $this->team_defense->name));
        }
    }
}

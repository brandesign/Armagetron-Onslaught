#!/usr/bin/php
<?php

require "vendor/autoload.php";

$onslaught = new \Parser\Onslaught();

$onslaught->setRoundTime(180)->setBonusTime(60)->setBonusScore(5);

$parser = new Armagetron\Parser\StyCt($onslaught);

$parser->run();


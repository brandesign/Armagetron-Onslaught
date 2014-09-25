#!/usr/bin/php
<?php

require "vendor/autoload.php";

$parser = new Armagetron\Parser\StyCt(new \Parser\Onslaught());

$parser->run();


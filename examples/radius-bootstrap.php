<?php

/**
 * Generates a RADIUS bootstrap .rsc script — needs NO router or network
 * connection at all, it's pure string generation. Paste it into a fresh
 * router's WinBox terminal (or `/import` it), and the router starts
 * consulting RADIUS for PPP authentication.
 *
 *   php examples/radius-bootstrap.php
 */

require __DIR__ . '/../vendor/autoload.php';

use RouterOS\Sdk\Isp\RadiusBootstrapScript;

$script = RadiusBootstrapScript::generate(
    radiusAddress: '192.168.88.10',
    secret: 'super-secret',
);

echo $script;

<?php
/**
 * 
 */

// There is a composer package for libphutil, but it's unofficial and not 100% compatible:
// https://packagist.org/packages/mtrunkat/libphutil
require_once 'vendor/autoload.php';
require_once '/usr/share/libphutil/src/__phutil_library_init__.php';

use Ttf\Remaim\Wizard;

use Redmine\Client;

use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;

try {
    $yaml = new Parser();
    $config = $yaml->parse(file_get_contents('remaim.yml'));

} catch (ParseException $e) {
    printf("Unable to parse the YAML string: %s", $e->getMessage());
}

$redmine = new Client(
    $config['redmine']['host'],
    $config['redmine']['user'],
    $config['redmine']['password']
);

$conduit = new \ConduitClient($config['phabricator']['host']);
$conduit->setConduitToken($config['phabricator']['token']);

$wizard = new Wizard($config, $redmine, $conduit);
$wizard->run();
<?php

// WRITE TESTS!!!!!

namespace Remaim;

use Redmine\Client;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;

// There is a composer package for libphutil, but it's unofficial and not 100% compatible:
// https://packagist.org/packages/mtrunkat/libphutil
require_once '/usr/share/libphutil/src/__phutil_library_init__.php';
require_once 'vendor/autoload.php';

/**
 * redmine:
    host:
    user:
    password:
phabricator:
    host:
    token:
 */
try {
    $yaml = new Parser();
    $config = $yaml->parse(file_get_contents('remaim.yml'));
} catch (ParseException $e) {
    printf("Unable to parse the YAML string: %s", $e->getMessage());
}

function representProject($project)
{
    if (isset($project['parent'])) {
        return sprintf("\t%s", representProject($project))
    } else {
        return sprintf("[%d]\t[%s]\n", $project['id'], $project['name']);
    }
}

function addToProject($container, $project)
{
    return array_map(function ($slot, $key) use ($project) {
        if ($key == $project['parent']['id']) {
            $slot[] = $project;
        } else {
            return addToProject()
        }
        return $slot;
    }, $container, array_keys($container));
}

// OK, the API treats APIKeys as usernames,
// Client::prepareRequest() looks at isset(Password) and replaces it by a random string in the opposite case
// It always sets CURLOPT_USERPWD though… Well, this is kind of a a limiation of our redmine setup too
//
// Read URL, token/password from config file?
$redmine = new Client(
    $config['redmine']['host'],
    $config['redmine']['user'],
    $config['redmine']['password']
);
// $redmine = new Client('https://redmine.1024.lu', '4ff32c96a52dfe3c850b4cd22be33cfcce02cb54');

// First list available projects, then allow the user to select one
$reply = $redmine->project->all(['limit' => 1024]);
printf('%d total projects retrieved from your redmine instance.', $reply['total_count'][0]);
$projects = $reply['projects'];

$projects = array_reduce($projects, function ($container, $project) {
    if (isset($project['parent'])) {
        $container = addToParent($container, $project);
    } else {
        $container[$project['id']] = $project;
    }
    return $container;
}, []);

// use ($sortkey) from $argv to allow to sort by name or by id?
usort($projects, function ($a, $b) {
    return $a['id'] > $b['id'];
});
foreach ($projects as $project) {
    print(representProject($project));
}
print('Select a project: [0]' . "\n");
$fp = fopen('php://stdin', 'r');
$project = trim(fgets($fp, 1024));

// Grab issues for the selected project
$tasks = $redmine->issue->all([
    'project_id' => $project,
    'limit' => 1024
]);    // 94 == VM Test Software
if (!$tasks || empty($tasks['issues'])) {
    printf('No tasks found on project %s', $project);
}
$issues = $tasks['issues'];

// Well, this will probably have to go into the yml file?
$priority_map = [
    'Immediate' => 100, // unbreak now!
    'Urgent' => 100,    // unbreak now!
    'High' => 80,       // High
    'Normal' => 50,     // Normal
    'Low' => 25         // Low
                        // Wishlist
];

$conduit = new ConduitClient($config['phabricator']['host']);
$conduit->setConduitToken($config['ṕhabricator']['token']);

$results = array_map(function ($issue) use ($conduit, $redmine) {
    $details = $redmine->issue->show(
        $issue['id'],
        [
            'include' => [
                'journals',
                'attachments'   // AFAIK no support for relations or children in phabricator
            ]
        ]
    );
    var_dump($details); exit;

    $description = str_replace("\r", '', $data[20]);
    $api_parameters = array(
        'title' => $data[6],
        'description' => $description,
        'priority' => $priority_map[$data[5]],
        'projectPHIDs' => array(
            'PHID-PROJ-a6hzhivybh7qpyq47yiv',
        ),
    );
    $task = $conduit->callMethodSynchronous('maniphest.createtask', $api_parameters);
    $api_parameters = array(
        'comments' => 'test comment',
    );

    $result = $conduit->callMethodSynchronous('maniphest.update', $api_parameters);
});

// Make this nicer obviously ;)
print_r($results);

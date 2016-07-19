<?php

// WRITE TESTS!!!!!

namespace Remaim;

use Redmine\Client;
use Redmine\Api\Issue;
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
        return sprintf("\t%s", representProject($project));
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
            //return addToProject($container, ...)  should have finished this …
        }
        return $slot;
    }, $container, array_keys($container));
}

// OK, the API treats APIKeys as usernames,
// Client::prepareRequest() looks at isset(Password) and replaces it by a random string in the opposite case
// $redmine = new Client('https://redmine.1024.lu', '4ff32c96a52dfe3c850b4cd22be33cfcce02cb54');
// It always sets CURLOPT_USERPWD though… 
// Well, this is kind of a a limiation of our redmine setup too because of basic Auth based on ldap!
//
// Read URL, token/password from config file?
$redmine = new Client(
    $config['redmine']['host'],
    $config['redmine']['user'],
    $config['redmine']['password']
);

$conduit = new \ConduitClient($config['phabricator']['host']);
$conduit->setConduitToken($config['phabricator']['token']);

// DR: can we find another, simpler method for checking connection than this?
// Unfortunately, the Client does not have a way of checking whether the connection was successfull,
// since it never established a connection.
$project_listing = $redmine->project->listing();
if (empty($project_listing)) {
    die("\n" . 'Your project list is empty or we were unable to connect to redmine. Check your credentials!' . "\n");
}

// First list available projects, then allow the user to select one
$reply = $redmine->project->all(['limit' => 1024]);
printf('%d total projects retrieved from your redmine instance.', $reply['total_count'][0]);
$projects = $reply['projects'];

$projects = array_reduce($projects, function ($container, $project) {
    if (isset($project['parent'])) {
        $container = addToProject($container, $project);
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
fclose($fp);

/////
// $detail = $redmine->project->show($project);
// var_dump($detail); exit;

$tasks = $redmine->issue->all([
    'project_id' => $project,
    'limit' => 1024
]);    // 94 == VM Test Software
if (!$tasks || empty($tasks['issues'])) {
    printf('No tasks found on project %s', $project); exit;
}
$issues = $tasks['issues'];

// $project_issuepriorities = $redmine->issue_priority->all([
//     'limit' => 1024,
// ]);
// var_dump($project_issuepriorities); 

// if ($project_issuepriorities == Issue::PRIO_IMMEDIATE || $project_issuepriorities == Issue::PRIO_URGENT) {
//     printf('Priority: Immediate/Unbreak now');
// } else {
//     if ($project_issuepriorities == Issue::PRIO_HIGH) {
//         printf('Priority: High');
//     } else {
//         if ($project_issuepriorities == Issue::PRIO_NORMAL){
//           printf('Priority: Normal');
//           } else {
//               if ($project_issuepriorities == Issue::PRIO_LOW)
//               {printf('Priority: Low');}
//            }

//      }
// }

print('Enter the id/slug of the project, press enter to see projects or enter [0] to create a new project in phabricator' . "\n");
$fp = fopen('php://stdin', 'r');
$phab_project = trim(fgets($fp, 1024));
fclose($fp);

if ('0' === $phab_project) {
    $detail = $redmine->project->show($project);
    var_dump($detail);
    //  here be redmine to phabricator conversion magic
    // $api_parameters = $detail;
    $result = $conduit->callMethodSynchronous('project.create', $api_parameters);

} elseif ('' === $phab_project) {

} else {
    if (is_numeric($phab_project)) {
        $api_parameters = [
            'ids' => [$phab_project],
        ];
        $result = $conduit->callMethodSynchronous('project.query', $api_parameters);
        $found = array_pop($result['data']);
        // print_r($found['phid']);
    } else {
        $api_parameters = [
             'slugs' => [$phab_project],
        ];
        $result = $conduit->callMethodSynchronous('project.query', $api_parameters);
        print_r($result);
    }
}

exit;

// $project_issuerelation = $redmine->issuerelation->show($relation);
// var_dump($project_issuerelation); 

// $project_issuestatus = $redmine->issue_status->all([
//     'project_id' => $project,
//     'limit' => 1024
// ]); 
// var_dump($project_issuestatus); 

/////

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

/**
 * Once we have a list of all issues on the selected project from redmine,
 * we will loop through them using array_map and add each issue to the 
 * new project on phabricator
 */
$results = array_map(function ($issue) use ($conduit, $redmine) {
    $details = $redmine->issue->show(
        $issue['id'],
        [
            'include' => [
                'children',
                'attachments',
                'relations',
                'watchers',
                'journals',
            ]
        ]
    );

    // $relations = $redmine->issue_relation->all(
    //     $issue['id'], 
    //     [
    //         'limit' => 1024,
    //     ]
    // );
    // var_dump('relations', $relations);

    var_dump($details); 
    // $project_attachments = $redmine->attachment->show($attachment);
    // var_dump($project_attachments);

    /*$description = str_replace("\r", '', $data[20]);
    $api_parameters = [
        'title' => $data[6],
        'description' => $description,
        'priority' => $priority_map[$data[5]],
        'projectPHIDs' => array(
            'PHID-PROJ-a6hzhivybh7qpyq47yiv',
        ),
    ];
    $task = $conduit->callMethodSynchronous('maniphest.createtask', $api_parameters);

    $api_parameters = array(
        'comments' => 'test comment',
    );

    $result = $conduit->callMethodSynchronous('maniphest.edit', $api_parameters);*/
}, $issues);

// Make this nicer obviously ;)
print_r($results);
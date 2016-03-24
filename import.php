<?php

// There is a composer package for libphutil, but it's unofficial and not 100% compatible:
// https://packagist.org/packages/mtrunkat/libphutil
require_once '/usr/share/libphutil/src/__phutil_library_init__.php';
require_once 'vendor/autoload.php';

// OK, the API treats APIKeys as usernames,
// Client::prepareRequest() looks at isset(Password) and replaces it by a random string in the opposite case
// It always sets CURLOPT_USERPWD though… Well, this is kind of a a limiation of our redmine setup too
$redmine = new Redmine\Client('https://redmine.1024.lu', 'kwisatz', 'aSh0rterPasswordTT');
// $redmine = new Redmine\Client('https://redmine.1024.lu', '4ff32c96a52dfe3c850b4cd22be33cfcce02cb54');

// $projects = $redmine->project->all(['limit' => 100]);
// var_dump($projects);

// var_dump($argv);
// Parse arguments here…

$tasks = $redmine->issue->all(['project_id' => 93]);    // VM
var_dump($tasks);

exit;


// phabrictor
$api_token = "api-2tjdrldcrad5zv233ipsbjdvsctc";

$results = [];

// array(21) {
//   [0]=>
//   string(1) "#"
//   [1]=>
//   string(7) "Project"
//   [2]=>
//   string(7) "Tracker"
//   [3]=>
//   string(11) "Parent task"
//   [4]=>
//   string(6) "Status"
//   [5]=>
//   string(8) "Priority"
//   [6]=>
//   string(7) "Subject"
//   [7]=>
//   string(6) "Author"
//   [8]=>
//   string(8) "Assignee"
//   [9]=>
//   string(7) "Updated"
//   [10]=>
//   string(8) "Category"
//   [11]=>
//   string(14) "Target version"
//   [12]=>
//   string(10) "Start date"
//   [13]=>
//   string(8) "Due date"
//   [14]=>
//   string(14) "Estimated time"
//   [15]=>
//   string(10) "Spent time"
//   [16]=>
//   string(6) "% Done"
//   [17]=>
//   string(7) "Created"
//   [18]=>
//   string(10) "Resolution"
//   [19]=>
//   string(6) "Billed"
//   [20]=>
//   string(11) "Description"


$priority_map = [
    'Immediate' => 100, // unbreak now!
    'Urgent' => 100,    // unbreak now!
    'High' => 80,
    'Normal' => 50,
    'Low' => 25
];

$client = new ConduitClient('https://cator.1024.lu/');
$client->setConduitToken($api_token);

if (($handle = fopen("export.csv", "r")) !== FALSE) {
    while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
        if ($data[0] === '#') {
            // this is the header, skip this one
            continue;
        }

        $description = str_replace("\r", '', $data[20]);

        $api_parameters = array(
            'title' => $data[6],
            'description' => $description,
            'priority' => $priority_map[$data[5]],
            'projectPHIDs' => array(
                'PHID-PROJ-a6hzhivybh7qpyq47yiv',
            ),
        );

        $results[] = $client->callMethodSynchronous('maniphest.createtask', $api_parameters);
    }
    fclose($handle);
}
print_r($results);

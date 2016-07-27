<?php

$projects = redmine->projects->all();

$level = 0;
$parents = [];
while (!empty($projects)) {
    $num_projects = sizeof($projects);
    for ($i = 0; $i < $num_projects; $i++) {
        $project = $projects[$i];
        if (!isset($project['parent'])) {
            $project['children'] = [];
            $parents[$project['id']] = $project;
            unset($projects[$i]);
        } elseif ($level > 0) {
            if (array_key_exists($project['parent']['id'], $parents)) {
                $parents[$project['parent']['id']]['children'][] = $project;
            }
        }
    }
    $level++;
}

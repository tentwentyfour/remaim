<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.0.2 The day after
 * @since    0.0.1 First public release
 *
 * @author  Jonathan Jin <jonathan@tentwentyfour.lu>
 * @author  David Raison <david@tentwentyfour.lu>
 *
 * (c) TenTwentyFour
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Ttf\Remaim\Traits;

trait Redmine
{
    /**
     * Can we find another, simpler method for checking connection than this?
     * Unfortunately, the Client does not have a way of checking whether the connection was successful,
     * since it never established a connection.
     * @return [type] [description]
     */
    public function assertConnectionToRedmine()
    {
        $project_listing = $this->redmine->project->listing();
        if (empty($project_listing) || !is_array($project_listing)) {
            throw new \RuntimeException(
                'Your project list is empty or we were unable to connect to your Redmine instance.
                Please verify your credentials are correct!'
            );
        }
        return true;
    }

    /**
     * Queries Redmine for a list of all projects on the platform.
     * @todo : find a way to not have the print side-effects!!
     *
     * @return Array A tree structure of all projects on the redmine platform
     */
    public function listRedmineProjects()
    {
        $reply = $this->redmine->project->all(['limit' => 1024]);
        $ids = array_column($reply['projects'], 'id');
        sort($ids);

        $projects = array_map(function ($project) {
            if (!isset($project['parent'])) {
                $project['parent'] = [
                    'id' => 0
                ];
            }
            return $project;
        }, $reply['projects']);

        $projects = $this->buildProjectTree($projects);

        usort($projects, function ($a, $b) {
            return $a['id'] > $b['id'];
        });

        return [
            'total_count' => $reply['total_count'][0],
            'lowest' => array_shift($ids),
            'highest' => array_pop($ids),
            'projects' => $projects,
        ];
    }

    /**
     * Retrieve all Issues for a given Redmine project
     *
     * @param  Integer $project_id The redmine project ID
     *
     * @return array                A list of issues that should be migrated
     */
    public function getIssuesForProject($project_id)
    {
        $tasks = $this->redmine->issue->all([
            'project_id' => $project_id,
            'limit' => 1024
        ]);

        if (!$tasks || empty($tasks['issues'])) {
            throw new \RuntimeException(
                sprintf(
                    "No tasks found on project with id %d.\n There is nothing to do, I'm getting some sleep now.\nYou know where to find me if you ever need me again!\n",
                    $project_id
                )
            );
        }
        return $tasks;
    }

    /**
     * Retrieve a list of all members of a given Redmine project
     *
     * @param  Integer $project_id Redmine project id
     *
     * @return array                A list of all members of Redmine project $project_id
     */
    public function getRedmineProjectMembers($project_id)
    {
        $memberships = $this->redmine->membership->all($project_id);

        return array_filter(
            array_map(function ($relation) {
                return isset($relation['user']) ? $relation['user']['name'] : null;
            }, $memberships['memberships']),
            function ($member) {
                return $member != null;
            }
        );
    }



}

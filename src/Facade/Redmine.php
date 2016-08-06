<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.2.0
 * @since    0.2.0
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

namespace Ttf\Remaim\Facade;

use Redmine\Client;

use Ttf\Remaim\Exception\NoIssuesFoundException;

class Redmine
{
    private $users;
    private $custom_fields;
    private $issue_stati;
    private $priorities;
    private $versions;

    public function __construct(Client $client) {
        $this->redmine = $client;
    }

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
            'lowest' => min($ids),
            'highest' => max($ids),
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
            throw new NoIssuesFoundException(
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


    /**
     * Needs to be finished and needs tests (see Transactions::recountStory)
     * @param  [type] $id [description]
     * @return [type]     [description]
     */
    public function getRedmineUserById($id)
    {
        if (!array_key_exists($id, $this->users)) {
            $this->users[$id] = $this->redmine->user->show($id);
        }
        return $this->users[$id];
    }

    /**
     * Fetch Redmine status name by its ID
     *
     * @return string   Status name
     */
    public function getStatusById($id)
    {
        if (!isset($this->issue_stati) || empty($this->issue_stati)) {
            $this->issue_stati = array_flip(
                $this->redmine->issue_status->listing()
            );
        }
        return array_key_exists($id, $this->issue_stati) ? $this->issue_stati[$id] : null;
    }

    /**
     * Look up a custom field by its Id
     *
     * @param  int $id  Custom field Id
     *
     * @return string   Custom field name
     */
    public function getCustomFieldById($id)
    {
        if (!isset($this->custom_fields) || empty($this->custom_fields)) {
            $this->custom_fields = array_flip(
                $this->redmine->custom_field->listing()
            );
        }
        return array_key_exists($id, $this->custom_fields) ? $this->custom_fields[$id] : null;
    }

    public function getPriorityById($id)
    {
        if (!isset($this->priorities) || empty($this->priorities)) {
            $this->priorities = array_flip(
                $this->redmine->issue_priority->listing()
            );
        }
        return array_key_exists($id, $this->priorities) ? $this->priorities[$id] : null;
    }


    public function getVersionById($project_id, $id)
    {
        if (!isset($this->versions[$project_id]) || empty($this->versions[$project_id])) {
            $this->versions[$project_id] = array_flip(
                $this->redmine->version->listing($project_id)
            );
        }
        var_dump($this->versions); exit;
        // Might have to ->show($id) on a version to get what we need
        return array_key_exists($id, $this->versions[$project_id]) ? $this->versions[$project_id][$id] : null;
    }

    public function getTrackerById($id)
    {
        if (!isset($this->trackers) || empty($this->trackers)) {
            $this->trackers = array_flip(
                $this->redmine->tracker->listing()
            );
        }
        return array_key_exists($id, $this->trackers) ? $this->trackers[$id] : null;
    }

}

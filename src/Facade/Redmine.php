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
    use \Ttf\Remaim\Traits\ProjectList;

    // buffers
    private $custom_fields = [];
    private $issue_stati = [];
    private $issue_categories = [];
    private $priorities = [];
    private $versions = [];
    private $projects = [];
    private $users = [];
    private $issues = [];

    private $limit = 1024;
    private $redmine;

    public function __construct(Client $client, $limit = null)
    {
        $this->redmine = $client;
        $limit !== null && $this->limit = $limit;
    }

    /**
     * Forward any calls we don't know about to the actual
     * redmine client.
     *
     * @param  string $method    The method called
     * @param  array  $arguments Arguments passed to the method
     *
     * @return misc              Return whatever the called method returned
     */
    public function __call($method, $arguments)
    {
        if (method_exists($this->redmine, $method)) {
            return call_user_func_array(
                [$this->redmine, $method],
                $arguments
            );
        }
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
    public function listProjects()
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
     * Show details of a redmine project after having selected it from a list.
     *
     * @param  Integer $project_id Integer ID of the selected project
     *
     * @return Integer          Same ID
     */
    public function show($project_id)
    {
        if ($project_id) {
            $result = $this->getProjectDetails($project_id);
            printf(
                'Found project "%s" on your Redmine instance.' . PHP_EOL . PHP_EOL,
                $result['project']['name']
            );
            return $project_id;
        }
    }

    /**
     * Simply a memory-caching wrapper around the redmine api
     *
     * @param  Integer $project_id  ID of project to look up
     *
     * @return Array            Details about $project_id
     */
    public function getProjectDetails($project_id)
    {
        if (!array_key_exists($project_id, $this->projects)) {
            $this->projects[$project_id] = $this->redmine->project->show($project_id);
        }
        return $this->projects[$project_id];
    }

    /**
     * Get details on an issue, including journal, attachments, children, watchers, etc
     *
     * @return array Details about this issue
     */
    public function getIssueDetail($issue_id)
    {
        return $this->redmine->issue->show(
            $issue_id,
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
    }

    /**
     * Retrieve all Issues for a given Redmine project.
     * We sort them by id, ascending to increase the chance
     * of importing parent tasks first (so we need to loop less often through
     * the task list).
     *
     * @param  Integer $project_id The Redmine project ID
     *
     * @return array                A list of issues that should be migrated
     */
    public function getIssuesForProject($project_id)
    {
        $tasks = $this->redmine->issue->all([
            'project_id' => $project_id,
            'limit' => $this->limit,
            'sort' => 'id:asc',
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
    public function getProjectMembers($project_id)
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
    public function getUserById($id)
    {
        if (!array_key_exists($id, $this->users)) {
            $this->users[$id] = $this->redmine->user->show($id);
        }
        return $this->users[$id];
    }

    /**
     * Fetch Redmine task name by its ID
     *
     * @return string   Task name
     */
    public function getTaskById($id)
    {
        if (!array_key_exists($id, $this->issues)) {
            $this->issues[$id] = $this->redmine->issue->show($id);
        }
        return $this->issues[$id];
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
                $this->redmine->custom_fields->listing()
            );
        }
        return array_key_exists($id, $this->custom_fields) ? $this->custom_fields[$id] : null;
    }

    public function getPriorityById($id)
    {
        if (!isset($this->priorities) || empty($this->priorities)) {
            $priorities = $this->redmine->issue_priority->all();
            foreach ($priorities['issue_priorities'] as $priority) {
                $this->priorities[$priority['id']] = $priority['name'];
            }
        }
        return array_key_exists($id, $this->priorities) ? $this->priorities[$id] : null;
    }


    public function getVersionById($id, $project_id)
    {
        if (!isset($this->versions[$project_id]) || empty($this->versions[$project_id])) {
            $this->versions[$project_id] = array_flip(
                $this->redmine->version->listing($project_id)
            );
        }
        return array_key_exists($id, $this->versions[$project_id]) ? $this->versions[$project_id][$id] : null;
    }

    public function getCategoryById($id, $project_id)
    {
        if (!isset($this->issue_categories[$project_id]) || empty($this->issue_categories[$project_id])) {
            $this->issue_categories[$project_id] = array_flip(
                $this->redmine->issue_category->listing($project_id)
            );
        }
        return array_key_exists($id, $this->issue_categories[$project_id]) ? $this->issue_categories[$project_id][$id] : null;
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

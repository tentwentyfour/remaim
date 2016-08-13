<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 * @version  0.1.1 Short Circuit
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

trait Phabricator
{
    /**
     * Fetch list of stati available in your phabricator instance
     *
     * @return array    List of stati available within your phabricator instance
     */
    public function fetchPhabricatorStati()
    {
        $status_list = $this->conduit->callMethodSynchronous(
            'maniphest.querystatuses',
            []
        );
        return array_flip($status_list['statusMap']);
    }

    /**
     * Retrieves a list of all available (even inactive)
     * projects on your phabricator instance.
     *
     * @return array List of phabricator projects
     */
    public function getAllPhabricatorProjects()
    {
        $result = $this->retrieveAllPhabricatorProjects();
        $ids = array_column($result, 'id');

        if ($result && !empty($result)) {
            $projects = array_reduce($result, function ($carry, $project) {
                $carry[$project['id']] = [
                    'id' => $project['id'],
                    'phid' => $project['phid'],
                    'name' => $project['fields']['name'],
                ];
                return $carry;
            });

            return [
                'total_count' => sizeof($result),
                'lowest' => min($ids),
                'highest' => max($ids),
                'projects' => $projects,
            ];
        }
    }

    public function retrieveAllPhabricatorProjects($after = 0)
    {
        $result = $this->conduit->callMethodSynchronous(
            'project.search',
            [
                'queryKey' => 'all',
                'after' => $after,
            ]
        );
        if ($result['cursor']['after'] != null) {
            $result['data'] = array_merge(
                $result['data'],
                $this->retrieveAllPhabricatorProjects($result['cursor']['after'])
            );
        }
        return $result['data'];
    }

    /**
     * Create a new phabricator project
     *
     * @param  array $detail       Project detail retrieved from Redmine
     * @param  array $phab_members List of Phabricator user PHIDs
     * @param  array $policies     Policies to be applied to the project
     *
     * @return array               Result of conduit call
     */
    public function createNewPhabricatorProject($detail, $phab_members, $policies)
    {
        return $this->conduit->callMethodSynchronous(
            'project.edit',
            [
                'objectIdentifier' => null,
                'transactions' => [
                    [
                        'type' => 'name',
                        'value' => $detail['project']['name'],
                    ],
                    [
                        'type' => 'members.add',
                        'value' => $phab_members,
                    ],
                    [
                        'type' => 'view',
                        'value' => $policies['view'],
                    ],
                    [
                        'type' => 'edit',
                        'value' => $policies['edit'],
                    ],
                    [
                        'type' => 'join',
                        'value' => $policies['view'],
                    ],
                ]
            ]
        );
    }

    /**
     * Looks up projects in phabricator of type "group"
     *
     * @return array Result of lookup
     */
    public function lookupGroupProjects()
    {
        return $this->conduit->callMethodSynchronous(
            'project.search',
            [
                'constraints' => [
                    'icons' => [
                        'group',
                    ],
                ],
            ]
        );
    }

    /**
     * Find an existing phabricator project by either its id or slug
     *
     * @param  array $params API parameters to be passed to conduit
     *
     * @return String        Project PHID
     */
    public function findPhabricatorProject($params)
    {
        $result = $this->conduit->callMethodSynchronous(
            'project.query',
            $params
        );
        if (!empty($result['data'])) {
            $found = array_pop($result['data']);
            if (isset($found['phid'])) {
                return $found;
            }
        }
    }

    /**
     * Caching user lookup.
     * Checks local cache for known users and queries conduit for any
     * not yet known users.
     *
     * @param  ConduitClient $conduit   Conduit client instance
     * @param  array         $fullnames Array of full names to look up
     *
     * @return array         PHIDs for users in $fullnames
     */
    public function getPhabricatorUserPhid(array $fullnames)
    {
        $unknown_users = array_diff($fullnames, array_keys($this->phabricator_users));

        if (!empty($unknown_users)) {
            $api_parameters = [
                'realnames' => $unknown_users,
            ];
            $result = $this->conduit->callMethodSynchronous('user.query', $api_parameters);
            $queried_users = array_reduce($result, function ($carry, $user) {
                $carry[$user['realName']] = $user['phid'];
                return $carry;
            });

            if (!empty($queried_users)) {
                $this->phabricator_users = array_merge(
                    $this->phabricator_users,
                    $queried_users
                );
            }
        }

        return array_values(
            array_intersect_key($this->phabricator_users, array_flip($fullnames))
        );
    }

    /**
     * Calls conduit API with the given identifier (if any)
     * and transactions. Returns the API call result.
     *
     * @param  array  $transactions Array of transactions to be applied
     * @param  array  $task         Existing task or empty array
     * @return array                Results of API call
     */
    public function createOrUpdatePhabTicket($transactions, $task = [])
    {
        $identifier = isset($task['phid']) ? $task['phid'] : null;
        return $this->conduit->callMethodSynchronous(
            'maniphest.edit',
            [
              'objectIdentifier' => $identifier,
              'transactions' => $transactions,
            ]
        );
    }

    /**
     * Finds tasks that have already been imported to phabricator based on their description.
     *
     * @param  array $issue Redmine issue description
     * @return array        [description]
     */
    public function findExistingTask($issue, $project_phid, $resume = false)
    {
        $tasks = [];
        if (!empty($issue['description'])) {
            $issue['description'] = $this->textileToMarkdown($issue['description']);
            $tasks = $this->conduit->callMethodSynchronous(
                'maniphest.query',
                [
                    'projectPHIDs' => [$project_phid],
                    'fullText' => $issue['description'],
                ]
            );
        }

        $num_found = sizeof($tasks);
        if ($resume && !empty($tasks) && $num_found > 1) {
            return false;
        } elseif (!empty($tasks) && $num_found > 1) {
            print(
                'Oops, I found more than one already existing task in phabricator.'
                . PHP_EOL
                . 'Please indicate which one to update.'
                . PHP_EOL
            );
            $i = 0;
            foreach ($tasks as $task) {
                printf(
                    "[%d] =>\t[ID]: T%d\n\t[Status]: %s\n\t[Name]: %s\n\t[Description]: %s\n",
                    $i++,
                    $task['id'],
                    $task['statusName'],
                    $task['title'],
                    $task['description']
                );
            }
            $index = $this->selectIndexFromList(
                'Enter the [index] of the task you would like to use',
                $i - 1
            );
            $keys = array_keys($tasks);
            if (array_key_exists($index, $keys)) {
                $key = $keys[$index];
                return $tasks[$key];
            }
            return [];
        } elseif (sizeof($tasks) === 1) {
            return array_pop($tasks);
        }
        // implicit third case: ticket does not yet exist in phabricator
        return [];
    }
}

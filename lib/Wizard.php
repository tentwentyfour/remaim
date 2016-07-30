<?php
/**
 * ReMaIm – Redmine to Phabricator Importer
 *
 * @package Ttf\Remaim
 *
 * @version  0.0.1 First public release
 * @since    0.0.1 First public release
 *
 * @author  Jonathan Jin <jonathan@tentwentyfour.lu>
 * @author  David Raison <david@tentwentyfour.lu>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 */

namespace Ttf\Remaim;

use Redmine\Client;
use Redmine\Api\Issue;

class Wizard
{
    use Traits\Transactions;
    use Traits\FileManager;

    private $phabricator_users = [];
    private $config;
    private $redmine;
    private $conduit;

    private $priority_map = [
        'Immediate' => 100, // unbreak now!
        'Urgent' => 100,    // unbreak now!
        'High' => 80,       // High
        'Normal' => 50,     // Normal
        'Low' => 25         // Low
         // Wishlist
    ];

    /**
     * Initialize Migration Wizard
     *
     * @param array           $config  Config read from YAML file
     * @param \Redmine\Client $redmine Instance of the Redmine API Client
     * @param \ConduitClient  $conduit Instance of ConduitClient
     */
    public function __construct(array $config, Client $redmine, $conduit)
    {
        $this->config = $config;
        $this->redmine = $redmine;
        $this->conduit = $conduit;
        try {
            $this->status_map = $this->fetchPhabricatorStati();
        } catch (\HTTPFutureCURLResponseStatus $e) {
            printf(
                "\nI am unable to connect to %s. \nThere was an error resolving the server hostname. Check that you are connected to the internet and that DNS is correctly configured.\n\n",
                $config['phabricator']['host']
            );
            exit(1);
        }
    }

    public function fetchPhabricatorStati()
    {
        $status_list = $this->conduit->callMethodSynchronous(
            'maniphest.querystatuses',
            []
        );
        return array_flip($status_list['statusMap']);
    }

    public function run()
    {
        try {
            $this->testConnectionToRedmine();

            $redmine_project = $this->selectAProject($this->listRedmineProjects());
            $issues = $this->getIssuesForProject($redmine_project);

            $phabricator_project = $this->selectOrCreatePhabricatorProject($redmine_project);

            $this->presentSummary(
                $redmine_project,
                $phabricator_project,
                $issues
            );

            $results = $this->migrateIssues(
                $issues,
                $phabricator_project,
                $policies
            );
            printf("%d tickets successfully migrated or updated!\n", sizeof($results));
        } catch (\Exception $e) {
            die($e->getMessage());
        }
    }

    /**
     * Can we find another, simpler method for checking connection than this?
     * Unfortunately, the Client does not have a way of checking whether the connection was successful,
     * since it never established a connection.
     * @return [type] [description]
     */
    public function testConnectionToRedmine()
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

    private function selectIndexFromList($message, $max)
    {
        printf("%s\n> ", $message);
        $fp = fopen('php://stdin', 'r');
        $selectedIndex = trim(fgets($fp, 1024));
        if ($selectedIndex > $max) {
            printf("You must select a value between 0 and %d\n", $max);
            return $this->selectTicketPhidFromDuplicates($max);
        }
        fclose($fp);
        return $selectedIndex;
    }

    public function presentSummary($redmine_project, $phabricator_project, $issues)
    {
        $project_detail = $this->redmine->project->show($redmine_project);

        printf(
            "\n\n####################\n# Pre-flight check #\n####################\nRedmine project named \"%s\" with ID %s.\n",
            $project_detail['project']['name'],
            $redmine_project
        );

        printf(
            'Target phabricator project named "%s" with ID %s.' . "\n",
            $phabricator_project['name'],
            $phabricator_project['id']
        );

        printf(
            '%d tickets to be migrated! OK to continue? [y/N]' . "\n> ",
            sizeof($issues)
        );
        $fp = fopen('php://stdin', 'r');
        $checking = trim(fgets($fp, 1024));
        fclose($fp);

        if (!($checking == 'y' || $checking == 'Y')) {
            die('KTHXBAI! Please try again soon!'. "\n");
        }
    }

    public function getIssuesForProject($project_id)
    {
        $tasks = $this->redmine->issue->all([
            'project_id' => $project_id,
            'limit' => 1024
        ]);

        if (!$tasks || empty($tasks['issues'])) {
            throw new \RuntimeException(
                sprintf(
                    "No tasks found on project with id %d.\n There is nothing to do, I'm going home.\nYou know where to find me if you ever need me again!\n",
                    $project_id
                )
            );
        }
        return $tasks['issues'];
    }

    public function selectOrCreatePhabricatorProject($project_id)
    {
        print("Please enter the id/slug of the project in Phabricator.\nPress [Enter] to see a list of available projects or\nEnter [0] to create a new project from the Redmine project's details\n> ");
        $fp = fopen('php://stdin', 'r');
        $choice = trim(fgets($fp, 1024));
        fclose($fp);
        return $this->actOnChoice($choice, $project_id);
    }

    /**
     * Retrieves a list of all available (even inactive)
     * projects on your phabricator instance.
     *
     * @return array List of phabricator projects
     */
    public function getAllPhabricatorProjects()
    {
        $result = $this->conduit->callMethodSynchronous(
            'project.search',
            ['queryKey' => 'all']
        );

        if ($result && isset($result['data'])) {
            return array_reduce($result['data'], function ($carry, $project) {
                $carry[$project['id']] = [
                    'id' => $project['id'],
                    'phid' => $project['phid'],
                    'name' => $project['fields']['name'],
                ];
                return $carry;
            });
        }
    }

    public function actOnChoice($choice, $redmine_project)
    {
        switch ($choice) {
            case '':
                $projects = $this->getAllPhabricatorProjects();
                ksort($projects);
                $project = $this->selectAProject($projects);

                if ('0' === $project) {
                    $this->selectOrCreatePhabricatorProject($redmine_project);
                } elseif (array_key_exists($project, $projects)) {
                    return $projects[$project];
                }
                break;
            case '0':
                $policies = $this->definePolicies($this->lookupGroupProjects());
                $detail = $this->redmine->project->show($redmine_project);

                $phab_members = $this->getPhabricatorUserPhid(
                    $this->getRedmineProjectMembers($redmine_project)
                );

                $api_parameters = [
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
                ];

                $project = $this->conduit->callMethodSynchronous(
                    'project.edit',
                    $api_parameters
                );

                printf(
                    "OK, created project \"%s\" with phid %s\n",
                    $detail['project']['name'],
                    $project['object']['phid']
                );

                $choice = $project['object']['id'];
                // intentional fall-through!
            default:
                if (empty($choice)) {
                    throw new \Exception('Cannot continue without a valid choice');
                } elseif (is_numeric($choice)) {
                    $api_parameters = [
                        'ids' => [$choice],
                    ];
                    $project = $this->findPhabProjectWithIdSlug($api_parameters);
                } elseif (is_string($choice)) {
                    $api_parameters = [
                        'slugs' => [$choice],
                    ];
                    $project = $this->findPhabProjectWithIdSlug($api_parameters);
                }
                if ($project) {
                    $this->notifyProjectFound($project);
                    return $project;
                }
                break;
        }
        throw new \Exception("Failed to identify a phabricator project to migrate to.\n");
    }

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
     * Looks up projects in phabricator of type "group"
     *
     * @return array Result of lookup
     */
    public function lookupGroupProjects()
    {
        $api_parameters = [
          'constraints' => [
            'icons' => [
              'group',
            ],
          ],
        ];
        return $this->conduit->callMethodSynchronous('project.search', $api_parameters);
    }

    /**
     * Allow the user to select a group that will
     * be used for view and edit policies for both
     * projects and tasks creaed in maniphest.
     *
     * @param  array $groups Groups found by self::lookupGroupPojects()
     * @return array         Array containing PHIDs for view and edit policies
     */
    public function definePolicies($groups)
    {
        $i = 0;
        foreach ($groups['data'] as $group) {
            printf(
                "[%d] =>\t[ID]: T%d \n\t[Name]: %s\n",
                $i++,
                $group['id'],
                $group['fields']['name']
            );
        }
        $index = $this->selectIndexFromList(
            'Select a group to get view and edit permissions.',
            $i
        );
        $groupproject = $groups['data'][$index];
        return [
            'view' => $groupproject['phid'],
            'edit' => $groupproject['phid'],
        ];
    }

    public function notifyProjectFound($project)
    {
        printf(
            'OK, found project named "%s" with PHID %s' . "\n",
            $project['name'],
            $project['phid']
        );
    }

    /**
     * Find an existing phabricator project by either its id or slug
     *
     * @param  [type] $api_parameters [description]
     *
     * @return [type]                 [description]
     */
    public function findPhabProjectWithIdSlug($api_parameters)
    {
        $result = $this->conduit->callMethodSynchronous('project.query', $api_parameters);
        if (!empty($result['data'])) {
            $found = array_pop($result['data']);
            if (isset($found['phid'])) {
                return $found;
            }
        }
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
        // printf(
        //     "%d total projects retrieved from your redmine instance.\n\n",
        //     $reply['total_count'][0]
        // );
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
        return $projects;
    }

    public function buildProjectTree(&$projects, $parent = 0)
    {
        $tmp_array = [];
        foreach ($projects as $project) {
            if ($project['parent']['id'] == $parent) {
                $project['children'] = $this->buildProjectTree($projects, $project['id']);
                $tmp_array[] = $project;
            }
        }
        usort($tmp_array, function ($a, $b) {
            return $a['id'] > $b['id'];
        });
        return $tmp_array;
    }

    /**
     * Visually represents project structure
     *
     * @param  Array  $project  Single project array
     * @param  integer $level   Level of hierarchy-depth
     *
     * @return String           Returns a visual representation of the structure of $project
     */
    public function representProject($project, $level = 0)
    {
        $string = sprintf(
            "[%d – %s]\n",
            $project['id'],
            $project['name']
        );
        if (!empty($project['children'])) {
            $indent = implode('', array_pad([], ++$level, "\t"));
            foreach ($project['children'] as $project) {
                $string .= sprintf("%s└–––––––– %s", $indent, $this->representProject($project, $level));
            }
        }
        return $string;
    }

    /**
     * Generic choice
     * @param  [type] $projects [description]
     * @return [type]           [description]
     */
    public function selectAProject($projects)
    {
        foreach ($projects as $toplevel) {
            print($this->representProject($toplevel));
        }
        print('Select a project ID or enter 0 to go back: ' . "\n> ");
        $fp = fopen('php://stdin', 'r');
        $project = trim(fgets($fp, 1024));
        fclose($fp);
        return $project;
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
                $this->phabricator_users = array_merge($this->phabricator_users, $queried_users);
            }
        }

        return array_values(
            array_intersect_key($this->phabricator_users, array_flip($fullnames))
        );
    }

    public function watchersToSubscribers($conduit, $redmine_watchers)
    {
        $watchers = [];
        foreach ($redmine_watchers as $watcher) {
            if (!isset($watcher['name']) || empty($watcher['name'])) {
                continue;
            }
            $watchers[] = $watcher['name'];
        }
        return $this->getPhabricatorUserPhid($watchers);
    }

    public function migrateIssues(
        $issues,
        $ph_project,
        $policies
    ) {
        return array_map(function ($issue) use ($ph_project, $policies) {
            $details = $this->redmine->issue->show(
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

            $owner = $this->grabOwnerPhid($details['issue']);
            $tasks = [];
            $description = $details['issue']['description'];

            if (!empty($description)) {
                $details['issue']['description'] = str_replace("\r", '', trim($description));
                $api_parameters = [
                    'projectPHIDs' => [$ph_project['phid']],
                    'fullText' => $details['issue']['description'],
                ];
                $tasks = $this->conduit->callMethodSynchronous(
                    'maniphest.query',
                    $api_parameters
                );
            }

            return $this->createManiphestTask(
                $tasks,
                $details['issue'],
                $description,
                $owner,
                $ph_project['phid'],
                $policies
            );
        }, $issues);
    }

    public function createManiphestTask(
        $tasks,
        $issue,
        $description,
        $owner,
        $project_phid,
        $policies
    ) {
        $task = [];
        $num_found = sizeof($tasks);
        if (!empty($tasks) && $num_found > 1) {
            print("Oops, I found more than one already existing task in phabricator.\nPlease indicate which one to update. \n(You might want to delete the duplicate from your phabricator CLI later.\n");
            $i = 0;
            foreach ($tasks as $task) {
                printf(
                    "[%d] =>\t[ID]: T%d \n\t[Name]: %s \n\t[Descriptions]: %s\n",
                    $i++,
                    $task['id'],
                    $task['title'],
                    $task['description']
                );
            }
            $index = $this->selectIndexFromList(
                'Enter the [index] of the task you would like to use.',
                $i - 1
            );
            $keys = array_keys($tasks);
            $key = $keys[$index];
            $task = $tasks[$key];
        } elseif (sizeof($tasks) === 1) {
            $task = array_pop($tasks);
        } // implicit third case: ticket does not yet exist in phabricator

        $transactions = $this->assembleTransactionsFor(
            $project_phid,
            $issue,
            $description,
            $policies,
            $task,
            $owner
        );

        return $this->createOrUpdatePhabTicket($transactions, $task);
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
     * Assembles a collection of transactions to be applied to each
     * issue that will be migrated.
     *
     * @param  [type]  $project_phid     [description]
     * @param  [type]  $details          [description]
     * @param  [type]  $task_description [description]
     * @param  [type]  $policies         [description]
     * @param  array   $task             [description]
     * @param  boolean $assignee         [description]
     * @return [type]                    [description]
     */
    public function assembleTransactionsFor(
        $project_phid,
        $issue,
        $task_description,
        $policies,
        $task = [],
        $assignee = false
    ) {
        $transactions = [];
        $transactions[] = $this->createProjectTransaction($project_phid);
        $transactions[] = $this->createTitleTransaction(
            $issue,
            $task
        );
        $transactions[] = $this->createDescriptionTransaction(
            $issue,
            $policies,
            $task
        );
        $transactions[] = $this->createStatusTransaction($issue);
        $transactions[] = $this->createSubscriberTransaction($issue);
        $transactions[] = $this->createPriorityTransaction($issue);

        if ($assignee) {
            $transactions[] = $this->createOwnerTransaction($assignee['phid']);
        }

        $transactions = array_merge(
            $transactions,
            $this->createCommentTransactions($issue)
        );

        $transactions = array_merge(
            $transactions,
            $this->createPolicyTransactions($policies)
        );

        return array_values(
            array_filter($transactions, function ($transaction) {
                return !empty($transaction);
            })
        );
    }

    /**
     * Grab the PHID for a user that will become the Owner of the
     * task.
     *
     * @param  array $issue Issue detail from redmine
     *
     * @return String       Owner PHID
     */
    public function grabOwnerPhid($issue)
    {
        if (!isset($issue['assigned_to'])) {
            return false;
        }

        $phab_ids = $this->getPhabricatorUserPhid([$issue['assigned_to']['name']]);
        return $phab_ids[0];
    }
}
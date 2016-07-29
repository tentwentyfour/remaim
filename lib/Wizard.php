<?php
/**
 * Redmine to Maniphest migration wizard
 * // todo:
    // priority    Change the priority of the task.
    // view    Change the view policy of the object.
    // edit    Change the edit policy of the object.
    // subscribers.set Set subscribers, overwriting current value.
    //  - refactor code into functions
    //  - fix status array problem
    //  - list of phabricator projects

*/

namespace Ttf\Remaim;

use Redmine\Api\Issue;

class Wizard
{
    use Traits\Transactions;

    private $phabricator_users = [];
    private $config;
    private $redmine;
    private $conduit;

    // DR: Dont put properties on the global level unless
    // absolutely necessary!
    // private $project;
    // private $found;
    // private $project_detail;
    // private $issues;
    // private $transactions = [];

    // Well, this will probably have to go into the yml file?

    private $priority_map = [
    'Immediate' => 100, // unbreak now!
    'Urgent' => 100,    // unbreak now!
    'High' => 80,       // High
    'Normal' => 50,     // Normal
    'Low' => 25         // Low
     // Wishlist
    ];

    // @todo   \ConduitClient
    public function __construct(array $config, \Redmine\Client $redmine, $conduit)
    {
        $this->config = $config;
        $this->redmine = $redmine;
        $this->conduit = $conduit;
        $this->status_map = $this->fetchPhabricatorStati();
    }

    public function fetchPhabricatorStati()
    {
        $phab_statuses = $this->conduit->callMethodSynchronous(
            'maniphest.querystatuses', 
            []
        );
        return array_flip($phab_statuses['statusMap']);
    }

    public function run()
    {
        try {
            $this->testConnectionToRedmine();
            $projects = $this->listRedmineProjects();
            $redmine_project = $this->selectAProject($projects);
            $groups = $this->lookupGroupProjects();
            $policies = $this->definePolicies($groups);
            $phabricator_project = $this->selectOrCreatePhabricatorProject($redmine_project, $policies);
            $issues = $this->getIssuesForProject($redmine_project);
            $this->identifyRedmineAndTargetphabricatorProject($redmine_project, $phabricator_project, $issues);
            $results = $this->findOrCreateTicketFromRedmineInPhab(
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
     * Unfortunately, the Client does not have a way of checking whether the connection was successfull,
     * since it never established a connection.
     * @return [type] [description]
     */
    public function testConnectionToRedmine()
    {
        $project_listing = $this->redmine->project->listing();
        if (empty($project_listing) || !is_array($project_listing)) {
            throw new \InvalidArgumentException(
                "Your project list is empty or we were unable to connect to redmine.\n
                Please verify your credentials are correct!\n"
            );
        }
        return true;
    }

    public function selectIndexFromList($message, $max)
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
    
    public function createOrUpdatePhabTicket($transactions, $taskPHID = null)
    {
        $api_parameters = [
          'objectIdentifier' => $taskPHID,
          'transactions' => $transactions
        ];

        return $this->conduit->callMethodSynchronous(
            'maniphest.edit', 
            $api_parameters
        );
    }

    public function identifyRedmineAndTargetphabricatorProject($redmine_project, $phabricator_project, $issues)
    {
        $project_detail = $this->redmine->project->show($redmine_project);

        printf(
            "\n\n" .
            'SUMMING UP:' . "\n" .
            'Redmine project named "%s" with ID %s' . "\n",
            $project_detail['project']['name'],
            $redmine_project
        );

        printf(
            'Target phabricator project named "%s" with ID %s' . "\n",
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
            die('Bye'. "\n");
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
                    "No tasks found on project with id %d\n",
                    $project_id
                )
            );
        }
        // DR: would be better to return $tasks['issues'];
        // then to assign them on the class level.
        $issues = $tasks['issues'];
        return $issues;
    }

    public function selectOrCreatePhabricatorProject($project_id, $policies)
    {
        print("Please enter the id/slug of the project in phabricator.\nPress [Enter] to see a list of available projects or\nEnter [0] to create a new project from the Redmine project's details\n> ");
        $fp = fopen('php://stdin', 'r');
        $choice = trim(fgets($fp, 1024));
        fclose($fp);
        return $this->actOnChoice($choice, $project_id, $policies);
    }

    public function actOnChoice($choice, $project_id, $policies)
    {
        switch ($choice) {
            case '':
                
                $api_parameters = [
                    'ids' => [],
                ];
                $result = $this->conduit->callMethodSynchronous('project.query', $api_parameters);
                var_dump($result);

                // TO BE FINISHED
                break;
            case '0':
                $detail = $this->redmine->project->show(
                    $project_id
                );

                $phab_members = $this->getPhabricatorUserPhid(
                    $this->getRedmineProjectMembers($project_id)
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
     * [findPhabProjectWithIdSlug description]
     * @param  [type] $api_parameters [description]
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

    public function selectAProject($projects)
    {
        foreach ($projects as $toplevel) {
            print($this->representProject($toplevel));
        }
        print('Select a project: [0] ' . "\n> ");
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

    public function findOrCreateTicketFromRedmineInPhab(
        $issues, 
        $phabricator_project, 
        $policies
    ) {
         // * Once we have a list of all issues on the selected project from redmine,
         // * we will loop through them using array_map and add each issue to the
         // * new project on phabricator
        return array_map(function ($issue) use ($phabricator_project, $policies) {
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
            $owner = $this->grabOwnerPhid($details);

            $tickets = [];
            $description = $details['issue']['description'];
            if (!empty($description)) {
                $description = str_replace("\r", '', $description);
                $api_parameters = [
                    'projectPHIDs' => [$phabricator_project['phid']],
                    'fullText' => $description,
                ];
                $tickets = $this->conduit->callMethodSynchronous(
                    'maniphest.query', 
                    $api_parameters
                );
            }

            return $this->createManiphestTask(
                $tickets, 
                $details, 
                $description, 
                $owner, 
                $phabricator_project,
                $policies
            );
        }, $issues);
    }

    public function createManiphestTask(
        $tickets, 
        $details, 
        $description, 
        $owner, 
        $phabricator_project, 
        $policies
    ) {
        $num_found = sizeof($tickets);
        if (!empty($tickets) && $num_found > 1) {
            print("Oops, I found more than one already exisiting ticket in phabricator.\nPlease indicate which one to update. \n(You might want to delete the duplicate from your phabricator CLI later.\n");
            $i = 0;
            foreach ($tickets as $ticket) {
                printf(
                    "[%d] =>\t[ID]: T%d \n\t[Name]: %s \n\t[Descriptions]: %s\n",
                    $i++, 
                    $ticket['id'],
                    $ticket['title'], 
                    $ticket['description']
                );
            }
            $index = $this->selectIndexFromList(
                'Enter the [index] of the ticket you would like to use.',
                $i - 1
            );
            $keys = array_keys($tickets);
            $key = $keys[$index];
            $ticket = $tickets[$key];
            $selected_ticketphid = $ticket['phid'];
        } elseif (empty($tickets) || sizeof($tickets) === 1) {
            $ticket = array_pop($tickets);
            $selected_ticketphid = $ticket['phid'];
        } // implicit third case: ticket does not yet exist in phabricator

        $transactions = [];
        $transactions[] = $this->transactPhabProjectPhid($phabricator_project['phid']);
        if ($owner) {
            $transactions[] = $this->transactOwnerPhid($owner['phid']);
        }
        $transactions[] = $this->transactTitle($details, $ticket);
        $transactions[] = $this->transactFiles($details, $description);
        $transactions[] = $this->transactStatus($details);
        $transactions[] = $this->transactComments($details);
        $transactions[] = $this->transactSubscriber($details);
        $transactions[] = $this->transactPriority($details);
        $transactions = array_merge(
            $transactions,
            $this->transactPolicy($details, $policies)
        );
        $transactions = array_filter($transactions, function ($transaction) {
            return !empty($transaction);
        });
        return $this->createOrUpdatePhabTicket($transactions, $selected_ticketphid);
    }

    public function grabOwnerPhid($details)
    {
        if (!isset($details['issue']['assigned_to'])) {
            return false;
        }

        $api_parameters = [
            'realnames' => [$details['issue']['assigned_to']['name']],
        ];
        $result = $this->conduit->callMethodSynchronous(
            'user.query', 
            $api_parameters
        );
        return array_pop($result);
    }
}